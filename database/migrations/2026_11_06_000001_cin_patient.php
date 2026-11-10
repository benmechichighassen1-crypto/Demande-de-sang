<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N° CIN du patient sur la demande (exigé par la banque de sang) : repris de Clinisys (Client.NumCIN), sinon saisi dans
 * l'appli. cin_source : clinisys | saisie | autre (pas de CIN : passeport ou autre pièce). Rien n'est écrit dans Clinisys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->string('cin', 40)->nullable()->index();
            $table->string('cin_source', 10)->nullable();
        });

        // Demandes déjà inscrites : CIN repris de Clinisys (lecture seule), si Clinisys répond (repris aussi à chaque mise à jour).
        \Illuminate\Support\Facades\Cache::forget('schema.ds_demandes.cin');
        \App\Services\CinPatients::completerTout();
    }

    public function down(): void
    {
        Schema::table('ds_demandes', function (Blueprint $table) {
            $table->dropIndex(['cin']);
            $table->dropColumn(['cin', 'cin_source']);
        });
    }
};
