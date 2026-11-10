<?php

namespace App\Http\Controllers;

use App\Clinisys\Pharmacie;
use App\Models\Action;
use App\Models\Demande;
use App\Services\Alertes;
use App\Services\Circuit;
use App\Services\DmiNonInscrites;
use App\Services\Referentiels;
use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Écran « Pharmacie » (consultation) : ce que la pharmacie a reçu et délivré, lu dans Clinisys
 * (C5 = bon de dépôt BD, C8 = sortie patient). Synchronisé à l'ouverture, au plus une fois par minute.
 */
class PharmacieController extends Controller
{
    public const BLOCS = [
        'attente_bd' => 'Portées à la pharmacie, bon BD pas encore saisi',
        'nouvelles' => 'Nouvelles réceptions (24 h)',
        'a_delivrer' => 'Reçues, à délivrer au service',
        'delivrees' => 'Délivrées aujourd\'hui, accusé du service attendu',
    ];

    public function index(Circuit $circuit, Referentiels $ref, Alertes $alertes): View
    {
        $blocs = array_fill_keys(array_keys(self::BLOCS), []);

        // En cours, plus les demandes reçues (C5) depuis 24 h ou délivrées (C8) aujourd'hui, même clôturées depuis.
        $recentes = Action::where(fn ($q) => $q->where('etape', 'C5')->where('fait_le', '>=', now()->subDay()))
            ->orWhere(fn ($q) => $q->where('etape', 'C8')->where('fait_le', '>=', today()))->distinct()->pluck('demande_id');
        $demandes = Demande::with('actions', 'remises', 'poches')
            ->where(fn ($q) => $q->where('statut', 'en_cours')->orWhereIn('id', $recentes))
            ->orderByRaw("CASE urgence WHEN 'vitale' THEN 0 WHEN 'urgente' THEN 1 ELSE 2 END")->orderBy('cree_le')->get();

        foreach ($demandes as $d) {
            $e = $circuit->etat($d);
            $fait = fn ($c) => ($e[$c]['statut'] ?? '') === 'faite';
            $quand = fn ($c) => $e[$c]['action']?->fait_le;
            $ligne = ['demande' => $d, 'etat' => $e];
            $enCours = $d->statut === 'en_cours';

            if ($enCours && $fait('C4') && ! $fait('C5')) {
                $blocs['attente_bd'][] = $ligne + ['depuis' => $quand('C4')];
            }
            if ($fait('C5') && $quand('C5')->gte(now()->subDay())) {
                $blocs['nouvelles'][] = $ligne + ['depuis' => $quand('C5')];
            }
            if ($enCours && $fait('C5') && ! $fait('C8')) {
                $blocs['a_delivrer'][] = $ligne + ['depuis' => $quand('C5')];
            }
            if ($fait('C8') && $quand('C8')->gte(today()) && ! $fait('C9')) {
                $blocs['delivrees'][] = $ligne + ['depuis' => $quand('C8')];
            }
        }
        // Nouvelles réceptions : les plus récentes en haut.
        usort($blocs['nouvelles'], fn ($a, $b) => $b['depuis'] <=> $a['depuis']);

        // Reçues hors registre : demandes DMI réceptionnées (V) jamais inscrites dans l'appli, avec leur bon BD.
        $horsRegistre = [];
        $dmiHors = array_slice(C::essayer(fn () => app(DmiNonInscrites::class)->horsRegistre(), []), 0, 30);
        // Une seule lecture pour toutes ces demandes, gardée une minute.
        $numerosHors = array_map(fn ($x) => (string) $x->ID, $dmiHors);
        $bonsHors = (array) (C::essayer(fn () => C::enCache('clinisys.bons_hors.'.md5(implode(',', $numerosHors)), 60, fn () => app(Pharmacie::class)->bonsDepotPour($numerosHors)), []) ?? []);
        foreach ($dmiHors as $x) {
            $bon = $bonsHors[(string) $x->ID][0] ?? null;
            $horsRegistre[] = ['dmi' => $x, 'bon' => $bon, 'recu_le' => $bon ? Pharmacie::horodatage($bon) : null];
        }
        usort($horsRegistre, fn ($a, $b) => ($b['recu_le'] ?? now()) <=> ($a['recu_le'] ?? now()));

        // Nouvelles réceptions (24 h) que le circuit n'enregistre pas en C5 : bons BD hors registre, et bons BD de demandes
        // inscrites dont l'étape C4 (dépôt à la pharmacie) n'est pas saisie (la synchronisation attend C4).
        $recuesHorsRegistre = array_values(array_filter($horsRegistre, fn ($h) => $h['recu_le'] && $h['recu_le']->gte(now()->subDay())));
        $sansC4 = [];
        $attente = $demandes->filter(fn ($d) => $d->statut === 'en_cours' && $d->num_demande_clinisys && $d->regularisation === null
            && ! in_array($circuit->etat($d)['C4']['statut'] ?? '', ['faite', 'non_tracee'], true));
        if ($attente->isNotEmpty()) {
            $numerosAttente = $attente->pluck('num_demande_clinisys')->map(fn ($n) => (string) $n)->all();
            $parBd = (array) (C::essayer(fn () => C::enCache('clinisys.bd_attente.'.md5(implode(',', $numerosAttente)), 60, fn () => app(Pharmacie::class)->pochesParDemande($numerosAttente)), []) ?? []);
            foreach ($attente as $d) {
                if (($parBd[(string) $d->num_demande_clinisys] ?? 0) > 0) {
                    $sansC4[] = ['demande' => $d, 'poches' => $parBd[(string) $d->num_demande_clinisys]];
                }
            }
        }
        Cache::put('pharmacie.receptions_hors_circuit', count($recuesHorsRegistre) + count($sansC4), 300);

        return view('pharmacie.index', [
            'blocs' => $blocs, 'ref' => $ref, 'alertes' => $alertes, 'horsRegistre' => $horsRegistre,
            'pochesEnAttente' => app(\App\Services\PochesEnAttente::class)->liste(),
            'recuesHorsRegistre' => $recuesHorsRegistre, 'sansC4' => $sansC4,
            'seuil' => (int) $ref->parametre('clinisys_minutes', config('demandesang.clinisys_minutes')),
        ]);
    }

    /** Nombre de réceptions (C5) des dernières 24 h, pour le lien de « À faire ». */
    public static function nouvellesReceptions(): int
    {
        // Plus les bons BD reçus hors circuit (hors registre ou C4 non saisie), comptés au dernier affichage de l'écran Pharmacie.
        return Action::where('etape', 'C5')->where('fait_le', '>=', now()->subDay())->distinct()->count('demande_id')
            + (int) Cache::get('pharmacie.receptions_hors_circuit', 0);
    }
}
