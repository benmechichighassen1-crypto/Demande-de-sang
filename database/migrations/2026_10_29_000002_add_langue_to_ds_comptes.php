<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Langue d'affichage choisie par l'utilisateur (fr ou ar), reprise à chaque connexion. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_comptes', function (Blueprint $table) {
            $table->string('langue', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ds_comptes', fn (Blueprint $table) => $table->dropColumn('langue'));
    }
};
