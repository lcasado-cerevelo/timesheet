<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comisiones por productividad (ver diseno.md, v1.11). La comisión es del trabajador y de un proyecto (una por proyecto):
 * dice qué servicios y tareas cuentan, cuánto debe haber trabajado del total de la tarea, cuánto extra gana por hora y cómo se
 * reparte el ahorro. Al completar una tarea se guarda un resultado por persona (commission_results) con el cálculo completo, para
 * que no cambie después; el dueño lo libera y entonces puede facturarse (consultores) o marcarse pagado (empleados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->decimal('percent_required', 5, 2)->default(80);   // % del total trabajado en la tarea que esta persona debe haber hecho
            $table->decimal('extra_per_hour', 10, 2)->default(0);     // extra por cada hora suya en la tarea
            $table->string('savings_mode', 10)->default('fixed');     // fixed | assigned (el % que le correspondía hacer)
            $table->decimal('savings_pct', 5, 2)->default(100);       // % del ahorro que se reconoce (si fixed)
            $table->decimal('savings_per_hour', 10, 2)->default(0);   // monto por hora ahorrada
            $table->decimal('tolerance_hours', 6, 2)->default(0);     // horas que se perdonan sobre la meta de ahorro
            $table->boolean('withhold')->default(true);               // lleva retención en la factura
            $table->boolean('ivu')->default(false);                   // lleva IVU retenido en la factura
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        Schema::create('commission_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unique(['commission_id', 'service_id']);
        });
        Schema::create('commission_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unique(['commission_id', 'task_id']);
        });

        Schema::create('commission_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending');          // pending | released | invoiced | paid | none
            // El cálculo, tal como salió al completar la tarea:
            $table->date('completed_on');
            $table->date('deadline')->nullable();
            $table->decimal('budget_hours', 8, 2);
            $table->decimal('goal_hours', 8, 2);
            $table->decimal('tolerance_hours', 6, 2)->default(0);
            $table->decimal('total_hours', 8, 2);
            $table->decimal('user_hours', 8, 2);
            $table->decimal('user_pct', 6, 2)->default(0);
            $table->decimal('saved_hours', 8, 2)->default(0);
            $table->decimal('percent_required', 5, 2);
            $table->decimal('extra_per_hour', 10, 2);
            $table->string('savings_mode', 10);
            $table->decimal('savings_pct', 5, 2);                       // el % del ahorro que se aplicó (ya resuelto según el modo)
            $table->decimal('savings_per_hour', 10, 2);
            $table->boolean('met_goal')->default(false);
            $table->boolean('met_date')->default(false);
            $table->boolean('worked_min')->default(false);              // trabajó al menos 1 hora en la tarea
            $table->boolean('met_share')->default(false);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('savings_amount', 12, 2)->default(0);
            $table->decimal('adjustment', 12, 2)->default(0);           // ajuste manual del dueño
            $table->string('adjustment_note', 500)->nullable();
            $table->decimal('total', 12, 2)->default(0);                // commission + savings + adjustment
            $table->boolean('withhold')->default(true);
            $table->boolean('ivu')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contractor_invoice_id')->nullable()->constrained('contractor_invoices')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_results');
        Schema::dropIfExists('commission_task');
        Schema::dropIfExists('commission_service');
        Schema::dropIfExists('commissions');
    }
};
