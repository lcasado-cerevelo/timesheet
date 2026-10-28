<?php

use App\Models\Timesheet;
use App\Models\TimesheetEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // En qué etapa se devolvió la hoja (internal | external): el aprobador externo no ve las devoluciones de la etapa interna.
            $table->string('return_stage', 10)->nullable()->after('return_comment');
        });
        // Las hojas ya devueltas toman la etapa de su última devolución (la guarda el historial).
        Timesheet::where('status', 'returned')->each(function (Timesheet $s) {
            $ev = TimesheetEvent::where('timesheet_id', $s->id)->where('type', 'returned')->latest('id')->first();
            $stage = $ev?->data['stage'] ?? null;
            if (in_array($stage, ['internal', 'external'], true)) {
                $s->forceFill(['return_stage' => $stage])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn('return_stage'));
    }
};
