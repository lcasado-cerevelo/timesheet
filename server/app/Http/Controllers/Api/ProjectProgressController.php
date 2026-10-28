<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectProgressService;
use App\Services\Tables\TableExporter;
use Illuminate\Http\Request;

/** Avance de cualquier proyecto de la compañía para el dueño (la misma vista del gerente de proyecto, sin límite de cliente). Solo lectura. */
class ProjectProgressController extends Controller
{
    public function __construct(private ProjectProgressService $progress, private TableExporter $exporter)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->progress->projectsOfCompany($request->user()->company_id)
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'customer' => $p->customer?->name, 'active' => (bool) $p->active])->values());
    }

    public function show(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        return response()->json($this->progress->build($project, $request->user()->company));
    }

    public function export(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless($project->company_id === $user->company_id, 404);
        $format = $request->input('format', 'pdf');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422, __('Formato no válido: csv, xlsx o pdf.'));
        $table = $this->progress->tables($this->progress->build($project, $user->company));
        $file = $this->exporter->render($user->company, $table, $format);
        return response($file['body'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"', 'X-Export-Rows' => count($table['rows'])]);
    }
}
