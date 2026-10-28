<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // worked = las horas que la persona trabajó; assigned = el % que le correspondía terminar (percent_required) del total de la tarea.
            $table->string('commission_basis', 10)->default('worked')->after('extra_per_hour');
        });
        Schema::table('commission_results', function (Blueprint $table) {
            $table->string('commission_basis', 10)->default('worked');
            $table->decimal('commission_hours', 8, 2)->default(0);     // las horas sobre las que se pagó la comisión individual
        });
        DB::table('commission_results')->update(['commission_hours' => DB::raw('user_hours')]);
    }

    public function down(): void
    {
        Schema::table('commission_results', fn (Blueprint $t) => $t->dropColumn(['commission_basis', 'commission_hours']));
        Schema::table('commissions', fn (Blueprint $t) => $t->dropColumn('commission_basis'));
    }
};
