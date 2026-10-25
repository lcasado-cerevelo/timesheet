<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas y contraseñas: dominio por compañía, invitaciones/reseteos de un solo uso, código por correo al entrar,
 * dispositivos de confianza, cuentas de aprobador y registro de seguridad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('domain', 190)->nullable()->unique();
        });
        Schema::table('approvers', function (Blueprint $table) {
            $table->boolean('can_login')->default(false);
        });
        Schema::table('users', function (Blueprint $table) {
            // true mientras la persona no haya creado su contraseña (invitación o reseteo pendientes)
            $table->boolean('must_set_password')->default(false);
            $table->foreignId('approver_profile_id')->nullable()->constrained('approvers')->nullOnDelete();
        });

        Schema::create('password_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 10); // invite | reset
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('challenge_hash', 64)->unique();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(1);
            $table->timestamp('code_expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['security_events', 'trusted_devices', 'login_challenges', 'password_tokens'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_profile_id');
            $table->dropColumn('must_set_password');
        });
        Schema::table('approvers', fn (Blueprint $t) => $t->dropColumn('can_login'));
        Schema::table('companies', function (Blueprint $t) {
            $t->dropUnique(['domain']);
            $t->dropColumn('domain');
        });
    }
};
