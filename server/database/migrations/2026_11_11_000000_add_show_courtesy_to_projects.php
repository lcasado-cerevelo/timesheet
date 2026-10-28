<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // El gerente de proyecto del cliente ve, aparte y solo en horas, el trabajo no facturado de este proyecto (cortesía).
            $table->boolean('show_courtesy')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('show_courtesy');
        });
    }
};
