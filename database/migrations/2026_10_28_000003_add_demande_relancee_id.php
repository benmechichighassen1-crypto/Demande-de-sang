<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Relance : le CTS livre souvent moins de poches que demandé et l'équipe refait une
 * demande DMI pour le reste. La nouvelle demande pointe vers celle qu'elle relance
 * (un seul besoin au registre et aux indicateurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->foreignId('demande_relancee_id')->nullable()->index()->constrained('ds_demandes');
        });
    }

    public function down(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demande_relancee_id');
        });
    }
};
