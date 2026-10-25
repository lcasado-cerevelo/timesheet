<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Services\SheetService;
use App\Support\Week;
use Illuminate\Http\Request;

class SheetController extends Controller
{
    public function __construct(private SheetService $sheets)
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
        $s->loadMissing('user');
        $out = [
            'id' => $s->id,
            'user_id' => $s->user_id,
            'user' => $s->relationLoaded('user') ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
            'week_starting' => Week::starting($s->week_ending->toDateString()),
            'week_ending' => $s->week_ending->toDateString(),
            'status' => $s->status,
            'locked' => $s->isLocked(),
            'version' => $s->version,
            'total_hours' => (float) $s->total_hours,
            'late' => $s->late,
            'return_comment' => $s->return_comment,
            'submitted_at' => $s->submitted_at,
            'decided_at' => $s->decided_at,
            'approved_by' => $s->status === Timesheet::APPROVED ? ($s->decidedByApprover?->name ?? $s->decidedByUser?->name) : null,
            'invoice_number' => $s->invoice?->number,
            'warnings' => $s->warnings(),
        ];
        if ($withEntries) {
            $out['entries'] = $s->entries()->with('project:id,code,name')->get()->map(fn ($e) => EntryController::payload($e));
        }
        return $out;
    }
}
