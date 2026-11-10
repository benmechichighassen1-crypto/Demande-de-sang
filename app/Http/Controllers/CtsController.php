<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Services\Alertes;
use App\Services\Circuit;
use App\Services\Referentiels;
use App\Support\Clinisys as C;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Écran « CTS » du coursier : où en est chaque demande entre le service, le CTS et la clinique.
 *
 * Nature « instance » : à B2, le coursier indique que les poches restent gardées au CTS
 * (etat_cts = instance) ; la demande attend alors C1 (« Poches livrées à la clinique »), faite
 * quand le coursier les ramène. Pas d'alerte de délai pendant l'instance, sauf au-delà de N jours.
 */
class CtsController extends Controller
{
    public const BLOCS = [
        'recuperer' => 'À récupérer dans les services',
        'deposer' => 'À déposer au CTS',
        'attente' => 'Déposées, en attente au CTS',
        'ramener' => 'À ramener : poches prêtes',
        'instance' => 'En instance au CTS',
    ];

    public function index(Request $request, Circuit $circuit, Referentiels $ref, Alertes $alertes): View
    {
        $blocs = array_fill_keys(array_keys(self::BLOCS), []);
        $demandes = Demande::with('actions', 'remises')->where('statut', 'en_cours')
            ->orderByRaw("CASE urgence WHEN 'vitale' THEN 0 WHEN 'urgente' THEN 1 ELSE 2 END")->orderBy('cree_le')->get();
        \App\Services\CinPatients::completer($demandes);

        foreach ($demandes as $d) {
            $e = $circuit->etat($d);
            $fait = fn ($c) => ($e[$c]['statut'] ?? '') === 'faite';
            $bloc = match (true) {
                ! $fait('A11') && in_array($e['A11']['statut'], ['disponible', 'remise_attente'], true) => 'recuperer',
                $fait('A11') && ! $fait('B1') => 'deposer',
                $fait('B1') && ! $fait('B2') => 'attente',
                $fait('B2') && ! $fait('C1') => ($e['B2']['action']?->donnees['etat_cts'] ?? '') === 'instance' ? 'instance' : 'ramener',
                default => null,
            };
            if ($bloc) {
                $b2 = $e['B2']['action'];
                $blocs[$bloc][] = [
                    'demande' => $d,
                    'etat' => $e,
                    'depuis' => $d->etape_le ?? $d->cree_le,
                    'remise' => $e['A11']['remise'],
                    'nb_pretes' => $b2?->donnees['nb_pretes'] ?? null,
                ];
            }
        }

        return view('cts.index', [
            'blocs' => $blocs, 'ref' => $ref, 'alertes' => $alertes, 'circuit' => $circuit,
            'instanceJours' => (int) $ref->parametre('instance_jours', config('demandesang.instance_jours')),
        ]);
    }
}
