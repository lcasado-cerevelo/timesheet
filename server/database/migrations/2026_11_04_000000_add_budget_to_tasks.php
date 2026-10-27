<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Horas estimadas (presupuesto) de una tarea. Opcional: sin valor, la tarea no tiene tope. Solo informativo: no bloquea horas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('budget_hours', 8, 2)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('budget_hours'));
    }
};
