<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Services source / destination = services de soin Clinisys (prmEtage), lecture seule.
Artisan::command('ds:services-clinisys', function (App\Services\ServicesClinisys $services) {
    $r = $services->synchroniser();
    $this->info($r['ajoutes'].' ajouté(s), '.$r['mis_a_jour'].' mis à jour, désactivés : '.(implode(', ', $r['desactives']) ?: 'aucun'));
})->purpose('Recopie les services de soin de Clinisys dans ds_services');

// N° CIN des demandes qui n'en ont pas : repris de Clinisys (lecture seule). Lancé par mise-a-jour.bat.
Artisan::command('ds:cin', function () {
    \Illuminate\Support\Facades\Cache::forget('schema.ds_demandes.cin');
    $this->info(\App\Services\CinPatients::completerTout().' CIN repris de Clinisys.');
})->purpose('Reprend le n° CIN des patients depuis Clinisys pour les demandes qui n\'en ont pas');

// Étapes pharmacie lues dans Clinisys (C5 bon de dépôt, C8 sortie patient, retours PR), à la demande.
Artisan::command('ds:sync-clinisys', function (App\Services\SyncClinisys $sync) {
    $r = $sync->synchroniser();
    $this->info("Clinisys : {$r['c5']} réception(s) C5, {$r['c8']} sortie(s) patient C8, {$r['pr']} retour(s) fournisseur.");
})->purpose('Enregistre les étapes de la pharmacie lues dans Clinisys');

// Désactivé par défaut (pas de charge sur le serveur Clinisys réel) : la lecture se fait à l'ouverture des écrans.
if (env('DS_SYNC_PLANIFIEE', false)) {
    Illuminate\Support\Facades\Schedule::command('ds:sync-clinisys')->everyFiveMinutes()->withoutOverlapping();
}
