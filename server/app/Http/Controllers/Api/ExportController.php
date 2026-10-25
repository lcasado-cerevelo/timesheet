<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExportTemplate;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller
{
    public function __construct(private ExportService $exports)
    {
    }

    public function templates(Request $request)
    {
        $this->exports->ensureSystemTemplates($request->user()->company);
        return response()->json(
            ExportTemplate::where('company_id', $request->user()->company_id)->orderByDesc('is_system')->orderBy('name')->get()
        );
    }

    public function save(Request $request, ?ExportTemplate $template = null)
    {
        $cid = $request->user()->company_id;
        if ($template) {
            abort_unless($template->company_id === $cid, 404);
            abort_if($template->is_system, 422, 'Las plantillas del sistema no se editan; duplícala.');
        }
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'format' => 'required|in:xlsx,csv',
            'config' => 'required|array',
            'config.sheet_name' => 'nullable|string|max:60',
            'config.group_by' => 'nullable|array',
            'config.columns' => 'required|array|min:1|max:40',
        ]);
        $errors = $this->exports->validateConfig($v['config']);
        if ($errors) {
            throw ValidationException::withMessages(['config' => $errors]);
        }
        $slug = Str::slug($v['name']);
        $payload = ['name' => $v['name'], 'format' => $v['format'], 'config' => $v['config']];
        $tpl = $template
            ? tap($template)->update($payload)
            : ExportTemplate::create($payload + ['company_id' => $cid, 'slug' => $slug.'-'.Str::lower(Str::random(4)), 'is_system' => false]);
        return response()->json($tpl, $template ? 200 : 201);
    }

    public function destroy(Request $request, ExportTemplate $template)
    {
        abort_unless($template->company_id === $request->user()->company_id, 404);
        abort_if($template->is_system, 422, 'Las plantillas del sistema no se borran.');
        $template->delete();
        return response()->json(['ok' => true]);
    }

    /** Genera y descarga el archivo. Una persona sin rol de dueño solo exporta sus propias horas. */
    public function export(Request $request)
    {
        $v = $request->validate([
            'template_id' => 'required|integer',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'user_id' => 'nullable|integer',
            'project_ids' => 'nullable|array',
        ]);
        $tpl = ExportTemplate::where('company_id', $request->user()->company_id)->findOrFail($v['template_id']);
        $file = $this->exports->generate($tpl, $request->user(), $v['from'], $v['to'], $v);
        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => $file['mime'], 'X-Export-Rows' => $file['rows'], 'X-Export-Hash' => $file['hash'],
        ])->deleteFileAfterSend(true);
    }
}
