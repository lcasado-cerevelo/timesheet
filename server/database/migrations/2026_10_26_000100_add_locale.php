<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Idioma: cada persona puede escoger el suyo (es o en); si no, se usa el de la compañía. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('timezone');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('locale'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('locale'));
    }
};
