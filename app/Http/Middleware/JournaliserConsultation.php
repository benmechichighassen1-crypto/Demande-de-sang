<?php

namespace App\Http\Middleware;

use App\Models\Demande;
use App\Services\Journal;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trace chaque page consultée par un utilisateur connecté. L'écriture se fait
 * après l'envoi de la page (terminate) : elle ne ralentit pas l'affichage.
 * Seuls des identifiants sont gardés (jamais le contenu d'une demande).
 */
class JournaliserConsultation
{
    /** Filtres utiles à l'audit, repris de l'adresse. */
    private const FILTRES = ['q', 'num_demande', 'num_doss', 'du', 'au', 'vue', 'statut', 'etape'];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->user() || ! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return;
        }
        $details = ['route' => $request->route()?->getName()];
        $demandeId = null;
        foreach ($request->route()?->parameters() ?? [] as $nom => $valeur) {
            if ($valeur instanceof Demande) {
                $demandeId = $valeur->id;
                $details += ['demande' => $valeur->id, 'dossier' => $valeur->num_doss, 'dmi' => $valeur->num_demande_clinisys];
            } elseif ($valeur instanceof Model) {
                $details[$nom] = $valeur->getKey();
            } elseif (is_scalar($valeur)) {
                $details[$nom] = $valeur;
            }
        }
        foreach (self::FILTRES as $f) {
            if (filled($request->query($f))) {
                $details[$f] = mb_substr((string) $request->query($f), 0, 60);
            }
        }
        Journal::ecrire('page', array_filter($details, fn ($v) => $v !== null), null, $demandeId);
    }
}
