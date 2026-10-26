<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación por servicio: cada servicio dice qué aprobación necesita (none | internal | external | both).
 * projects.requires_internal_approval pasa a ser un dato derivado (algún servicio activo es internal o both).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('approval_mode', 10)->default('external');
        });
        $this->backfill();
    }

    /** Los proyectos que ya exigían aprobación interna: sus servicios piden las dos. Las hojas ya enviadas no se tocan. */
    public function backfill(): void
    {
        DB::table('services')
            ->whereIn('project_id', DB::table('projects')->where('requires_internal_approval', true)->select('id'))
            ->update(['approval_mode' => 'both']);
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('approval_mode'));
    }
};
