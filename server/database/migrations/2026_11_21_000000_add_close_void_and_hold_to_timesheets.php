<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // La compañía cerró la hoja sin la aprobación del cliente: cuenta como aprobada, pero no se factura al cliente.
            $table->boolean('closed_by_company')->default(false)->after('invoiced_snapshot');
            // Hoja anulada por el dueño (horas que no valen): sus entradas se borran y queda el rastro en el historial.
            $table->dateTime('voided_at')->nullable()->after('closed_by_company');
            $table->string('void_reason', 1000)->nullable()->after('voided_at');
            // En pausa hasta esa fecha: no se mandan recordatorios. Las fechas de las horas no se tocan.
            $table->date('hold_until')->nullable()->after('void_reason');
            $table->string('hold_reason', 500)->nullable()->after('hold_until');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn(['closed_by_company', 'voided_at', 'void_reason', 'hold_until', 'hold_reason']));
    }
};
