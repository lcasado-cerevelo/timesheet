<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->boolean('require_code')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'email']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('accountisi_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('approvers')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('billable')->default(true);
            $table->boolean('active')->default(true);
            // hours_total | by_day | with_times: cómo se detalla la factura
            $table->string('invoice_detail', 20)->default('hours_total');
            $table->decimal('bill_rate', 10, 2)->nullable();
            $table->decimal('budget_hours', 8, 2)->nullable();
            // Campos libres por proyecto (p. ej. plan_medico y numero_contrato para Consultorio 1500)
            $table->json('meta')->nullable();
            // Lugar de trabajo (geocerca) para avisar al llegar
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->unsignedInteger('geo_radius_m')->nullable();
            $table->string('accountisi_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('contractor_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('retention_pct', 5, 2)->default(0);
            $table->boolean('ivu_agent')->default(false);
            $table->decimal('ivu_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('terms_days')->default(30);
            $table->string('accountisi_vendor_id')->nullable();
            $table->timestamps();
        });

        Schema::create('contractor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('issued_on');
            $table->date('due_on');
            $table->decimal('gross', 12, 2);
            $table->decimal('retention', 12, 2)->default(0);
            $table->decimal('ivu', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->json('lines');
            $table->string('status', 20)->default('issued'); // issued | sent | paid
            $table->string('pdf_path')->nullable();
            $table->string('accountisi_bill_id')->nullable();
            $table->string('accountisi_status', 20)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_ending');
            $table->foreignId('approver_id')->nullable()->constrained('approvers')->nullOnDelete();
            // draft | submitted | approved | returned
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->decimal('total_hours', 7, 2)->default(0);
            $table->boolean('late')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_approver_id')->nullable()->constrained('approvers')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_via', 20)->nullable();
            $table->string('return_comment', 1000)->nullable();
            // Aviso de cambio pendiente (se envía tras un periodo sin ediciones)
            $table->timestamp('change_notice_due_at')->nullable();
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('last_notified_version')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('contractor_invoice_id')->nullable()->constrained('contractor_invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'week_ending']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_invoice_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('project_ids');
            $table->decimal('total_hours', 8, 2);
            $table->string('mode', 20); // new | append
            $table->string('accountisi_invoice_id')->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('status', 20)->default('created');
            $table->timestamps();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_sync_id')->nullable()->constrained('sales_invoice_syncs')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->date('work_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('running')->default(false);
            $table->decimal('hours', 6, 2)->default(0);
            $table->string('note', 1000)->nullable();
            $table->boolean('billable')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'client_uuid']);
            $table->index(['user_id', 'work_date']);
        });

        Schema::create('day_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('note', 1000);
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('timesheet_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('actor', 200)->nullable();
            $table->string('channel', 20)->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 10); // sheet | batch
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('sheet_version')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->timestamp('code_verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('brand_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 60);
            $table->string('format', 10)->default('xlsx'); // xlsx | csv
            $table->json('config');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
        });

        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('export_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_hash', 64);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // accountisi
            $table->boolean('enabled')->default(false);
            $table->text('config')->nullable(); // JSON cifrado
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['integrations', 'export_logs', 'export_templates', 'brand_history', 'approval_tokens',
                  'timesheet_events', 'day_notes', 'time_entries', 'sales_invoice_syncs', 'timesheets',
                  'contractor_invoices', 'contractor_terms', 'project_user', 'projects', 'customers',
                  'approvers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
