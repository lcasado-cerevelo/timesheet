<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvers', function (Blueprint $table) {
            // Gerente de proyecto: el aprobador con cuenta que además ve la pantalla Avance (solo lectura) de los proyectos de sus clientes.
            $table->boolean('project_manager')->default(false)->after('can_login');
        });
    }

    public function down(): void
    {
        Schema::table('approvers', function (Blueprint $table) {
            $table->dropColumn('project_manager');
        });
    }
};
