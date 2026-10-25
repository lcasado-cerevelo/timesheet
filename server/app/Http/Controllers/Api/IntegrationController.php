<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Integration;
use App\Services\Accountisi\AccountisiClient;
use App\Services\Accountisi\AccountisiException;
use App\Services\Accountisi\SalesInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Módulo de integración (no todas las compañías lo usan). Solo el dueño. */
class IntegrationController extends Controller
{
    public function __construct(private SalesInvoiceService $sales)
    {
    }

    public function show(Request $request)
    {
        $i = Integration::where('company_id', $request->user()->company_id)->where('type', 'accountisi')->first();
        $cfg = $i?->config ?? [];
        // Las credenciales nunca se devuelven; solo si existen.
        return response()->json([
            'enabled' => (bool) $i?->enabled,
            'base_url' => $cfg['base_url'] ?? null,
            'auth_mode' => $cfg['auth_mode'] ?? 'login',
            'login' => $cfg['login'] ?? null,
            'company_id' => $cfg['company_id'] ?? null,
            'has_password' => ! empty($cfg['password']),
            'has_api_key' => ! empty($cfg['api_key']),
            'default_item_id' => $cfg['default_item_id'] ?? null,
            'default_income_account_id' => $cfg['default_income_account_id'] ?? null,
            'default_service_item_id' => $cfg['default_service_item_id'] ?? null,
            'default_expense_account_id' => $cfg['default_expense_account_id'] ?? null,
            'last_ok_at' => $i?->last_ok_at, 'last_error' => $i?->last_error,
        ]);
    }

    public function save(Request $request)
    {
        $v = $request->validate([
            'enabled' => 'boolean',
            'base_url' => ['required', 'url', 'max:200'],
            'auth_mode' => 'required|in:login,api_key',
            'login' => 'nullable|string|max:120',
            'password' => 'nullable|string|max:200',
            'company_id' => 'nullable|integer',
            'api_key' => 'nullable|string|max:300',
            'default_item_id' => 'nullable|integer',
            'default_income_account_id' => 'nullable|integer',
            'default_service_item_id' => 'nullable|integer',
            'default_expense_account_id' => 'nullable|integer',
        ]);
        $i = Integration::firstOrNew(['company_id' => $request->user()->company_id, 'type' => 'accountisi']);
        $old = $i->config ?? [];
        $cfg = collect($v)->except('enabled')->all();
        // Si no se manda de nuevo, se conserva la credencial guardada.
        foreach (['password', 'api_key'] as $secret) {
            if (empty($cfg[$secret])) {
                $cfg[$secret] = $old[$secret] ?? null;
            }
        }
        $i->fill(['enabled' => (bool) ($v['enabled'] ?? false), 'config' => $cfg])->save();
        return $this->show($request);
    }

    public function test(Request $request)
    {
        $i = Integration::where('company_id', $request->user()->company_id)->where('type', 'accountisi')->first();
        abort_unless($i, 404, __('Primero guarda la configuración.'));
        try {
            $me = (new AccountisiClient($i->config, $i->id))->ping();
            $i->update(['last_ok_at' => now(), 'last_error' => null]);
            return response()->json(['ok' => true, 'me' => $me]);
        } catch (AccountisiException $e) {
            $i->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Trae los clientes de Accountisi: vincula por nombre los que ya existen y crea los que faltan. */
    public function syncCustomers(Request $request)
    {
        $client = $this->client($request);
        $created = $linked = 0;
        foreach ($this->guard(fn () => $client->customers()) as $c) {
            $local = Customer::where('company_id', $request->user()->company_id)
                ->where(fn ($q) => $q->where('accountisi_id', $c['id'])->orWhereRaw('lower(name) = ?', [mb_strtolower($c['name'])]))->first();
            if ($local) {
                $local->update(['accountisi_id' => $c['id']]);
                $linked++;
            } else {
                Customer::create(['company_id' => $request->user()->company_id, 'name' => $c['name'], 'email' => $c['email'], 'accountisi_id' => $c['id']]);
                $created++;
            }
        }
        return response()->json(['created' => $created, 'linked' => $linked]);
    }

    public function vendors(Request $request)
    {
        return response()->json($this->guard(fn () => $this->client($request)->vendors()));
    }

    /** Vista previa de la factura (líneas, horas, importe, comentario). No toca Accountisi. */
    public function salesPreview(Request $request)
    {
        [$customer, $v] = $this->salesInput($request);
        return response()->json($this->sales->preview($request->user()->company, $customer, $v['from'], $v['to'], $v['project_ids'] ?? null));
    }

    /** Crea la factura en Accountisi (nueva, o añadida a una abierta del mismo cliente). */
    public function salesCreate(Request $request)
    {
        [$customer, $v] = $this->salesInput($request);
        $client = $this->client($request);
        $sync = $this->guard(fn () => $this->sales->create($request->user()->company, $customer, $v['from'], $v['to'], $v, $client));
        return response()->json($sync, 201);
    }

    private function salesInput(Request $request): array
    {
        $v = $request->validate([
            'customer_id' => 'required|integer',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'project_ids' => 'nullable|array',
            'mode' => 'nullable|in:new,append',
            'accountisi_invoice_id' => 'required_if:mode,append|nullable|string|max:40',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'comment' => 'nullable|string|max:300',
        ]);
        $customer = Customer::where('company_id', $request->user()->company_id)->findOrFail($v['customer_id']);
        return [$customer, $v];
    }

    private function client(Request $request): AccountisiClient
    {
        $c = AccountisiClient::forCompany($request->user()->company);
        if (! $c) {
            throw ValidationException::withMessages(['integration' => __('La integración con Accountisi no está activada.')]);
        }
        return $c;
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AccountisiException $e) {
            throw ValidationException::withMessages(['accountisi' => $e->getMessage()]);
        }
    }
}
