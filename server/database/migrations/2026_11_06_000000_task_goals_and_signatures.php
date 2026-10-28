<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de la tarea para las comisiones por productividad (ver diseno.md, v1.11) y firmas digitales con contraseña.
 *  - tasks.estimated_end: fecha estimada de terminación (editable). Completarla después de esta fecha pierde el bono.
 *  - tasks.savings_goal_pct: meta de ahorro sobre el presupuesto de horas (p. ej. 20 = terminarla en el 80% de las horas).
 *  - tasks.commissionable: la tarea puede entrar en una comisión.
 *  - signatures: quién firmó qué, cuándo, desde dónde y con la huella del contenido firmado (firma digital con contraseña).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('estimated_end')->nullable()->after('budget_hours');
            $table->decimal('savings_goal_pct', 5, 2)->nullable()->after('estimated_end');
            $table->boolean('commissionable')->default(false)->after('savings_goal_pct');
        });

        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);                    // task.complete, task.reopen, ...
            $table->string('subject_type', 40)->nullable();  // task, timesheet, invoice, ...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('fingerprint', 64);               // sha256 del contenido firmado
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('signed_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['company_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['estimated_end', 'savings_goal_pct', 'commissionable']));
    }
};
