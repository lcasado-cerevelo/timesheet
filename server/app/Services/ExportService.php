<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ExportLog;
use App\Models\ExportTemplate;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plantillas de exportación: columnas, orden, encabezados, agrupación y filtros definidos en datos.
 * La plantilla "Consultorio 1500" genera hojas "Atendidos <año>" con las columnas que su importador reconoce
 * (cada cliente/proyecto del timesheet es un paciente).
 */
class ExportService
{
    /** Orígenes de datos que puede usar una columna. */
    public const SOURCES = [
        'date', 'week_ending', 'worker', 'project.code', 'project.name', 'project.patient', 'project.plan', 'project.contract', 'customer', 'hours', 'amount', 'rate',
        'start', 'end', 'notes', 'billable', 'literal',
    ];
    public const GROUPS = ['project', 'date', 'worker', 'week', 'customer'];

    public function ensureSystemTemplates(Company $company): void
    {
        foreach ($this->systemTemplates() as $slug => $def) {
            // Las del sistema son de solo lectura, así que se mantienen al día con la versión actual del programa.
            ExportTemplate::updateOrCreate(
                ['company_id' => $company->id, 'slug' => $slug],
                ['name' => $def['name'], 'format' => $def['format'], 'config' => $def['config'], 'is_system' => true]
            );
        }
    }

