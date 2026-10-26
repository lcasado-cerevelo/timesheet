<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envío por partes y notas:
 *  - timesheets.part / period_start / period_end: una semana puede tener varias hojas (partes). Una hoja sin periodo cubre toda la semana
 *    (comportamiento de siempre). Con periodo, cubre solo esos días; los días sin horas cuentan igual.
 *  - timesheets.submission_note: la nota del envío. Al enviar se copia a las líneas sin nota (time_entries.note_from_submission las marca).
 *  - services.require_note: el servicio exige nota en cada línea (propia o la del envío) para poder enviar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // El borrador no tiene número de parte (null); toma el siguiente al enviarse. Las hojas que ya existían son la parte 1.
            $table->unsignedSmallInteger('part')->nullable()->after('week_ending');
            $table->date('period_start')->nullable()->after('part');
            $table->date('period_end')->nullable()->after('period_start');
            $table->string('submission_note', 1000)->nullable();
        });
        DB::table('timesheets')->where('status', '!=', 'draft')->update(['part' => 1]);
        // El índice nuevo primero: la llave foránea de user_id necesita siempre un índice que empiece por esa columna.
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unique(['user_id', 'week_ending', 'part']);
        });
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'week_ending']);
        });
        Schema::table('time_entries', function (Blueprint $table) {
            $table->boolean('note_from_submission')->default(false);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('require_note')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('require_note'));
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn('note_from_submission'));
        Schema::table('timesheets', fn (Blueprint $t) => $t->unique(['user_id', 'week_ending']));
        Schema::table('timesheets', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'week_ending', 'part']);
        });
        Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn(['part', 'period_start', 'period_end', 'submission_note']));
    }
};
