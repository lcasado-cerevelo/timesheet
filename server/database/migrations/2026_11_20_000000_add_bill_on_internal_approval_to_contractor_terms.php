<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_terms', function (Blueprint $table) {
            // El consultor factura sus hojas con la aprobación interna, sin esperar al aprobador externo (cliente).
            $table->boolean('bill_on_internal_approval')->default(false)->after('ivu_agent');
        });
    }

    public function down(): void
    {
        Schema::table('contractor_terms', fn (Blueprint $t) => $t->dropColumn('bill_on_internal_approval'));
    }
};
