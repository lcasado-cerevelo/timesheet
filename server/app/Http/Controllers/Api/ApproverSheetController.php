<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalToken;
use App\Models\Approver;
use App\Models\Timesheet;
use App\Services\ApprovalService;
use App\Services\SheetService;
use App\Support\Week;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Hojas del aprobador con cuenta: solo las que tienen su aprobador asignado. */
class ApproverSheetController extends Controller
{
    public function __construct(private SheetService $sheets, private ApprovalService $approvals, private SheetController $sheetCtl)
    {
    }

    public function index(Request $request)
    {
        $approver = $this->approver($request);
        $status = $request->query('status', 'pending');
        $map = ['pending' => [Timesheet::SUBMITTED], 'approved' => [Timesheet::APPROVED], 'returned' => [Timesheet::RETURNED],
            'all' => [Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::RETURNED]];
        abort_unless(isset($map[$status]), 422, __('Estado no válido.'));
        $rows = Timesheet::with('user')->where('approver_id', $approver->id)->whereIn('status', $map[$status])
            ->orderByRaw('submitted_at is null')->orderBy('submitted_at')->orderBy('id')->get();
        return response()->json($rows->map(fn ($s) => $this->summary($s, $approver))->values());
    }

    public function show(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        return response()->json($this->sheetCtl->payload($sheet, true) + ['needs_code' => $this->needsCode($sheet, $approver)]);
    }

    public function code(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        abort_unless($sheet->status === Timesheet::SUBMITTED, 422, __('Esta hoja ya no está pendiente de aprobación.'));
        $minutes = (int) $approver->company->rules()->get('code_minutes');
        $token = ApprovalToken::create([
            'approver_id' => $approver->id, 'timesheet_id' => $sheet->id, 'scope' => 'app',
            'token_hash' => ApprovalToken::hash(Str::random(48)), 'sheet_version' => $sheet->version,
            'expires_at' => now()->addMinutes($minutes), 'created_at' => now(),
        ]);
        $this->approvals->sendCode($token);
        return response()->json(['ok' => true, 'expires_in' => $minutes * 60]);
    }

    public function approve(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        $v = $request->validate(['version' => 'required|integer', 'code' => 'nullable|string|max:12']);
        $this->checkCode($sheet, $approver, $v['code'] ?? null);
        $sheet = $this->sheets->approve($sheet, $approver, null, 'app', $v['version']);
        return response()->json($this->sheetCtl->payload($sheet));
    }

    public function return(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        $v = $request->validate(['version' => 'required|integer', 'comment' => 'required|string|max:1000', 'code' => 'nullable|string|max:12']);
        $this->checkCode($sheet, $approver, $v['code'] ?? null);
        $sheet = $this->sheets->return($sheet, $approver, null, 'app', $v['comment'], $v['version']);
        return response()->json($this->sheetCtl->payload($sheet));
    }

    private function approver(Request $request): Approver
    {
        return Approver::with('company')->findOrFail($request->user()->approver_profile_id);
    }

    private function own(Timesheet $sheet, Approver $approver): void
    {
        abort_unless($sheet->approver_id === $approver->id && $sheet->status !== Timesheet::DRAFT, 404);
    }

    private function needsCode(Timesheet $sheet, Approver $approver): bool
    {
        $limit = $approver->company->rules()->get('second_approval_hours');
        return $approver->require_code || ($limit !== null && (float) $sheet->total_hours > (float) $limit);
    }

    private function checkCode(Timesheet $sheet, Approver $approver, ?string $code): void
    {
        if (! $this->needsCode($sheet, $approver) || $sheet->status !== Timesheet::SUBMITTED) {
            return;
        }
        $token = ApprovalToken::where('approver_id', $approver->id)->where('timesheet_id', $sheet->id)->where('scope', 'app')
            ->whereNull('used_at')->where('sheet_version', $sheet->version)->whereNotNull('code_hash')->latest('id')->first();
        if (! $code || ! $token || ! $token->isUsable() || ! $this->approvals->verifyCode($token, $code)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => __('El código no es correcto o venció.'),
                'errors' => ['code' => [$code ? __('El código no es correcto o venció.') : __('Escribe el código que enviamos a tu correo.')]],
                'needs_code' => true,
            ], 422));
        }
    }

    private function summary(Timesheet $s, Approver $approver): array
    {
        return [
            'id' => $s->id,
            'user' => ['id' => $s->user->id, 'name' => $s->user->name],
            'week_starting' => Week::starting($s->week_ending->toDateString()),
            'week_ending' => $s->week_ending->toDateString(),
            'status' => $s->status,
            'total_hours' => (float) $s->total_hours,
            'late' => (bool) $s->late,
            'version' => $s->version,
            'submitted_at' => $s->submitted_at,
            'decided_at' => $s->decided_at,
            'warnings' => array_map(fn ($w) => ['message' => $w['message']], $s->warnings()),
            'needs_code' => $this->needsCode($s, $approver),
            'return_comment' => $s->return_comment,
        ];
    }
}
