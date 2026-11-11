<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoursReportService;
use Illuminate\Http\Request;

/** Reporte de horas (web). El dueño y la oficina ven toda la compañía; quien no, solo sus horas. */
class ReportController extends Controller
{
    public function __construct(private HoursReportService $report)
    {
    }

    public function summary(Request $request)
    {
        $d = $request->validate(self::rules());
        $r = $this->report->summary($request->user(), $d);
        return response()->json([
            'from' => $d['from'], 'to' => $d['to'], 'group' => $r['group'], 'rows' => $r['rows'],
            'total_hours' => $r['total']['hours'], 'billable_hours' => $r['total']['billable_hours'], 'total' => $r['total'],
        ]);
    }

    public static function rules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'in:'.implode(',', HoursReportService::GROUPS),
            'project_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
        ];
    }
}
