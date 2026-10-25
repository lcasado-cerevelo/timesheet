<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ClientTimesheetService;
use Illuminate\Http\Request;

/** Hoja de tiempo para entregarle a un cliente. Solo el dueño de la compañía. */
class ClientTimesheetController extends Controller
{
    public function __construct(private ClientTimesheetService $service, private \App\Services\Tables\TableExporter $tables)
    {
    }

    /** Vista previa (datos) de la hoja. */
    public function preview(Request $request)
    {
        [$customer, $v] = $this->input($request);
        return response()->json($this->service->build($request->user()->company, $customer, $v['from'], $v['to'], $v));
    }

    /** Descarga el PDF o el Excel. */
    public function download(Request $request)
    {
        [$customer, $v] = $this->input($request);
        $company = $request->user()->company;
        $data = $this->service->build($company, $customer, $v['from'], $v['to'], $v);
        $base = 'hoja-de-tiempo-'.\Illuminate\Support\Str::slug($customer->name).'-'.$v['from'].'_'.$v['to'];
        if (($v['format'] ?? 'pdf') === 'csv') {
            $f = $this->tables->render($company, $this->service->asTable($data), 'csv');
            return response($f['body'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="'.$base.'.csv"']);
        }
        if (($v['format'] ?? 'pdf') === 'xlsx') {
            $path = $this->service->xlsx($data);
            return response()->download($path, $base.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        }
        return response($this->service->pdf($company, $data), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$base.'.pdf"',
        ]);
    }

    /** Envía el PDF por email a la dirección indicada (por omisión, la del cliente). Solo cuando el dueño lo pide. */
    public function email(Request $request)
    {
        [$customer, $v] = $this->input($request);
        $request->validate(['email_to' => 'nullable|email|max:190', 'message' => 'nullable|string|max:1000']);
        $to = $request->input('email_to') ?: $customer->email;
        if (! $to) {
            return response()->json(['message' => 'Escribe el correo al que se envía; este cliente no tiene uno guardado.', 'errors' => ['email_to' => ['Falta el correo.']]], 422);
        }
        $company = $request->user()->company;
        $data = $this->service->build($company, $customer, $v['from'], $v['to'], $v);
        if (! $data['entry_count']) {
            return response()->json(['message' => 'No hay horas para enviar en ese período.', 'errors' => ['from' => ['No hay horas.']]], 422);
        }
        $this->service->email($company, $data, $to, $request->input('message'), $this->service->pdf($company, $data));
        return response()->json(['ok' => true, 'sent_to' => $to, 'total_hours' => $data['total_hours']]);
    }

    private function input(Request $request): array
    {
        $v = $request->validate([
            'customer_id' => 'required|integer',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'integer',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
            'include_pending' => 'nullable|boolean',
            'include_non_billable' => 'nullable|boolean',
            'show_amounts' => 'nullable|boolean',
            'format' => 'nullable|in:pdf,xlsx,csv',
        ]);
        $customer = Customer::where('company_id', $request->user()->company_id)->findOrFail($v['customer_id']);
        foreach (['include_pending', 'include_non_billable', 'show_amounts'] as $k) {
            $v[$k] = filter_var($v[$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        return [$customer, $v];
    }
}
