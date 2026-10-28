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
        'report' => 'any', 'week' => 'any', 'invoices' => 'any', 'sheets' => 'any',
        'pending' => 'owner', 'commissions' => 'owner', 'projects' => 'owner', 'people' => 'owner', 'approvers' => 'owner', 'customers' => 'owner',
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
            'sheets' => $this->sheets($user, $p),
            'invoices' => $this->invoices($user, $p),
            'pending' => $this->pending($user, $p),
            'commissions' => $this->commissions($user, $p),
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
        return ! empty($f['q']) ? __('texto ":q"', ['q' => $f['q']]) : null;
    }

    /** Texto que describe los filtros activos, para dejarlo escrito en el archivo. */
    private function describe(array $parts): string
    {
        $parts = array_filter($parts);
        return $parts ? ' · '.__('Filtro: :filters', ['filters' => implode(', ', $parts)]) : '';
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
            'week' => Week::ending($e->work_date, $u->company->rules()->weekStart()),
            'customer' => $e->project->customer?->name ?? __('(sin cliente)'),
            default => $e->project->code.' · '.$e->project->name,
        })->sortKeys()->map(fn ($g, $k) => [
            $k, round((float) $g->sum('hours'), 2), round((float) $g->where('billable', true)->sum('hours'), 2), round((float) $g->where('billable', false)->sum('hours'), 2),
        ])->values()->all();
        $label = ['project' => __('Proyecto'), 'day' => __('Día'), 'week' => __('Semana que termina'), 'customer' => __('Cliente')][$group];
        return [
            'title' => __('Reporte de horas'),
            'subtitle' => __(':name · :from a :to · por :group', ['name' => $u->name, 'from' => $v['from'], 'to' => $v['to'], 'group' => mb_strtolower($label)])
                .$this->describe([$projCode ? __('proyecto :code', ['code' => $projCode]) : null]),
            'columns' => [['h' => $label, 't' => $group === 'day' || $group === 'week' ? 'date' : 'text'], ['h' => __('Horas'), 't' => 'hours'], ['h' => __('Facturables'), 't' => 'hours'], ['h' => __('No facturables'), 't' => 'hours']],
            'rows' => $rows,
            'total' => [__('Total'), round((float) $entries->sum('hours'), 2), round((float) $entries->where('billable', true)->sum('hours'), 2), round((float) $entries->where('billable', false)->sum('hours'), 2)],
        ];
    }

    /**
     * Tu hoja de la semana tal como se ve en pantalla: una fila por línea (proyecto y servicio), una columna por día, el total y,
     * al final de cada línea, sus notas (con el día de cada una). Incluye todas las partes de la semana.
     */
    private function week(User $u, array $p): array
    {
        $date = validator($p, ['date' => 'required|date_format:Y-m-d'])->validate()['date'];
        $end = Week::ending($date, $u->company->rules()->weekStart());
        $start = Week::starting($end);
        $sheets = Timesheet::where('user_id', $u->id)->where('week_ending', $end)->orderBy('part')->get();
        $entries = TimeEntry::with('project:id,code,name', 'service:id,name', 'task:id,name')->where('user_id', $u->id)->where('running', false)
            ->whereBetween('work_date', [$start, $end])->orderBy('work_date')->orderBy('start_time')->get();
        $labels = ['draft' => __('Borrador'), 'submitted' => __('Enviada'), 'approved' => __('Aprobada'), 'returned' => __('Devuelta')];
        $status = $sheets->isEmpty() ? $labels['draft'] : $sheets->pluck('status')->unique()->map(fn ($x) => $labels[$x] ?? $x)->implode(' / ');
        $days = [];
        for ($d = \Carbon\CarbonImmutable::parse($start); $d->toDateString() <= $end; $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }
        $short = fn (string $d) => mb_convert_case(rtrim(\Carbon\CarbonImmutable::parse($d)->locale(app()->getLocale())->isoFormat('ddd'), '.'), MB_CASE_TITLE).' '.\Carbon\CarbonImmutable::parse($d)->format('j/n');
        $lines = $entries->groupBy(fn ($e) => $e->project_id.'|'.($e->service_id ?? 0));
        $rows = [];
        $dayTotals = array_fill(0, 7, 0.0);
        foreach ($lines as $group) {
            $first = $group->first();
            $cells = [];
            $notes = [];
            foreach ($days as $i => $d) {
                $of = $group->filter(fn ($e) => $e->work_date->toDateString() === $d);
                $h = round((float) $of->sum('hours'), 2);
                $cells[] = $h > 0 ? $h : '';
                $dayTotals[$i] += $h;
                foreach ($of->filter(fn ($e) => trim((string) $e->note) !== '') as $e) {
                    $notes[] = $short($d).': '.trim($e->note);
                }
            }
            $rows[] = array_merge([$first->project->code.' · '.$first->project->name, (string) ($first->service?->name ?? '')], $cells,
                [round((float) $group->sum('hours'), 2), implode("\n", $notes)]);
        }
        $columns = array_merge([['h' => __('Proyecto'), 't' => 'text'], ['h' => __('Servicio'), 't' => 'text']],
            array_map(fn ($d) => ['h' => $short($d), 't' => 'hours'], $days), [['h' => __('Total'), 't' => 'hours'], ['h' => __('Notas'), 't' => 'text']]);
        return [
            'title' => __('Hoja de la semana'),
            'subtitle' => __(':name · semana :start a :end · :status', ['name' => $u->name, 'start' => $start, 'end' => $end, 'status' => $status]),
            'columns' => $columns,
            'rows' => $rows,
            'total' => array_merge([__('Total del día'), ''], array_map(fn ($h) => round($h, 2), $dayTotals), [round(array_sum($dayTotals), 2), '']),
        ];
    }

    /**
     * Las hojas del periodo, una fila por día y línea (con su parte, proyecto, servicio y nota). El dueño ve las de toda la compañía,
     * un aprobador con cuenta solo las líneas de sus servicios y cada persona las suyas.
     */
    private function sheets(User $u, array $p = []): array
    {
        $f = validator($p, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'q' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'status' => 'nullable|in:draft,submitted,approved,returned'])->validate();
        $external = app(\App\Services\ExternalApprovalService::class);
        $approver = $u->isApprover() ? \App\Models\Approver::find($u->approver_profile_id) : null;
        $q = Timesheet::with('user:id,name')->where('company_id', $u->company_id)
            ->when($u->isApprover(), fn ($x) => $x->whereIn('id', $approver ? $external->sheetIdsFor($approver) : []))
            ->when(! $u->isApprover() && ! $u->isOwner(), fn ($x) => $x->where('user_id', $u->id))
            ->when($u->isOwner() && ! empty($f['user_id']), fn ($x) => $x->where('user_id', $f['user_id']))
            ->when(! empty($f['status']), fn ($x) => $x->where('status', $f['status']))
            ->where('week_ending', '>=', $f['from'])->where('week_ending', '<=', \Carbon\CarbonImmutable::parse($f['to'])->addDays(6)->toDateString())
            ->orderBy('week_ending')->orderBy('user_id')->orderBy('part')->orderBy('id');
        $status = ['draft' => __('Borrador'), 'submitted' => __('Enviada'), 'approved' => __('Aprobada'), 'returned' => __('Devuelta')];
        $rows = [];
        foreach ($q->limit(3000)->get() as $s) {
            if ($approver) {
                $external->restrictTo($s, $approver);
                $entries = $s->entries;
            } else {
                $entries = $s->entries()->with('project:id,code,name', 'service:id,name', 'task:id,name')->get();
            }
            foreach ($entries->sortBy(fn ($e) => $e->work_date->toDateString().'|'.$e->start_time) as $e) {
                $day = $e->work_date->toDateString();
                if ($day < $f['from'] || $day > $f['to'] || (! empty($f['project_id']) && (int) $e->project_id !== (int) $f['project_id'])) {
                    continue;
                }
                $row = [$s->user->name, $s->week_ending->toDateString(), $s->part ? (string) $s->part : '', $status[$s->status] ?? $s->status,
                    $e->project->code.' · '.$e->project->name, (string) ($e->service?->name ?? ''), $day,
                    $e->start_time ? substr($e->start_time, 0, 5) : '', $e->end_time ? substr($e->end_time, 0, 5) : '', (float) $e->hours, (string) $e->note];
                if ($this->matches($f['q'] ?? null, [$row[0], $row[4], $row[5], $row[10]])) {
                    $rows[] = $row;
                }
            }
        }
        return [
            'title' => __('Hojas de tiempo'),
            'subtitle' => __(':count línea(s) · del :from al :to', ['count' => count($rows), 'from' => $f['from'], 'to' => $f['to']]).$this->describe([$this->txt($f),
                ! empty($f['status']) ? __('estado :status', ['status' => $status[$f['status']]]) : null,
                $u->isOwner() && ! empty($f['user_id']) ? __('persona :name', ['name' => User::find($f['user_id'])?->name ?? '#'.$f['user_id']]) : null,
                ! empty($f['project_id']) ? __('proyecto :name', ['name' => Project::find($f['project_id'])?->code ?? '#'.$f['project_id']]) : null]),
            'columns' => [['h' => __('Persona'), 't' => 'text'], ['h' => __('Semana que termina'), 't' => 'date'], ['h' => __('Parte'), 't' => 'text'], ['h' => __('Estado'), 't' => 'text'],
                ['h' => __('Proyecto'), 't' => 'text'], ['h' => __('Servicio'), 't' => 'text'], ['h' => __('Fecha'), 't' => 'date'], ['h' => __('Desde'), 't' => 'text'],
                ['h' => __('Hasta'), 't' => 'text'], ['h' => __('Horas'), 't' => 'hours'], ['h' => __('Nota'), 't' => 'text']],
            'rows' => $rows,
            'total' => [__('Total'), '', '', '', '', '', '', '', '', round(array_sum(array_column($rows, 9)), 2), ''],
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
            'title' => __('Facturas de consultores'),
            'subtitle' => ($u->isOwner() ? __('Todas las facturas de la compañía') : $u->name).$this->describe([
                $this->txt($f), ! empty($f['status']) ? __('estado :status', ['status' => $f['status']]) : null,
                $u->isOwner() && ! empty($f['user_id']) ? __('consultor :name', ['name' => User::find($f['user_id'])?->name ?? '#'.$f['user_id']]) : null,
                ! empty($f['from']) ? __('emitidas desde :date', ['date' => $f['from']]) : null, ! empty($f['to']) ? __('hasta :date', ['date' => $f['to']]) : null]),
            'columns' => [['h' => __('Número'), 't' => 'text'], ['h' => __('Consultor'), 't' => 'text'], ['h' => __('Desde'), 't' => 'date'], ['h' => __('Hasta'), 't' => 'date'], ['h' => __('Bruto'), 't' => 'money'],
                ['h' => __('Retención'), 't' => 'money'], ['h' => __('IVU'), 't' => 'money'], ['h' => __('Neto'), 't' => 'money'], ['h' => __('Estado'), 't' => 'text'], ['h' => 'Accountisi', 't' => 'text']],
            'rows' => $list->map(fn ($i) => [$i->number, $i->user->name, $i->period_from->toDateString(), $i->period_to->toDateString(), (float) $i->gross, (float) $i->retention,
                (float) $i->ivu, (float) $i->net, $i->status, (string) $i->accountisi_status])->all(),
            'total' => [__('Total'), '', '', '', round((float) $list->sum('gross'), 2), round((float) $list->sum('retention'), 2), round((float) $list->sum('ivu'), 2), round((float) $list->sum('net'), 2), '', ''],
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
            'title' => __('Hojas pendientes de aprobar'),
            'subtitle' => __(':count hoja(s) esperando decisión', ['count' => $list->count()]).$this->describe([$this->txt($f),
                ! empty($f['user_id']) ? __('persona :name', ['name' => User::find($f['user_id'])?->name ?? '#'.$f['user_id']]) : null, $late === true ? __('solo enviadas tarde') : null]),
            'columns' => [['h' => __('Persona'), 't' => 'text'], ['h' => __('Semana que termina'), 't' => 'date'], ['h' => __('Horas'), 't' => 'hours'], ['h' => __('Aprobador'), 't' => 'text'], ['h' => __('Enviada'), 't' => 'date'], ['h' => __('Tarde'), 't' => 'bool']],
            'rows' => $list->map(fn ($s) => [$s->user->name, $s->week_ending->toDateString(), (float) $s->total_hours, $s->approver?->name ?? '', $s->submitted_at?->toDateString() ?? '', (bool) $s->late])->all(),
            'total' => [__('Total'), '', round((float) $list->sum('total_hours'), 2), '', '', ''],
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
        return [
            'title' => __('Proyectos'), 'subtitle' => __(':count proyecto(s)', ['count' => $list->count()]).$this->describe([$this->txt($f),
                $active === null ? null : ($active ? __('solo activos') : __('solo inactivos')), $billable === null ? null : ($billable ? __('solo facturables') : __('solo no facturables')),
                ! empty($f['customer_id']) ? __('cliente :name', ['name' => Customer::find($f['customer_id'])?->name ?? '#'.$f['customer_id']]) : null]),
            'columns' => [['h' => __('Código'), 't' => 'text'], ['h' => __('Nombre'), 't' => 'text'], ['h' => __('Cliente'), 't' => 'text'], ['h' => __('Facturable'), 't' => 'bool'], ['h' => __('Activo'), 't' => 'bool'],
                ['h' => __('Tarifa'), 't' => 'money'], ['h' => __('Horas presupuestadas'), 't' => 'hours'], ['h' => __('Aprobador'), 't' => 'text']],
            'rows' => $list->map(fn ($p) => [$p->code, $p->name, $p->customer?->name ?? '', (bool) $p->billable, (bool) $p->active,
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
            'title' => __('Personas'), 'subtitle' => __(':count persona(s)', ['count' => $list->count()]).$this->describe([$this->txt($f),
                $active === null ? null : ($active ? __('solo activas') : __('solo inactivas')),
                ! empty($f['worker_type']) ? ($f['worker_type'] === 'employee' ? __('empleados') : __('consultores')) : null,
                ! empty($f['capture_mode']) ? ($f['capture_mode'] === 'full_day' ? __('día completo') : __('desde / hasta')) : null]),
            'columns' => [['h' => __('Nombre'), 't' => 'text'], ['h' => __('Correo'), 't' => 'text'], ['h' => __('Rol'), 't' => 'text'], ['h' => __('Tipo'), 't' => 'text'], ['h' => __('Modo de captura'), 't' => 'text'],
                ['h' => __('Horas por semana'), 't' => 'hours'], ['h' => __('Aprobador'), 't' => 'text'], ['h' => __('Activo'), 't' => 'bool']],
            'rows' => $list->map(fn ($x) => [$x->name, $x->email, $x->role === 'owner' ? __('Dueño') : __('Miembro'), $x->worker_type === 'employee' ? __('Empleado') : __('Consultor'),
                $x->capture_mode === 'full_day' ? __('Día completo') : __('Desde / hasta'), $x->weekly_hours !== null ? (float) $x->weekly_hours : null, $x->approver?->name ?? '', (bool) $x->active])->all(),
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
            'title' => __('Aprobadores'), 'subtitle' => __(':count aprobador(es)', ['count' => $list->count()]).$this->describe([$this->txt($f), $active === null ? null : ($active ? __('solo activos') : __('solo inactivos'))]),
            'columns' => [['h' => __('Nombre'), 't' => 'text'], ['h' => __('Correo'), 't' => 'text'], ['h' => __('Pide código extra'), 't' => 'bool'], ['h' => __('Activo'), 't' => 'bool']],
            'rows' => $list->map(fn ($a) => [$a->name, $a->email, (bool) $a->require_code, (bool) $a->active])->all(),
            'total' => null,
        ];
    }

    /** Comisiones calculadas al completar tareas (no incluye las que no dieron nada, salvo con status=none). */
    private function commissions(User $u, array $p = []): array
    {
        $f = validator($p, ['q' => 'nullable|string|max:100', 'status' => 'nullable|in:open,pending,released,invoiced,paid,none,all', 'user_id' => 'nullable|integer',
            'project_id' => 'nullable|integer'])->validate();
        $status = $f['status'] ?? null;
        $labels = ['pending' => __('Por liberar'), 'released' => __('Liberada'), 'invoiced' => __('Facturada'), 'paid' => __('Pagada'), 'none' => __('Sin comisión')];
        $list = \App\Models\CommissionResult::with('user:id,name', 'project:id,code,name', 'task:id,name')->where('company_id', $u->company_id)
            ->when($status === 'open', fn ($x) => $x->whereIn('status', ['pending', 'released']))
            ->when($status && ! in_array($status, ['open', 'all'], true), fn ($x) => $x->where('status', $status))
            ->when(! $status, fn ($x) => $x->where('status', '!=', 'none'))
            ->when(! empty($f['user_id']), fn ($x) => $x->where('user_id', $f['user_id']))
            ->when(! empty($f['project_id']), fn ($x) => $x->where('project_id', $f['project_id']))
            ->orderByDesc('completed_on')->orderBy('id')->get()
            ->filter(fn ($r) => $this->matches($f['q'] ?? null, [$r->user?->name, $r->project?->code, $r->project?->name, $r->task?->name]))->values();
        return [
            'title' => __('Comisiones'), 'subtitle' => __(':count comisión(es)', ['count' => $list->count()]).$this->describe([$this->txt($f),
                $status && $status !== 'all' ? ($status === 'open' ? __('por liberar y liberadas') : ($labels[$status] ?? $status)) : null]),
            'columns' => [['h' => __('Persona'), 't' => 'text'], ['h' => __('Proyecto'), 't' => 'text'], ['h' => __('Tarea'), 't' => 'text'], ['h' => __('Completada'), 't' => 'date'],
                ['h' => __('Horas de la persona'), 't' => 'hours'], ['h' => __('% del total'), 't' => 'text'], ['h' => __('Comisión'), 't' => 'money'],
                ['h' => __('Ahorro'), 't' => 'money'], ['h' => __('Ajuste'), 't' => 'money'], ['h' => __('Total'), 't' => 'money'], ['h' => __('Estado'), 't' => 'text']],
            'rows' => $list->map(fn ($r) => [$r->user?->name, $r->project?->code.' · '.$r->project?->name, $r->task?->name, $r->completed_on->toDateString(), (float) $r->user_hours,
                rtrim(rtrim(number_format((float) $r->user_pct, 2, '.', ''), '0'), '.').'%', (float) $r->commission_amount, (float) $r->savings_amount, (float) $r->adjustment,
                (float) $r->total, $labels[$r->status] ?? $r->status])->all(),
            'total' => ['', '', '', '', '', '', round((float) $list->sum('commission_amount'), 2), round((float) $list->sum('savings_amount'), 2),
                round((float) $list->sum('adjustment'), 2), round((float) $list->sum('total'), 2), __('Total')],
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
            'title' => __('Clientes'), 'subtitle' => __(':count cliente(s)', ['count' => $list->count()]).$this->describe([$this->txt($f),
                $active === null ? null : ($active ? __('solo activos') : __('solo inactivos')), $linked === null ? null : ($linked ? __('vinculados a Accountisi') : __('sin vincular'))]),
            'columns' => [['h' => __('Nombre'), 't' => 'text'], ['h' => __('Correo'), 't' => 'text'], ['h' => __('Id en Accountisi'), 't' => 'text'], ['h' => __('Activo'), 't' => 'bool']],
            'rows' => $list->map(fn ($c) => [$c->name, (string) $c->email, (string) $c->accountisi_id, (bool) $c->active])->all(),
            'total' => null,
        ];
    }
}
