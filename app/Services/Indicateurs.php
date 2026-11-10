<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\Poche;
use Illuminate\Support\Collection;

/**
 * Délais par segment du circuit, demandes en retard ou perdues, poches non
 * consommées. Les heures viennent du registre (ds_actions, ds_remises).
 */
class Indicateurs
{
    /** Segments mesurés : libellé => [point de départ, point d'arrivée]. */
    public const SEGMENTS = [
        'Création → remise au coursier' => ['creation', 'A11'],
        'Remise au coursier → dépôt CTS' => ['A11', 'B1'],
        'Dépôt CTS → arrivée clinique' => ['B1', 'C1'],
        'Arrivée clinique → réception pharmacie' => ['C1', 'C5'],
        'Réception pharmacie → délivrance au service' => ['C5', 'C8'],
        'Création → reçu dans le service (total)' => ['creation', 'C9'],
    ];

    public function __construct(private Circuit $circuit)
    {
    }

    /** Heure de chaque étape faite d'une demande (remise : heure de confirmation). */
    public function heures(Demande $d): array
    {
        $heures = ['creation' => $d->cree_le];
        foreach ($this->circuit->etat($d) as $code => $e) {
            if ($e['statut'] === 'faite') {
                $heures[$code] = $e['remise']?->repondu_le ?? $e['action']?->fait_le;
            }
        }

        return $heures;
    }

    /**
     * Dernière remise de main à main de chaque étape (A11, C2, C4, C8) : qui a remis et quand,
     * qui a reçu et quand, ou en attente / refusée. Une remise refusée puis refaite montre la nouvelle.
     *
     * @return array<string, \App\Models\Remise>
     */
    public function remises(Demande $d): array
    {
        $d->loadMissing('remises');
        $remises = [];
        foreach ($d->remises->sortBy('remis_le') as $r) {
            if ($r->reponse !== 'annulee') {
                $remises[$r->etape] = $r;
            }
        }

        return $remises;
    }

    public function calculer(Collection $demandes): array
    {
        $durees = array_fill_keys(array_keys(self::SEGMENTS), []);
        $parStatut = ['en_cours' => 0, 'cloturee' => 0, 'annulee' => 0];

        foreach ($demandes as $d) {
            $parStatut[$d->statut] = ($parStatut[$d->statut] ?? 0) + 1;
            $h = $this->heures($d);
            foreach (self::SEGMENTS as $libelle => [$de, $a]) {
                if (isset($h[$de], $h[$a])) {
                    $durees[$libelle][] = (int) $h[$de]->diffInMinutes($h[$a]);
                }
            }
        }

        $segments = [];
        foreach ($durees as $libelle => $liste) {
            sort($liste);
            $n = count($liste);
            $segments[$libelle] = [
                'n' => $n,
                'moyenne' => $n ? (int) round(array_sum($liste) / $n) : null,
                'mediane' => $n ? $liste[intdiv($n, 2)] : null,
                'max' => $n ? max($liste) : null,
            ];
        }

        $poches = Poche::whereIn('demande_id', $demandes->pluck('id'))->get();
        $consommees = $poches->whereIn('statut', ['delivree', 'transfusee'])->count();
        $retournees = $poches->whereIn('statut', ['a_retourner', 'prelevee'])->count();
        $motifs = $poches->whereIn('statut', ['a_retourner', 'prelevee'])->countBy('motif')->sortDesc()->all();

        // Demandes servies (au moins une poche reçue) : en partie seulement, ou entièrement.
        $nbPoches = Poche::whereIn('demande_id', $demandes->pluck('id'))->selectRaw('demande_id, count(*) as n')->groupBy('demande_id')->pluck('n', 'demande_id');
        $servies = $demandes->filter(fn ($d) => ($nbPoches[$d->id] ?? 0) > 0);
        $partielles = $servies->filter(fn ($d) => ($nbPoches[$d->id] ?? 0) < $d->nb_poches)->count();

        return [
            'total' => $demandes->count(),
            // Un besoin = une demande et ses relances (le CTS a livré moins, l'équipe a redemandé le reste).
            'besoins' => $demandes->whereNull('demande_relancee_id')->count(),
            'relances' => $demandes->whereNotNull('demande_relancee_id')->count(),
            'servies_partiellement' => $partielles,
            'taux_servies_partiellement' => $servies->count() ? round(100 * $partielles / $servies->count(), 1) : null,
            // Reçues en pharmacie hors registre, inscrites a posteriori (régularisation).
            'hors_registre' => $demandes->whereNotNull('regularisation')->count(),
            'taux_hors_registre' => $demandes->count() ? round(100 * $demandes->whereNotNull('regularisation')->count() / $demandes->count(), 1) : null,
            'par_statut' => $parStatut,
            'segments' => $segments,
            'poches' => [
                'total' => $poches->count(),
                'en_stock' => $poches->where('statut', 'en_stock')->count(),
                'consommees' => $consommees,
                'retournees' => $retournees,
                'taux_non_consommees' => ($consommees + $retournees) ? round(100 * $retournees / ($consommees + $retournees), 1) : null,
                'motifs' => $motifs,
            ],
        ];
    }
}
