<?php

namespace App\Services;

use App\Support\Lang;
use App\Mail\BrandedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Hoja de tiempo para entregarle a un cliente: las horas de sus proyectos en un período, con la descripción
 * de lo trabajado. Por omisión solo salen horas aprobadas y facturables, y sin tarifas ni importes.
 * (Es el equivalente de la lista que Dash imprime o exporta: persona, proyecto, fecha, total y descripción.)
 */
class ClientTimesheetService
{
    /**
     * @param array{project_ids?:array,user_ids?:array,include_pending?:bool,include_non_billable?:bool,show_amounts?:bool} $opts
     */
    public function build(Company $company, Customer $customer, string $from, string $to, array $opts = []): array
    {
        $q = TimeEntry::with(['project.services', 'service', 'user', 'timesheet.approver'])
            ->where('company_id', $company->id)->where('running', false)
            ->whereBetween('work_date', [$from, $to])
            ->whereHas('project', fn ($p) => $p->where('customer_id', $customer->id)
                ->when(! empty($opts['project_ids']), fn ($x) => $x->whereIn('id', $opts['project_ids'])))
            ->when(! empty($opts['user_ids']), fn ($x) => $x->whereIn('user_id', $opts['user_ids']))
            ->when(empty($opts['include_non_billable']), fn ($x) => $x->where('billable', true))
            ->orderBy('work_date')->orderBy('start_time')->orderBy('id');
        if (empty($opts['include_pending'])) {
            $q->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED));
        }
        $entries = $q->get();
        $showAmounts = ! empty($opts['show_amounts']);

        $projects = [];
        foreach ($entries->groupBy('project_id')->sortKeys() as $rows) {
            $p = $rows->first()->project;
            $lines = $rows->map(function (TimeEntry $e) use ($p, $showAmounts) {
                $approved = $e->timesheet->status === Timesheet::APPROVED;
                return [
                    'date' => $e->work_date->toDateString(),
                    'worker' => $e->user->name,
                    'start' => $e->start_time ? substr($e->start_time, 0, 5) : null,
                    'end' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                    'hours' => (float) $e->hours,
                    'description' => ($p->services->count() > 1 && $e->service ? $e->service->name.($e->note ? ' — ' : '') : '').(string) $e->note,
                    'approved' => $approved,
                    'amount' => $showAmounts ? round((float) $e->hours * $e->saleRate(), 2) : null,
                ];
            })->values()->all();
            $byWorker = collect($lines)->groupBy('worker')->map(fn ($g, $w) => ['worker' => $w, 'hours' => round($g->sum('hours'), 2)])->values()->all();
            $projects[] = [
                'code' => $p->code, 'name' => $p->name, 'lines' => $lines, 'by_worker' => $byWorker,
                'hours' => round(array_sum(array_column($lines, 'hours')), 2),
                'amount' => $showAmounts ? round(array_sum(array_column($lines, 'amount')), 2) : null,
            ];
        }

        $approvers = $entries->map(fn ($e) => $e->timesheet->approver?->name)->filter()->unique()->values()->all();
        return [
            'company' => $company->displayName(),
            'customer' => $customer->name,
            'from' => $from, 'to' => $to,
            'generated_on' => now($company->timezone)->toDateString(),
            'projects' => $projects,
            'total_hours' => round(array_sum(array_column($projects, 'hours')), 2),
            'total_amount' => $showAmounts ? round(array_sum(array_column($projects, 'amount')), 2) : null,
            'show_amounts' => $showAmounts,
            'has_pending' => $entries->contains(fn ($e) => $e->timesheet->status !== Timesheet::APPROVED),
            'approved_by' => $approvers,
            'entry_count' => $entries->count(),
        ];
    }

    /** La misma hoja como tabla plana (para CSV). */
    public function asTable(array $data): array
    {
        $rows = [];
        foreach ($data['projects'] as $p) {
            foreach ($p['lines'] as $l) {
                $rows[] = [$l['date'], $l['worker'], $p['code'].' · '.$p['name'], $l['start'] ?? '', $l['end'] ?? '', $l['hours'],
                    $l['description'].($l['approved'] ? '' : ' ('.__('sin aprobar').')')] + ($data['show_amounts'] ? [7 => $l['amount']] : []);
            }
        }
        $cols = [['h' => __('Fecha'), 't' => 'date'], ['h' => __('Persona'), 't' => 'text'], ['h' => __('Proyecto'), 't' => 'text'], ['h' => __('Desde'), 't' => 'text'],
            ['h' => __('Hasta'), 't' => 'text'], ['h' => __('Horas'), 't' => 'hours'], ['h' => __('Descripción'), 't' => 'text']];
        $total = [__('TOTAL'), '', '', '', '', $data['total_hours'], ''];
        if ($data['show_amounts']) {
            $cols[] = ['h' => __('Importe'), 't' => 'money'];
            $total[] = $data['total_amount'];
        }
        return ['title' => __('Hoja de tiempo'), 'subtitle' => $data['customer'].' · '.__(':from a :to', ['from' => $data['from'], 'to' => $data['to']]), 'columns' => $cols, 'rows' => $rows, 'total' => $total];
    }

    public function pdf(Company $company, array $data): string
    {
        // La hoja es para el cliente de la compañía: sale en el idioma de la compañía.
        return Lang::with($company->lang(), fn () => $this->renderPdf($company, $data));
    }

    private function renderPdf(Company $company, array $data): string
    {
        $logo = null;
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $logo = 'data:'.Storage::disk('public')->mimeType($company->logo_path).';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
        }
        $html = view('pdf.client-timesheet', ['d' => $data, 'brand' => $company->brand(), 'logo' => $logo])->render();
        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        return $pdf->output();
    }

    /** Devuelve la ruta de un .xlsx temporal con la misma información que el PDF. */
    public function xlsx(array $data): string
    {
        $book = new Spreadsheet();
        $s = $book->getActiveSheet();
        $s->setTitle(__('Hoja de tiempo'));
        $s->setCellValue('A1', $data['company'].' — '.__('Hoja de tiempo'));
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $s->setCellValue('A2', __('Cliente: :name', ['name' => $data['customer']]));
        $s->setCellValue('A3', __('Período: :from a :to', ['from' => $data['from'], 'to' => $data['to']]));
        $s->setCellValue('A4', __('Emitida: :date', ['date' => $data['generated_on']]));
        $row = 6;
        $heads = [__('Fecha'), __('Persona'), __('Proyecto'), __('Desde'), __('Hasta'), __('Horas'), __('Descripción')] + ($data['show_amounts'] ? [7 => __('Importe')] : []);
        foreach ($heads as $i => $h) {
            $s->setCellValue([$i + 1, $row], $h);
        }
        $s->getStyle('A'.$row.':'.($data['show_amounts'] ? 'H' : 'G').$row)->getFont()->setBold(true);
        $row++;
        foreach ($data['projects'] as $p) {
            foreach ($p['lines'] as $l) {
                $s->setCellValue([1, $row], XlDate::PHPToExcel(\Carbon\Carbon::parse($l['date'])->toDateTime()));
                $s->getStyle([1, $row])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                $s->setCellValue([2, $row], $l['worker']);
                $s->setCellValue([3, $row], $p['code'].' · '.$p['name']);
                $s->setCellValue([4, $row], $l['start'] ?? '');
                $s->setCellValue([5, $row], $l['end'] ?? '');
                $s->setCellValue([6, $row], $l['hours']);
                $s->setCellValue([7, $row], $l['description'].($l['approved'] ? '' : ' ('.__('sin aprobar').')'));
                $s->getStyle([7, $row])->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                if ($data['show_amounts']) {
                    $s->setCellValue([8, $row], $l['amount']);
                }
                $row++;
            }
            $s->setCellValue([3, $row], __('Subtotal :code', ['code' => $p['code']]));
            $s->setCellValue([6, $row], $p['hours']);
            if ($data['show_amounts']) {
                $s->setCellValue([8, $row], $p['amount']);
            }
            $s->getStyle('A'.$row.':'.($data['show_amounts'] ? 'H' : 'G').$row)->getFont()->setBold(true);
            $row += 2;
        }
        $s->setCellValue([3, $row], __('TOTAL'));
        $s->setCellValue([6, $row], $data['total_hours']);
        if ($data['show_amounts']) {
            $s->setCellValue([8, $row], $data['total_amount']);
        }
        $s->getStyle('A'.$row.':'.($data['show_amounts'] ? 'H' : 'G').$row)->getFont()->setBold(true);
        foreach (['A' => 12, 'B' => 22, 'C' => 30, 'D' => 8, 'E' => 8, 'F' => 8, 'G' => 70, 'H' => 12] as $col => $w) {
            $s->getColumnDimension($col)->setWidth($w);
        }
        $dir = storage_path('app/exports');
        @mkdir($dir, 0775, true);
        $path = $dir.'/'.bin2hex(random_bytes(6)).'-hoja-de-tiempo.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    /** Envía la hoja en PDF al correo indicado, con la marca de la compañía. */
    public function email(Company $company, array $data, string $to, ?string $message, string $pdfBytes): void
    {
        $name = \Illuminate\Support\Str::slug(Lang::with($company->lang(), fn () => __('Hoja de tiempo'))).'-'.$data['from'].'_'.$data['to'].'.pdf';
        $tmp = storage_path('app/exports/'.bin2hex(random_bytes(6)).'-'.$name);
        @mkdir(dirname($tmp), 0775, true);
        file_put_contents($tmp, $pdfBytes);
        try {
            Lang::with($company->lang(), function () use ($company, $data, $to, $message, $tmp, $name) {
                Mail::to($to)->locale($company->lang())->send(new BrandedMail(
                    $company, __('Hoja de tiempo :from a :to', ['from' => $data['from'], 'to' => $data['to']]).' — '.$company->displayName(), 'mail.client-timesheet',
                    ['d' => $data, 'note' => $message],
                    [['path' => $tmp, 'name' => $name, 'mime' => 'application/pdf']]
                ));
            });
        } finally {
            @unlink($tmp);
        }
    }
}
