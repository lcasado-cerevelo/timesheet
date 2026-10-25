<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Services\InternalApprovalService;
use App\Services\ReviewService;
use App\Services\SheetService;
use App\Support\Week;
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
        return response()->json($this->payload($sheet, true));
    }

    public function show(Request $request, Timesheet $sheet)
    {
        $this->authorizeView($request, $sheet);
        return response()->json($this->payload($sheet, true));
    }

    public function submit(Request $request, Timesheet $sheet)
    {
        $this->authorizeOwn($request, $sheet);
        $sheet = $this->sheets->submit($sheet, $request->user(), 'app');
        return response()->json($this->payload($sheet, true));
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
