<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SQL Server ne crée pas d'index pour une clé étrangère : chaque chargement des
 * actions / remises / poches d'une demande parcourait toute la table.
 */
return new class extends Migration
{
    private const TABLES = ['ds_actions', 'ds_remises', 'ds_poches', 'ds_services_demande'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->index('demande_id'));
        }
        Schema::table('ds_actions', fn (Blueprint $table) => $table->index('remise_id'));
    }

    public function down(): void
    {
        Schema::table('ds_actions', fn (Blueprint $table) => $table->dropIndex(['remise_id']));
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropIndex(['demande_id']));
        }
    }
};
