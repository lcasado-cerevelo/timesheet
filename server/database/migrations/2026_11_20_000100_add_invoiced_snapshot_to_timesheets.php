<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Horas facturables que ya salieron en la factura del consultor, por proyecto|servicio|tarifa. Si la hoja termina aprobada con más horas, la diferencia sale como ajuste en la próxima factura.
            $table->json('invoiced_snapshot')->nullable()->after('contractor_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn('invoiced_snapshot'));
    }
};
