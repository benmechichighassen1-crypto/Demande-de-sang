<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue d'affichage : français (par défaut) ou arabe. Choisie sur la page de connexion ou dans l'en-tête,
 * gardée dans la session et dans un cookie (même appareil), et sur le compte (ds_comptes.langue).
 */
class Langue
{
    public const LANGUES = ['fr' => 'Français', 'ar' => 'العربية'];

    public function handle(Request $request, Closure $next): Response
    {
        $langue = $request->session()->get('langue') ?? $request->cookie('langue');
        $langue = array_key_exists((string) $langue, self::LANGUES) ? $langue : 'fr';
        app()->setLocale($langue);
        Carbon::setLocale($langue);

        return $next($request);
    }
}
