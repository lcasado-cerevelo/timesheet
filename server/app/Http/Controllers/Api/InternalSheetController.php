<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Services\InternalApprovalService;
use App\Services\ReviewService;
use App\Services\SheetService;
use Illuminate\Http\Request;

/** Hojas del aprobador interno (supervisor): solo ve y decide sobre las líneas de los servicios que supervisa. */
class InternalSheetController extends Controller
{
    public function __construct(private SheetService $sheets, private InternalApprovalService $internal, private SheetController $sheetCtl, private ReviewService $review)
    {
    }

    /** Mismo formato y reglas de periodo que /api/review/sheets; pending = tiene una parte suya sin aprobar. */
    public function review(Request $request)
    {
        $user = $request->user();
        return response()->json($this->review->run($request, $this->internal->scopeFor($user), fn ($s) => $this->present($s, $user, false)
            + $this->review->extras($s), fn ($s) => $this->internal->classify($s, $user)));
    }

    public function show(Request $request, Timesheet $sheet)
    {
        $user = $request->user();
        $this->authorizeSheet($sheet, $user);
        return response()->json($this->present($sheet, $user, true));
    }

    public function approve(Request $request, Timesheet $sheet)
    {
        $user = $request->user();
        $this->authorizeSheet($sheet, $user);
        $v = $request->validate(['version' => 'required|integer']);
        $sheet = $this->sheets->internalApprove($sheet, $user, $v['version']);
        return response()->json($this->present($sheet, $user, false));
    }

    public function return(Request $request, Timesheet $sheet)
    {
        $user = $request->user();
        $this->authorizeSheet($sheet, $user);
        $v = $request->validate(['version' => 'required|integer', 'comment' => 'required|string|max:1000']);
        $sheet = $this->sheets->internalReturn($sheet, $user, $v['comment'], $v['version']);
        return response()->json($this->present($sheet, $user, false));
    }

    /** La hoja debe tener al menos un servicio que esta persona supervisa, y no ser suya. */
    private function authorizeSheet(Timesheet $sheet, $user): void
    {
        $ids = $this->internal->supervisedServiceIds($user);
        abort_unless($sheet->company_id === $user->company_id && $sheet->user_id !== $user->id
            && $sheet->status !== Timesheet::DRAFT
            && $sheet->entries()->whereIn('service_id', $ids ?: [0])->exists(), 404);
    }

    /** La hoja con solo las líneas (y el total) de los servicios que supervisa esta persona. */
    private function present(Timesheet $sheet, $user, bool $withEntries): array
    {
        $ids = $this->internal->supervisedServiceIds($user);
        $info = $this->internal->info($sheet, $ids);
        $this->internal->restrictTo($sheet, $user);
        $out = $this->sheetCtl->payload($sheet, false);
        $out['warnings'] = [];
        unset($out['approver']);
        $out = array_merge($out, $info);
        if ($withEntries) {
            $out['entries'] = $sheet->entries->map(fn ($e) => EntryController::payload($e));
            $out['worker'] = ['id' => $sheet->user->id, 'name' => $sheet->user->name, 'worker_type' => $sheet->user->worker_type, 'employee_number' => $sheet->user->employee_number];
        }
        $out['total_hours'] = round((float) $sheet->entries->sum('hours'), 2);
        $out['needs_my_approval'] = $sheet->status === Timesheet::SUBMITTED && $sheet->stage === Timesheet::STAGE_INTERNAL
            && $this->internal->pendingPartsOf($sheet, $user)->isNotEmpty();
        return $out;
    }
}
