<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use App\Models\Remise;
use Illuminate\Support\Carbon;

/**
 * Alertes : demandes bloquées trop longtemps à une étape, remises non
 * confirmées, poches proches de la péremption ou à retourner, non-conformités.
 * Seuils par urgence et par phase : ds_parametres (écran Paramètres).
 */
class Alertes
{
    public function __construct(private Referentiels $ref, private DmiNonInscrites $dmi)
    {
    }

    /** Phase dans laquelle se trouve la demande, d'après sa dernière étape faite. */
    public function phase(Demande $d): ?string
    {
        return match (true) {
            $d->etape === null || (str_starts_with($d->etape, 'A') && $d->etape !== 'A11') => 'service',
            in_array($d->etape, ['A11', 'B1', 'B2'], true) => 'cts',
            $d->etape === 'C9' => null, // en attente de transfusion : pas de délai imposé
            default => 'retour',
        };
    }

    /** @return array<int, array{type: string, gravite: string, texte: string, depuis: ?Carbon, demande: ?Demande, poche: ?Poche}> */
    public function liste(): array
    {
        $alertes = [];
        $now = now();

        $demandes = Demande::where('statut', 'en_cours')->get()->keyBy('id');
        // B2 (instance au CTS) et C3 (non-conformité) lus en une seule requête.
        $b2c3 = Action::whereIn('demande_id', $demandes->keys())->whereIn('etape', ['B2', 'C3'])
            ->get(['id', 'demande_id', 'etape', 'fait_le', 'donnees'])
            ->each(fn ($a) => $a->setRelation('demande', $demandes[$a->demande_id]));
        $instance = $b2c3->where('etape', 'B2')
            ->filter(fn ($a) => ($a->donnees['etat_cts'] ?? null) === 'instance')->pluck('demande_id')->all();

        foreach ($demandes as $d) {
            $phase = $this->phase($d);
            if ($d->etape === 'B2' && in_array($d->id, $instance, true)) {
                // Poches gardées en instance au CTS : pas de délai, sauf au-delà de N jours.
                $jours = (int) $this->ref->parametre('instance_jours', config('demandesang.instance_jours'));
                $depuis = $d->etape_le ?? $d->cree_le;
                if ($depuis->lt($now->copy()->subDays($jours))) {
                    $alertes[] = ['type' => 'instance', 'gravite' => 'moyenne', 'depuis' => $depuis, 'demande' => $d, 'poche' => null,
                        'texte' => __('Poches en instance au CTS depuis :duree (plus de :jours jour(s))', ['duree' => $this->duree((int) $depuis->diffInMinutes($now)), 'jours' => $jours])];
                }
                continue;
            }
            if ($phase === null) {
                continue;
            }
            $depuis = $d->etape_le ?? $d->cree_le;
            $minutes = (int) $depuis->diffInMinutes($now);
            $seuil = $this->ref->seuil($d->urgence, $phase);
            if ($minutes > $seuil) {
                $alertes[] = [
                    'type' => 'retard', 'gravite' => $minutes > 2 * $seuil ? 'haute' : 'moyenne', 'depuis' => $depuis,
                    'texte' => __('Bloquée à « :etape » depuis :duree (seuil :seuil)', ['etape' => $d->libelleEtape(), 'duree' => $this->duree($minutes), 'seuil' => $this->duree($seuil)]),
                    'demande' => $d, 'poche' => null,
                ];
            }
        }

        // Étapes de la pharmacie attendues dans Clinisys (bon de dépôt après C4, sortie patient après C5 / C6).
        if (config('demandesang.clinisys_actif')) {
            $seuilClinisys = (int) $this->ref->parametre('clinisys_minutes', config('demandesang.clinisys_minutes'));
            foreach ($demandes as $d) {
                $attendu = match ($d->etape) { 'C4' => __('le bon de dépôt (BD)'), 'C5', 'C6' => __('la sortie patient (PA)'), default => null };
                $depuis = $d->etape_le ?? $d->cree_le;
                if ($attendu && $depuis->lt($now->copy()->subMinutes($seuilClinisys))) {
                    $alertes[] = ['type' => 'clinisys', 'gravite' => 'moyenne', 'depuis' => $depuis, 'demande' => $d, 'poche' => null,
                        'texte' => __('Pas encore saisi dans Clinisys : :attendu, depuis :duree', ['attendu' => $attendu, 'duree' => $this->duree((int) $depuis->diffInMinutes($now))])];
                }
            }
        }

        foreach (Remise::with('demande')->where('reponse', 'attente')->get() as $r) {
            $minutes = (int) $r->remis_le->diffInMinutes($now);
            if ($minutes > $this->ref->seuil($r->demande->urgence, 'remise')) {
                $alertes[] = [
                    'type' => 'remise', 'gravite' => 'haute', 'depuis' => $r->remis_le,
                    'texte' => __('Remise non confirmée : « :etape » par :nom depuis :duree', ['etape' => __((string) config("demandesang.etapes.{$r->etape}.court")), 'nom' => $r->nom_donneur, 'duree' => $this->duree($minutes)]),
                    'demande' => $r->demande, 'poche' => null,
                ];
            }
        }

        $limite = $now->copy()->startOfDay()->addDays((int) $this->ref->parametre('peremption_jours', config('demandesang.peremption_jours')));
        // Poches proches de la péremption et poches à retourner : une seule requête.
        $poches = Poche::with('demande')->where(fn ($q) => $q->where(fn ($q) => $q->where('statut', 'en_stock')->whereNotNull('peremption')->where('peremption', '<=', $limite))
            ->orWhere('statut', 'a_retourner'))->get();
        foreach ($poches->where('statut', 'en_stock') as $p) {
            $alertes[] = [
                'type' => 'peremption', 'gravite' => $p->peremption->isPast() ? 'haute' : 'moyenne', 'depuis' => null,
                'texte' => $p->peremption->isPast()
                    ? __('Poche :numero (:produit) : périmée depuis le :date', ['numero' => $p->numero, 'produit' => $p->produit, 'date' => $p->peremption->format('d/m/Y')])
                    : __('Poche :numero (:produit) : péremption le :date', ['numero' => $p->numero, 'produit' => $p->produit, 'date' => $p->peremption->format('d/m/Y')]),
                'demande' => $p->demande, 'poche' => $p,
            ];
        }

        foreach ($poches->where('statut', 'a_retourner') as $p) {
            $alertes[] = [
                'type' => 'retour', 'gravite' => 'moyenne', 'depuis' => $p->statut_le,
                'texte' => __('Poche :numero non utilisée (:motif) : prélèvement fournisseur à faire', ['numero' => $p->numero, 'motif' => __((string) (config('demandesang.motifs_non_utilisation')[$p->motif] ?? $p->motif))]),
                'demande' => $p->demande, 'poche' => $p,
            ];
        }

        $nonConformes = $b2c3->where('etape', 'C3')->filter(fn ($a) => ($a->donnees['conforme'] ?? null) === 'non');
        foreach ($nonConformes as $a) {
            $alertes[] = [
                'type' => 'conformite', 'gravite' => 'haute', 'depuis' => $a->fait_le,
                'texte' => __('Non-conformité au contrôle : :motif', ['motif' => $a->donnees['motif_non_conformite'] ?? '']),
                'demande' => $a->demande, 'poche' => null,
            ];
        }

        // Demande saisie dans le DMI mais pas encore inscrite au registre de l'appli.
        $seuilDmi = $this->dmi->minutes();
        foreach ($this->dmi->liste() as $x) {
            $depuis = DmiNonInscrites::depuis($x);
            $minutes = (int) $depuis->diffInMinutes($now);
            // Réceptionnée en pharmacie (V) sans inscription : non-conformité, tout de suite.
            if (! empty($x->hors_registre)) {
                $alertes[] = [
                    'type' => 'conformite', 'gravite' => 'haute', 'depuis' => $depuis,
                    'texte' => __('Demande DMI n° :n réceptionnée en pharmacie sans inscription au registre · dossier :dossier :patient : à régulariser',
                        ['n' => $x->ID, 'dossier' => trim($x->NumDoss), 'patient' => trim(($x->NomCli ?? '').' '.($x->Prenom ?? ''))]),
                    'demande' => null, 'poche' => null, 'lien' => route('demandes.create', ['num_demande' => $x->ID]),
                ];
            } elseif ($minutes > $seuilDmi) {
                $alertes[] = [
                    'type' => 'dmi', 'gravite' => $minutes > 4 * $seuilDmi ? 'haute' : 'moyenne', 'depuis' => $depuis,
                    'texte' => __('Demande DMI n° :n non inscrite dans l\'appli depuis :duree · dossier :dossier :patient',
                        ['n' => $x->ID, 'duree' => $this->duree($minutes), 'dossier' => trim($x->NumDoss), 'patient' => trim(($x->NomCli ?? '').' '.($x->Prenom ?? ''))]),
                    'demande' => null, 'poche' => null, 'lien' => route('demandes.create', ['num_demande' => $x->ID]),
                ];
            }
        }

        usort($alertes, fn ($a, $b) => [$a['gravite'] === 'haute' ? 0 : 1, $a['depuis']?->timestamp ?? PHP_INT_MAX]
            <=> [$b['gravite'] === 'haute' ? 0 : 1, $b['depuis']?->timestamp ?? PHP_INT_MAX]);

        return $alertes;
    }

    public function duree(int $minutes): string
    {
        if ($minutes < 60) {
            return __(':minutes min', ['minutes' => $minutes]);
        }
        if ($minutes < 1440) {
            return __(':heures h :minutes', ['heures' => intdiv($minutes, 60), 'minutes' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)]);
        }

        return __(':jours j :heures h', ['jours' => intdiv($minutes, 1440), 'heures' => intdiv($minutes % 1440, 60)]);
    }
}
