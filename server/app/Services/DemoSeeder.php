<?php

namespace App\Services;

use App\Models\Approver;
use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\Company;
use App\Models\ContractorInvoice;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Service;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Demo;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Siembra del sitio de demostración. Todo pasa por los servicios de la aplicación (EntryService, SheetService, los servicios de aprobación,
 * SignatureService, CommissionService, ContractorInvoiceService) mientras el reloj avanza semana a semana (Carbon::setTestNow), así las reglas,
 * las firmas, las fechas de envío y decisión, la tardanza y los totales son los reales. Las fechas son relativas a hoy: las últimas 8 semanas
 * más la actual. Estados que no se alcanzan con los servicios (lo mínimo que se inserta a mano): la factura "pagada" (no hay pantalla que la marque).
 */
class DemoSeeder
{
    public const WEEKS = 8;

    private Company $company;
    private Request $request;
    private CarbonImmutable $real;
    private CarbonImmutable $curStart;
    private string $tz = 'America/Puerto_Rico';
    private int $seed = 20260101;

    /** @var array<string,User> */ private array $u = [];
    /** @var array<string,Approver> */ private array $ap = [];
    /** @var array<string,Project> */ private array $p = [];
    /** @var array<string,Service> */ private array $s = [];
    /** @var array<string,Task> */ private array $t = [];
    /** @var array<string,array<int,Task>> tareas que nunca se completan: reciben el trabajo normal del proyecto */
    private array $perm = [];
    /** @var array<int,string> avisos de lo que no se pudo sembrar */
    public array $notes = [];

    public function __construct(
        private EntryService $entries, private SheetService $sheets, private InternalApprovalService $internal, private ExternalApprovalService $external,
        private SignatureService $sigs, private CommissionService $commissions, private ContractorInvoiceService $invoices,
    ) {
    }

    // ------------------------------------------------------------------------------------------------ entrada y salida

    /** @return array<string,int> conteos de lo sembrado */
    public function run(string $brand = 'cerevelo', bool $fresh = false): array
    {
        $this->quietMail();
        if ($fresh) {
            $this->wipe();
        }
        $existing = Company::where('slug', Demo::COMPANY_SLUG)->first();
        if ($existing) {
            $this->company = $existing;
            $this->applyBrand($brand);   // idempotente: no se duplica nada, solo se actualiza la marca
            return $this->counts();
        }
        $this->tz = 'America/Puerto_Rico';
        $this->real = CarbonImmutable::now($this->tz);
        $this->curStart = $this->real->startOfDay()->startOfWeek(Carbon::SUNDAY);
        $this->request = Request::create('/demo', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Demo seeder']);
        try {
            $this->at($this->weekStart(self::WEEKS)->setTime(8, 0));
            $this->company();
            $this->applyBrand($brand);
            $this->people();
            $this->projects();
            $this->history();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
        return $this->counts();
    }

    /** Borra solo la compañía demo y todo lo suyo. */
    public function wipe(): void
    {
        $company = Company::where('slug', Demo::COMPANY_SLUG)->first();
        if (! $company) {
            return;
        }
        $cid = $company->id;
        DB::transaction(function () use ($cid, $company) {
            foreach (['signatures', 'commission_results', 'commissions', 'time_entries', 'timesheets', 'contractor_invoices', 'sales_invoice_syncs', 'export_logs', 'export_templates',
                'integrations', 'security_events', 'brand_history'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) {
                    DB::table($table)->where('company_id', $cid)->delete();
                }
            }
            // time_entries.task_id / service_id restringen el borrado: ya no hay horas, ahora caen tareas, servicios y proyectos.
            DB::table('tasks')->where('company_id', $cid)->delete();
            DB::table('services')->where('company_id', $cid)->delete();
            DB::table('projects')->where('company_id', $cid)->delete();
            DB::table('customers')->where('company_id', $cid)->delete();
            $userIds = DB::table('users')->where('company_id', $cid)->pluck('id');
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('users')->where('company_id', $cid)->delete();
            DB::table('approvers')->where('company_id', $cid)->delete();
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            DB::table('companies')->where('id', $cid)->delete();
        });
        Storage::disk('local')->deleteDirectory('invoices/'.$cid);
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $cid = $this->company->id;
        $status = fn (string $s) => Timesheet::where('company_id', $cid)->where('status', $s)->count();
        return [
            'users' => User::where('company_id', $cid)->count(),
            'approvers' => Approver::where('company_id', $cid)->count(),
            'customers' => Customer::where('company_id', $cid)->count(),
            'projects' => Project::where('company_id', $cid)->count(),
            'services' => Service::where('company_id', $cid)->count(),
            'tasks' => Task::where('company_id', $cid)->count(),
            'timesheets' => Timesheet::where('company_id', $cid)->count(),
            'draft' => $status('draft'), 'submitted' => $status('submitted'), 'approved' => $status('approved'), 'returned' => $status('returned'),
            'late' => Timesheet::where('company_id', $cid)->where('late', true)->count(),
            'parts' => Timesheet::where('company_id', $cid)->whereNotNull('period_start')->count(),
            'entries' => TimeEntry::where('company_id', $cid)->count(),
            'signatures' => \App\Models\Signature::where('company_id', $cid)->count(),
            'invoices' => ContractorInvoice::where('company_id', $cid)->count(),
            'commissions' => Commission::where('company_id', $cid)->count(),
            'commission_results' => CommissionResult::where('company_id', $cid)->count(),
        ];
    }

    // ------------------------------------------------------------------------------------------------ reloj y azar

    private function weekStart(int $k): CarbonImmutable
    {
        return $this->curStart->subWeeks($k);
    }

    /** El reloj de la aplicación (nunca después de ahora mismo). */
    private function at(CarbonImmutable $d): void
    {
        $d = $d->greaterThan($this->real) ? $this->real : $d;
        $c = Carbon::createFromTimestamp($d->getTimestamp(), $this->tz);
        Carbon::setTestNow($c);
        CarbonImmutable::setTestNow($c);
    }

    /** Día $dow (0 = domingo) de la semana $k, a esa hora. */
    private function day(int $k, int $dow, string $time = '10:00'): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $this->weekStart($k)->addDays($dow)->setTime($h, $m);
    }

    private function rand(int $min, int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) & 0x7fffffff;
        return $min + intdiv($this->seed >> 8, 1) % ($max - $min + 1);
    }

