<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\Project;
use App\Services\ProjectProgressService;
use App\Services\Tables\TableExporter;
use Illuminate\Http\Request;

/** Avance de los proyectos para el gerente de proyecto (aprobador con cuenta y la marca activa). Solo lectura. */
class ApproverProgressController extends Controller
{
    public function __construct(private ProjectProgressService $progress, private TableExporter $exporter)
    {
    }

    public function index(Request $request)
    {
        $a = $this->manager($request);
        return response()->json($this->progress->projectsFor($a)->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'customer' => $p->customer?->name, 'active' => (bool) $p->active])->values());
    }

    public function show(Request $request, Project $project)
    {
        $a = $this->manager($request);
        abort_unless($this->progress->canSee($a, $project), 404);
        return response()->json($this->progress->build($project, $a->company, $a));
    }

    public function export(Request $request, Project $project)
    {
        $a = $this->manager($request);
        abort_unless($this->progress->canSee($a, $project), 404);
        $format = $request->input('format', 'pdf');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422, __('Formato no válido: csv, xlsx o pdf.'));
        $table = $this->progress->tables($this->progress->build($project, $a->company, $a));
        $file = $this->exporter->render($a->company, $table, $format);
        return response($file['body'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"', 'X-Export-Rows' => count($table['rows'])]);
    }

    private function manager(Request $request): Approver
    {
        $a = Approver::with('company')->findOrFail($request->user()->approver_profile_id);
        abort_unless($a->project_manager && $a->active, 403, __('Esta cuenta no es de gerente de proyecto.'));
        return $a;
    }
}
