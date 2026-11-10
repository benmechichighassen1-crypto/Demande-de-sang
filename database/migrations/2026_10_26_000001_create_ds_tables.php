<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tables propres à l'application (base dédiée, jamais dans Clinisys).
 *
 * ds_actions est le registre : une ligne par action, jamais modifiée ni
 * supprimée. Une erreur se corrige par une action « correction » qui pointe
 * vers l'action annulée. Les colonnes d'état de ds_demandes et ds_poches ne
 * sont qu'un résumé recalculé à chaque action, pour les listes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ds_comptes', function (Blueprint $table) {
            $table->string('user_name', 50)->primary();   // login Clinisys, ou login local (coursier sans compte Clinisys)
            $table->string('nom', 120);
            $table->string('roles', 200)->default('');     // rôles séparés par des virgules
            $table->string('service_code', 20)->nullable(); // service de rattachement (infirmière, major)
            $table->string('pin_hash', 255)->nullable();   // compte local uniquement
            $table->boolean('admin')->default(false);
            $table->boolean('actif')->default(true);
            $table->string('modifie_par', 50)->nullable();
            $table->dateTime('modifie_le')->nullable();
        });

        Schema::create('ds_services', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('libelle', 120);
            $table->boolean('actif')->default(true);
            $table->integer('ordre')->default(0);
        });

        Schema::create('ds_demandes', function (Blueprint $table) {
            $table->id();
            $table->string('num_demande_clinisys', 30)->nullable()->index(); // « Entête demande de sang » (ex. 694)
            $table->string('num_doss', 20)->index();
            $table->string('identifiant_patient', 20)->nullable()->index();
            $table->string('nom_patient', 150);
            $table->boolean('externe')->default(false);
            $table->string('urgence', 12);                 // vitale | urgente | programmee
            $table->string('nature', 15);                  // acheminement | instance
            $table->string('produit', 10);
            $table->unsignedSmallInteger('nb_poches');
            $table->string('groupe_sanguin', 4)->nullable();
            $table->string('diagnostic', 255);
            $table->string('medecin', 120)->nullable();
            $table->string('service_saisie_dmi', 120)->nullable(); // service lu dans Clinisys, pour comparaison
            $table->string('service_source', 20)->nullable();      // dernier service saisi par le major (résumé)
            $table->string('service_destination', 20)->nullable();
            $table->string('etape', 10)->nullable();       // dernière étape faite (résumé)
            $table->dateTime('etape_le')->nullable();
            $table->string('statut', 15)->default('en_cours')->index(); // en_cours | cloturee | annulee
            $table->string('cree_par', 50);
            $table->dateTime('cree_le')->index();
        });

        Schema::create('ds_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('ds_demandes');
            $table->string('etape', 30)->index();          // A4, C3… ou creation, services, correction…
            $table->string('user_name', 50)->index();
            $table->string('nom_acteur', 120);
            $table->dateTime('fait_le')->index();
            $table->text('donnees')->nullable();           // JSON des champs saisis
            $table->text('commentaire')->nullable();
            $table->foreignId('annule_action_id')->nullable()->constrained('ds_actions');
            $table->foreignId('remise_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('appareil', 255)->nullable();
        });

        Schema::create('ds_remises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('ds_demandes');
            $table->string('etape', 10);
            $table->string('donneur', 50);
            $table->string('nom_donneur', 120);
            $table->dateTime('remis_le');
            $table->string('receveur', 50)->nullable();
            $table->string('nom_receveur', 120)->nullable();
            $table->dateTime('repondu_le')->nullable();
            $table->string('reponse', 10)->default('attente')->index(); // attente | confirmee | refusee
            $table->string('motif_refus', 255)->nullable();
        });

        Schema::create('ds_services_demande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('ds_demandes');
            $table->string('service_source', 20)->nullable();
            $table->string('service_destination', 20)->nullable();
            $table->boolean('transfert')->default(false);
            $table->string('saisi_par', 50);
            $table->dateTime('saisi_le');
        });

        // Poches : saisies au contrôle de conformité (C3) en attendant la lecture
        // des réceptions et sorties dans Clinisys pharmacie.
        Schema::create('ds_poches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('ds_demandes');
            $table->string('numero', 40)->index();
            $table->string('produit', 10);
            $table->string('groupe_sanguin', 4)->nullable();
            $table->date('peremption')->nullable();
            $table->string('bon_depot', 30)->nullable();    // BD… (C5)
            // attente (non encore reçue en pharmacie) | en_stock | delivree | transfusee | prelevee (prélèvement fournisseur)
            $table->string('statut', 12)->default('attente')->index();
            $table->string('motif', 30)->nullable();
            $table->string('bon_prelevement', 30)->nullable(); // PR…
            $table->dateTime('statut_le')->nullable();
        });

        Schema::create('ds_parametres', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
            $table->string('valeur', 255);
            $table->string('modifie_par', 50)->nullable();
            $table->dateTime('modifie_le')->nullable();
        });

        Schema::create('ds_journal', function (Blueprint $table) {
            $table->id();
            $table->string('user_name', 50)->nullable()->index();
            $table->string('action', 40)->index();
            $table->text('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('navigateur', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->dateTime('cree_le')->index();
        });
    }

    public function down(): void
    {
        foreach (['ds_journal', 'ds_parametres', 'ds_poches', 'ds_services_demande', 'ds_remises', 'ds_actions', 'ds_demandes', 'ds_services', 'ds_comptes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
