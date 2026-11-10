<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Demande inscrite a posteriori (« régularisation ») : la demande DMI a été réceptionnée en pharmacie
 * (état V) sans avoir été inscrite au registre. Motif obligatoire ; les étapes d'avant la pharmacie
 * restent « non tracées » (aucun faux horodatage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->string('regularisation', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ds_demandes', fn (Blueprint $table) => $table->dropColumn('regularisation'));
    }
};
