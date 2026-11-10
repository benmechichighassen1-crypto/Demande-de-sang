<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index des colonnes filtrées à chaque affichage (À faire, Pharmacie, Alertes). Aucune donnée n'est modifiée.
 */
return new class extends Migration
{
    private const INDEX = [
        'ds_remises' => [['reponse'], ['donneur']],
        'ds_poches' => [['statut']],
        'ds_demandes' => [['num_demande_clinisys'], ['statut', 'etape']],
    ];

    public function up(): void
    {
        foreach (self::INDEX as $table => $liste) {
            foreach ($liste as $colonnes) {
                if (! Schema::hasIndex($table, $colonnes)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($colonnes));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEX as $table => $liste) {
            foreach ($liste as $colonnes) {
                if (Schema::hasIndex($table, $colonnes)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($colonnes));
                }
            }
        }
    }
};
