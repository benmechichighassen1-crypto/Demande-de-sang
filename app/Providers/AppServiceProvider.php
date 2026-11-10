<?php

namespace App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Mode démonstration (vidéos, formations) : Clinisys fictif, jamais de vrai patient.
        if (env('DS_DEMO')) {
            \App\Demo\ClinisysDemo::enregistrer($this->app);
        }
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        // Pagination au style de l'appli (pas de Tailwind) : Précédent / numéros / Suivant, traduite fr / ar.
        Paginator::defaultView('partials.pagination');
        Paginator::defaultSimpleView('partials.pagination-simple');

        // Le login SQL est en français (DATEFORMAT dmy) : « 2026-10-27 18:09:50 » serait lu
        // jour 2026, mois 27… Laravel envoie les dates en Y-m-d : on l'impose à chaque connexion
        // SQL Server (base de l'appli et Clinisys). SET DATEFORMAT ne modifie aucune donnée.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $e): void {
            if ($e->connection->getDriverName() === 'sqlsrv') {
                $e->connection->getPdo()->exec('SET DATEFORMAT ymd;');
            }
        });
    }
}
