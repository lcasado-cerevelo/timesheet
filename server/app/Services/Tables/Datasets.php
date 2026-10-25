<?php

namespace App\Services\Tables;

use App\Models\Approver;
use App\Models\ContractorInvoice;
use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Week;
use Illuminate\Validation\ValidationException;

/**
 * Las tablas de la aplicación como datos. Se generan en el servidor, con los permisos y la compañía de quien pide,
 * y no a partir de lo que mande el navegador: una exportación no puede mostrar más de lo que esa persona ya puede ver.
 */
class Datasets
{
    /** Quién puede pedir cada tabla: 'any' o 'owner'. */
    public const ACCESS = [
        'report' => 'any', 'week' => 'any', 'invoices' => 'any',
        'pending' => 'owner', 'projects' => 'owner', 'people' => 'owner', 'approvers' => 'owner', 'customers' => 'owner',
    ];

    public function allowed(string $dataset, User $user): bool
    {
        $a = self::ACCESS[$dataset] ?? null;
        return $a === 'any' || ($a === 'owner' && $user->isOwner());
    }

    public function build(string $dataset, User $user, array $p): array
    {
        return match ($dataset) {
            'report' => $this->report($user, $p),
            'week' => $this->week($user, $p),
            'invoices' => $this->invoices($user, $p),
            'pending' => $this->pending($user, $p),
            'projects' => $this->projects($user, $p),
            'people' => $this->people($user, $p),
            'approvers' => $this->approvers($user, $p),
            'customers' => $this->customers($user, $p),
        };
    }

