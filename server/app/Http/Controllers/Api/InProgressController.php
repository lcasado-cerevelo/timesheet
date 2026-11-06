<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Support\Text;
use Illuminate\Http\Request;

/**
 * Horas que la gente va trabajando y todavía no ha enviado (los borradores de sus semanas), para el dueño y la oficina. Solo lectura:
 * esas horas pueden cambiar o borrarse hasta que la persona las envíe, así que aquí no se aprueba nada.
 */
class InProgressController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => 'nullable|string|max:100',
            'type' => 'nullable|in:employee,consultant',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            // Un cliente, o 'none' para los proyectos internos (sin cliente).
            'customer_id' => ['nullable', 'regex:/^(\d+|none)$/'],
        ]);
        $customer = $f['customer_id'] ?? null;

        $sheets = Timesheet::with('user:id,name,worker_type', 'entries.project:id,code,name,customer_id', 'entries.service:id,name')
            ->where('company_id', $request->user()->company_id)->where('status', Timesheet::DRAFT)
            ->when(! empty($f['type']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('worker_type', $f['type'])))
            ->when(! empty($f['from']), fn ($q) => $q->where('week_ending', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->where('week_ending', '<=', $f['to']))
            ->orderByDesc('week_ending')->get()
            ->filter(fn (Timesheet $s) => Text::matches($f['q'] ?? null, [$s->user->name]));

        $rows = $sheets->map(function (Timesheet $s) use ($customer) {
            $entries = $s->entries->filter(fn ($e) => $customer === null || ($customer === 'none' ? $e->project->customer_id === null : (int) $e->project->customer_id === (int) $customer));
            if ($entries->isEmpty()) {
                return null;
            }
            return [
                'id' => $s->id,
                'user' => ['id' => $s->user->id, 'name' => $s->user->name, 'worker_type' => $s->user->worker_type],
                'week_ending' => $s->week_ending->toDateString(),
                'total_hours' => round((float) $entries->sum('hours'), 2),
                'running' => $entries->contains(fn ($e) => (bool) $e->running),
                'lines' => $entries->map(fn ($e) => [
                    'date' => $e->work_date->toDateString(), 'project' => $e->project->code.' · '.$e->project->name, 'service' => $e->service?->name,
                    'hours' => (float) $e->hours, 'running' => (bool) $e->running,
                ])->values()->all(),
            ];
        })->filter()->values();

        return response()->json([
            'sheets' => $rows->all(),
            'people' => $rows->pluck('user.id')->unique()->count(),
            'hours' => round((float) $rows->sum('total_hours'), 2),
        ]);
    }
}
