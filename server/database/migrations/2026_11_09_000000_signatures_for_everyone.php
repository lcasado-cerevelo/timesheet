<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma digital en todas las pantallas (ver diseno.md, v1.11, fase 4). Una firma puede ser de una persona con cuenta (contraseña) o de un
 * aprobador externo que aprueba desde el enlace del correo (código de 6 dígitos): para esa, no hay usuario, hay aprobador.
 *  - user_id pasa a ser opcional y se agrega approver_id.
 *  - method: password | email_code.
 *  - signer_name: el nombre de quien firmó, tal como era al firmar (los informes lo muestran aunque la cuenta cambie después).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
        Schema::table('signatures', function (Blueprint $table) {
            $table->foreignId('approver_id')->nullable()->after('user_id')->constrained('approvers')->nullOnDelete();
            $table->string('method', 12)->default('password')->after('action');
            $table->string('signer_name', 120)->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_id');
            $table->dropColumn(['method', 'signer_name']);
        });
    }
};
