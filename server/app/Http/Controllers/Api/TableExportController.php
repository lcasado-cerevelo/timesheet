<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tables\Datasets;
use App\Services\Tables\TableExporter;
use Illuminate\Http\Request;

/**
 * Exporta cualquier tabla de la aplicación a CSV, Excel o PDF (con el logo y los colores de la compañía).
 * GET o POST /api/tables/{dataset}/export?format=csv|xlsx|pdf&...parámetros de esa tabla
 */
class TableExportController extends Controller
{
    public function __construct(private Datasets $datasets, private TableExporter $exporter)
    {
    }

    public function export(Request $request, string $dataset)
    {
        $user = $request->user();
        abort_unless(isset(Datasets::ACCESS[$dataset]), 404, 'Esa tabla no existe.');
        abort_unless($this->datasets->allowed($dataset, $user), 403, 'Solo el dueño de la compañía puede exportar esta tabla.');
        $format = $request->input('format', 'pdf');
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'Formato no válido: csv, xlsx o pdf.');

        $table = $this->datasets->build($dataset, $user, $request->except('format'));
        $file = $this->exporter->render($user->company, $table, $format);
        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'X-Export-Rows' => count($table['rows']),
        ]);
    }
}
