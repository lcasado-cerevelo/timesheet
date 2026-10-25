<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación interna: un proyecto puede exigir que un supervisor (usuario con cuenta) apruebe la hoja
 * antes de que pase al aprobador externo. Ver App\Services\InternalApprovalService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('requires_internal_approval')->default(false);
        });

        Schema::create('project_internal_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::table('timesheets', function (Blueprint $table) {
            // Etapa mientras status = submitted: 'internal' (esperan supervisores) | 'external' (espera el aprobador externo).
            $table->string('stage', 10)->nullable();
            $table->timestamp('internal_reminded_at')->nullable();
        });
        DB::table('timesheets')->where('status', 'submitted')->update(['stage' => 'external']);

        Schema::create('timesheet_internal_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('skipped')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->index(['timesheet_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_internal_approvals');
        Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn(['stage', 'internal_reminded_at']));
        Schema::dropIfExists('project_internal_approvers');
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('requires_internal_approval'));
    }
};
