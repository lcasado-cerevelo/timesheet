<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractorInvoice;
use App\Models\User;
use App\Services\ContractorInvoiceService;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractorInvoiceController extends Controller
{
    public function __construct(private ContractorInvoiceService $service)
    {
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $q = ContractorInvoice::with('user:id,name')->where('company_id', $u->company_id)->orderByDesc('id');
        if (! $u->isOwner()) {
            $q->where('user_id', $u->id);
        }
        // Las facturas con comisión lo dicen: la comisión sale en una línea aparte.
        return response()->json($q->limit(100)->get()->map(fn (ContractorInvoice $i) => $i->toArray()
            + ['has_commission' => collect($i->lines)->contains(fn ($l) => ($l['kind'] ?? null) === 'commission')])->values());
    }

    /** Las comisiones liberadas que entrarán en la próxima factura de esta persona (el dueño puede preguntar por un consultor: user_id). */
    public function pendingCommissions(Request $request)
    {
        $u = $request->user();
        $worker = $u;
        if ($request->query('user_id') && (int) $request->query('user_id') !== $u->id) {
            abort_unless($u->isOwner(), 403);
            $worker = User::where('company_id', $u->company_id)->findOrFail((int) $request->query('user_id'));
        }
        $results = $worker->worker_type === 'consultant' ? $this->service->releasedCommissions($worker) : collect();
        return response()->json(['total' => round((float) $results->sum('total'), 2), 'items' => $results->map(fn ($r) => [
            'project' => $r->project->code.' · '.$r->project->name, 'task' => $r->task->name, 'total' => (float) $r->total, 'withhold' => (bool) $r->withhold])->values()]);
    }

    /** El consultor genera su propia factura; el dueño puede generarla por un consultor (user_id). */
    public function store(Request $request)
    {
        $v = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'user_id' => 'nullable|integer']);
        $u = $request->user();
        $worker = $u;
        if (! empty($v['user_id']) && (int) $v['user_id'] !== $u->id) {
            abort_unless($u->isOwner(), 403);
            $worker = User::where('company_id', $u->company_id)->findOrFail($v['user_id']);
        }
        // Someter la factura se firma con la contraseña de quien la genera.
        app(SignatureService::class)->verifyPassword($request, $u);
        return response()->json($this->service->create($worker, $v['from'], $v['to'], $u, $request), 201);
    }

    public function pdf(Request $request, ContractorInvoice $invoice)
    {
        $this->authorizeView($request, $invoice);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);
        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number.'.pdf');
    }

    public function pushAccountisi(Request $request, ContractorInvoice $invoice)
    {
        abort_unless($invoice->company_id === $request->user()->company_id, 404);
        $this->service->pushToAccountisi($invoice);
        return response()->json($invoice->refresh());
    }

    private function authorizeView(Request $request, ContractorInvoice $invoice): void
    {
        $u = $request->user();
        abort_unless($invoice->company_id === $u->company_id && ($u->isOwner() || $invoice->user_id === $u->id), 403);
    }
}
