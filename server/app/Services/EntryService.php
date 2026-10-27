<?php

namespace App\Services;

use App\Support\Lang;
use App\Exceptions\SheetLockedException;
use App\Models\Project;
use App\Models\Service;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntryService
{
    public function __construct(private SheetService $sheets)
    {
    }

    /** Crea (o actualiza, si $entry) una entrada de tiempo del usuario. */
    public function save(User $user, array $data, ?TimeEntry $entry = null, string $channel = 'app'): TimeEntry
    {
        return DB::transaction(function () use ($user, $data, $entry, $channel) {
            $company = $user->company;
            $rules = $company->rules();

            $project = Project::where('company_id', $user->company_id)->where('active', true)
                ->find($data['project_id'] ?? null);
            if (! $project || (! $user->isOwner() && ! $user->projects()->whereKey($project->id)->exists())) {
                $this->fail('project_id', __('Ese proyecto no existe o no está asignado a esta persona.'));
            }

            $date = $data['work_date'] ?? null;
            if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                $this->fail('work_date', __('Fecha inválida.'));
            }
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $d = CarbonImmutable::parse($date, $company->timezone);
            if ($d->gt($today)) {
                $this->fail('work_date', __('No se pueden registrar horas en fechas futuras.'));
            }
            $oldestWeekEnd = $today->endOfWeek(($rules->weekStart() + 6) % 7)->subWeeks((int) $rules->get('weeks_back'));
            if (Week::ending($date, $rules->weekStart()) < $oldestWeekEnd->toDateString()) {
                $this->fail('work_date', __('Esa fecha queda más atrás de lo que se permite llenar.'));
            }

            // El servicio manda: si permite las dos formas, decide la preferencia de la persona (la fija la gerencia).
            $service = $this->resolveService($project, $data['service_id'] ?? null, $entry);
            $task = $this->resolveTask($project, $data, $entry);
            $mode = $service->effectiveMode($user);
            $inc = $rules->increment();
            $running = (bool) ($data['running'] ?? false);
            $start = $data['start_time'] ?? null;
            $end = $data['end_time'] ?? null;
            $hours = isset($data['hours']) ? (float) $data['hours'] : null;

            if ($mode === 'full_day') {
                if ($start || $end || $running) {
                    $this->fail('start_time', __('Este servicio se registra con el total de horas del día; no lleva hora desde ni hasta.'));
                }
                if ($hours === null || $hours <= 0) {
                    $this->fail('hours', __('Escribe las horas del día.'));
                }
                // El incremento se aplica al entrar los datos: no se redondea en silencio.
                if (! $this->isMultiple($hours, $inc)) {
                    $this->fail('hours', __('Las horas deben ser múltiplos de :step', ['step' => $rules->incrementLabel()]));
                }
                $hours = round($hours, 2);
            } else { // from_to
                if ($hours !== null && ! $start && ! $end) {
                    $this->fail('start_time', __('Este servicio se registra con hora desde y hasta.'));
                }
                if (! $this->validTime($start)) {
                    $this->fail('start_time', __('Hora de inicio inválida (HH:MM).'));
                }
                // Una hora que la entrada ya traía sin cambiar (guardada antes de este control) no se vuelve a exigir.
                $this->assertAligned($rules, [
                    'start_time' => $entry && substr((string) $entry->start_time, 0, 5) === $start ? null : $start,
                ] + ($running ? [] : ['end_time' => $entry && substr((string) $entry->end_time, 0, 5) === $end ? null : $end]));
                if ($running) {
                    $end = null;
                    $hours = 0.0;
                    if (TimeEntry::where('user_id', $user->id)->where('running', true)
                        ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))->exists()) {
                        $this->fail('running', __('Ya hay un reloj corriendo.'));
                    }
                } else {
                    if (! $this->validTime($end)) {
                        $this->fail('end_time', __('Hora de fin inválida (HH:MM).'));
                    }
                    $minutes = $this->minutes($end) - $this->minutes($start);
                    if ($minutes <= 0) {
                        $this->fail('end_time', __('La hora de fin debe ser después de la de inicio.'));
                    }
                    $hours = $this->roundTo($minutes / 60, $inc);
                    if ($hours <= 0) {
                        $this->fail('end_time', __('La duración es demasiado corta.'));
                    }
                    $overlap = TimeEntry::where('user_id', $user->id)->where('work_date', $date)
                        ->where('running', false)
                        ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))
                        ->where('start_time', '<', $end.':00')->where('end_time', '>', $start.':00')->exists();
                    if ($overlap) {
                        $this->fail('start_time', __('Se cruza con otra entrada del mismo día.'));
                    }
                }
            }

            $others = (float) TimeEntry::where('user_id', $user->id)->where('work_date', $date)
                ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))->sum('hours');
            if ($others + $hours > (float) $rules->get('max_day_hours')) {
                $this->fail('hours', __('Pasa del máximo de horas por día (:max).', ['max' => $rules->get('max_day_hours')]));
            }

            $newSheet = $this->sheets->forWeek($user, $date);
            if ($newSheet->isLocked()) {
                throw new SheetLockedException();
            }
            $oldSheet = null;
            $before = null;
            $oldServiceId = null;
            if ($entry) {
                if ($entry->user_id !== $user->id) {
                    abort(403);
                }
                $oldSheet = $entry->timesheet;
                if ($oldSheet->isLocked()) {
                    throw new SheetLockedException();
                }
                $before = $this->describe($entry);
                $oldServiceId = $entry->service_id;
            }

            // El tipo de hora solo cuenta para empleados; los consultores siempre son 'reg'.
            $hourType = 'reg';
            if ($user->isEmployee()) {
                $hourType = $data['hour_type'] ?? ($entry?->hour_type ?: 'reg');
                if (! in_array($hourType, TimeEntry::HOUR_TYPES, true)) {
                    $this->fail('hour_type', __('Tipo de hora no válido.'));
                }
            }
            $billable = (bool) ($data['billable'] ?? $service->billable);
            if (! in_array($hourType, TimeEntry::BILLABLE_HOUR_TYPES, true)) {
                $billable = false;
            }

            $attrs = [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'service_id' => $service->id,
                'task_id' => $task?->id,
                'timesheet_id' => $newSheet->id,
                'work_date' => $date,
                'start_time' => $start ? $start.':00' : null,
                'end_time' => $end ? $end.':00' : null,
                'running' => $running,
                'hours' => $hours,
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 1000) : null,
                'note_from_submission' => $entry && $entry->note_from_submission && isset($data['note']) && (string) $data['note'] === (string) $entry->note,
                'billable' => $billable,
                'hour_type' => $hourType,
            ];
            // Las tarifas quedan guardadas en la entrada al crearla: cambiar la tarifa de un servicio solo afecta entradas nuevas.
            if (! $entry || (int) $entry->service_id !== $service->id || $entry->bill_rate === null && $entry->pay_rate === null) {
                $attrs['bill_rate'] = $service->effectiveRate();
                $attrs['pay_rate'] = $user->terms?->rate !== null ? (float) $user->terms->rate : null;
            }

            if ($entry) {
                $entry->update($attrs);
            } else {
                $entry = TimeEntry::create($attrs + ['client_uuid' => $data['client_uuid'] ?? null]);
            }

            // Estas líneas se guardan y las lee el aprobador (persona externa): salen en el idioma de la compañía.
            $line = Lang::with($company->lang(), fn () => $before
                ? __('Cambiada :before → :after', ['before' => $before, 'after' => $this->describe($entry)])
                : __('Añadida :entry', ['entry' => $this->describe($entry)]));
            $this->sheets->entriesChanged($newSheet->refresh(), $line, $user, $channel, [$oldServiceId, $entry->service_id]);
            if ($oldSheet && $oldSheet->id !== $newSheet->id) {
                $this->sheets->entriesChanged($oldSheet->refresh(), Lang::with($company->lang(), fn () => __('Movida fuera de esta semana: :entry', ['entry' => $before])), $user, $channel, [$oldServiceId]);
            }
            return $entry->refresh();
        });
    }

    /** El servicio de la entrada: el indicado, el que ya tenía, o el único activo del proyecto. */
    private function resolveService(Project $project, mixed $serviceId, ?TimeEntry $entry): Service
    {
        if ($serviceId) {
            $s = Service::where('project_id', $project->id)->find($serviceId);
            // Un servicio desactivado no se puede usar en entradas nuevas, pero una entrada vieja puede conservarlo.
            if ($s && ($s->active || ($entry && (int) $entry->service_id === $s->id))) {
                return $s;
            }
            $this->fail('service_id', __('Ese servicio no existe en este proyecto o está desactivado.'));
        }
        if ($entry && $entry->service_id && (int) $entry->project_id === $project->id) {
            return Service::findOrFail($entry->service_id);
        }
        $active = Service::where('project_id', $project->id)->where('active', true)->orderBy('sort')->orderBy('id')->get();
        if ($active->count() === 1) {
            return $active->first();
        }
        $this->fail('service_id', $active->isEmpty() ? __('Ese proyecto no tiene servicios activos.') : __('Escoge el servicio.'));
    }

    /**
     * La tarea es opcional. Si viene, debe ser de este proyecto y estar activa (una entrada vieja puede conservar una desactivada).
     * Si no viene la clave, la entrada conserva la que tenía (siempre que siga siendo del mismo proyecto); si viene vacía, se quita.
     */
    private function resolveTask(Project $project, array $data, ?TimeEntry $entry): ?Task
    {
        if (! array_key_exists('task_id', $data)) {
            return $entry && $entry->task_id && (int) $entry->project_id === $project->id ? Task::find($entry->task_id) : null;
        }
        if (empty($data['task_id'])) {
            return null;
        }
        $t = Task::where('project_id', $project->id)->find($data['task_id']);
        if ($t && ($t->active || ($entry && (int) $entry->task_id === $t->id))) {
            return $t;
        }
        $this->fail('task_id', __('Esa tarea no existe en este proyecto o está desactivada.'));
    }

    public function delete(User $user, TimeEntry $entry, string $channel = 'app'): void
    {
        DB::transaction(function () use ($user, $entry, $channel) {
            if ($entry->user_id !== $user->id) {
                abort(403);
            }
            $sheet = $entry->timesheet;
            if ($sheet->isLocked()) {
                throw new SheetLockedException();
            }
            $line = Lang::with($user->company->lang(), fn () => __('Borrada :entry', ['entry' => $this->describe($entry)]));
            $entry->delete();
            $this->sheets->entriesChanged($sheet->refresh(), $line, $user, $channel, [$entry->service_id]);
        });
    }

    /** Reloj: empieza ahora. */
    public function startClock(User $user, int $projectId, ?string $note = null, ?string $uuid = null, ?string $at = null, ?int $serviceId = null, ?int $taskId = null): TimeEntry
    {
        $tz = $user->company->timezone;
        $now = $at ? CarbonImmutable::parse($at)->setTimezone($tz) : CarbonImmutable::now($tz);
        $start = $now->format('H:i');
        if (! $at) {
            // Sin 'at' el reloj empieza a la hora actual redondeada al múltiplo del incremento más cercano.
            $start = $this->nearestAligned($user->company->rules(), $start);
        }
        return $this->save($user, [
            'project_id' => $projectId,
            'service_id' => $serviceId,
            'task_id' => $taskId,
            'work_date' => $now->toDateString(),
            'start_time' => $start,
            'running' => true,
            'note' => $note,
            'client_uuid' => $uuid,
        ], null, 'clock');
    }

    /** Reloj: termina ahora (o a la hora indicada). */
    public function stopClock(User $user, ?string $at = null): TimeEntry
    {
        $entry = TimeEntry::where('user_id', $user->id)->where('running', true)->first();
        if (! $entry) {
            $this->fail('running', __('No hay un reloj corriendo.'));
        }
        $tz = $user->company->timezone;
        $now = $at ? CarbonImmutable::parse($at)->setTimezone($tz) : CarbonImmutable::now($tz);
        if ($now->toDateString() !== $entry->work_date->toDateString()) {
            $this->fail('end_time', __('El reloj pasó de medianoche; corrige la hora de salida a mano.'));
        }
        $rules = $user->company->rules();
        $end = $now->format('H:i');
        $startMin = $this->minutes(substr($entry->start_time, 0, 5));
        if (! $at) {
            // Sin 'at' no se adivina: si pasó menos de un incremento, o la hora actual no está alineada, la app debe confirmar.
            $elapsed = $this->minutes($end) - $startMin;
            if ($elapsed < $rules->minimumMinutes()) {
                $this->clockRefusal('below_minimum', __('La duración es demasiado corta.'), $rules, $end);
            }
            $step = $rules->stepMinutes();
            if ($step !== null && $this->minutes($end) % $step !== 0) {
                $this->clockRefusal('not_aligned', $step >= 60 ? __('Los minutos deben ser 00') : __('Los minutos deben ser múltiplos de :step', ['step' => $step]), $rules, $end);
            }
        }
        return $this->save($user, [
            'project_id' => $entry->project_id,
            'service_id' => $entry->service_id,
            'task_id' => $entry->task_id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => substr($entry->start_time, 0, 5),
            'end_time' => $now->format('H:i'),
            'running' => false,
            'note' => $entry->note,
        ], $entry, 'clock');
    }

    /** 422 del reloj con un código para que la app pregunte: below_minimum | not_aligned, más minimum_minutes. */
    private function clockRefusal(string $code, string $message, \App\Support\Rules $rules, string $now): never
    {
        $payload = ['message' => $message, 'code' => $code, 'minimum_minutes' => $rules->minimumMinutes(),
            'step_minutes' => $rules->stepMinutes(), 'suggested_end_time' => $this->nearestAligned($rules, $now),
            'errors' => ['end_time' => [$message]]];
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json($payload, 422));
    }

    /** La hora HH:MM redondeada al múltiplo del incremento más cercano (sin pasar de las 23:xx del mismo día). */
    private function nearestAligned(\App\Support\Rules $rules, string $hhmm): string
    {
        $step = $rules->stepMinutes();
        if ($step === null) {
            return $hhmm;
        }
        $m = (int) round($this->minutes($hhmm) / $step) * $step;
        while ($m >= 1440) {
            $m -= $step;
        }
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    private function describe(TimeEntry $e): string
    {
        $e->loadMissing('project');
        // La relación puede ser la de antes de cambiar la tarea: se vuelve a leer si no coincide.
        $task = $e->task_id ? ($e->relationLoaded('task') && $e->task?->id === (int) $e->task_id ? $e->task : Task::find($e->task_id)) : null;
        $span = $e->start_time ? substr($e->start_time, 0, 5).'-'.($e->end_time ? substr($e->end_time, 0, 5) : '…') : '';
        return trim($e->work_date->toDateString().' '.$e->project->code.($task ? ' / '.$task->name : '').' '.$span).' '.number_format((float) $e->hours, 2).' h';
    }

    /** Los minutos de las horas desde/hasta deben ser múltiplos del incremento (0.01 no exige alineación). */
    private function assertAligned(\App\Support\Rules $rules, array $times): void
    {
        $step = $rules->stepMinutes();
        if ($step === null) {
            return;
        }
        $errors = [];
        foreach ($times as $field => $t) {
            if ($this->validTime($t) && $this->minutes($t) % $step !== 0) {
                $errors[$field] = $step >= 60 ? __('Los minutos deben ser 00') : __('Los minutos deben ser múltiplos de :step', ['step' => $step]);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isMultiple(float $v, float $inc): bool
    {
        return abs($v / $inc - round($v / $inc)) * $inc < 1e-6;
    }

    private function roundTo(float $v, float $inc): float
    {
        return round(round($v / $inc) * $inc, 2);
    }

    private function validTime(?string $t): bool
    {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    }

    private function minutes(string $t): int
    {
        [$h, $m] = explode(':', $t);
        return (int) $h * 60 + (int) $m;
    }

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => $msg]);
    }
}
