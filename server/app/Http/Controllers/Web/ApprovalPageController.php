<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApprovalToken;
use App\Models\Timesheet;
use App\Services\ApprovalService;
use App\Services\SheetService;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

/**
 * Páginas públicas de aprobación (sin cuenta). Abrir el enlace no aprueba nada:
 * la decisión se toma con un botón en la página (POST).
 */
class ApprovalPageController extends Controller
{
    public function __construct(private ApprovalService $approvals, private SheetService $sheets)
    {
    }

    // ---------- Una hoja ----------

    public function show(string $plain)
    {
        [$token, $sheet, $err] = $this->loadSheetToken($plain);
        $this->lang($sheet?->company ?? $token?->approver?->company);
        if ($err) {
            return $this->invalid($token, $err);
        }
        if ($this->needsCode($token, $sheet) && ! $this->codeOk($token)) {
            return $this->codeView($token, $plain, 'a', $sheet->company);
        }
        return view('approval.sheet', $this->sheetData($token, $sheet, $plain));
    }

    public function sendCode(string $plain)
    {
        $token = $this->approvals->findToken($plain);
        $this->lang($token?->approver?->company);
        if (! $token || ! $token->isUsable()) {
            return $this->invalid($token, __('Este enlace ya no es válido.'));
        }
        $this->approvals->sendCode($token);
        return redirect()->back()->with('status', __('Te enviamos un código de 6 dígitos a tu correo.'));
    }

    public function verifyCode(Request $request, string $plain)
    {
        $token = $this->approvals->findToken($plain);
        $this->lang($token?->approver?->company);
        if (! $token || ! $token->isUsable()) {
            return $this->invalid($token, __('Este enlace ya no es válido.'));
        }
        $ok = $this->approvals->verifyCode($token, (string) $request->input('code'));
        return $ok ? redirect()->back() : redirect()->back()->withErrors(['code' => __('El código no es correcto o venció.')]);
    }

    public function approve(string $plain)
    {
        [$token, $sheet, $err] = $this->loadSheetToken($plain);
        if ($err) {
            return $this->invalid($token, $err);
        }
        if ($this->needsCode($token, $sheet) && ! $this->codeOk($token)) {
            return redirect()->back()->withErrors(['code' => __('Verifica el código primero.')]);
        }
        try {
            $this->sheets->approve($sheet, $token->approver, null, 'link', (int) $token->sheet_version);
        } catch (ValidationException $e) {
            return $this->invalid($token, collect($e->errors())->flatten()->first());
        }
        return view('approval.done', ['brand' => $sheet->company->brand(), 'title' => __('Hoja aprobada'),
            'message' => __('Aprobaste la hoja de :name (semana :week). Ya no se puede cambiar.', ['name' => $sheet->user->name, 'week' => $sheet->week_ending->toDateString()])]);
    }

    public function returnSheet(Request $request, string $plain)
    {
        [$token, $sheet, $err] = $this->loadSheetToken($plain);
        if ($err) {
            return $this->invalid($token, $err);
        }
        if ($this->needsCode($token, $sheet) && ! $this->codeOk($token)) {
            return redirect()->back()->withErrors(['code' => __('Verifica el código primero.')]);
        }
        try {
            $this->sheets->return($sheet, $token->approver, null, 'link', (string) $request->input('comment'), (int) $token->sheet_version);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }
        return view('approval.done', ['brand' => $sheet->company->brand(), 'title' => __('Hoja devuelta'),
            'message' => __('Devolviste la hoja de :name. Le avisamos para que la corrija y la vuelva a enviar.', ['name' => $sheet->user->name])]);
    }

    // ---------- Todas las pendientes (lote) ----------

    public function batch(string $plain)
    {
        $token = $this->approvals->findToken($plain);
        $this->lang($token?->approver?->company);
        if (! $token || $token->scope !== 'batch' || ! $token->isUsable()) {
            return $this->invalid($token, __('Este enlace ya no es válido o venció. Pide uno nuevo.'));
        }
        $approver = $token->approver()->with('company')->first();
        if (! $this->codeOk($token)) {
            return $this->codeView($token, $plain, 'b', $approver->company);
        }
        $sheets = Timesheet::with('user', 'entries.project')->where('approver_id', $approver->id)
            ->where('status', Timesheet::SUBMITTED)->orderBy('submitted_at')->get();
        $limit = $approver->company->rules()->get('second_approval_hours');
        return view('approval.batch', [
            'brand' => $approver->company->brand(), 'plain' => $plain, 'approver' => $approver,
            'rows' => $sheets->map(fn ($s) => ['sheet' => $s, 'summary' => $this->approvals->sheetSummary($s),
                'bulk_ok' => $limit === null || (float) $s->total_hours <= (float) $limit]),
        ]);
    }

