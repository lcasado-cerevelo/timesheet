<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tareas de un proyecto. Son opcionales: un proyecto puede no tener ninguna y una entrada de horas puede no llevar tarea.
 * No cambian tarifas ni aprobaciones (eso lo sigue mandando el servicio); solo dicen en qué se trabajó.
 * Las tareas no se borran, se desactivan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('service_id')->constrained('tasks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('task_id'));
        Schema::dropIfExists('tasks');
    }
};
