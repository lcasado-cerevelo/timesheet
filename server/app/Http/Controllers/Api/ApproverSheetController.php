<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalToken;
use App\Models\Approver;
use App\Models\Timesheet;
use App\Services\ApprovalService;
use App\Services\ExternalApprovalService;
use App\Services\InternalApprovalService;
use App\Services\ReviewService;
use App\Services\SheetService;
use App\Support\Week;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Hojas del aprobador con cuenta: solo las partes (servicios) que le tocan; ve esas líneas y el total de esas líneas. */
class ApproverSheetController extends Controller
{
    public function __construct(private SheetService $sheets, private ApprovalService $approvals, private SheetController $sheetCtl, private ReviewService $review,
        private ExternalApprovalService $external, private InternalApprovalService $internal)
    {
    }

    public function index(Request $request)
    {
        $approver = $this->approver($request);
        $status = $request->query('status', 'pending');
        $map = ['pending' => [Timesheet::SUBMITTED], 'approved' => [Timesheet::APPROVED], 'returned' => [Timesheet::RETURNED],
            'all' => [Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::RETURNED]];
        abort_unless(isset($map[$status]), 422, __('Estado no válido.'));
        $rows = Timesheet::with('user')->whereIn('id', $this->external->sheetIdsFor($approver))
            ->orderByRaw('submitted_at is null')->orderBy('submitted_at')->orderBy('id')->get()
            ->filter(fn ($s) => $status === 'all' ? $this->external->classify($s, $approver) !== null : $this->external->classify($s, $approver) === $status);
        return response()->json($rows->map(fn ($s) => $this->summary($s, $approver))->values());
    }

    /** Lista de revisión con filtros (status, q, type, from, to) y conteos; solo las hojas de este aprobador. */
    public function review(Request $request)
    {
        $approver = $this->approver($request);
        // Solo las hojas con partes suyas; mientras la hoja espera a los aprobadores internos, el externo todavía no la ve.
        $scope = Timesheet::query()->whereIn('id', $this->external->sheetIdsFor($approver));
        return response()->json($this->review->run($request, $scope, fn ($s) => $this->summary($s, $approver) + $this->review->extras($s),
            fn ($s) => $this->external->classify($s, $approver)));
    }

    public function show(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        return response()->json($this->present($sheet, $approver, true));
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
        return response()->json($this->present($sheet, $approver, false));
    }

    public function return(Request $request, Timesheet $sheet)
    {
        $approver = $this->approver($request);
        $this->own($sheet, $approver);
        $v = $request->validate(['version' => 'required|integer', 'comment' => 'required|string|max:1000', 'code' => 'nullable|string|max:12']);
        $this->checkCode($sheet, $approver, $v['code'] ?? null);
        $sheet = $this->sheets->return($sheet, $approver, null, 'app', $v['comment'], $v['version']);
        return response()->json($this->present($sheet, $approver, false));
    }

    private function approver(Request $request): Approver
    {
        return Approver::with('company')->findOrFail($request->user()->approver_profile_id);
    }

    /** La hoja debe tener (o haber tenido) partes de este aprobador y haber salido ya de la etapa interna. */
    private function own(Timesheet $sheet, Approver $approver): void
    {
        abort_unless($sheet->company_id === $approver->company_id && $sheet->status !== Timesheet::DRAFT
            && ! ($sheet->status === Timesheet::SUBMITTED && $sheet->stage === Timesheet::STAGE_INTERNAL)
            && $this->external->involves($sheet, $approver), 404);
    }

    /** El límite de horas para pedir código se mide sobre las horas que le tocan a este aprobador. */
    private function needsCode(Timesheet $sheet, Approver $approver): bool
    {
        $limit = $approver->company->rules()->get('second_approval_hours');
        if ($approver->require_code) {
            return true;
        }
        $mine = $this->external->entriesOf($sheet, $approver);
        $hours = $mine->isNotEmpty() ? (float) $mine->sum('hours') : (float) $sheet->total_hours;
        return $limit !== null && $hours > (float) $limit;
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

    /**
     * La hoja con solo las líneas (y el total) de las partes de este aprobador; internal/external recortados a sus servicios.
     * Las advertencias (p. ej. horas por semana) se calculan sobre la hoja completa, antes de recortar.
     */
    private function present(Timesheet $sheet, Approver $approver, bool $withEntries): array
    {
        $warnings = $sheet->warnings();
        $info = $this->internal->info($sheet, $this->external->serviceKeysOf($sheet, $approver), $approver);
        $this->external->restrictTo($sheet, $approver);
        $out = $this->sheetCtl->payload($sheet, false);
        $out['approver'] = ['id' => $approver->id, 'name' => $approver->name];      // quien mira (las demás partes no se muestran)
        $out = array_merge($out, $info);
        $out['warnings'] = $warnings;
        if ($withEntries) {
            $out['entries'] = $sheet->entries->map(fn ($e) => EntryController::payload($e));
            $out['worker'] = ['id' => $sheet->user->id, 'name' => $sheet->user->name, 'worker_type' => $sheet->user->worker_type, 'employee_number' => $sheet->user->employee_number];
        }
        $out['total_hours'] = round((float) $sheet->entries->sum('hours'), 2);
        return $out + ['needs_code' => $this->needsCode($sheet, $approver)];
    }

    private function summary(Timesheet $s, Approver $approver): array
    {
        $info = $this->internal->info($s, $this->external->serviceKeysOf($s, $approver), $approver);
        $warnings = $s->warnings();
        $this->external->restrictTo($s, $approver);
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
            'warnings' => array_map(fn ($w) => ['message' => $w['message']], $warnings),
            'needs_code' => $this->needsCode($s, $approver),
            'return_comment' => $s->return_comment,
        ] + $info;
    }
}
