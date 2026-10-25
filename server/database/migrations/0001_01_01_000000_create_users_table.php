<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('America/Puerto_Rico');
            // Marca (editable desde Configuración > Marca)
            $table->string('brand_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('color_primary', 7)->default('#1f4e79');
            $table->string('color_accent', 7)->default('#2e8b57');
            $table->string('email_from_name')->nullable();
            $table->string('email_from_address')->nullable();
            $table->string('email_footer', 500)->nullable();
            // Reglas (JSON; valores por omisión en App\Support\Rules)
            $table->json('rules')->nullable();
            $table->string('invoice_prefix', 20)->default('INV');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // owner = dueño de la compañía; member = consultor o empleado
            $table->string('role', 20)->default('member');
            // consultant | employee
            $table->string('worker_type', 20)->default('consultant');
            // full_day (total por día) | from_to (desde/hasta). Lo fija el dueño.
            $table->string('capture_mode', 20)->default('from_to');
            // Solo empleados: horas por semana; sobre esto solo se avisa.
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
