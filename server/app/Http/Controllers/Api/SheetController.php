<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\InternalApprovalService;
use App\Services\ReviewService;
use App\Services\SheetService;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SheetController extends Controller
{
    public function __construct(private SheetService $sheets, private ReviewService $review, private InternalApprovalService $internal)
    {
    }

    /** Hojas de la persona (recientes primero). */
    public function index(Request $request)
    {
        $rows = Timesheet::with('decidedByApprover:id,name', 'decidedByUser:id,name', 'invoice:id,number')->where('user_id', $request->user()->id)->orderByDesc('week_ending')->limit(60)->get();
        return response()->json($rows->map(fn ($s) => $this->payload($s)));
    }

    /** Hoja de la semana que contiene ?date= (la crea en borrador si no existe). */
    public function week(Request $request)
    {
        $date = $request->validate(['date' => 'required|date_format:Y-m-d'])['date'];
        $sheet = $this->sheets->forWeek($request->user(), $date);
        return response()->json($this->weekPayload($request->user(), $sheet));
    }

    /**
     * La semana completa: la hoja de trabajo (la de los días abiertos), todas las partes de la semana, las líneas de todas ellas
     * y el estado de cada día. La pantalla de la semana se dibuja con esto.
     */
    private function weekPayload(\App\Models\User $user, Timesheet $working): array
    {
        $end = $working->week_ending->toDateString();
        $sheets = $this->sheets->weekSheets($user, $end);
        $entries = TimeEntry::with('project:id,code,name', 'service:id,name')->whereIn('timesheet_id', $sheets->pluck('id'))
            ->orderBy('work_date')->orderBy('start_time')->orderBy('id')->get();
        $out = $this->payload($working, true);
        $out['entries'] = $entries->map(fn ($e) => EntryController::payload($e));
        $out['parts'] = $sheets->map(fn ($s) => [
            'id' => $s->id, 'part' => $s->part, 'status' => $s->status, 'stage' => $s->stage, 'version' => $s->version, 'locked' => $s->isLocked(),
            'period_starting' => $s->periodStarting(), 'period_ending' => $s->periodEnding(), 'is_part' => $s->period_start !== null,
            'total_hours' => (float) $s->total_hours, 'submitted_at' => $s->submitted_at, 'return_comment' => $s->return_comment,
        ])->values();
        $days = [];
        for ($d = CarbonImmutable::parse(Week::starting($end)); $d->toDateString() <= $end; $d = $d->addDay()) {
            $day = $d->toDateString();
            $cover = $sheets->first(fn ($s) => $s->covers($day));
            $days[] = ['date' => $day, 'sheet_id' => ($cover ?? $working)->id, 'part' => ($cover ?? $working)->part,
                'status' => $cover?->status ?? Timesheet::DRAFT, 'locked' => (bool) $cover?->isLocked(), 'open' => $cover === null];
        }
        $out['days'] = $days;
        $out['week_total_hours'] = round((float) $entries->sum('hours'), 2);
        $out['partial_submission'] = (bool) $user->company->rules()->get('partial_submission');
        return $out;
    }

    public function show(Request $request, Timesheet $sheet)
    {
        $this->authorizeView($request, $sheet);
        return response()->json($this->payload($sheet, true));
    }

    public function submit(Request $request, Timesheet $sheet)
    {
        $this->authorizeOwn($request, $sheet);
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;
        $user = $request->user();
        // Una hoja en borrador de una semana que ya tiene partes envía los días abiertos hasta hoy (o hasta el domingo), en bloques.
        if ($sheet->status === Timesheet::DRAFT && $this->sheets->weekSheets($user, $sheet->week_ending->toDateString())->count() > 1) {
            $today = CarbonImmutable::now($user->company->timezone)->toDateString();
            $this->sheets->submitDays($user, min($today, $sheet->week_ending->toDateString()), $note, 'app', false);
            return response()->json($this->weekPayload($user, $this->sheets->forWeek($user, $sheet->week_ending->toDateString())));
        }
        $sheet = $this->sheets->submit($sheet, $user, 'app', $note);
        return response()->json($this->payload($sheet, true));
    }

    /** Envía los días abiertos de la semana hasta ese día (días corridos); cada bloque seguido es una hoja. */
    public function submitDays(Request $request)
    {
        $v = $request->validate(['through' => 'required|date_format:Y-m-d', 'note' => 'nullable|string|max:1000']);
        $user = $request->user();
        $this->sheets->submitDays($user, $v['through'], $v['note'] ?? null);
        return response()->json($this->weekPayload($user, $this->sheets->forWeek($user, $v['through'])));
    }

    /**
     * Lista de revisión del dueño, con filtros (status, q, type, from, to) y conteos.
     * Pendientes muestra todo lo que espera sin importar fechas; el periodo aplica a las demás.
     */
    public function review(Request $request)
    {
        $scope = Timesheet::query()->where('company_id', $request->user()->company_id);
        return response()->json($this->review->run($request, $scope, fn ($s) => $this->payload($s) + $this->review->extras($s)
            + ['approver' => $s->approver ? ['id' => $s->approver->id, 'name' => $s->approver->name] : null]));
    }

    /** Hojas pendientes de aprobar (dueño de la compañía). */
    public function pending(Request $request)
    {
        $rows = Timesheet::with('user:id,name', 'approver:id,name')
            ->where('company_id', $request->user()->company_id)->where('status', Timesheet::SUBMITTED)
            ->orderBy('submitted_at')->get();
        return response()->json($rows->map(fn ($s) => $this->payload($s)));
    }

    public function approve(Request $request, Timesheet $sheet)
    {
        abort_unless($sheet->company_id === $request->user()->company_id, 404);
        $v = $request->validate(['version' => 'nullable|integer']);
        $sheet = $this->sheets->approve($sheet, null, $request->user(), 'app', $v['version'] ?? null);
        return response()->json($this->payload($sheet));
    }

    public function return(Request $request, Timesheet $sheet)
    {
        abort_unless($sheet->company_id === $request->user()->company_id, 404);
        $v = $request->validate(['comment' => 'required|string|max:1000', 'version' => 'nullable|integer']);
        $sheet = $this->sheets->return($sheet, null, $request->user(), 'app', $v['comment'], $v['version'] ?? null);
        return response()->json($this->payload($sheet));
    }

    private function authorizeOwn(Request $request, Timesheet $sheet): void
    {
        abort_unless($sheet->user_id === $request->user()->id, 403);
    }

    private function authorizeView(Request $request, Timesheet $sheet): void
    {
        $u = $request->user();
        abort_unless($sheet->user_id === $u->id || ($u->isOwner() && $sheet->company_id === $u->company_id), 403);
    }

    public function payload(Timesheet $s, bool $withEntries = false): array
    {
        $s->loadMissing('user', 'approver');
        $entries = $withEntries ? $s->entries()->with('project:id,code,name', 'service:id,name')->get() : null;
        $out = [
            'id' => $s->id,
            'user_id' => $s->user_id,
            'user' => $s->relationLoaded('user') ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
            'week_starting' => Week::starting($s->week_ending->toDateString()),
            'week_ending' => $s->week_ending->toDateString(),
            'part' => $s->part,
            'is_part' => $s->period_start !== null,
            'period_starting' => $s->periodStarting(),
            'period_ending' => $s->periodEnding(),
            'submission_note' => $s->submission_note,
            'status' => $s->status,
            'locked' => $s->isLocked(),
            'version' => $s->version,
            'late' => $s->late,
            'return_comment' => $s->return_comment,
            'submitted_at' => $s->submitted_at,
            'decided_at' => $s->decided_at,
            'approved_by' => $s->status === Timesheet::APPROVED ? ($s->decidedByApprover?->name ?? $s->decidedByUser?->name) : null,
            'invoice_number' => $s->invoice?->number,
            'return_by' => $s->status === Timesheet::RETURNED ? ($s->decidedByApprover?->name ?? $s->decidedByUser?->name) : null,
            'warnings' => $s->warnings(),
        ] + $s->hoursSplit($entries ?? ($s->relationLoaded('entries') ? $s->entries : null)) + $this->internal->info($s);
        if ($withEntries) {
            $out['entries'] = $entries->map(fn ($e) => EntryController::payload($e));
            $out['worker'] = ['id' => $s->user->id, 'name' => $s->user->name, 'worker_type' => $s->user->worker_type, 'employee_number' => $s->user->employee_number];
            $out['approver'] = $s->approver ? ['id' => $s->approver->id, 'name' => $s->approver->name] : null;
        }
        return $out;
    }
}
