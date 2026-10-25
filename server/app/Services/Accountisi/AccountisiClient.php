<?php

namespace App\Services\Accountisi;

use App\Models\Company;
use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API de Accountisi (contabilidad).
 *
 * Campos y rutas tomados de los tipos de la API instalada (Accountisi.Api.dll):
 *   POST /api/auth/login        { Login, Password, CompanyId? } -> { Token (guid), ExpiresAt, ... }
 *   GET  /api/setup/customers   -> CustomerRow[]      GET /api/setup/vendors -> VendorRow[]
 *   POST /api/invoices          { CustomerId, InvoiceDate, Reference, Description }
 *   POST /api/invoices/{id}/lines { ItemId, Description, Quantity, UnitPrice, DiscountAmount, AccountId }
 *   POST /api/bills             { VendorId, BillDate, VendorInvoiceNumber, Description }
 *   POST /api/bills/{id}/lines  (mismo formato de línea)
 *
 * Dos modos de acceso: "login" (usuario de servicio, funciona hoy) y "api_key" (credencial de servicio,
 * que Accountisi todavía debe añadir; se envía en el encabezado Authorization: Bearer).
 * La URL base es configurable: localhost hoy, el servidor de Amazon mañana.
 */
class AccountisiClient
{
    public function __construct(private array $cfg, private ?int $integrationId = null)
    {
    }

