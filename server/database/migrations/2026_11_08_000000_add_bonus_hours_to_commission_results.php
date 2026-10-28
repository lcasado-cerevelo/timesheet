<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El bono del equipo se paga sobre las horas de la META (p. ej. 16 h), no sobre las horas ahorradas (20 − 16 = 4). Se guarda con qué
 * horas se calculó cada resultado. Los resultados anteriores se calcularon sobre las horas ahorradas: se les deja eso, para que su detalle
 * siga cuadrando con lo ya guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_results', function (Blueprint $table) {
            $table->decimal('bonus_hours', 8, 2)->default(0)->after('saved_hours');
        });
        DB::table('commission_results')->update(['bonus_hours' => DB::raw('saved_hours')]);
    }

    public function down(): void
    {
        Schema::table('commission_results', fn (Blueprint $table) => $table->dropColumn('bonus_hours'));
    }
};