    public function systemTemplates(): array
    {
        return [
            'consultorio-1500' => [
                'name' => 'Consultorio 1500', 'format' => 'xlsx',
                'config' => [
                    'sheet_name' => 'Atendidos {year}',
                    'group_by' => ['project', 'date'], // una visita por paciente y día (evita duplicados al importar)
                    'filters' => ['status' => 'approved', 'billable_only' => true],
                    'columns' => [
                        ['header' => 'Nombre del asegurado', 'source' => 'project.patient'],
                        ['header' => 'Contrato', 'source' => 'project.contract'],
                        ['header' => 'Plan medico', 'source' => 'project.plan'],
                        ['header' => 'Facturada con plan', 'source' => 'amount',
                            'when' => ['source' => 'project.plan', 'op' => 'filled_not_contains', 'value' => 'privado']],
                        ['header' => 'Facturada sin plan', 'source' => 'amount',
                            'when' => ['source' => 'project.plan', 'op' => 'empty_or_contains', 'value' => 'privado']],
                        ['header' => 'Fecha', 'source' => 'date', 'format' => 'date'],
                        ['header' => 'Descripcion', 'source' => 'notes'],
                    ],
                ],
            ],
            'horas-por-dia' => [
                'name' => 'Horas por día (detalle)', 'format' => 'xlsx',
                'config' => [
                    'sheet_name' => 'Horas', 'group_by' => [],
                    'filters' => ['status' => 'approved'],
                    'columns' => [
                        ['header' => 'Fecha', 'source' => 'date', 'format' => 'date'],
                        ['header' => 'Persona', 'source' => 'worker'],
                        ['header' => 'Proyecto', 'source' => 'project.code'],
                        ['header' => 'Desde', 'source' => 'start'],
                        ['header' => 'Hasta', 'source' => 'end'],
                        ['header' => 'Horas', 'source' => 'hours'],
                        ['header' => 'Nota', 'source' => 'notes'],
                    ],
                ],
            ],
            'resumen-por-proyecto' => [
                'name' => 'Resumen por proyecto', 'format' => 'xlsx',
                'config' => [
                    'sheet_name' => 'Resumen', 'group_by' => ['project'],
                    'filters' => ['status' => 'approved'],
                    'columns' => [
                        ['header' => 'Cliente', 'source' => 'customer'],
                        ['header' => 'Proyecto', 'source' => 'project.name'],
                        ['header' => 'Horas', 'source' => 'hours'],
                        ['header' => 'Importe', 'source' => 'amount'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Un proyecto de Consultorio 1500 se nombra "Paciente - Plan médico - Número de contrato".
     * Lo que va antes del primer guión es el paciente, entre el primero y el segundo el plan, y después del segundo el contrato.
     * Si el nombre no trae plan o contrato, se usan los campos plan_medico y numero_contrato del proyecto.
     * @return array{patient:string,plan:string,contract:string}
     */
    public static function parseProject(Project $p): array
    {
        $parts = preg_split('/\s*-\s*/u', (string) $p->name, 3);
        $trim = fn ($s) => trim((string) $s);
        return [
            'patient' => $trim($parts[0] ?? ''),
            'plan' => $trim($parts[1] ?? '') !== '' ? $trim($parts[1]) : $trim(data_get($p->meta, 'plan_medico', '')),
            'contract' => $trim($parts[2] ?? '') !== '' ? $trim($parts[2]) : $trim(data_get($p->meta, 'numero_contrato', '')),
        ];
    }

    /** Valida la definición de una plantilla creada por el usuario. Devuelve lista de errores. */
    public function validateConfig(array $c): array
    {
        $errors = [];
        if (empty($c['columns']) || ! is_array($c['columns'])) {
            return ['La plantilla necesita al menos una columna.'];
        }
        foreach ($c['columns'] as $i => $col) {
            $src = $col['source'] ?? '';
            $base = str_starts_with($src, 'project.meta.') ? 'project.meta.' : $src;
            if (trim((string) ($col['header'] ?? '')) === '') {
                $errors[] = 'Columna '.($i + 1).': falta el encabezado.';
            }
            if ($base !== 'project.meta.' && ! in_array($src, self::SOURCES, true)) {
                $errors[] = 'Columna '.($i + 1).': origen desconocido "'.$src.'".';
            }
        }
        foreach ($c['group_by'] ?? [] as $g) {
            if (! in_array($g, self::GROUPS, true)) {
                $errors[] = 'Agrupación desconocida "'.$g.'".';
            }
        }
        return $errors;
    }

    /**
     * @return array{path:string,filename:string,mime:string,rows:int,hash:string}
     */
    public function generate(ExportTemplate $tpl, User $actor, string $from, string $to, array $opts = []): array
    {
        $rows = $this->collect($tpl, $actor, $from, $to, $opts);
        $config = $tpl->config;
        $dir = storage_path('app/exports');
        @mkdir($dir, 0775, true);
        $office = ! empty($opts['customer_id']) ? Customer::where('company_id', $actor->company_id)->find($opts['customer_id']) : null;
        $base = Str::slug($tpl->name).($office ? '-'.Str::slug($office->name) : '').'-'.$from.'_'.$to;
        $path = $dir.'/'.Str::random(10).'-'.$base.'.'.$tpl->format;

        if ($tpl->format === 'csv') {
            $fh = fopen($path, 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // BOM para que Excel abra bien las tildes
            fputcsv($fh, array_column($config['columns'], 'header'));
            foreach ($rows as $r) {
                fputcsv($fh, $this->cells($config, $r, false));
            }
            fclose($fh);
            $mime = 'text/csv';
        } else {
            $book = new Spreadsheet();
            $book->removeSheetByIndex(0);
            $byYear = str_contains($config['sheet_name'] ?? '', '{year}')
                ? collect($rows)->groupBy(fn ($r) => substr($r['date'], 0, 4))
                : collect([substr($from, 0, 4) => $rows]);
            if ($byYear->isEmpty()) {
                $byYear = collect([substr($from, 0, 4) => []]);
            }
            foreach ($byYear->sortKeys() as $year => $yearRows) {
                $sheet = $book->createSheet();
                $sheet->setTitle(mb_substr(str_replace('{year}', (string) $year, $config['sheet_name'] ?? 'Datos'), 0, 31));
                foreach ($config['columns'] as $i => $col) {
                    $sheet->setCellValue([$i + 1, 1], $col['header']);
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($i + 1).'1')->getFont()->setBold(true);
                    $sheet->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
                }
                foreach ($yearRows as $n => $r) {
                    foreach ($this->cells($config, $r, true) as $i => $value) {
                        $col = $config['columns'][$i];
                        $coord = [$i + 1, $n + 2];
                        if (($col['format'] ?? null) === 'date' && $value) {
                            $sheet->setCellValue($coord, XlDate::PHPToExcel(CarbonImmutable::parse($value)->toDateTime()));
                            $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                        } else {
                            $sheet->setCellValue($coord, $value);
                        }
                    }
                }
            }
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->save($path);
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        $hash = hash_file('sha256', $path);
        ExportLog::create([
            'company_id' => $actor->company_id, 'user_id' => $actor->id, 'export_template_id' => $tpl->id,
            'period_from' => $from, 'period_to' => $to, 'row_count' => count($rows), 'file_hash' => $hash, 'created_at' => now(),
        ]);
        return ['path' => $path, 'filename' => $base.'.'.$tpl->format, 'mime' => $mime, 'rows' => count($rows), 'hash' => $hash];
    }

    /**
     * Pacientes (proyectos) de una oficina con horas aprobadas y facturables en el período, para escoger cuáles exportar.
     * @return array<int, array{id:int,name:string,plan:string,hours:float,amount:float,days:int}>
     */
    public function patients(User $actor, int $customerId, string $from, string $to, ?int $userId = null): array
    {
        $q = TimeEntry::with('project')
            ->where('company_id', $actor->company_id)->where('running', false)->where('billable', true)
            ->whereBetween('work_date', [$from, $to])
            ->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED))
            ->whereHas('project', fn ($p) => $p->where('customer_id', $customerId));
        if (! $actor->isOwner()) {
            $q->where('user_id', $actor->id);
        } elseif ($userId) {
            $q->where('user_id', $userId);
        }
        return $q->get()->groupBy('project_id')->map(function ($g) {
            $p = $g->first()->project;
            $hours = round($g->sum(fn ($e) => (float) $e->hours), 2);
            $parsed = self::parseProject($p);
            return [
                'id' => $p->id, 'name' => $parsed['patient'], 'project' => $p->name, 'plan' => $parsed['plan'], 'contract' => $parsed['contract'],
                'hours' => $hours, 'amount' => round($hours * (float) ($p->bill_rate ?? 0), 2),
                'days' => $g->pluck('work_date')->map(fn ($d) => $d->toDateString())->unique()->count(),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Filas ya agrupadas, con todos los orígenes disponibles. */
    public function collect(ExportTemplate $tpl, User $actor, string $from, string $to, array $opts = []): array
    {
        $filters = $tpl->config['filters'] ?? [];
        $q = TimeEntry::with('project.customer', 'user', 'timesheet')
            ->where('company_id', $actor->company_id)->where('running', false)
            ->whereBetween('work_date', [$from, $to])->orderBy('work_date')->orderBy('start_time');

        // Una persona sin rol de dueño solo exporta sus propias horas.
        if (! $actor->isOwner()) {
            $q->where('user_id', $actor->id);
        } elseif (! empty($opts['user_id'])) {
            $q->where('user_id', $opts['user_id']);
        }
        if (! empty($opts['customer_id'])) {
            // En Consultorio 1500 el cliente es la oficina: solo salen los pacientes (proyectos) de esa oficina.
            $q->whereHas('project', fn ($p) => $p->where('customer_id', $opts['customer_id']));
        }
        if (! empty($opts['project_ids'])) {
            $q->whereIn('project_id', $opts['project_ids']);
        }
        if (($filters['status'] ?? null) === 'approved') {
            $q->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED));
        }
        if (! empty($filters['billable_only'])) {
            $q->where('billable', true);
        }

        $flat = $q->get()->map(function (TimeEntry $e) {
            $p = $e->project;
            $rate = (float) ($p->bill_rate ?? 0);
            return [
                'date' => $e->work_date->toDateString(),
                'week_ending' => Week::ending($e->work_date),
                'worker' => $e->user->name,
                'project_id' => $p->id,
                'project' => $p,
                'customer' => $p->customer?->name ?? '',
                'hours' => (float) $e->hours,
                'rate' => $rate,
                'amount' => round((float) $e->hours * $rate, 2),
                'start' => $e->start_time ? substr($e->start_time, 0, 5) : '',
                'end' => $e->end_time ? substr($e->end_time, 0, 5) : '',
                'notes' => (string) $e->note,
                'billable' => $e->billable ? 'Sí' : 'No',
                'user_id' => $e->user_id,
            ];
        });

        $groupBy = $tpl->config['group_by'] ?? [];
        if (! $groupBy) {
            return $flat->all();
        }
        return $flat->groupBy(fn ($r) => implode('|', array_map(fn ($g) => match ($g) {
            'project' => $r['project_id'], 'date' => $r['date'], 'worker' => $r['user_id'],
            'week' => $r['week_ending'], 'customer' => $r['customer'],
        }, $groupBy)))->map(function ($g) use ($groupBy) {
            $first = $g->first();
            $first['hours'] = round($g->sum('hours'), 2);
            $first['amount'] = round($g->sum('amount'), 2);
            $first['notes'] = $g->pluck('notes')->filter()->unique()->implode('; ');
            if (! in_array('date', $groupBy, true)) {
                $first['date'] = $g->min('date');
            }
            $first['start'] = $first['end'] = '';
            return $first;
        })->values()->all();
    }

    /** Celdas de una fila según las columnas de la plantilla. */
    private function cells(array $config, array $row, bool $native): array
    {
        $out = [];
        foreach ($config['columns'] as $col) {
            if (isset($col['when']) && ! $this->passes($col['when'], $row)) {
                $out[] = null;
                continue;
            }
            $v = $this->value($col['source'], $row, $col);
            if (! $native && ($col['format'] ?? null) === 'date' && $v) {
                $v = (string) $v;
            }
            $out[] = $v;
        }
        return $out;
    }

    private function value(string $source, array $row, array $col): mixed
    {
        if ($source === 'literal') {
            return $col['value'] ?? '';
        }
        if (str_starts_with($source, 'project.meta.')) {
            return (string) data_get($row['project']->meta, substr($source, 13), '');
        }
        return match ($source) {
            'project.code' => $row['project']->code,
            'project.name' => $row['project']->name,
            'project.patient', 'project.plan', 'project.contract' => self::parseProject($row['project'])[substr($source, 8)],
            default => $row[$source] ?? '',
        };
    }

    private function passes(array $when, array $row): bool
    {
        $v = mb_strtolower((string) $this->value($when['source'], $row, []));
        $needle = mb_strtolower((string) ($when['value'] ?? ''));
        return match ($when['op'] ?? 'eq') {
            'contains' => str_contains($v, $needle),
            'not_contains' => ! str_contains($v, $needle),
            // Sin plan médico (campo vacío) cuenta igual que "privado": va a la columna "Facturada sin plan".
            'empty_or_contains' => trim($v) === '' || str_contains($v, $needle),
            'filled_not_contains' => trim($v) !== '' && ! str_contains($v, $needle),
            'neq' => $v !== $needle,
            default => $v === $needle,
        };
    }
}