    public static function forCompany(Company $company): ?self
    {
        $i = Integration::where('company_id', $company->id)->where('type', 'accountisi')->where('enabled', true)->first();
        return $i && ! empty($i->config['base_url']) ? new self($i->config, $i->id) : null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->cfg['base_url'], '/'))->acceptJson()->asJson()->timeout(20)
            ->withToken($this->token());
    }

    private function token(): string
    {
        if (($this->cfg['auth_mode'] ?? 'login') === 'api_key') {
            return (string) $this->cfg['api_key'];
        }
        $key = 'accountisi-token-'.md5(($this->cfg['base_url'] ?? '').($this->cfg['login'] ?? '').($this->cfg['company_id'] ?? ''));
        return Cache::remember($key, now()->addMinutes(30), function () {
            $r = Http::baseUrl(rtrim($this->cfg['base_url'], '/'))->acceptJson()->asJson()->timeout(20)
                ->post('/api/auth/login', array_filter([
                    'Login' => $this->cfg['login'] ?? '', 'Password' => $this->cfg['password'] ?? '',
                    'CompanyId' => $this->cfg['company_id'] ?? null,
                ], fn ($v) => $v !== null));
            if (! $r->successful()) {
                throw new AccountisiException('Accountisi rechazó el inicio de sesión ('.$r->status().').');
            }
            $t = $this->pick($r->json(), ['token', 'Token']);
            if (! $t) {
                throw new AccountisiException('Accountisi no devolvió un token de sesión.');
            }
            return (string) $t;
        });
    }

    private function pick(mixed $data, array $names): mixed
    {
        foreach ($names as $n) {
            if (is_array($data) && array_key_exists($n, $data)) {
                return $data[$n];
            }
        }
        return null;
    }

    private function call(string $method, string $path, array $data = []): mixed
    {
        try {
            $r = $this->http()->send($method, $path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new AccountisiException('No se pudo conectar con Accountisi en '.($this->cfg['base_url'] ?? '?').'.');
        }
        if ($r->status() === 401) {
            Cache::forget('accountisi-token-'.md5(($this->cfg['base_url'] ?? '').($this->cfg['login'] ?? '').($this->cfg['company_id'] ?? '')));
            throw new AccountisiException('Accountisi no aceptó la credencial (401).');
        }
        if (! $r->successful()) {
            $msg = $r->json('message') ?? $r->json('detail') ?? $r->json('title') ?? mb_substr($r->body(), 0, 200);
            throw new AccountisiException('Accountisi respondió '.$r->status().': '.$msg);
        }
        return $r->json();
    }

    public function ping(): array
    {
        return (array) $this->call('GET', '/api/me');
    }

    /** @return array<int, array{id:string,code:string,name:string,email:?string}> */
    public function customers(): array
    {
        return collect($this->listAll('/api/setup/customers'))->map(fn ($r) => [
            'id' => (string) ($this->pick($r, ['customerId', 'CustomerId']) ?? ''),
            'code' => (string) ($this->pick($r, ['code', 'Code']) ?? ''),
            'name' => (string) ($this->pick($r, ['name', 'Name']) ?? ''),
            'email' => $this->pick($r, ['email', 'Email']),
        ])->filter(fn ($r) => $r['id'] !== '')->values()->all();
    }

    public function vendors(): array
    {
        return collect($this->listAll('/api/setup/vendors'))->map(fn ($r) => [
            'id' => (string) ($this->pick($r, ['vendorId', 'VendorId']) ?? ''),
            'code' => (string) ($this->pick($r, ['code', 'Code']) ?? ''),
            'name' => (string) ($this->pick($r, ['name', 'Name']) ?? ''),
        ])->filter(fn ($r) => $r['id'] !== '')->values()->all();
    }

    /** Factura de venta en borrador. Devuelve el id. */
    public function createInvoice(int|string $customerId, string $date, string $reference, string $description): string
    {
        $r = $this->call('POST', '/api/invoices', [
            'CustomerId' => (int) $customerId, 'InvoiceDate' => $date, 'Reference' => $reference, 'Description' => $description,
        ]);
        return $this->idFrom($r, ['invoiceId', 'InvoiceId', 'id', 'Id']);
    }

    /** Estado de una factura existente: ['customer_id'=>..., 'status'=>0 borrador...]. */
    public function getInvoice(string $id): array
    {
        $r = $this->call('GET', '/api/invoices/'.$id);
        $h = $this->pick($r, ['cabecera', 'Cabecera']) ?? $r;
        return [
            'customer_id' => (string) $this->pick($h, ['customerId', 'CustomerId']),
            'status' => (int) $this->pick($h, ['status', 'Status']),
            'number' => $this->pick($h, ['invoiceNumber', 'InvoiceNumber']),
            'description' => (string) ($this->pick($h, ['description', 'Description']) ?? ''),
        ];
    }

    public function updateInvoiceDescription(string $id, string $description): void
    {
        $this->call('PUT', '/api/invoices/'.$id, ['Description' => $description]);
    }

    public function addInvoiceLine(string $invoiceId, array $line): void
    {
        $this->call('POST', '/api/invoices/'.$invoiceId.'/lines', $this->line($line));
    }

    public function createBill(int|string $vendorId, string $date, string $vendorInvoiceNumber, string $description): string
    {
        $r = $this->call('POST', '/api/bills', [
            'VendorId' => (int) $vendorId, 'BillDate' => $date, 'VendorInvoiceNumber' => $vendorInvoiceNumber, 'Description' => $description,
        ]);
        return $this->idFrom($r, ['billId', 'BillId', 'id', 'Id']);
    }

    public function addBillLine(string $billId, array $line): void
    {
        $this->call('POST', '/api/bills/'.$billId.'/lines', $this->line($line));
    }

    /**
     * Las listas de Accountisi vienen como { documentos: [...] } y cada fila trae `total` (el conteo completo).
     * Si faltan filas, se piden las páginas que siguen.
     */
    private function listAll(string $path): array
    {
        $out = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->call('GET', $path, ['page' => $page, 'pageSize' => 500]);
            $rows = $this->pick($r, ['documentos', 'items', 'rows']) ?? (is_array($r) && array_is_list($r) ? $r : []);
            if (! $rows) {
                break;
            }
            $before = count($out);
            foreach ($rows as $row) {
                $key = $this->pick($row, ['customerId', 'vendorId', 'id']) ?? md5(json_encode($row));
                $out[$key] = $row; // si el servidor ignora la página, no se duplica
            }
            $total = (int) ($this->pick($rows[0], ['total', 'Total']) ?? count($rows));
            if (count($out) >= $total || count($out) === $before) {
                break;
            }
        }
        return array_values($out);
    }

    private function line(array $l): array
    {
        return array_filter([
            'ItemId' => $l['item_id'] ?? null, 'AccountId' => $l['account_id'] ?? null,
            'Description' => $l['description'], 'Quantity' => round((float) $l['quantity'], 4),
            'UnitPrice' => round((float) $l['unit_price'], 4), 'DiscountAmount' => 0,
        ], fn ($v) => $v !== null);
    }

    private function idFrom(mixed $r, array $names): string
    {
        $id = $this->pick($r, $names) ?? $this->pick($this->pick($r, ['cabecera', 'Cabecera']) ?? [], $names);
        if ($id === null && is_scalar($r)) {
            $id = $r;
        }
        if ($id === null) {
            throw new AccountisiException('Accountisi no devolvió el id del documento creado.');
        }
        return (string) $id;
    }
}
