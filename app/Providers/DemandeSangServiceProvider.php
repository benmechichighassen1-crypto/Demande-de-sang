<?php

namespace App\Providers;

use App\Auth\FournisseurUtilisateurs;
use App\Auth\VerificateurMotDePasse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class DemandeSangServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('demandesang', fn ($app) => new FournisseurUtilisateurs($app->make(VerificateurMotDePasse::class)));
    }
}
