<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Models\TimesheetEvent;

/**
 * Lo que pasó con una hoja, del historial que se guarda siempre (envíos, aprobaciones y devoluciones con su motivo).
 * Quién lo ve: el dueño, los aprobadores internos y la propia persona lo ven todo; el aprobador externo solo lo que hicieron los
 * aprobadores externos (nunca lo que se decidió en la etapa interna).
 */
class SheetHistory
{
    public const ALL = 'all';
    public const EXTERNAL = 'external';

    /** @return array<int, array{type:string, stage:?string, by:?string, at:string, comment:?string}> */
    public function for(Timesheet $sheet, string $scope = self::ALL): array
    {
        $out = [];
        foreach (TimesheetEvent::where('timesheet_id', $sheet->id)->whereIn('type', ['submitted', 'resubmitted', 'internal_approved', 'external_approved', 'approved', 'self_approved', 'auto_approved', 'returned', 'reopened'])
            ->orderBy('id')->get() as $e) {
            $stage = $e->data['stage'] ?? null;
            if ($scope === self::EXTERNAL) {
                $external = in_array($e->type, ['external_approved'], true) || (in_array($e->type, ['returned', 'approved'], true) && $stage === 'external');
                if (! $external) {
                    continue;
                }
            }
            $out[] = ['type' => $e->type, 'stage' => $stage, 'by' => $e->actor, 'at' => $e->created_at?->toIso8601String(), 'comment' => in_array($e->type, ['returned', 'reopened'], true) ? ($e->data['comment'] ?? null) : null];
        }
        return $out;
    }
}
