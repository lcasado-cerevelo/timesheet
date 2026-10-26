<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobadores por servicio:
 *  - service_internal_approvers: supervisores (usuarios) de cada servicio (antes eran del proyecto: project_internal_approvers).
 *  - service_external_approvers: aprobadores externos que escoge un servicio entre los de su cliente (vacío = todos los del cliente).
 *  - approver_customer: los aprobadores externos se ligan a clientes (muchos a muchos).
 *  - timesheet_internal_approvals.service_id: las partes internas pasan a ser por servicio (nulo en filas viejas, que eran por proyecto).
 *  - timesheet_external_approvals: las partes externas de una hoja (una por servicio) ya aprobadas.
 * users.approver_id y projects.approver_id se CONSERVAN: ya no mandan, solo sirven de respaldo para servicios viejos sin configurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_internal_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['service_id', 'user_id']);
        });
        Schema::create('service_external_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained()->cascadeOnDelete();
            $table->unique(['service_id', 'approver_id']);
        });
        Schema::create('approver_customer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unique(['approver_id', 'customer_id']);
        });
        Schema::table('timesheet_internal_approvals', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('timesheet_external_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('approved_by_approver_id')->nullable()->constrained('approvers')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->index(['timesheet_id', 'service_id']);
        });

        $this->backfill();
    }

    /**
     * Migración de datos (idempotente, no toca hojas en vuelo):
     *  1. la lista interna de cada proyecto se copia a cada servicio internal/both de ese proyecto;
     *  2. cada aprobador externo se liga a los clientes de los proyectos donde ya actuaba (projects.approver_id, o
     *     users.approver_id de las personas asignadas al proyecto). Sin señal, queda sin cliente.
     * Las listas externas de los servicios quedan vacías (= todos los aprobadores del cliente).
     */
    public function backfill(): void
    {
        $lists = DB::table('project_internal_approvers')->get()->groupBy('project_id');
        DB::table('services')->whereIn('approval_mode', ['internal', 'both'])->get()->each(function ($svc) use ($lists) {
            if (DB::table('service_internal_approvers')->where('service_id', $svc->id)->exists()) {
                return;
            }
            foreach ($lists->get($svc->project_id, collect()) as $row) {
                DB::table('service_internal_approvers')->insertOrIgnore(['service_id' => $svc->id, 'user_id' => $row->user_id]);
            }
        });

        DB::table('projects')->whereNotNull('approver_id')->whereNotNull('customer_id')->get(['approver_id', 'customer_id'])
            ->each(fn ($p) => DB::table('approver_customer')->insertOrIgnore(['approver_id' => $p->approver_id, 'customer_id' => $p->customer_id]));

        DB::table('project_user')->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->join('users', 'users.id', '=', 'project_user.user_id')
            ->whereNotNull('projects.customer_id')->whereNotNull('users.approver_id')
            ->get(['users.approver_id as approver_id', 'projects.customer_id as customer_id'])
            ->each(fn ($r) => DB::table('approver_customer')->insertOrIgnore(['approver_id' => $r->approver_id, 'customer_id' => $r->customer_id]));
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_external_approvals');
        Schema::table('timesheet_internal_approvals', fn (Blueprint $t) => $t->dropConstrainedForeignId('service_id'));
        Schema::dropIfExists('approver_customer');
        Schema::dropIfExists('service_external_approvers');
        Schema::dropIfExists('service_internal_approvers');
    }
};
