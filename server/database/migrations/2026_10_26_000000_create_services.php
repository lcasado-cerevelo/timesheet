<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios dentro de un proyecto. El servicio define la tarifa, si se factura y cómo se entran las horas
 * (detail = desde/hasta, total = total de horas del día, both = lo decide la preferencia de la persona).
 * Cada entrada guarda la tarifa vigente al crearse, así un cambio de tarifa solo afecta entradas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('bill_rate', 10, 2)->nullable();
            $table->boolean('billable')->default(true);
            $table->string('capture_mode', 10)->default('both'); // detail | total | both
            $table->string('accountisi_item_id', 40)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('project_id')->constrained('services')->restrictOnDelete();
            $table->decimal('bill_rate', 10, 2)->nullable()->after('billable'); // tarifa de venta guardada al crear la entrada
            $table->decimal('pay_rate', 10, 2)->nullable()->after('bill_rate');  // tarifa del consultor guardada al crear la entrada
        });

        // Los proyectos que ya existen reciben un servicio "General" que se comporta igual que antes.
        $now = now();
        foreach (DB::table('projects')->get() as $p) {
            $sid = DB::table('services')->insertGetId([
                'company_id' => $p->company_id, 'project_id' => $p->id, 'name' => 'General', 'bill_rate' => null,
                'billable' => (bool) $p->billable, 'capture_mode' => 'both', 'active' => true, 'sort' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('time_entries')->where('project_id', $p->id)->update(['service_id' => $sid, 'bill_rate' => $p->bill_rate]);
        }
        // Tarifa del consultor de las horas que ya existen.
        foreach (DB::table('contractor_terms')->get() as $t) {
            DB::table('time_entries')->where('user_id', $t->user_id)->update(['pay_rate' => $t->rate]);
        }
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['bill_rate', 'pay_rate']);
        });
        Schema::dropIfExists('services');
    }
};
