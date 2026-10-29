<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\InternalApprovalService;
use App\Services\ReviewService;
use App\Services\SheetService;
use App\Services\SignatureService;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SheetController extends Controller
{
    public function __construct(private SheetService $sheets, private ReviewService $review, private InternalApprovalService $internal, private \App\Services\ExternalApprovalService $external)
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
        $entries = TimeEntry::with('project:id,code,name', 'service:id,name', 'task:id,name')->whereIn('timesheet_id', $sheets->pluck('id'))
            ->orderBy('work_date')->orderBy('start_time')->orderBy('id')->get();
        $out = $this->payload($working, true);
        $out['entries'] = $entries->map(fn ($e) => EntryController::payload($e));
        $out['parts'] = $sheets->map(fn ($s) => [
            'id' => $s->id, 'part' => $s->part, 'status' => $s->status, 'stage' => $s->stage, 'version' => $s->version, 'locked' => $s->isLocked(),
            'period_starting' => $s->periodStarting(), 'period_ending' => $s->periodEnding(), 'is_part' => $s->period_start !== null,
            'total_hours' => (float) $s->total_hours, 'submitted_at' => $s->submitted_at, 'return_comment' => $s->return_comment,
            'decided_at' => $s->decided_at,
            'return_by' => $s->status === Timesheet::RETURNED ? ($s->decidedByApprover?->name ?? $s->decidedByUser?->name) : null,
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
        // Someter la hoja se firma con la contraseña: se verifica antes de hacer nada y la firma queda cuando la hoja salió bien.
        $sig = app(SignatureService::class);
        $sig->verifyPassword($request, $user);
        // Una hoja en borrador envía los días abiertos hasta hoy (o hasta el fin de la semana), en bloques, cuando la semana ya tiene partes o
        // cuando la compañía permite enviar por partes y la semana todavía no termina: enviar la semana completa bloquearía días que aún no llegan.
        $today = CarbonImmutable::now($user->company->timezone)->toDateString();
        $running = (bool) $user->company->rules()->get('partial_submission') && $sheet->week_ending->toDateString() > $today;
        if (($sheet->status === Timesheet::DRAFT || ($sheet->status === Timesheet::RETURNED && $sheet->period_start === null)) && ($running || $this->sheets->weekSheets($user, $sheet->week_ending->toDateString())->count() > 1)) {
            $sent = $this->sheets->submitDays($user, min($today, $sheet->week_ending->toDateString()), $note, 'app', false);
            $this->signSubmitted($sig, $request, $user, $sent);
            return response()->json($this->weekPayload($user, $this->sheets->forWeek($user, $sheet->week_ending->toDateString())));
        }
        $sheet = $this->sheets->submit($sheet, $user, 'app', $note);
        $this->signSubmitted($sig, $request, $user, collect([$sheet]));
        return response()->json($this->payload($sheet, true));
    }

    /** Envía los días abiertos de la semana hasta ese día (días corridos); cada bloque seguido es una hoja. */
    public function submitDays(Request $request)
    {
        $v = $request->validate(['through' => 'required|date_format:Y-m-d', 'note' => 'nullable|string|max:1000']);
        $user = $request->user();
        $sig = app(SignatureService::class);
        $sig->verifyPassword($request, $user);
        $sent = $this->sheets->submitDays($user, $v['through'], $v['note'] ?? null);
        $this->signSubmitted($sig, $request, $user, $sent);
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
        $sig = app(SignatureService::class);
        $sig->verifyPassword($request, $request->user());
        $stage = $sheet->stage;
        $sheet = $this->sheets->approve($sheet, null, $request->user(), 'app', $v['version'] ?? null);
        $sig->record($request, $request->user(), 'sheet.approve', $sheet, ['version' => (int) $sheet->version, 'hours' => (float) $sheet->total_hours] + ($stage === Timesheet::STAGE_INTERNAL ? ['stage' => 'internal'] : []));
        return response()->json($this->payload($sheet));
    }

    public function return(Request $request, Timesheet $sheet)
    {
        abort_unless($sheet->company_id === $request->user()->company_id, 404);
        $v = $request->validate(['comment' => 'required|string|max:1000', 'version' => 'nullable|integer']);
        $sig = app(SignatureService::class);
        $sig->verifyPassword($request, $request->user());
        $sheet = $this->sheets->return($sheet, null, $request->user(), 'app', $v['comment'], $v['version'] ?? null);
        $sig->record($request, $request->user(), 'sheet.return', $sheet, ['version' => (int) $sheet->version, 'comment' => $v['comment']]);
        return response()->json($this->payload($sheet));
    }

    /** El dueño reabre una hoja aprobada (por error o para corregirla). Firma con su contraseña. */
    public function reopen(Request $request, Timesheet $sheet)
    {
        abort_unless($sheet->company_id === $request->user()->company_id, 404);
        $v = $request->validate(['comment' => 'required|string|min:3|max:1000']);
        $sig = app(SignatureService::class);
        $sig->verifyPassword($request, $request->user());
        $sheet = $this->sheets->reopen($sheet, $request->user(), $v['comment']);
        $sig->record($request, $request->user(), 'sheet.reopen', $sheet, ['version' => (int) $sheet->version, 'comment' => $v['comment']]);
        return response()->json($this->payload($sheet, true));
    }

    /** ¿Puede quien mira (si es el dueño) aprobar esta hoja ahora? Solo si le toca la etapa interna; la del cliente no la sustituye nadie. */
    private function canApprove(Timesheet $s): ?bool
    {
        $u = auth()->user();
        if (! $u || ! $u->isStaff() || $s->status !== Timesheet::SUBMITTED) {
            return null;
        }
        if ($s->stage === Timesheet::STAGE_INTERNAL) {
            return $this->internal->pendingPartsOf($s, $u)->isNotEmpty();
        }
        return $s->stage === null || $this->external->pendingParts($s)->isEmpty();
    }

    /** Deja la firma de quien envió, en cada hoja que salió. */
    private function signSubmitted(SignatureService $sig, Request $request, User $user, \Illuminate\Support\Collection $sheets): void
    {
        foreach ($sheets as $s) {
            $sig->record($request, $user, 'sheet.submit', $s, ['version' => (int) $s->version, 'hours' => (float) $s->total_hours, 'week_ending' => $s->week_ending->toDateString()]);
        }
    }

    private function authorizeOwn(Request $request, Timesheet $sheet): void
    {
        abort_unless($sheet->user_id === $request->user()->id, 403);
    }

    private function authorizeView(Request $request, Timesheet $sheet): void
    {
        $u = $request->user();
        abort_unless($sheet->user_id === $u->id || ($u->isStaff() && $sheet->company_id === $u->company_id), 403);
    }

    public function payload(Timesheet $s, bool $withEntries = false): array
    {
        $s->loadMissing('user', 'approver');
        $entries = $withEntries ? $s->entries()->with('project:id,code,name', 'service:id,name', 'task:id,name')->get() : null;
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
            'can_approve' => $this->canApprove($s),
        ] + $s->hoursSplit($entries ?? ($s->relationLoaded('entries') ? $s->entries : null)) + $this->internal->info($s);
        if ($withEntries) {
            $out['entries'] = $entries->map(fn ($e) => EntryController::payload($e));
            $out['worker'] = ['id' => $s->user->id, 'name' => $s->user->name, 'worker_type' => $s->user->worker_type, 'employee_number' => $s->user->employee_number];
            $out['approver'] = $s->approver ? ['id' => $s->approver->id, 'name' => $s->approver->name] : null;
            // Quién envió y quién aprobó o devolvió, con firma digital, en el ciclo actual de la hoja.
            $out['signatures'] = app(SignatureService::class)->forSheet($s);
            $out['history'] = app(\App\Services\SheetHistory::class)->for($s);
        }
        return $out;
    }
}
