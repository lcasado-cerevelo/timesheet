<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El bono del equipo se paga por las horas ahorradas RESPECTO A LA META (meta − total trabajado; p. ej. meta 16 h, terminada en 10 h = 6 h),
 * no respecto al presupuesto (20 − 10). Se guarda con cuántas horas se calculó cada resultado. Los resultados anteriores se calcularon contra
 * el presupuesto: se les deja eso, para que su detalle siga cuadrando con lo ya guardado.
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
