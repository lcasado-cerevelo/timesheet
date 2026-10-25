<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Número de empleado por persona y tipo de hora por entrada (reg, ot, hol, sick, vac, nb). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number', 40)->nullable();
        });
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('hour_type', 8)->default('reg');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn('hour_type'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('employee_number'));
    }
};
