<?php

namespace App\Services;

use App\Exceptions\SheetLockedException;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
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
                $this->fail('project_id', 'Ese proyecto no existe o no está asignado a esta persona.');
            }

            $date = $data['work_date'] ?? null;
            if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                $this->fail('work_date', 'Fecha inválida.');
            }
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $d = CarbonImmutable::parse($date, $company->timezone);
            if ($d->gt($today)) {
                $this->fail('work_date', 'No se pueden registrar horas en fechas futuras.');
            }
            $oldestWeekEnd = $today->endOfWeek(CarbonImmutable::SUNDAY)->subWeeks((int) $rules->get('weeks_back'));
            if (Week::ending($date) < $oldestWeekEnd->toDateString()) {
                $this->fail('work_date', 'Esa fecha queda más atrás de lo que se permite llenar.');
            }

            $mode = $user->capture_mode;
            $inc = (float) $rules->get('increment') ?: 0.25;
            $running = (bool) ($data['running'] ?? false);
            $start = $data['start_time'] ?? null;
            $end = $data['end_time'] ?? null;
            $hours = isset($data['hours']) ? (float) $data['hours'] : null;

            if ($mode === 'full_day') {
                if ($start || $end || $running) {
                    $this->fail('start_time', 'Este usuario registra el día completo; no lleva hora desde ni hasta.');
                }
                if ($project->requiresTimes()) {
                    $this->fail('project_id', 'Ese proyecto exige hora desde y hasta; este usuario registra el día completo.');
                }
                if ($hours === null || $hours <= 0) {
                    $this->fail('hours', 'Escribe las horas del día.');
                }
                $hours = $this->roundTo($hours, $inc);
            } else { // from_to
                if ($hours !== null && ! $start && ! $end) {
                    $this->fail('start_time', 'A este usuario se le exige hora desde y hasta.');
                }
                if (! $this->validTime($start)) {
                    $this->fail('start_time', 'Hora de inicio inválida (HH:MM).');
                }
                if ($running) {
                    $end = null;
                    $hours = 0.0;
                    if (TimeEntry::where('user_id', $user->id)->where('running', true)
                        ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))->exists()) {
                        $this->fail('running', 'Ya hay un reloj corriendo.');
                    }
                } else {
                    if (! $this->validTime($end)) {
                        $this->fail('end_time', 'Hora de fin inválida (HH:MM).');
                    }
                    $minutes = $this->minutes($end) - $this->minutes($start);
                    if ($minutes <= 0) {
                        $this->fail('end_time', 'La hora de fin debe ser después de la de inicio.');
                    }
                    $hours = $this->roundTo($minutes / 60, $inc);
                    if ($hours <= 0) {
                        $this->fail('end_time', 'La duración es demasiado corta.');
                    }
                    $overlap = TimeEntry::where('user_id', $user->id)->where('work_date', $date)
                        ->where('running', false)
                        ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))
                        ->where('start_time', '<', $end.':00')->where('end_time', '>', $start.':00')->exists();
                    if ($overlap) {
                        $this->fail('start_time', 'Se cruza con otra entrada del mismo día.');
                    }
                }
            }

            $others = (float) TimeEntry::where('user_id', $user->id)->where('work_date', $date)
                ->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))->sum('hours');
            if ($others + $hours > (float) $rules->get('max_day_hours')) {
                $this->fail('hours', 'Pasa del máximo de horas por día ('.$rules->get('max_day_hours').').');
            }

            $newSheet = $this->sheets->forWeek($user, $date);
            if ($newSheet->isLocked()) {
                throw new SheetLockedException();
            }
            $oldSheet = null;
            $before = null;
            if ($entry) {
                if ($entry->user_id !== $user->id) {
                    abort(403);
                }
                $oldSheet = $entry->timesheet;
                if ($oldSheet->isLocked()) {
                    throw new SheetLockedException();
                }
                $before = $this->describe($entry);
            }

            $attrs = [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'timesheet_id' => $newSheet->id,
                'work_date' => $date,
                'start_time' => $start ? $start.':00' : null,
                'end_time' => $end ? $end.':00' : null,
                'running' => $running,
                'hours' => $hours,
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 1000) : null,
                'billable' => (bool) ($data['billable'] ?? $project->billable),
            ];

            if ($entry) {
                $entry->update($attrs);
            } else {
                $entry = TimeEntry::create($attrs + ['client_uuid' => $data['client_uuid'] ?? null]);
            }

            $line = $before
                ? 'Cambiada '.$before.' → '.$this->describe($entry)
                : 'Añadida '.$this->describe($entry);
            $this->sheets->entriesChanged($newSheet->refresh(), $line, $user, $channel);
            if ($oldSheet && $oldSheet->id !== $newSheet->id) {
                $this->sheets->entriesChanged($oldSheet->refresh(), 'Movida fuera de esta semana: '.$before, $user, $channel);
            }
            return $entry->refresh();
        });
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
            $line = 'Borrada '.$this->describe($entry);
            $entry->delete();
            $this->sheets->entriesChanged($sheet->refresh(), $line, $user, $channel);
        });
    }

    /** Reloj: empieza ahora. */
    public function startClock(User $user, int $projectId, ?string $note = null, ?string $uuid = null, ?string $at = null): TimeEntry
    {
        $tz = $user->company->timezone;
        $now = $at ? CarbonImmutable::parse($at)->setTimezone($tz) : CarbonImmutable::now($tz);
        return $this->save($user, [
            'project_id' => $projectId,
            'work_date' => $now->toDateString(),
            'start_time' => $now->format('H:i'),
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
            $this->fail('running', 'No hay un reloj corriendo.');
        }
        $tz = $user->company->timezone;
        $now = $at ? CarbonImmutable::parse($at)->setTimezone($tz) : CarbonImmutable::now($tz);
        if ($now->toDateString() !== $entry->work_date->toDateString()) {
            $this->fail('end_time', 'El reloj pasó de medianoche; corrige la hora de salida a mano.');
        }
        return $this->save($user, [
            'project_id' => $entry->project_id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => substr($entry->start_time, 0, 5),
            'end_time' => $now->format('H:i'),
            'running' => false,
            'note' => $entry->note,
        ], $entry, 'clock');
    }

    private function describe(TimeEntry $e): string
    {
        $e->loadMissing('project');
        $span = $e->start_time ? substr($e->start_time, 0, 5).'-'.($e->end_time ? substr($e->end_time, 0, 5) : '…') : '';
        return trim($e->work_date->toDateString().' '.$e->project->code.' '.$span).' '.number_format((float) $e->hours, 2).' h';
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
