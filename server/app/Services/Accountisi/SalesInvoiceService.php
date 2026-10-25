<?php

namespace App\Services\Accountisi;

use App\Support\Lang;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesInvoiceSync;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

/**
 * Factura de venta en Accountisi a partir de horas aprobadas de un cliente en un período.
 *  - El proyecto va en el comentario (Description) de la factura.
 *  - Nivel de detalle por proyecto: total, por día o con hora desde/hasta (projects.invoice_detail).
 *  - Modo "new" crea una factura; "append" añade a una factura abierta (borrador) del mismo cliente.
 *  - Las horas facturadas quedan marcadas y no se vuelven a facturar.
 */
class SalesInvoiceService
{
    /** Solo calcula: líneas, total y comentario. No toca Accountisi. */
    public function preview(Company $company, Customer $customer, string $from, string $to, ?array $projectIds = null): array
    {
        // Descripciones y comentario viajan a la factura de Accountisi (la ve el cliente): van en el idioma de la compañía.
        return Lang::with($company->lang(), fn () => $this->buildPreview($company, $customer, $from, $to, $projectIds));
    }

    private function buildPreview(Company $company, Customer $customer, string $from, string $to, ?array $projectIds = null): array
    {
        $entries = TimeEntry::with('project.services', 'service', 'user')
            ->where('company_id', $company->id)->where('running', false)->where('billable', true)
            ->whereNull('sales_sync_id')->whereBetween('work_date', [$from, $to])
            ->whereHas('project', fn ($p) => $p->where('customer_id', $customer->id)->where('billable', true)
                ->when($projectIds, fn ($q) => $q->whereIn('id', $projectIds)))
            ->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED))
            ->orderBy('work_date')->orderBy('start_time')->get();

        $lines = [];
        $missingRate = [];
        // Una línea por proyecto, servicio y tarifa: cada servicio puede llevar su artículo de Accountisi.
        $groups = $entries->groupBy(fn ($e) => $e->project_id.'|'.$e->service_id.'|'.number_format($e->saleRate(), 2, '.', ''));
        foreach ($groups as $rows) {
            $first = $rows->first();
            $p = $first->project;
            $rate = $first->saleRate();
            if ($rate <= 0) {
                $missingRate[] = $p->code;
                continue;
            }
            $svc = $first->service;
            $title = $p->code.' '.$p->name.($p->services->count() > 1 && $svc ? ' · '.$svc->name : '');
            $item = $svc?->accountisi_item_id ?: null;
            if ($p->invoice_detail === 'by_day') {
                foreach ($rows->groupBy(fn ($e) => $e->work_date->toDateString()) as $day => $g) {
                    $lines[] = ['project_id' => $p->id, 'service_id' => $svc?->id, 'item_id' => $item, 'description' => $title.' · '.$day,
                        'quantity' => (float) $g->sum('hours'), 'unit_price' => $rate, 'entry_ids' => $g->pluck('id')->all()];
                }
            } elseif ($p->invoice_detail === 'with_times') {
                foreach ($rows as $e) {
                    $span = $e->start_time ? substr($e->start_time, 0, 5).'–'.substr((string) $e->end_time, 0, 5) : '';
                    $lines[] = ['project_id' => $p->id, 'service_id' => $svc?->id, 'item_id' => $item,
                        'description' => trim($title.' · '.$e->work_date->toDateString().' '.$span),
                        'quantity' => (float) $e->hours, 'unit_price' => $rate, 'entry_ids' => [$e->id]];
                }
            } else {
                $lines[] = ['project_id' => $p->id, 'service_id' => $svc?->id, 'item_id' => $item, 'description' => $title.' '.__('(:from a :to)', ['from' => $from, 'to' => $to]),
                    'quantity' => (float) $rows->sum('hours'), 'unit_price' => $rate, 'entry_ids' => $rows->pluck('id')->all()];
            }
        }
        $missingRate = array_values(array_unique($missingRate));

        $projects = $entries->pluck('project')->unique('id')->filter(fn ($p) => ! in_array($p->code, $missingRate, true));
        return [
            'lines' => $lines,
            'total_hours' => round(array_sum(array_column($lines, 'quantity')), 2),
            'amount' => round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines)), 2),
            'projects' => $projects->map(fn ($p) => $p->code.' '.$p->name)->values()->all(),
            'projects_without_rate' => $missingRate,
            'comment' => $projects->count() > 1
                ? __('Proyectos: :list', ['list' => $projects->map(fn ($p) => $p->code.' '.$p->name)->implode('; ')])
                : __('Proyecto: :list', ['list' => $projects->map(fn ($p) => $p->code.' '.$p->name)->implode('; ')]),
        ];
    }

    public function create(Company $company, Customer $customer, string $from, string $to, array $opts, AccountisiClient $client): SalesInvoiceSync
    {
        if (! $customer->accountisi_id) {
            throw ValidationException::withMessages(['customer_id' => __('Este cliente no está vinculado a un cliente de Accountisi. Sincroniza los clientes primero.')]);
        }
        $plan = $this->preview($company, $customer, $from, $to, $opts['project_ids'] ?? null);
        if ($plan['projects_without_rate']) {
            throw ValidationException::withMessages(['project_ids' => __('Falta la tarifa de facturación en: :projects.', ['projects' => implode(', ', $plan['projects_without_rate'])])]);
        }
        if (! $plan['lines']) {
            throw ValidationException::withMessages(['from' => __('No hay horas aprobadas sin facturar de ese cliente en ese período.')]);
        }

        $cfg = \App\Models\Integration::accountisiConfig($company);
        $comment = trim(($opts['comment'] ?? '') !== '' ? $plan['comment'].' — '.$opts['comment'] : $plan['comment']);
        $mode = $opts['mode'] ?? 'new';
        $date = $opts['invoice_date'] ?? now($company->timezone)->toDateString();

        $sync = SalesInvoiceSync::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'period_from' => $from, 'period_to' => $to,
            'project_ids' => array_values(array_unique(array_column($plan['lines'], 'project_id'))),
            'total_hours' => $plan['total_hours'], 'mode' => $mode, 'comment' => mb_substr($comment, 0, 500), 'status' => 'creating',
        ]);

        try {
            if ($mode === 'append') {
                $invoiceId = (string) ($opts['accountisi_invoice_id'] ?? '');
                $inv = $client->getInvoice($invoiceId);
                if ($inv['customer_id'] !== (string) $customer->accountisi_id) {
                    throw ValidationException::withMessages(['accountisi_invoice_id' => __('Esa factura es de otro cliente.')]);
                }
                if ($inv['status'] !== 0) {
                    throw ValidationException::withMessages(['accountisi_invoice_id' => __('Esa factura ya no está abierta (borrador); no se le pueden añadir horas.')]);
                }
                $client->updateInvoiceDescription($invoiceId, trim($inv['description'].' | '.$comment, ' |'));
            } else {
                $invoiceId = $client->createInvoice($customer->accountisi_id, $date, Lang::with($company->lang(), fn () => __('Horas :from a :to', ['from' => $from, 'to' => $to])), $comment);
            }
            $sync->update(['accountisi_invoice_id' => $invoiceId]);
            foreach ($plan['lines'] as $l) {
                $client->addInvoiceLine($invoiceId, [
                    // El artículo del servicio manda; si no tiene, se usa el artículo o la cuenta por omisión de la integración.
                    'item_id' => $l['item_id'] ?? $cfg['default_item_id'] ?? null,
                    'account_id' => ($l['item_id'] ?? $cfg['default_item_id'] ?? null) ? null : ($cfg['default_income_account_id'] ?? null),
                    'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                ]);
            }
        } catch (\Throwable $e) {
            // Las horas NO se marcan como facturadas si algo falló; el borrador remoto, si se creó, queda anotado.
            $sync->update(['status' => 'failed']);
            throw $e;
        }

        TimeEntry::whereIn('id', collect($plan['lines'])->pluck('entry_ids')->flatten())->update(['sales_sync_id' => $sync->id]);
        $sync->update(['status' => 'created']);
        return $sync->refresh();
    }
}