    /**
     * Filtros: los mismos que aplica la pantalla (ver app.js). `q` busca, sin importar mayúsculas ni tildes, en los
     * campos que se indican para cada tabla. Lo exportado y los totales salen ya filtrados.
     */
    private function fold(?string $s): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii((string) $s));
    }

    private function matches(?string $q, array $fields): bool
    {
        $q = trim((string) $q);
        if ($q === '') {
            return true;
        }
        $hay = $this->fold(implode(' ', $fields));
        foreach (preg_split('/\s+/', $this->fold($q)) as $word) {
            if ($word !== '' && ! str_contains($hay, $word)) {
                return false;
            }
        }
        return true;
    }

    private function flag(array $p, string $key): ?bool
    {
        return isset($p[$key]) && $p[$key] !== '' ? filter_var($p[$key], FILTER_VALIDATE_BOOLEAN) : null;
    }

    private function txt(array $f): ?string
    {
        return ! empty($f['q']) ? 'texto "'.$f['q'].'"' : null;
    }

    /** Texto que describe los filtros activos, para dejarlo escrito en el archivo. */
    private function describe(array $parts): string
    {
        $parts = array_filter($parts);
        return $parts ? ' · Filtro: '.implode(', ', $parts) : '';
    }

    private function report(User $u, array $p): array
    {
        $v = validator($p, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'group' => 'nullable|in:project,day,week,customer', 'project_id' => 'nullable|integer'])->validate();
        $group = $v['group'] ?? 'project';
        $entries = TimeEntry::with('project.customer')->where('user_id', $u->id)->where('running', false)
            ->whereBetween('work_date', [$v['from'], $v['to']])
            ->when(! empty($v['project_id']), fn ($q) => $q->where('project_id', $v['project_id']))->get();
        $projCode = ! empty($v['project_id']) ? Project::where('company_id', $u->company_id)->find($v['project_id'])?->code : null;
        $rows = $entries->groupBy(fn ($e) => match ($group) {
            'day' => $e->work_date->toDateString(),
            'week' => Week::ending($e->work_date),
            'customer' => $e->project->customer?->name ?? '(sin cliente)',
            default => $e->project->code.' · '.$e->project->name,
        })->sortKeys()->map(fn ($g, $k) => [
            $k, round((float) $g->sum('hours'), 2), round((float) $g->where('billable', true)->sum('hours'), 2), round((float) $g->where('billable', false)->sum('hours'), 2),
        ])->values()->all();
        $label = ['project' => 'Proyecto', 'day' => 'Día', 'week' => 'Semana que termina', 'customer' => 'Cliente'][$group];
        return [
            'title' => 'Reporte de horas',
            'subtitle' => $u->name.' · '.$v['from'].' a '.$v['to'].' · por '.mb_strtolower($label).$this->describe([$projCode ? 'proyecto '.$projCode : null]),
            'columns' => [['h' => $label, 't' => $group === 'day' || $group === 'week' ? 'date' : 'text'], ['h' => 'Horas', 't' => 'hours'], ['h' => 'Facturables', 't' => 'hours'], ['h' => 'No facturables', 't' => 'hours']],
            'rows' => $rows,
            'total' => ['Total', round((float) $entries->sum('hours'), 2), round((float) $entries->where('billable', true)->sum('hours'), 2), round((float) $entries->where('billable', false)->sum('hours'), 2)],
        ];
    }

    private function week(User $u, array $p): array
    {
        $date = validator($p, ['date' => 'required|date_format:Y-m-d'])->validate()['date'];
        $end = Week::ending($date);
        $start = Week::starting($end);
        $sheet = Timesheet::where('user_id', $u->id)->where('week_ending', $end)->first();
        $entries = TimeEntry::with('project')->where('user_id', $u->id)->where('running', false)
            ->whereBetween('work_date', [$start, $end])->orderBy('work_date')->orderBy('start_time')->get();
        $status = ['draft' => 'Borrador', 'submitted' => 'Enviada', 'approved' => 'Aprobada', 'returned' => 'Devuelta'][$sheet->status ?? 'draft'] ?? 'Borrador';
        return [
            'title' => 'Hoja de la semana',
            'subtitle' => $u->name.' · semana '.$start.' a '.$end.' · '.$status,
            'columns' => [['h' => 'Fecha', 't' => 'date'], ['h' => 'Proyecto', 't' => 'text'], ['h' => 'Desde', 't' => 'text'], ['h' => 'Hasta', 't' => 'text'], ['h' => 'Horas', 't' => 'hours'], ['h' => 'Nota', 't' => 'text']],
            'rows' => $entries->map(fn ($e) => [$e->work_date->toDateString(), $e->project->code.' · '.$e->project->name,
                $e->start_time ? substr($e->start_time, 0, 5) : '', $e->end_time ? substr($e->end_time, 0, 5) : '', (float) $e->hours, (string) $e->note])->all(),
            'total' => ['Total', '', '', '', round((float) $entries->sum('hours'), 2), ''],
        ];
    }

    private function invoices(User $u, array $p = []): array
    {
        $q = ContractorInvoice::with('user:id,name')->where('company_id', $u->company_id)->orderByDesc('id');
        if (! $u->isOwner()) {
            $q->where('user_id', $u->id);
        }
        $f = validator($p, ['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:20', 'user_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'])->validate();
        $q->when(! empty($f['status']), fn ($x) => $x->where('status', $f['status']))
            ->when($u->isOwner() && ! empty($f['user_id']), fn ($x) => $x->where('user_id', $f['user_id']))
            ->when(! empty($f['from']), fn ($x) => $x->where('issued_on', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($x) => $x->where('issued_on', '<=', $f['to']));
        $list = $q->limit(2000)->get()->filter(fn ($i) => $this->matches($f['q'] ?? null, [$i->number, $i->user->name]))->values();
        return [
            'title' => 'Facturas de consultores',
            'subtitle' => ($u->isOwner() ? 'Todas las facturas de la compañía' : $u->name).$this->describe([
                $this->txt($f), ! empty($f['status']) ? 'estado '.$f['status'] : null,
                $u->isOwner() && ! empty($f['user_id']) ? 'consultor '.(User::find($f['user_id'])?->name ?? '#'.$f['user_id']) : null,
                ! empty($f['from']) ? 'emitidas desde '.$f['from'] : null, ! empty($f['to']) ? 'hasta '.$f['to'] : null]),
            'columns' => [['h' => 'Número', 't' => 'text'], ['h' => 'Consultor', 't' => 'text'], ['h' => 'Desde', 't' => 'date'], ['h' => 'Hasta', 't' => 'date'], ['h' => 'Bruto', 't' => 'money'],
                ['h' => 'Retención', 't' => 'money'], ['h' => 'IVU', 't' => 'money'], ['h' => 'Neto', 't' => 'money'], ['h' => 'Estado', 't' => 'text'], ['h' => 'Accountisi', 't' => 'text']],
            'rows' => $list->map(fn ($i) => [$i->number, $i->user->name, $i->period_from->toDateString(), $i->period_to->toDateString(), (float) $i->gross, (float) $i->retention,
                (float) $i->ivu, (float) $i->net, $i->status, (string) $i->accountisi_status])->all(),
            'total' => ['Total', '', '', '', round((float) $list->sum('gross'), 2), round((float) $list->sum('retention'), 2), round((float) $list->sum('ivu'), 2), round((float) $list->sum('net'), 2), '', ''],
        ];
    }

    private function pending(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'user_id' => 'nullable|integer', 'late' => 'nullable'])->validate();
        $late = $this->flag($f, 'late');
        $list = Timesheet::with('user:id,name', 'approver:id,name')->where('company_id', $u->company_id)->where('status', Timesheet::SUBMITTED)
            ->when(! empty($f['user_id']), fn ($x) => $x->where('user_id', $f['user_id']))
            ->when($late === true, fn ($x) => $x->where('late', true))
            ->orderBy('submitted_at')->get()
            ->filter(fn ($s) => $this->matches($f['q'] ?? null, [$s->user->name]))->values();
        return [
            'title' => 'Hojas pendientes de aprobar',
            'subtitle' => $list->count().' hoja(s) esperando decisión'.$this->describe([$this->txt($f),
                ! empty($f['user_id']) ? 'persona '.(User::find($f['user_id'])?->name ?? '#'.$f['user_id']) : null, $late === true ? 'solo enviadas tarde' : null]),
            'columns' => [['h' => 'Persona', 't' => 'text'], ['h' => 'Semana que termina', 't' => 'date'], ['h' => 'Horas', 't' => 'hours'], ['h' => 'Aprobador', 't' => 'text'], ['h' => 'Enviada', 't' => 'date'], ['h' => 'Tarde', 't' => 'bool']],
            'rows' => $list->map(fn ($s) => [$s->user->name, $s->week_ending->toDateString(), (float) $s->total_hours, $s->approver?->name ?? '', $s->submitted_at?->toDateString() ?? '', (bool) $s->late])->all(),
            'total' => ['Total', '', round((float) $list->sum('total_hours'), 2), '', '', ''],
        ];
    }

    private function projects(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'active' => 'nullable', 'billable' => 'nullable', 'customer_id' => 'nullable|integer'])->validate();
        $active = $this->flag($f, 'active');
        $billable = $this->flag($f, 'billable');
        $list = Project::with('customer:id,name', 'approver:id,name')->where('company_id', $u->company_id)
            ->when($active !== null, fn ($x) => $x->where('active', $active))
            ->when($billable !== null, fn ($x) => $x->where('billable', $billable))
            ->when(! empty($f['customer_id']), fn ($x) => $x->where('customer_id', $f['customer_id']))
            ->orderBy('code')->get()
            ->filter(fn ($x) => $this->matches($f['q'] ?? null, [$x->code, $x->name, $x->customer?->name]))->values();
        $detail = ['hours_total' => 'Total', 'by_day' => 'Por día', 'with_times' => 'Con desde/hasta'];
        return [
            'title' => 'Proyectos', 'subtitle' => $list->count().' proyecto(s)'.$this->describe([$this->txt($f),
                $active === null ? null : ($active ? 'solo activos' : 'solo inactivos'), $billable === null ? null : ($billable ? 'solo facturables' : 'solo no facturables'),
                ! empty($f['customer_id']) ? 'cliente '.(Customer::find($f['customer_id'])?->name ?? '#'.$f['customer_id']) : null]),
            'columns' => [['h' => 'Código', 't' => 'text'], ['h' => 'Nombre', 't' => 'text'], ['h' => 'Cliente', 't' => 'text'], ['h' => 'Facturable', 't' => 'bool'], ['h' => 'Activo', 't' => 'bool'],
                ['h' => 'Detalle de factura', 't' => 'text'], ['h' => 'Tarifa', 't' => 'money'], ['h' => 'Horas presupuestadas', 't' => 'hours'], ['h' => 'Aprobador', 't' => 'text']],
            'rows' => $list->map(fn ($p) => [$p->code, $p->name, $p->customer?->name ?? '', (bool) $p->billable, (bool) $p->active, $detail[$p->invoice_detail] ?? '',
                $p->bill_rate !== null ? (float) $p->bill_rate : null, $p->budget_hours !== null ? (float) $p->budget_hours : null, $p->approver?->name ?? ''])->all(),
            'total' => null,
        ];
    }

    private function people(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'active' => 'nullable', 'worker_type' => 'nullable|in:consultant,employee', 'capture_mode' => 'nullable|in:full_day,from_to'])->validate();
        $active = $this->flag($f, 'active');
        $list = User::with('approver:id,name')->where('company_id', $u->company_id)
            ->when($active !== null, fn ($x) => $x->where('active', $active))
            ->when(! empty($f['worker_type']), fn ($x) => $x->where('worker_type', $f['worker_type']))
            ->when(! empty($f['capture_mode']), fn ($x) => $x->where('capture_mode', $f['capture_mode']))
            ->orderBy('name')->get()
            ->filter(fn ($x) => $this->matches($f['q'] ?? null, [$x->name, $x->email]))->values();
        return [
            'title' => 'Personas', 'subtitle' => $list->count().' persona(s)'.$this->describe([$this->txt($f),
                $active === null ? null : ($active ? 'solo activas' : 'solo inactivas'),
                ! empty($f['worker_type']) ? ($f['worker_type'] === 'employee' ? 'empleados' : 'consultores') : null,
                ! empty($f['capture_mode']) ? ($f['capture_mode'] === 'full_day' ? 'día completo' : 'desde / hasta') : null]),
            'columns' => [['h' => 'Nombre', 't' => 'text'], ['h' => 'Correo', 't' => 'text'], ['h' => 'Rol', 't' => 'text'], ['h' => 'Tipo', 't' => 'text'], ['h' => 'Modo de captura', 't' => 'text'],
                ['h' => 'Horas por semana', 't' => 'hours'], ['h' => 'Aprobador', 't' => 'text'], ['h' => 'Activo', 't' => 'bool']],
            'rows' => $list->map(fn ($x) => [$x->name, $x->email, $x->role === 'owner' ? 'Dueño' : 'Miembro', $x->worker_type === 'employee' ? 'Empleado' : 'Consultor',
                $x->capture_mode === 'full_day' ? 'Día completo' : 'Desde / hasta', $x->weekly_hours !== null ? (float) $x->weekly_hours : null, $x->approver?->name ?? '', (bool) $x->active])->all(),
            'total' => null,
        ];
    }

    private function approvers(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'active' => 'nullable'])->validate();
        $active = $this->flag($f, 'active');
        $list = Approver::where('company_id', $u->company_id)->when($active !== null, fn ($x) => $x->where('active', $active))->orderBy('name')->get()
            ->filter(fn ($a) => $this->matches($f['q'] ?? null, [$a->name, $a->email]))->values();
        return [
            'title' => 'Aprobadores', 'subtitle' => $list->count().' aprobador(es)'.$this->describe([$this->txt($f), $active === null ? null : ($active ? 'solo activos' : 'solo inactivos')]),
            'columns' => [['h' => 'Nombre', 't' => 'text'], ['h' => 'Correo', 't' => 'text'], ['h' => 'Pide código extra', 't' => 'bool'], ['h' => 'Activo', 't' => 'bool']],
            'rows' => $list->map(fn ($a) => [$a->name, $a->email, (bool) $a->require_code, (bool) $a->active])->all(),
            'total' => null,
        ];
    }

    private function customers(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'active' => 'nullable', 'linked' => 'nullable'])->validate();
        $active = $this->flag($f, 'active');
        $linked = $this->flag($f, 'linked');
        $list = Customer::where('company_id', $u->company_id)->when($active !== null, fn ($x) => $x->where('active', $active))
            ->when($linked === true, fn ($x) => $x->whereNotNull('accountisi_id'))->when($linked === false, fn ($x) => $x->whereNull('accountisi_id'))
            ->orderBy('name')->get()
            ->filter(fn ($c) => $this->matches($f['q'] ?? null, [$c->name, $c->email]))->values();
        return [
            'title' => 'Clientes', 'subtitle' => $list->count().' cliente(s)'.$this->describe([$this->txt($f),
                $active === null ? null : ($active ? 'solo activos' : 'solo inactivos'), $linked === null ? null : ($linked ? 'vinculados a Accountisi' : 'sin vincular')]),
            'columns' => [['h' => 'Nombre', 't' => 'text'], ['h' => 'Correo', 't' => 'text'], ['h' => 'Id en Accountisi', 't' => 'text'], ['h' => 'Activo', 't' => 'bool']],
            'rows' => $list->map(fn ($c) => [$c->name, (string) $c->email, (string) $c->accountisi_id, (bool) $c->active])->all(),
            'total' => null,
        ];
    }
}
