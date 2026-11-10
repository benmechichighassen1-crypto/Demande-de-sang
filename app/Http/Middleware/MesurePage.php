<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mesure de chaque page pour l'administrateur : en-tête Server-Timing (outils du navigateur) et petite ligne
 * en bas de page « page 85 ms · 12 req · 2 Clinisys ». Rien pour les autres comptes.
 */
class MesurePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $debut = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $appli = $clinisys = 0;
        $msAppli = $msClinisys = 0.0;
        $connexion = config('demandesang.connexion');
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$appli, &$clinisys, &$msAppli, &$msClinisys, $connexion): void {
            if ($e->connectionName === $connexion) {
                $clinisys++;
                $msClinisys += $e->time;
            } else {
                $appli++;
                $msAppli += $e->time;
            }
        });

        $reponse = $next($request);

        if (! $request->user()?->admin) {
            return $reponse;
        }
        $total = (int) round((microtime(true) - $debut) * 1000);
        $reponse->headers->set('Server-Timing', sprintf('page;dur=%d, appli;desc="%d req";dur=%d, clinisys;desc="%d req";dur=%d', $total, $appli, $msAppli, $clinisys, $msClinisys));
        if (str_contains((string) $reponse->headers->get('Content-Type'), 'text/html')) {
            $contenu = (string) $reponse->getContent();
            if (($p = strrpos($contenu, '</main>')) !== false) {
                $ligne = sprintf('<div class="mesure-page non-imprime">page %d ms · %d req · %d Clinisys</div>', $total, $appli + $clinisys, $clinisys);
                $reponse->setContent(substr_replace($contenu, $ligne, $p, 0));
            }
        }

        return $reponse;
    }
}
