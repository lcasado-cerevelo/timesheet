<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SheetLockedException;
use App\Http\Controllers\Controller;
use App\Models\DayNote;
use App\Models\TimeEntry;
use App\Services\EntryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EntryController extends Controller
{
    public function __construct(private EntryService $entries)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $rows = TimeEntry::with('project:id,code,name', 'service:id,name')
            ->where('user_id', $request->user()->id)
            ->whereBetween('work_date', [$data['from'], $data['to']])
            ->orderBy('work_date')->orderBy('start_time')->orderBy('id')->get();
        $notes = DayNote::where('user_id', $request->user()->id)
            ->whereBetween('work_date', [$data['from'], $data['to']])->get(['work_date', 'note']);
        return response()->json(['entries' => $rows->map(fn ($e) => $this->payload($e)), 'day_notes' => $notes]);
    }

    public function store(Request $request)
    {
        $entry = $this->entries->save($request->user(), $this->input($request), null, 'app');
        return response()->json($this->payload($entry->load('project:id,code,name', 'service:id,name')), 201);
    }

    public function update(Request $request, TimeEntry $entry)
    {
        $this->owns($request, $entry);
        $entry = $this->entries->save($request->user(), $this->input($request), $entry, 'app');
        return response()->json($this->payload($entry->load('project:id,code,name', 'service:id,name')));
    }

    public function destroy(Request $request, TimeEntry $entry)
    {
        $this->owns($request, $entry);
        $this->entries->delete($request->user(), $entry, 'app');
        return response()->json(['ok' => true]);
    }

    public function startClock(Request $request)
    {
        $d = $request->validate(['project_id' => 'required|integer', 'service_id' => 'nullable|integer', 'note' => 'nullable|string|max:1000',
            'client_uuid' => 'nullable|uuid', 'at' => 'nullable|date']);
        $e = $this->entries->startClock($request->user(), $d['project_id'], $d['note'] ?? null, $d['client_uuid'] ?? null, $d['at'] ?? null, $d['service_id'] ?? null);
        return response()->json($this->payload($e->load('project:id,code,name', 'service:id,name')), 201);
    }

    public function stopClock(Request $request)
    {
        $d = $request->validate(['at' => 'nullable|date']);
        $e = $this->entries->stopClock($request->user(), $d['at'] ?? null);
        return response()->json($this->payload($e->load('project:id,code,name', 'service:id,name')));
    }

    public function dayNote(Request $request)
    {
        $d = $request->validate(['work_date' => 'required|date', 'note' => 'nullable|string|max:1000']);
        if (($d['note'] ?? '') === '') {
            DayNote::where('user_id', $request->user()->id)->where('work_date', $d['work_date'])->delete();
        } else {
            DayNote::updateOrCreate(['user_id' => $request->user()->id, 'work_date' => $d['work_date']], ['note' => $d['note']]);
        }
        return response()->json(['ok' => true]);
    }

    /**
     * Sincronización sin conexión: la app manda lo que guardó localmente. Cada entrada lleva su
     * client_uuid, así que reenviar es seguro (no duplica). El servidor decide los conflictos:
     * si la hoja ya se aprobó, esa entrada se rechaza y la app lo muestra.
     */
    public function sync(Request $request)
    {
        $payload = $request->validate([
            'entries' => 'required|array|max:500',
            'entries.*.client_uuid' => 'required|uuid',
            'entries.*.deleted' => 'nullable|boolean',
        ]);
        $results = [];
        foreach ($request->input('entries') as $item) {
            $uuid = $item['client_uuid'];
            $existing = TimeEntry::where('company_id', $request->user()->company_id)->where('client_uuid', $uuid)->first();
            try {
                if (! empty($item['deleted'])) {
                    if ($existing) {
                        $this->owns($request, $existing);
                        $this->entries->delete($request->user(), $existing, 'sync');
                    }
                    $results[] = ['client_uuid' => $uuid, 'status' => 'ok'];
                    continue;
                }
                $data = $this->normalize($item);
                $entry = $this->entries->save($request->user(), $data + ['client_uuid' => $uuid], $existing, 'sync');
                $results[] = ['client_uuid' => $uuid, 'status' => 'ok', 'id' => $entry->id];
            } catch (SheetLockedException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'locked', 'message' => $e->getMessage()];
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'invalid', 'errors' => $e->errors()];
            }
        }
        return response()->json(['results' => $results]);
    }

    private function input(Request $request): array
    {
        $request->validate([
            'project_id' => 'required|integer',
            'service_id' => 'nullable|integer',
            'work_date' => 'required|date_format:Y-m-d',
            'start_time' => 'nullable|string|max:5',
            'end_time' => 'nullable|string|max:5',
            'hours' => 'nullable|numeric',
            'note' => 'nullable|string|max:1000',
            'billable' => 'nullable|boolean',
            'hour_type' => 'nullable|in:'.implode(',', TimeEntry::HOUR_TYPES),
            'client_uuid' => 'nullable|uuid',
        ]);
        return $this->normalize($request->all());
    }

    private function normalize(array $in): array
    {
        $keys = ['project_id', 'service_id', 'work_date', 'start_time', 'end_time', 'hours', 'note', 'billable', 'hour_type', 'running'];
        return array_intersect_key($in, array_flip($keys));
    }

    private function owns(Request $request, TimeEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 403);
    }

    public static function payload(TimeEntry $e): array
    {
        return [
            'id' => $e->id,
            'client_uuid' => $e->client_uuid,
            'project_id' => $e->project_id,
            'project' => $e->relationLoaded('project') ? $e->project : null,
            'service_id' => $e->service_id,
            'service' => $e->relationLoaded('service') && $e->service ? ['id' => $e->service->id, 'name' => $e->service->name] : null,
            'timesheet_id' => $e->timesheet_id,
            'work_date' => $e->work_date->toDateString(),
            'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null,
            'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
            'running' => $e->running,
            'hours' => (float) $e->hours,
            'note' => $e->note,
            'note_from_submission' => (bool) $e->note_from_submission,
            'billable' => $e->billable,
            'hour_type' => $e->hour_type ?: 'reg',
        ];
    }
}
