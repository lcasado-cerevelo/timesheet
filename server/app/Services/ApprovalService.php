<?php

namespace App\Services;

use App\Support\Lang;
use App\Mail\BrandedMail;
use App\Models\ApprovalToken;
use App\Models\Approver;
use App\Models\Timesheet;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Enlaces seguros para aprobadores que no tienen cuenta.
 *  - El email resume la hoja (horas por proyecto, sin tarifas) y trae un botón que abre una página
 *    con la hoja completa y los botones Aprobar / Devolver. Abrir el enlace NO aprueba.
 *  - El token es aleatorio, se guarda solo su hash, vence y queda ligado a la versión de la hoja.
 *  - El enlace del lote (todas las pendientes) siempre pide un código de 6 dígitos enviado al mismo correo.
 */
class ApprovalService
{
    public function issueSheetToken(Timesheet $sheet): string
    {
        ApprovalToken::where('timesheet_id', $sheet->id)->where('scope', 'sheet')->whereNull('used_at')
            ->update(['used_at' => now()]);
        $plain = Str::random(48);
        ApprovalToken::create([
            'approver_id' => $sheet->approver_id,
            'timesheet_id' => $sheet->id,
            'scope' => 'sheet',
            'token_hash' => ApprovalToken::hash($plain),
            'sheet_version' => $sheet->version,
            'expires_at' => now()->addDays((int) $sheet->company->rules()->get('token_days')),
            'created_at' => now(),
        ]);
        return $plain;
    }

    public function issueBatchToken(Approver $approver): string
    {
        $plain = Str::random(48);
        ApprovalToken::create([
            'approver_id' => $approver->id,
            'scope' => 'batch',
            'token_hash' => ApprovalToken::hash($plain),
            'expires_at' => now()->addDays((int) $approver->company->rules()->get('token_days')),
            'created_at' => now(),
        ]);
        return $plain;
    }

    public function findToken(string $plain): ?ApprovalToken
    {
        return ApprovalToken::where('token_hash', ApprovalToken::hash($plain))->first();
    }

    /** Resumen sin tarifas ni importes: persona, semana, horas por proyecto. */
    public function sheetSummary(Timesheet $sheet): array
    {
        $sheet->loadMissing('user', 'entries.project');
        $byProject = $sheet->entries->groupBy('project_id')->map(fn ($rows) => [
            'project' => $rows->first()->project->code.' · '.$rows->first()->project->name,
            'hours' => (float) $rows->sum('hours'),
        ])->values()->all();
        return [
            'worker' => $sheet->user->name,
            'week_ending' => $sheet->week_ending->toDateString(),
            'total_hours' => (float) $sheet->total_hours,
            'projects' => $byProject,
            'late' => (bool) $sheet->late,
            'warnings' => $sheet->warnings(),
        ];
    }

    /** $kind: new | resubmitted | changed */
    public function sendSheetRequest(Timesheet $sheet, string $kind): void
    {
        $sheet->loadMissing('approver', 'company', 'user');
        if (! $sheet->approver) {
            return;
        }
        $plain = $this->issueSheetToken($sheet);
        $company = $sheet->company;
        // El aprobador es una persona externa sin cuenta: recibe el correo en el idioma de la compañía.
        Lang::with($company->lang(), function () use ($sheet, $kind, $plain, $company) {
            $p = ['name' => $sheet->user->name, 'week' => $sheet->week_ending->toDateString()];
            $subject = match ($kind) {
                'changed' => __('Esta hoja cambió: :name, semana :week', $p),
                'resubmitted' => __('Hoja corregida para aprobar: :name, semana :week', $p),
                default => __('Hoja para aprobar: :name, semana :week', $p),
            };
            Mail::to($sheet->approver->email, $sheet->approver->name)->locale($company->lang())->send(new BrandedMail(
                $company, $subject, 'mail.approval-request',
                [
                    'kind' => $kind,
                    'summary' => $this->sheetSummary($sheet),
                    'changes' => $kind === 'changed' ? array_filter(explode("\n", (string) $sheet->change_summary)) : [],
                    'url' => url('/a/'.$plain),
                    'days' => (int) $company->rules()->get('token_days'),
                ]
            ));
        });
    }

