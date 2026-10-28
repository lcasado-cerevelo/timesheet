<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Descanso, en minutos, de una entrada con hora desde y hasta: las horas son hasta − desde − descanso.
            $table->unsignedSmallInteger('break_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('break_minutes');
        });
    }
};
