<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\ContractorInvoice;
use App\Models\Integration;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Accountisi\AccountisiClient;
use App\Services\Accountisi\AccountisiException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Factura del consultor hacia la compañía, a partir de sus hojas aprobadas. Los empleados no facturan.
 * Genera el PDF con la marca, lo envía por email al dueño y, si hay integración, lo crea en Accountisi
 * como cuenta por pagar en borrador (la retención la calcula Accountisi según la configuración del suplidor).
 */
class ContractorInvoiceService
{
    public function create(User $worker, string $from, string $to): ContractorInvoice
    {
        if ($worker->worker_type !== 'consultant') {
            throw ValidationException::withMessages(['user' => 'Solo los consultores generan factura; los empleados no.']);
        }
        $terms = $worker->terms;
        if (! $terms || (float) $terms->rate <= 0) {
            throw ValidationException::withMessages(['terms' => 'Falta la tarifa del consultor.']);
        }

        $company = $worker->company;
        $sheets = Timesheet::with('entries.project')->where('user_id', $worker->id)->where('status', Timesheet::APPROVED)
            ->whereNull('contractor_invoice_id')->whereBetween('week_ending', [$from, $to])->orderBy('week_ending')->get();
        if ($sheets->isEmpty()) {
            throw ValidationException::withMessages(['from' => 'No hay semanas aprobadas sin facturar en ese período.']);
        }

        $rate = (float) $terms->rate;
        $lines = $sheets->flatMap->entries->where('billable', true)->groupBy('project_id')->map(function ($rows) use ($rate) {
            $hours = round((float) $rows->sum('hours'), 2);
            return ['description' => $rows->first()->project->code.' · '.$rows->first()->project->name, 'hours' => $hours,
                'rate' => $rate, 'amount' => round($hours * $rate, 2)];
        })->values()->all();
        if (! $lines) {
            throw ValidationException::withMessages(['from' => 'Las horas de ese período no son facturables.']);
        }

        $gross = round(array_sum(array_column($lines, 'amount')), 2);
        $retention = round($gross * (float) $terms->retention_pct / 100, 2);
        $ivu = $terms->ivu_agent ? round($gross * (float) $terms->ivu_pct / 100, 2) : 0.0;
        $issued = now($company->timezone)->toDateString();

        $invoice = \Illuminate\Support\Facades\DB::transaction(function () use ($company, $worker, $from, $to, $issued, $terms, $lines, $gross, $retention, $ivu, $sheets) {
            $seq = ContractorInvoice::where('company_id', $company->id)->whereYear('issued_on', substr($issued, 0, 4))->count() + 1;
            $inv = ContractorInvoice::create([
                'company_id' => $company->id, 'user_id' => $worker->id,
                'number' => sprintf('%s-%s-%04d', $company->invoice_prefix, substr($issued, 0, 4), $seq),
                'period_from' => $from, 'period_to' => $to, 'issued_on' => $issued,
                'due_on' => now($company->timezone)->addDays((int) $terms->terms_days)->toDateString(),
                'gross' => $gross, 'retention' => $retention, 'ivu' => $ivu, 'net' => round($gross - $retention - $ivu, 2),
                'lines' => $lines, 'status' => 'issued',
            ]);
            Timesheet::whereIn('id', $sheets->pluck('id'))->update(['contractor_invoice_id' => $inv->id]);
            return $inv;
        });

        $invoice->update(['pdf_path' => $this->renderPdf($invoice)]);
        $this->emailOwners($invoice);
        $this->pushToAccountisi($invoice);
        return $invoice->refresh();
    }

    public function renderPdf(ContractorInvoice $inv): string
    {
        $inv->loadMissing('user.terms', 'user.company');
        $company = $inv->user->company;
        $brand = $company->brand();
        $logo = null;
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $logo = 'data:'.Storage::disk('public')->mimeType($company->logo_path).';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
        }
        $html = view('pdf.contractor-invoice', ['inv' => $inv, 'worker' => $inv->user, 'terms' => $inv->user->terms,
            'brand' => $brand, 'logo' => $logo, 'company' => $company])->render();
        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();
        $path = 'invoices/'.$company->id.'/'.$inv->number.'.pdf';
        Storage::disk('local')->put($path, $pdf->output());
        return $path;
    }

    private function emailOwners(ContractorInvoice $inv): void
    {
        $company = $inv->user->company;
        $owners = User::where('company_id', $company->id)->where('role', 'owner')->where('active', true)->get();
        if ($owners->isEmpty() || ! $inv->pdf_path) {
            return;
        }
        foreach ($owners as $o) {
            Mail::to($o->email, $o->name)->send(new BrandedMail(
                $company, 'Factura '.$inv->number.' de '.$inv->user->name, 'mail.contractor-invoice',
                ['inv' => $inv, 'worker' => $inv->user->name],
                [['path' => Storage::disk('local')->path($inv->pdf_path), 'name' => $inv->number.'.pdf', 'mime' => 'application/pdf']]
            ));
        }
        $inv->update(['status' => 'sent']);
    }

    /** Crea la cuenta por pagar en borrador. Si falla, la factura queda y se puede reintentar. */
    public function pushToAccountisi(ContractorInvoice $inv): void
    {
        $inv->loadMissing('user.terms', 'user.company');
        $company = $inv->user->company;
        $client = AccountisiClient::forCompany($company);
        if (! $client || $inv->accountisi_bill_id) {
            return;
        }
        $vendor = $inv->user->terms?->accountisi_vendor_id;
        if (! $vendor) {
            $inv->update(['accountisi_status' => 'no_vendor']);
            return;
        }
        $cfg = Integration::accountisiConfig($company);
        try {
            $note = 'Factura '.$inv->number.' de '.$inv->user->name.' ('.$inv->period_from->toDateString().' a '.$inv->period_to->toDateString().')';
            $billId = $client->createBill($vendor, $inv->issued_on->toDateString(), $inv->number, $note);
            foreach ($inv->lines as $l) {
                $client->addBillLine($billId, [
                    'item_id' => $cfg['default_service_item_id'] ?? null,
                    'account_id' => empty($cfg['default_service_item_id']) ? ($cfg['default_expense_account_id'] ?? null) : null,
                    'description' => $l['description'], 'quantity' => $l['hours'], 'unit_price' => $l['rate'],
                ]);
            }
            $inv->update(['accountisi_bill_id' => $billId, 'accountisi_status' => 'draft']);
        } catch (AccountisiException $e) {
            $inv->update(['accountisi_status' => 'error']);
            report($e);
        }
    }
}
