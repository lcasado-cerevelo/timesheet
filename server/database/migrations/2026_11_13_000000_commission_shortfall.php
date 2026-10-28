<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_results', function (Blueprint $table) {
            $table->decimal('assigned_hours', 8, 2)->default(0);        // las horas que le correspondían (porcentaje de la meta), si se paga por el % asignado
            $table->decimal('shortfall_hours', 8, 2)->default(0);       // las que le correspondían y no trabajó: se pagan a tarifa normal + extra
            $table->decimal('base_rate', 10, 2)->default(0);            // su tarifa normal por hora usada para esas horas
        });
    }

    public function down(): void
    {
        Schema::table('commission_results', fn (Blueprint $t) => $t->dropColumn(['assigned_hours', 'shortfall_hours', 'base_rate']));
    }
};
