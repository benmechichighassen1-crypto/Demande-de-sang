<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cas du patient (diagramme, pages 3 à 5) : hospitalisé, externe, ou hospitalisé avec transfusion hors séjour (second dossier).
 * La colonne externe reste : elle vaut 1 pour les deux cas qui passent par l'ouverture du dossier et le paiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->string('cas_patient', 15)->default('hospitalise');
        });
        DB::table('ds_demandes')->where('externe', true)->update(['cas_patient' => 'externe']);
    }

    public function down(): void
    {
        Schema::table('ds_demandes', fn (Blueprint $table) => $table->dropColumn('cas_patient'));
    }
};
