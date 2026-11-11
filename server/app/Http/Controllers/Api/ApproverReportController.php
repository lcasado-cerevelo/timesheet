<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoursReportService;
use Illuminate\Http\Request;

/**
 * Reporte de horas del gerente de proyecto (aprobador con cuenta y la marca): el mismo reporte, pero con las horas de todos en los proyectos de
 * los clientes de los que es parte (ver ManagerReportService). Sin tarifas.
 */
class ApproverReportController extends Controller
{
    public function __construct(private HoursReportService $report)
    {
    }

    public function summary(Request $request)
    {
        $d = $request->validate(ReportController::rules());
        $r = $this->report->summary($request->user(), $d);
        return response()->json([
            'from' => $d['from'], 'to' => $d['to'], 'group' => $r['group'], 'rows' => $r['rows'],
            'total_hours' => $r['total']['hours'], 'billable_hours' => $r['total']['billable_hours'], 'total' => $r['total'],
        ]);
    }
}