    public function batchApproveAll(Request $request, string $plain)
    {
        $token = $this->batchToken($plain);
        if (! $token instanceof ApprovalToken) {
            return $token;
        }
        $limit = $token->approver->company->rules()->get('second_approval_hours');
        $approved = 0;
        $skipped = 0;
        foreach ((array) $request->input('items', []) as $pair) {
            [$id, $version] = array_pad(explode(':', (string) $pair), 2, null);
            $sheet = Timesheet::where('approver_id', $token->approver_id)->where('status', Timesheet::SUBMITTED)->find($id);
            // Lo que cambió después de verse, o pasa del límite, no se aprueba en bloque.
            if (! $sheet || (int) $sheet->version !== (int) $version
                || ($limit !== null && (float) $sheet->total_hours > (float) $limit)) {
                $skipped++;
                continue;
            }
            try {
                $this->sheets->approve($sheet, $token->approver, null, 'link-batch', (int) $version);
                $approved++;
            } catch (ValidationException) {
                $skipped++;
            }
        }
        return view('approval.done', ['brand' => $token->approver->company->brand(), 'title' => __('Listo'),
            'message' => __('Aprobadas: :approved. Para revisar una por una: :skipped.', ['approved' => $approved, 'skipped' => $skipped]), 'back' => url('/b/'.$plain)]);
    }

    public function batchApprove(Request $request, string $plain, Timesheet $sheet)
    {
        $token = $this->batchToken($plain);
        if (! $token instanceof ApprovalToken) {
            return $token;
        }
        abort_unless($sheet->approver_id === $token->approver_id, 404);
        try {
            $this->sheets->approve($sheet, $token->approver, null, 'link-batch', (int) $request->input('version'));
        } catch (ValidationException $e) {
            return redirect('/b/'.$plain)->withErrors(collect($e->errors())->flatten()->all());
        }
        return redirect('/b/'.$plain)->with('status', __('Hoja aprobada.'));
    }

    public function batchReturn(Request $request, string $plain, Timesheet $sheet)
    {
        $token = $this->batchToken($plain);
        if (! $token instanceof ApprovalToken) {
            return $token;
        }
        abort_unless($sheet->approver_id === $token->approver_id, 404);
        try {
            $this->sheets->return($sheet, $token->approver, null, 'link-batch', (string) $request->input('comment'), (int) $request->input('version'));
        } catch (ValidationException $e) {
            return redirect('/b/'.$plain)->withErrors(collect($e->errors())->flatten()->all());
        }
        return redirect('/b/'.$plain)->with('status', __('Hoja devuelta.'));
    }

    // ---------- internos ----------

    /**
     * Estas páginas no tienen sesión: el idioma es el de la compañía dueña de la hoja (o del aprobador), no el del navegador.
     * Se fija en cuanto se conoce la compañía, antes de generar cualquier texto.
     */
    private function lang(?Company $company): void
    {
        if ($company) {
            App::setLocale($company->lang());
        }
    }

    private function batchToken(string $plain)
    {
        $token = $this->approvals->findToken($plain);
        $this->lang($token?->approver?->company);
        if (! $token || $token->scope !== 'batch' || ! $token->isUsable()) {
            return $this->invalid($token, __('Este enlace ya no es válido o venció.'));
        }
        if (! $this->codeOk($token)) {
            return redirect('/b/'.$plain);
        }
        return $token;
    }

    private function loadSheetToken(string $plain): array
    {
        $token = $this->approvals->findToken($plain);
        $this->lang($token?->approver?->company);
        if (! $token || $token->scope !== 'sheet') {
            return [null, null, __('Este enlace no es válido.')];
        }
        if (! $token->isUsable()) {
            return [$token, null, __('Este enlace ya se usó, fue reemplazado o venció. Si la hoja cambió, revisa el correo más reciente.')];
        }
        $sheet = $token->timesheet()->with('user', 'company')->first();
        if (! $sheet || $sheet->status !== Timesheet::SUBMITTED) {
            return [$token, null, __('Esta hoja ya fue decidida.')];
        }
        if ((int) $sheet->version !== (int) $token->sheet_version) {
            return [$token, null, __('Esta hoja cambió después de enviarse. Revisa el correo más reciente.')];
        }
        return [$token, $sheet, null];
    }

    private function needsCode(ApprovalToken $token, Timesheet $sheet): bool
    {
        $limit = $sheet->company->rules()->get('second_approval_hours');
        return $token->approver->require_code || ($limit !== null && (float) $sheet->total_hours > (float) $limit);
    }

    private function codeOk(ApprovalToken $token): bool
    {
        return $token->code_verified_at !== null && $token->code_verified_at->gt(now()->subMinutes(30));
    }

    private function sheetData(ApprovalToken $token, Timesheet $sheet, string $plain): array
    {
        $sheet->loadMissing('entries.project');
        return [
            'brand' => $sheet->company->brand(), 'plain' => $plain, 'sheet' => $sheet,
            'summary' => $this->approvals->sheetSummary($sheet),
            'byDay' => $sheet->entries->groupBy(fn ($e) => $e->work_date->toDateString()),
        ];
    }

    private function codeView(ApprovalToken $token, string $plain, string $prefix, $company)
    {
        return view('approval.code', ['brand' => $company->brand(), 'plain' => $plain, 'prefix' => $prefix,
            'sent' => $token->code_hash !== null && $token->code_expires_at?->isFuture()]);
    }

    private function invalid(?ApprovalToken $token, ?string $message)
    {
        $brand = $token?->approver?->company?->brand() ?? ['name' => config('app.name'), 'logo_url' => null,
            'color_primary' => '#1f4e79', 'color_accent' => '#14603B', 'email_footer' => null];
        return response()->view('approval.done', ['brand' => $brand, 'title' => __('No se puede abrir'), 'message' => $message], 410);
    }
}