    /** Recordatorio con una sola pantalla de todo lo pendiente del aprobador. */
    public function sendDigest(Approver $approver, int $count): void
    {
        $plain = $this->issueBatchToken($approver);
        Lang::with($approver->company->lang(), function () use ($approver, $count, $plain) {
            Mail::to($approver->email, $approver->name)->locale($approver->company->lang())->send(new BrandedMail(
                $approver->company,
                $count === 1 ? __('Tienes 1 hoja pendiente de aprobar') : __('Tienes :count hojas pendientes de aprobar', ['count' => $count]),
                'mail.pending-digest',
                ['count' => $count, 'url' => url('/b/'.$plain), 'name' => $approver->name]
            ));
        });
    }

    public function notifyWorkerDecision(Timesheet $sheet, string $decision): void
    {
        $sheet->loadMissing('user', 'company');
        // La persona que trabaja recibe el correo en su propio idioma.
        $lang = $sheet->user->lang();
        Lang::with($lang, function () use ($sheet, $decision, $lang) {
            $week = $sheet->week_ending->toDateString();
            Mail::to($sheet->user->email, $sheet->user->name)->locale($lang)->send(new BrandedMail(
                $sheet->company,
                $decision === 'approved'
                    ? __('Hoja aprobada: semana :week', ['week' => $week])
                    : __('Hoja devuelta para corregir: semana :week', ['week' => $week]),
                'mail.sheet-decision',
                ['decision' => $decision, 'week' => $week, 'comment' => $sheet->return_comment,
                 'name' => $sheet->user->name]
            ));
        });
    }

    public function sendCode(ApprovalToken $token): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $approver = $token->approver()->with('company')->first();
        $token->forceFill([
            'code_hash' => hash('sha256', $token->id.'|'.$code),
            'code_expires_at' => now()->addMinutes((int) $approver->company->rules()->get('code_minutes')),
            'code_attempts' => 0,
            'code_verified_at' => null,
        ])->save();
        Lang::with($approver->company->lang(), function () use ($approver, $code) {
            Mail::to($approver->email, $approver->name)->locale($approver->company->lang())->send(new BrandedMail(
                $approver->company, __('Tu código de verificación: :code', ['code' => $code]), 'mail.approval-code',
                ['code' => $code, 'minutes' => (int) $approver->company->rules()->get('code_minutes')]
            ));
        });
    }

    public function verifyCode(ApprovalToken $token, string $code): bool
    {
        if (! $token->code_hash || ! $token->code_expires_at || $token->code_expires_at->isPast() || $token->code_attempts >= 5) {
            return false;
        }
        $token->increment('code_attempts');
        if (hash_equals($token->code_hash, hash('sha256', $token->id.'|'.trim($code)))) {
            $token->forceFill(['code_verified_at' => now()])->save();
            return true;
        }
        return false;
    }

    /** Recordatorios: aprobadores con hojas enviadas hace más de N días sin decisión. Una sola alerta por aprobador. */
    public function sendReminders(): int
    {
        $sent = 0;
        Approver::where('active', true)->with('company')->each(function (Approver $a) use (&$sent) {
            $rules = $a->company->rules();
            $days = (int) $rules->get('approval_remind_days');
            $every = (int) $rules->get('remind_every_days');
            $stale = Timesheet::where('approver_id', $a->id)->where('status', Timesheet::SUBMITTED)
                ->where('submitted_at', '<=', now()->subDays($days))
                ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', now()->subDays($every)))
                ->get();
            if ($stale->isEmpty()) {
                return;
            }
            $pending = Timesheet::where('approver_id', $a->id)->where('status', Timesheet::SUBMITTED)->count();
            $this->sendDigest($a, $pending);
            Timesheet::whereIn('id', $stale->pluck('id'))->update(['last_reminded_at' => now()]);
            $sent++;
        });
        return $sent;
    }
}
