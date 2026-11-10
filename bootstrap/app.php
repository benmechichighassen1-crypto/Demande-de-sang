<?php

use App\Http\Middleware\JournaliserConsultation;
use App\Http\Middleware\Role;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'journal' => JournaliserConsultation::class,
            'role' => Role::class,
        ]);
        // Langue d'affichage (fr / ar) sur toutes les pages web.
        $middleware->web(append: [\App\Http\Middleware\Langue::class, \App\Http\Middleware\MesurePage::class]);
        // Le cookie de langue n'est pas chiffré : il ne contient que « fr » ou « ar ».
        $middleware->encryptCookies(except: ['langue']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('accueil'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
