<?php

namespace App\Services;

use App\Support\Lang;
use App\Support\Parts;
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
    /** Un enlace por hoja Y por aprobador: emitir uno nuevo solo anula los anteriores de ese mismo aprobador. */
    public function issueSheetToken(Timesheet $sheet, Approver $approver): string
    {
        ApprovalToken::where('timesheet_id', $sheet->id)->where('approver_id', $approver->id)->where('scope', 'sheet')->whereNull('used_at')
            ->update(['used_at' => now()]);
        $plain = Str::random(48);
        ApprovalToken::create([
            'approver_id' => $approver->id,
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

    /**
     * Resumen sin tarifas ni importes: persona, semana, horas por proyecto (y servicio, si el proyecto tiene varios).
     * $entries recorta el resumen a ciertas líneas (las partes de un aprobador externo); sin eso, toda la hoja.
     */
    public function sheetSummary(Timesheet $sheet, ?\Illuminate\Support\Collection $entries = null): array
    {
        $sheet->loadMissing('user');
        $entries ??= $sheet->entries()->get();
        \Illuminate\Database\Eloquent\Collection::make($entries)->loadMissing('project.services', 'service');
        $byPart = $entries->groupBy(fn ($e) => $e->project_id.'|'.(Parts::serviceName($e->project, $e->service) ?? ''))->map(fn ($rows) => [
            'project' => Parts::label($rows->first()->project, $rows->first()->service),
            'hours' => (float) $rows->sum('hours'),
        ])->values()->all();
        return [
            'worker' => $sheet->user->name,
            'week_ending' => $sheet->weekLabel(),
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'projects' => $byPart,
            'late' => (bool) $sheet->late,
            'submission_note' => $sheet->submission_note,
            'warnings' => $sheet->warnings(),
        ];
    }

    /** $kind: new | resubmitted | changed. Un correo para este aprobador solo con las horas de las partes que le tocan. */
    public function sendSheetRequest(Timesheet $sheet, string $kind, Approver $approver): void
    {
        $sheet->loadMissing('company', 'user');
        $entries = app(ExternalApprovalService::class)->entriesOf($sheet, $approver, true);
        if ($entries->isEmpty()) {
            return;
        }
        $plain = $this->issueSheetToken($sheet, $approver);
        $company = $sheet->company;
        // El aprobador es una persona externa sin cuenta: recibe el correo en el idioma de la compañía.
        Lang::with($company->lang(), function () use ($sheet, $kind, $plain, $company, $approver, $entries) {
            $p = ['name' => $sheet->user->name, 'week' => $sheet->weekLabel()];
            $subject = match ($kind) {
                'changed' => __('Esta hoja cambió: :name, semana :week', $p),
                'resubmitted' => __('Hoja corregida para aprobar: :name, semana :week', $p),
                default => __('Hoja para aprobar: :name, semana :week', $p),
            };
            Mail::to($approver->email, $approver->name)->locale($company->lang())->send(new BrandedMail(
                $company, $subject, 'mail.approval-request',
                [
                    'kind' => $kind,
                    'summary' => $this->sheetSummary($sheet, $entries),
                    'changes' => $kind === 'changed' ? Parts::changes($sheet->change_summary, $entries->map(fn ($e) => (int) $e->service_id)->unique()->values()->all()) : [],
                    'url' => $company->baseUrl().'/a/'.$plain,
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
                ['count' => $count, 'url' => $approver->company->baseUrl().'/b/'.$plain, 'name' => $approver->name]
            ));
        });
    }

    public function notifyWorkerDecision(Timesheet $sheet, string $decision, ?string $by = null): void
    {
        $sheet->loadMissing('user', 'company');
        // La persona que trabaja recibe el correo en su propio idioma.
        $lang = $sheet->user->lang();
        Lang::with($lang, function () use ($sheet, $decision, $lang, $by) {
            $week = $sheet->weekLabel();
            Mail::to($sheet->user->email, $sheet->user->name)->locale($lang)->send(new BrandedMail(
                $sheet->company,
                $decision === 'approved'
                    ? __('Hoja aprobada: semana :week', ['week' => $week])
                    : __('Hoja devuelta para corregir: semana :week', ['week' => $week]),
                'mail.sheet-decision',
                ['decision' => $decision, 'week' => $week, 'comment' => $sheet->return_comment,
                 'name' => $sheet->user->name, 'by' => $by]
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

    /**
     * Recordatorios: aprobadores externos con partes pendientes de hojas enviadas hace más de N días. Un solo correo por
     * aprobador. Como una hoja puede tener varios aprobadores, last_reminded_at se actualiza al final, para todos a la vez.
     */
    public function sendReminders(): int
    {
        $sent = 0;
        $dueSheets = [];
        $external = app(ExternalApprovalService::class);
        Approver::where('active', true)->with('company')->each(function (Approver $a) use (&$sent, &$dueSheets, $external) {
            $rules = $a->company->rules();
            $days = (int) $rules->get('approval_remind_days');
            $every = (int) $rules->get('remind_every_days');
            $pending = $external->pendingSheetsFor($a);
            $stale = $pending->filter(fn (Timesheet $s) => $s->submitted_at && $s->submitted_at->lte(now()->subDays($days))
                && (! $s->last_reminded_at || $s->last_reminded_at->lte(now()->subDays($every))));
            if ($stale->isEmpty()) {
                return;
            }
            $this->sendDigest($a, $pending->count());
            array_push($dueSheets, ...$stale->pluck('id')->all());
            $sent++;
        });
        if ($dueSheets) {
            Timesheet::whereIn('id', array_unique($dueSheets))->update(['last_reminded_at' => now()]);
        }
        // Los aprobadores internos con partes pendientes también reciben su recordatorio.
        return $sent + app(InternalApprovalService::class)->sendReminders();
    }
}