    private function pick(array $list)
    {
        return $list[$this->rand(0, count($list) - 1)];
    }

    /** Correo: en la siembra no sale nada (un transporte que se traga el mensaje). */
    private function quietMail(): void
    {
        Mail::extend('demo-null', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
            }

            public function __toString(): string
            {
                return 'demo-null';
            }
        });
        config(['mail.mailers.demo-null' => ['transport' => 'demo-null'], 'mail.default' => 'demo-null']);
        Mail::purge();
    }

    // ------------------------------------------------------------------------------------------------ compañía y marca

    private function company(): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: Demo::DOMAIN;
        $this->company = Company::create([
            'slug' => Demo::COMPANY_SLUG, 'name' => 'Cerevelo', 'timezone' => $this->tz, 'locale' => 'es', 'invoice_prefix' => 'DEM',
            'domain' => in_array($host, ['localhost', '127.0.0.1'], true) ? null : strtolower($host),
            'rules' => ['week_starts_on' => 0, 'partial_submission' => true, 'flag_late' => true, 'weeks_back' => self::WEEKS, 'deadline_dow' => 1,
                'deadline_time' => '12:00', 'increment' => 0.25, 'remember_days' => 0],
        ]);
    }

    private function applyBrand(string $brand): void
    {
        if ($brand === 'amsi') {
            $changes = ['name' => 'AMSI', 'brand_name' => 'AMSI', 'color_primary' => '#0b5e8c', 'color_accent' => '#e07a1f', 'show_brand_name' => true, 'email_footer' => 'AMSI · Demostración'];
            $logo = null;
        } else {
            $changes = ['name' => 'Cerevelo', 'brand_name' => 'Cerevelo', 'color_primary' => '#1f4e79', 'color_accent' => '#14603B', 'show_brand_name' => true, 'email_footer' => 'Cerevelo · Demostración'];
            $logo = base_path('../branding/icono-anillo-check.png');
        }
        if ($this->company->logo_path) {
            Storage::disk('public')->delete($this->company->logo_path);
        }
        $changes['logo_path'] = null;
        if ($logo && is_file($logo)) {
            $path = 'logos/'.$this->company->id.'/demo.png';
            Storage::disk('public')->put($path, file_get_contents($logo));
            $changes['logo_path'] = $path;
        }
        $this->company->update($changes);
    }

    // ------------------------------------------------------------------------------------------------ personas

    private function user(string $key, string $name, string $email, array $over = []): User
    {
        $u = User::create($over + [
            'company_id' => $this->company->id, 'name' => $name, 'email' => $email, 'password' => Demo::PASSWORD, 'must_set_password' => false,
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'active' => true,
        ]);
        return $this->u[$key] = $u;
    }

    private function approver(string $key, string $name, string $email, array $customers, bool $account, bool $manager = false): Approver
    {
        $a = Approver::create(['company_id' => $this->company->id, 'name' => $name, 'email' => $email, 'require_code' => false, 'active' => true,
            'can_login' => $account, 'project_manager' => $manager]);
        $this->ap[$key] = $a;
        $a->customers()->sync(array_map(fn ($c) => $this->customers[$c]->id, $customers));
        if ($account) {
            User::create(['company_id' => $this->company->id, 'name' => $name, 'email' => $email, 'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day',
                'password' => Demo::PASSWORD, 'must_set_password' => false, 'approver_profile_id' => $a->id, 'active' => true]);
        }
        return $a;
    }

    /** @var array<string,Customer> */
    private array $customers = [];

    private function people(): void
    {
        $cid = $this->company->id;
        foreach (['AMSI' => 'amsi@cliente.demo', 'CARIBE' => 'contabilidad@caribelogistica.demo', 'BORINQUEN' => 'compras@grupoborinquen.demo'] as $k => $mail) {
            $this->customers[$k] = Customer::create(['company_id' => $cid, 'name' => ['AMSI' => 'AMSI', 'CARIBE' => 'Caribe Logística', 'BORINQUEN' => 'Grupo Borinquen'][$k], 'email' => $mail]);
        }
        // Externos: tres con cuenta (uno es el gerente de proyecto del cliente) y uno que solo decide por el enlace del correo.
        $this->approver('elena', 'Elena Robles', 'elena.robles@demo.cerevelo.com', ['AMSI'], true, true);
        $this->approver('nelson', 'Nelson Báez', 'nelson.baez@demo.cerevelo.com', ['CARIBE'], true);
        $this->approver('gloria', 'Gloria Pagán', 'gloria.pagan@demo.cerevelo.com', ['BORINQUEN'], true);
        $this->approver('yolanda', 'Yolanda Reyes', 'yolanda.reyes@amsi.demo', ['AMSI'], false);

        $this->user('owner', 'Marisol Vega', 'dueno@demo.cerevelo.com', ['role' => 'owner', 'capture_mode' => 'full_day']);
        $this->user('office', 'Carlos Rivera', 'oficina@demo.cerevelo.com', ['role' => 'office', 'capture_mode' => 'full_day']);
        $this->user('ana', 'Ana Torres', 'ana.torres@demo.cerevelo.com', ['weekly_hours' => 40, 'employee_number' => 'E-101', 'capture_mode' => 'full_day']);
        $this->user('jorge', 'Jorge Medina', 'jorge.medina@demo.cerevelo.com', ['weekly_hours' => 40, 'employee_number' => 'E-102']);
        $this->user('lucia', 'Lucía Ortiz', 'lucia.ortiz@demo.cerevelo.com', ['weekly_hours' => 32, 'employee_number' => 'E-103', 'capture_mode' => 'full_day']);
        $this->user('patricia', 'Patricia Colón', 'patricia.colon@demo.cerevelo.com', ['weekly_hours' => 40, 'employee_number' => 'E-201', 'capture_mode' => 'full_day']);
        $this->user('raul', 'Raúl Acosta', 'raul.acosta@demo.cerevelo.com', ['weekly_hours' => 40, 'employee_number' => 'E-202', 'capture_mode' => 'full_day']);
        foreach ([['ricardo', 'Ricardo Cruz', 45, 10, false], ['sofia', 'Sofía Méndez', 60, 10, true], ['hector', 'Héctor Rosario', 38, 7, false], ['valeria', 'Valeria Núñez', 75, 10, true]] as [$k, $n, $rate, $ret, $ivu]) {
            $mail = str_replace(' ', '.', strtolower(\Illuminate\Support\Str::ascii($n))).'@demo.cerevelo.com';
            $u = $this->user($k, $n, $mail, ['worker_type' => 'consultant']);
            ContractorTerms::create(['user_id' => $u->id, 'rate' => $rate, 'retention_pct' => $ret, 'ivu_agent' => $ivu, 'ivu_pct' => $ivu ? 4 : 0, 'terms_days' => 30]);
        }
        // Las cuentas se crearon hace un tiempo, antes de la primera semana de la demo.
        User::where('company_id', $cid)->update(['created_at' => $this->weekStart(self::WEEKS + 1)]);
    }

    // ------------------------------------------------------------------------------------------------ proyectos, servicios y tareas

    private function service(Project $p, string $name, string $mode, string $approval, ?float $rate, array $int = [], array $ext = [], bool $billable = true, bool $note = false, ?Service $reuse = null): Service
    {
        $attrs = ['name' => $name, 'capture_mode' => $mode, 'approval_mode' => $approval, 'bill_rate' => $rate, 'billable' => $billable, 'require_note' => $note, 'active' => true];
        $s = $reuse ? tap($reuse)->update($attrs) : Service::create($attrs + ['company_id' => $this->company->id, 'project_id' => $p->id]);
        $s->internalApprovers()->sync(array_map(fn ($k) => $this->u[$k]->id, $int));
        $s->externalApprovers()->sync(array_map(fn ($k) => $this->ap[$k]->id, $ext));
        return $this->s[$p->code.':'.$name] = $s;
    }

    private function project(string $code, string $name, ?string $customer, ?float $rate, ?float $budget, array $users, bool $courtesy = false, bool $billable = true): Project
    {
        $p = Project::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'customer_id' => $customer ? $this->customers[$customer]->id : null,
            'billable' => $billable, 'bill_rate' => $rate, 'budget_hours' => $budget, 'show_courtesy' => $courtesy, 'active' => true]);
        $p->users()->sync(array_map(fn ($k) => $this->u[$k]->id, $users));
        return $this->p[$code] = $p;
    }

    private function task(string $project, string $name, ?float $budget, int|string|null $end, ?float $goal, bool $commissionable, bool $permanent = false): Task
    {
        $t = Task::create(['company_id' => $this->company->id, 'project_id' => $this->p[$project]->id, 'name' => $name, 'active' => true, 'budget_hours' => $budget,
            'estimated_end' => is_string($end) ? $end : ($end === null ? null : $this->real->startOfDay()->addDays($end)->toDateString()), 'savings_goal_pct' => $goal, 'commissionable' => $commissionable]);
        if ($permanent) {
            $this->perm[$project][] = $t;
        }
        return $this->t[$project.':'.$name] = $t;
    }

    private function projects(): void
    {
        $this->project('AMSI-PORT', 'Portal de pacientes', 'AMSI', 85, 500, ['ricardo', 'sofia', 'ana', 'patricia']);
        $first = fn (string $code) => $this->p[$code]->services()->first();
        $this->service($this->p['AMSI-PORT'], 'Desarrollo', 'detail', 'both', 85, ['patricia'], ['elena'], true, true, $first('AMSI-PORT'));
        $this->service($this->p['AMSI-PORT'], 'Soporte', 'total', 'external', 60, [], ['elena']);
        $this->service($this->p['AMSI-PORT'], 'Gestión', 'detail', 'internal', 70, ['raul']);

        $this->project('AMSI-MIG', 'Migración de datos', 'AMSI', 80, 300, ['sofia', 'jorge', 'raul'], true);
        $this->service($this->p['AMSI-MIG'], 'Análisis', 'detail', 'both', 80, ['raul'], ['elena'], true, false, $first('AMSI-MIG'));
        $this->service($this->p['AMSI-MIG'], 'Pruebas', 'detail', 'external', 70, [], ['yolanda']);
        $this->service($this->p['AMSI-MIG'], 'Garantía', 'detail', 'external', null, [], ['yolanda'], false);

        $this->project('CAR-LOG', 'Rastreo de flota', 'CARIBE', 75, 400, ['hector', 'jorge', 'patricia', 'raul']);
        $this->service($this->p['CAR-LOG'], 'Desarrollo', 'detail', 'internal', 75, ['patricia', 'raul'], [], true, false, $first('CAR-LOG'));
        $this->service($this->p['CAR-LOG'], 'Mantenimiento', 'total', 'external', 55, [], ['nelson']);

        $this->project('CAR-WEB', 'Sitio web corporativo', 'CARIBE', 60, 150, ['ricardo']);
        $this->service($this->p['CAR-WEB'], 'General', 'total', 'external', null, [], ['nelson'], true, false, $first('CAR-WEB'));

        $this->project('BOR-ERP', 'Implementación ERP', 'BORINQUEN', 90, 700, ['valeria', 'lucia', 'raul']);
        $this->service($this->p['BOR-ERP'], 'Consultoría', 'detail', 'both', 90, ['raul'], ['gloria'], true, false, $first('BOR-ERP'));
        $this->service($this->p['BOR-ERP'], 'Capacitación', 'total', 'external', 65, [], ['gloria']);

        $this->project('INT-ADM', 'Administración interna', null, null, null, ['ana', 'jorge', 'lucia', 'patricia', 'raul'], false, false);
        $this->service($this->p['INT-ADM'], 'General', 'total', 'none', null, [], [], false, false, $first('INT-ADM'));

        // Tareas: unas permanentes (reciben el trabajo normal), otras con guion (comisiones, completadas) y metas de ahorro.
        $this->task('AMSI-PORT', 'Módulo de citas', 120, 14, 15, false, true);
        $this->task('AMSI-PORT', 'Integración con laboratorio', 60, 21, 20, true, true);
        $this->task('AMSI-PORT', 'Migración de usuarios', 40, $this->weekStart(4)->addDays(6)->toDateString(), 20, true);
        $this->task('AMSI-PORT', 'Reportes clínicos', 80, $this->weekStart(3)->subDay()->toDateString(), 10, true);
        $this->task('AMSI-MIG', 'Mapeo de datos', 60, 12, 10, false, true);
        $this->task('AMSI-MIG', 'Carga inicial', 50, 20, null, false, true);
        $this->task('CAR-LOG', 'App de choferes', 90, 25, 10, false, true);
        $this->task('BOR-ERP', 'Módulo de facturación', 140, 20, 15, true, true);
        $this->task('BOR-ERP', 'Capacitación de usuarios', 48, 28, null, false, true);
        $this->task('BOR-ERP', 'Módulo de inventario', 100, $this->weekStart(2)->addDays(6)->toDateString(), 15, true);
        $this->task('BOR-ERP', 'Auditoría inicial', 30, $this->weekStart(5)->addDays(6)->toDateString(), null, false);

        // Comisiones: una por persona y proyecto.
        $this->commission('ricardo', 'AMSI-PORT', ['Migración de usuarios', 'Reportes clínicos', 'Integración con laboratorio'], 40, 5, 'fixed', 50, 10, 0);
        $this->commission('sofia', 'AMSI-PORT', ['Migración de usuarios', 'Reportes clínicos', 'Integración con laboratorio'], 30, 4, 'assigned', 0, 10, 0, 'assigned');
        $this->commission('ana', 'AMSI-PORT', ['Migración de usuarios'], 10, 3, 'actual', 0, 10, 0);
        $this->commission('valeria', 'BOR-ERP', ['Módulo de inventario', 'Módulo de facturación'], 50, 6, 'fixed', 60, 12, 2);
        $this->commission('lucia', 'BOR-ERP', ['Módulo de inventario'], 20, 3, 'assigned', 0, 12, 0);
    }

    private function commission(string $user, string $project, array $tasks, float $minPct, float $extra, string $mode, float $share, float $perHour, float $tolerance, string $basis = 'worked'): void
    {
        $c = Commission::create(['company_id' => $this->company->id, 'user_id' => $this->u[$user]->id, 'project_id' => $this->p[$project]->id, 'percent_required' => $minPct,
            'extra_per_hour' => $extra, 'commission_basis' => $basis, 'savings_mode' => $mode, 'savings_pct' => $share, 'savings_per_hour' => $perHour, 'tolerance_hours' => $tolerance,
            'withhold' => true, 'ivu' => false, 'active' => true]);
        $c->tasks()->sync(array_map(fn ($n) => $this->t[$project.':'.$n]->id, $tasks));
    }

    // ------------------------------------------------------------------------------------------------ horas de cada persona

    /** Trabajo normal de cada persona: [proyecto, servicio, días (1 = lunes … 5 = viernes), horas por día, hora de inicio]. */
    private function slots(string $who): array
    {
        return match ($who) {
            'ricardo' => [['AMSI-PORT', 'Desarrollo', [1, 2, 3, 4], 6, '08:00'], ['CAR-WEB', 'General', [5], 7, null]],
            'sofia' => [['AMSI-PORT', 'Desarrollo', [1, 2, 3], 5, '09:00'], ['AMSI-MIG', 'Análisis', [4], 6, '08:00'], ['AMSI-MIG', 'Pruebas', [5], 4, '13:00'], ['AMSI-MIG', 'Garantía', [5], 2, '17:30']],
            'hector' => [['CAR-LOG', 'Desarrollo', [1, 2, 3], 6, '08:00'], ['CAR-LOG', 'Mantenimiento', [4, 5], 4, null]],
            'valeria' => [['BOR-ERP', 'Consultoría', [1, 2, 3, 4], 7, '08:00'], ['BOR-ERP', 'Capacitación', [5], 6, null]],
            'ana' => [['AMSI-PORT', 'Soporte', [1, 2, 3, 4, 5], 6, null], ['INT-ADM', 'General', [1, 3], 2, null]],
            'jorge' => [['CAR-LOG', 'Mantenimiento', [1, 2, 3], 8, null], ['AMSI-MIG', 'Pruebas', [4, 5], 8, '08:00']],
            'lucia' => [['BOR-ERP', 'Capacitación', [1, 2, 3, 4], 8, null]],
            'patricia', 'raul' => [['INT-ADM', 'General', [1, 2, 3, 4, 5], 8, null]],
            default => [],
        };
    }

    /** Trabajo con guion (tareas con comisión): [semana k, persona, día, proyecto, servicio, tarea, inicio, horas]. */
    private function scripted(): array
    {
        $mig = ['AMSI-PORT', 'Desarrollo', 'Migración de usuarios'];
        $rep = ['AMSI-PORT', 'Desarrollo', 'Reportes clínicos'];
        $lab = ['AMSI-PORT', 'Desarrollo', 'Integración con laboratorio'];
        $inv = ['BOR-ERP', 'Consultoría', 'Módulo de inventario'];
        $aud = ['BOR-ERP', 'Consultoría', 'Auditoría inicial'];
        return [
            // Migración de usuarios (meta 32 h): el equipo hace 28 h, ahorra 4 y cobra bono. Se completa en la semana 5.
            [7, 'ricardo', 6, ...$mig, '14:00', 4], [6, 'ricardo', 6, ...$mig, '14:00', 10], [7, 'sofia', 0, ...$mig, '15:00', 3], [6, 'sofia', 0, ...$mig, '15:00', 7],
            [6, 'ana', 6, ...$mig, '10:00', 4],
            // Reportes clínicos (meta 72 h): el equipo hace 80 h, se pasa de la meta y nadie cobra. Se completa en la semana 4.
            [8, 'ricardo', 6, ...$rep, '10:00', 12], [7, 'ricardo', 0, ...$rep, '14:00', 8], [6, 'ricardo', 0, ...$rep, '14:00', 6], [5, 'ricardo', 6, ...$rep, '10:00', 12],
            [8, 'sofia', 6, ...$rep, '08:00', 14], [7, 'sofia', 6, ...$rep, '08:00', 12], [6, 'sofia', 6, ...$rep, '08:00', 8], [5, 'sofia', 6, ...$rep, '08:00', 8],
            // Módulo de inventario (meta 85 h): el equipo hace 76 h y cobra. Se completa en la semana 3.
            [7, 'valeria', 6, ...$inv, '08:00', 14], [6, 'valeria', 6, ...$inv, '08:00', 14], [5, 'valeria', 6, ...$inv, '08:00', 14], [4, 'valeria', 6, ...$inv, '08:00', 14],
            [6, 'lucia', 6, 'BOR-ERP', 'Capacitación', 'Módulo de inventario', null, 8], [5, 'lucia', 6, 'BOR-ERP', 'Capacitación', 'Módulo de inventario', null, 8],
            [4, 'lucia', 6, 'BOR-ERP', 'Capacitación', 'Módulo de inventario', null, 4],
            // Auditoría inicial (sin comisión): completada en la semana 6.
            [8, 'valeria', 6, ...$aud, '08:00', 10], [8, 'valeria', 0, ...$aud, '08:00', 8], [7, 'valeria', 0, ...$aud, '08:00', 8],
            // Integración con laboratorio (comisión abierta): se ve el avance en "Mis comisiones".
            [3, 'ricardo', 6, ...$lab, '10:00', 6], [2, 'ricardo', 6, ...$lab, '10:00', 6], [2, 'sofia', 6, ...$lab, '10:00', 5], [1, 'sofia', 6, ...$lab, '10:00', 4],
        ];
    }

    /** Horas especiales de empleados: [semana k, persona, día, tipo, horas, nota]. Van a Administración interna. */
    private function specials(): array
    {
        return [[5, 'ana', 5, 'vac', 8, 'Vacaciones'], [5, 'ana', 4, 'vac', 8, 'Vacaciones'], [4, 'lucia', 1, 'hol', 8, 'Feriado'], [3, 'jorge', 3, 'sick', 8, 'Cita médica'],
            [6, 'ana', 2, 'ot', 3, 'Tiempo extra por cierre de mes']];
    }

    private const NOTES = [
        'Reunión de seguimiento con el cliente y ajustes al alcance.', 'Pruebas de regresión y corrección de errores encontrados.', 'Revisión de requisitos y documentación.',
        'Soporte a usuarios y atención de tickets.', 'Configuración del ambiente de pruebas.', 'Preparación de la presentación para el comité.',
        'Desarrollo de la funcionalidad acordada en el sprint y revisión del código con el equipo. Se dejaron pendientes dos casos de borde que se atenderán mañana con el cliente presente para confirmar el comportamiento esperado.',
        'Se trabajó en la integración con el sistema del cliente: autenticación, mapeo de campos y manejo de errores. Hubo que repetir la carga de prueba porque el ambiente del cliente estuvo caído durante la mañana; se coordinó con su equipo de infraestructura y se retomó en la tarde. Quedó documentado en el tablero del proyecto.',
        'Capacitación a los usuarios finales.', 'Análisis de datos y validación de resultados.', 'Ajustes de diseño según comentarios.', 'Despliegue y verificación posterior.',
    ];

    /** Las entradas de una persona en una semana, en orden de fecha. Cada una: arreglo listo para EntryService::save. */
    private function weekSpecs(string $who, int $k, ?int $fromDow = null, ?int $toDow = null): array
    {
        $user = $this->u[$who];
        $out = [];
        $add = function (int $dow, string $proj, string $svc, ?Task $task, ?string $start, float $hours, ?string $note, string $type = 'reg') use (&$out, $user, $k) {
            $date = $this->weekStart($k)->addDays($dow)->toDateString();
            $service = $this->s[$proj.':'.$svc];
            $data = ['project_id' => $this->p[$proj]->id, 'service_id' => $service->id, 'task_id' => $task?->id, 'work_date' => $date, 'note' => $note];
            if ($type !== 'reg') {
                $data['hour_type'] = $type;
            }
            if ($start !== null && $service->capture_mode !== 'total') {
                $break = $hours >= 6 ? 30 : 0;
                $startMin = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
                $span = (int) round($hours * 60) + $break;
                if ($startMin + $span > 22 * 60) {
                    $startMin = max(5 * 60, 22 * 60 - $span);   // que la entrada termine antes de las 10 pm
                }
                $endMin = $startMin + $span;
                $start = sprintf('%02d:%02d', intdiv($startMin, 60), $startMin % 60);
                $data += ['start_time' => $start, 'end_time' => sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60), 'break_minutes' => $break];
            } else {
                $data['hours'] = $hours;
            }
            $out[] = $data;
        };
        foreach ($this->slots($who) as [$proj, $svc, $days, $hours, $start]) {
            foreach ($days as $dow) {
                if ($this->rand(1, 100) <= 7) {
                    continue;   // algún día sin registrar
                }
                $h = max(1, $hours + $this->pick([0, 0, 0.25, -0.5, 0.5, -0.25]));
                $needsNote = $this->s[$proj.':'.$svc]->require_note;
                $note = $needsNote || $this->rand(1, 100) <= 45 ? $this->pick(self::NOTES) : null;
                $tasks = $this->perm[$proj] ?? [];
                $task = $tasks ? $tasks[$this->rand(0, count($tasks) - 1)] : null;
                $add($dow, $proj, $svc, $task, $start, $h, $note);
            }
        }
        foreach ($this->scripted() as [$wk, $person, $dow, $proj, $svc, $task, $start, $hours]) {
            if ($wk === $k && $person === $who) {
                $add($dow, $proj, $svc, $this->t[$proj.':'.$task], $start, $hours, 'Trabajo de la tarea «'.$task.'».');
            }
        }
        foreach ($this->specials() as [$wk, $person, $dow, $type, $hours, $note]) {
            if ($wk === $k && $person === $who) {
                $add($dow, 'INT-ADM', 'General', null, null, $hours, $note, $type);
            }
        }
        usort($out, fn ($a, $b) => [$a['work_date'], $a['start_time'] ?? ''] <=> [$b['work_date'], $b['start_time'] ?? '']);
        $startDate = $fromDow === null ? null : $this->weekStart($k)->addDays($fromDow)->toDateString();
        $endDate = $toDow === null ? null : $this->weekStart($k)->addDays($toDow)->toDateString();
        return array_values(array_filter($out, fn ($d) => ($startDate === null || $d['work_date'] >= $startDate) && ($endDate === null || $d['work_date'] <= $endDate)));
    }

    /** Guarda las entradas con el servicio real (reglas, tarifas, hoja del borrador). Una entrada que la regla rechaza se omite y se anota. */
    private function logHours(string $who, array $specs): void
    {
        $now = Carbon::now()->toDateString();
        foreach ($specs as $data) {
            if ($data['work_date'] > $now) {
                continue;
            }
            try {
                $this->entries->save($this->u[$who], array_filter($data, fn ($v) => $v !== null));
            } catch (\Throwable $e) {
                $this->notes[] = $who.' '.$data['work_date'].': '.$e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ envío y decisiones

    private function draft(string $who, int $k): ?Timesheet
    {
        return Timesheet::where('user_id', $this->u[$who]->id)->where('week_ending', $this->weekStart($k)->addDays(6)->toDateString())
            ->where('status', Timesheet::DRAFT)->whereNull('period_start')->first()
            ?? Timesheet::where('user_id', $this->u[$who]->id)->where('week_ending', $this->weekStart($k)->addDays(6)->toDateString())->where('status', Timesheet::DRAFT)->first();
    }

    /** Envía la hoja de la semana (la persona firma con su contraseña). */
    private function submit(string $who, int $k, ?string $note = null): ?Timesheet
    {
        $sheet = $this->draft($who, $k);
        if (! $sheet || ! $sheet->entries()->exists()) {
            return null;
        }
        try {
            $sheet = $this->sheets->submit($sheet, $this->u[$who], 'app', $note);
        } catch (\Throwable $e) {
            $this->notes[] = 'envío '.$who.' semana '.$k.': '.$e->getMessage();
            return null;
        }
        $this->sign($this->u[$who], 'sheet.submit', $sheet, ['version' => (int) $sheet->version, 'hours' => (float) $sheet->total_hours, 'week_ending' => $sheet->week_ending->toDateString()]);
        return $sheet;
    }

    private function sign(User $by, string $action, $subject, array $payload = []): void
    {
        $this->sigs->record($this->request, $by, $action, $subject, $payload);
    }

    /** Firma de un aprobador externo: con cuenta, la contraseña; sin cuenta, el código del enlace. */
    private function signApprover(Approver $a, string $action, Timesheet $sheet, array $payload): void
    {
        $account = $a->account()->first();
        $payload += ['stage' => 'external'];
        $account ? $this->sigs->record($this->request, $account, $action, $sheet, $payload) : $this->sigs->recordByCode($this->request, $a, $action, $sheet, $payload + ['via' => 'link']);
    }

    /**
     * Lleva la hoja por sus aprobaciones. $until: 'internal' (se queda esperando a los internos), 'external' (los internos aprueban y queda con
     * el cliente) o 'approved' (todo). $return: 'internal' o 'external' devuelve la hoja en esa etapa con el comentario.
     */
    private function decide(?Timesheet $sheet, string $until = 'approved', ?string $return = null, ?string $comment = null): void
    {
        if (! $sheet) {
            return;
        }
        $sheet->refresh();
        $guard = 0;
        while ($sheet->status === Timesheet::SUBMITTED && $sheet->stage === Timesheet::STAGE_INTERNAL && $guard++ < 6) {
            if ($until === 'internal') {
                return;
            }
            $part = $this->internal->pendingParts($sheet)->first();
            $user = $part['eligible']->first();
            if (! $user) {
                return;
            }
            if ($return === 'internal') {
                $this->sheets->internalReturn($sheet, $user, $comment ?? 'Revisar las horas.', null);
                $this->sign($user, 'sheet.return', $sheet->refresh(), ['version' => (int) $sheet->version, 'comment' => $comment, 'stage' => 'internal']);
                return;
            }
            $this->sheets->internalApprove($sheet, $user, null);
            $this->sign($user, 'sheet.approve', $sheet->refresh(), ['version' => (int) $sheet->version, 'stage' => 'internal']);
        }
        if ($until === 'external') {
            return;
        }
        while ($sheet->status === Timesheet::SUBMITTED && $guard++ < 12) {
            $part = $this->external->pendingParts($sheet)->first();
            $approver = $part['eligible']->first() ?? null;
            if (! $approver) {
                return;
            }
            $via = $approver->account()->exists() ? 'app' : 'link';
            if ($return === 'external') {
                $this->sheets->return($sheet, $approver, null, $via, $comment ?? 'Revisar las horas.', null);
                $this->signApprover($approver, 'sheet.return', $sheet->refresh(), ['version' => (int) $sheet->version, 'comment' => $comment]);
                return;
            }
            $this->sheets->approve($sheet, $approver, null, $via);
            $this->signApprover($approver, 'sheet.approve', $sheet->refresh(), ['version' => (int) $sheet->version]);
        }
    }

    // ------------------------------------------------------------------------------------------------ la historia, semana por semana

    /** @return array<string,array{0:string,1?:string}> qué pasa con la hoja de cada persona en la semana k */
    private function scenario(string $who, int $k): array
    {
        $special = [
            1 => ['ricardo' => ['client_pending'], 'sofia' => ['internal_pending'], 'hector' => ['parts'], 'valeria' => ['returned_external', 'Falta el detalle de lo que se hizo el miércoles; por favor añade una nota a esas horas.'],
                'ana' => ['draft'], 'jorge' => ['client_pending'], 'lucia' => ['approved']],
            2 => ['sofia' => ['late'], 'valeria' => ['late_pending'], 'ricardo' => ['returned_internal', 'Las horas del jueves no coinciden con el parte del proyecto. Revísalas y vuelve a enviar.'],
                'jorge' => ['returned_external', 'Las horas de pruebas del viernes no están en el reporte de defectos; por favor confirma.'], 'lucia' => ['approved']],
        ];
        return $special[$k][$who] ?? ($k === 0 ? ['draft'] : ['approved']);
    }

    private function people_list(): array
    {
        return ['ricardo', 'sofia', 'hector', 'valeria', 'ana', 'jorge', 'lucia', 'patricia', 'raul'];
    }

    private function history(): void
    {
        $this->at($this->weekStart(self::WEEKS)->setTime(9, 0));
        $done = [5 => ['AMSI-PORT:Migración de usuarios'], 4 => ['AMSI-PORT:Reportes clínicos'], 3 => ['BOR-ERP:Módulo de inventario'], 6 => ['BOR-ERP:Auditoría inicial']];
        for ($k = self::WEEKS; $k >= 0; $k--) {
            $this->week($k);
            foreach ($done[$k] ?? [] as $key) {
                $this->complete($this->t[$key], $this->day($k, 10, '09:00'));
            }
            if ($k === 4) {
                $this->invoicePeriod($this->weekStart(self::WEEKS)->toDateString(), $this->weekStart(5)->addDays(6)->toDateString(), true, $this->day(4, 6, '12:00'));
            }
            if ($k === 2) {
                $this->invoicePeriod($this->weekStart(4)->toDateString(), $this->weekStart(3)->addDays(6)->toDateString(), false, $this->day(2, 6, '12:00'));
            }
        }
        // Un empleado con comisión liberada: la oficina la marca como pagada.
        $this->at($this->real);
        foreach (CommissionResult::with('user')->where('company_id', $this->company->id)->where('status', 'released')->get() as $r) {
            if ($r->user->worker_type === 'employee') {
                $this->sign($this->u['office'], 'commission.paid', $r, ['total' => (float) $r->total, 'user_id' => $r->user_id]);
                $r->update(['status' => 'paid', 'paid_at' => now()]);
            }
        }
    }

    private function week(int $k): void
    {
        foreach ($this->people_list() as $who) {
            [$kind, $comment] = $this->scenario($who, $k) + [1 => null];
            if ($kind === 'parts') {
                $this->partsWeek($who, $k);
                continue;
            }
            $created = $this->day($k, 6, '18:00');
            // La semana en curso: horas hasta hoy y todo en borrador. Las pasadas se llenan el sábado y se envían después.
            $this->at($k === 0 ? $this->real : $created);
            $this->logHours($who, $this->weekSpecs($who, $k));
            if ($kind === 'draft') {
                continue;
            }
            $late = in_array($kind, ['late', 'late_pending'], true);
            $this->at($late ? $this->day($k, 2 + 7, '15:30') : $this->day($k, 7, '10:00'));   // el domingo (a tiempo) o el martes siguiente (tarde)
            $sheet = $this->submit($who, $k, $this->rand(1, 100) <= 25 ? 'Horas de la semana, gracias.' : null);
            if (! $sheet) {
                continue;
            }
            $this->at($late ? $this->day($k, 10, '10:00') : $this->day($k, 8 + ($k % 2), '14:00'));
            match ($kind) {
                'internal_pending' => $this->decide($sheet, 'internal'),
                'client_pending', 'late_pending' => $this->decide($sheet, 'external'),
                'returned_external' => $this->decide($sheet, 'approved', 'external', $comment),
                'returned_internal' => $this->decide($sheet, 'approved', 'internal', $comment),
                default => $this->decide($sheet),
            };
        }
    }

    /** Semana enviada por partes: tres envíos (martes, jueves y el resto), con una parte aprobada, una con el cliente y una devuelta. */
    private function partsWeek(string $who, int $k): void
    {
        $sent = [];
        foreach ([[0, 2, 3], [3, 4, 5], [5, 6, 7]] as $i => [$from, $to, $when]) {   // [desde el día, hasta el día, día en que se envía]
            $this->at($this->day($k, $when, '09:30'));
            $this->logHours($who, $this->weekSpecs($who, $k, $from, $to));
            try {
                foreach ($this->sheets->submitDays($this->u[$who], $this->weekStart($k)->addDays($to)->toDateString(), null, 'app') as $s) {
                    $this->sign($this->u[$who], 'sheet.submit', $s, ['version' => (int) $s->version, 'hours' => (float) $s->total_hours, 'week_ending' => $s->week_ending->toDateString()]);
                    $sent[$i] = $s;
                }
            } catch (\Throwable $e) {
                $this->notes[] = 'partes '.$who.': '.$e->getMessage();
            }
        }
        $this->at($this->day($k, 8, '15:00'));
        $this->decide($sent[0] ?? null);
        $this->decide($sent[1] ?? null, 'external');
        $this->decide($sent[2] ?? null, 'approved', 'external', 'Falta la nota de lo trabajado el viernes.');
    }

    // ------------------------------------------------------------------------------------------------ tareas, comisiones y facturas

    /** Completa la tarea (firma del dueño) y calcula las comisiones con las horas aprobadas, como hace la pantalla de Tareas. */
    private function complete(Task $task, CarbonImmutable $on): void
    {
        $this->at($on);
        $task->refresh();
        if (! $task->active) {
            return;
        }
        $date = $on->toDateString();
        $eval = $this->commissions->applicable($task);
        $eval = $eval->isNotEmpty() ? $this->commissions->evaluate($task, $date, $eval) : null;
        if ($eval && $eval['pending_hours'] > 0) {
            $this->notes[] = 'La tarea «'.$task->name.'» tiene horas sin aprobar y no se completó.';
            return;
        }
        $release = $eval !== null && $this->commissions->payable($eval);
        DB::transaction(function () use ($task, $date, $eval, $release) {
            $this->sign($this->u['owner'], 'task.complete', $task, ['completed_on' => $date, 'hours' => (float) $task->entries()->sum('hours'), 'release' => $release]);
            $task->update(['active' => false, 'completed_on' => $date]);
            if ($eval) {
                $this->commissions->store($eval, $release, $this->u['owner']);
            }
        });
    }

    /** Facturas de los consultores del período. Las "pagadas" se marcan a mano (no hay pantalla que lo haga). */
    private function invoicePeriod(string $from, string $to, bool $paid, CarbonImmutable $when): void
    {
        $this->at($when);
        foreach (['ricardo', 'sofia', 'hector', 'valeria'] as $who) {
            try {
                $inv = $this->invoices->create($this->u[$who], $from, $to, $this->u[$who], $this->request);
                if ($paid) {
                    $inv->update(['status' => 'paid']);
                    CommissionResult::where('contractor_invoice_id', $inv->id)->update(['status' => 'paid', 'paid_at' => now()]);
                }
            } catch (\Throwable $e) {
                $this->notes[] = 'factura '.$who.' '.$from.': '.$e->getMessage();
            }
        }
    }
}
