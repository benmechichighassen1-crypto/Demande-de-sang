<?php

namespace App\Services;

use App\Clinisys\Pharmacie;
use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Étapes de la pharmacie lues dans Clinisys (lecture seule) : la pharmacie ne saisit rien dans l'appli.
 *  - C5 : le bon de dépôt BD de la demande DMI existe (FactureBD_PR, numDemandeS) ;
 *  - C8 : la sortie patient PA de ces poches existe (facture + mvtsto, NumBon_Depot = BD) ;
 *  - retour fournisseur : un bon PR reprend une poche du BD (MvtStoBD_PR, NumBon_Depot).
 * Lancée à l'ouverture des pages (au plus une fois par minute, cache partagé) ou par ds:sync-clinisys ;
 * pas de tâche planifiée par défaut (DS_SYNC_PLANIFIEE=true pour l'activer).
 */
class SyncClinisys
{
    public function __construct(private Circuit $circuit, private Pharmacie $pharmacie)
    {
    }

    /** Toutes les demandes en cours, au plus une fois par minute (pages ouvertes par plusieurs personnes). */
    public function siNecessaire(): void
    {
        if ($this->pharmacie->branche() && Cache::add('clinisys.sync', now()->toIso8601String(), 60)) {
            $this->synchroniser();
        }
    }

    /**
     * Une demande (ouverture de sa fiche), au plus une fois par minute et par étape : la fiche rouverte juste après
     * une nouvelle étape (ex. C4) relit Clinisys aussitôt, sans attendre la minute (C5 dès que le bon BD existe).
     */
    public function demandeSiNecessaire(Demande $d): void
    {
        if ($this->pharmacie->branche() && $d->enCours() && Cache::add('clinisys.sync.'.$d->id.'.'.$d->etape.'.'.$d->actions()->count(), 1, 60)) {
            $this->demande($d);
        }
    }

    /** @return array{c5: int, c8: int, pr: int} nombre d'étapes et de retours enregistrés */
    public function synchroniser(): array
    {
        $total = ['c5' => 0, 'c8' => 0, 'pr' => 0];
        // Requête ciblée : seulement les demandes qui attendent la pharmacie (C4 faite, C5 ou C8 à venir, poches
        // délivrées ou à retourner), pas tout l'historique.
        $avecPoches = Poche::whereIn('statut', ['en_stock', 'delivree', 'a_retourner'])->distinct()->pluck('demande_id');
        $demandes = Demande::whereNotNull('num_demande_clinisys')->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('statut', 'en_cours')->where(fn ($q) => $q->whereIn('etape', ['C4', 'C5', 'C6'])->orWhereNotNull('regularisation')))
            ->orWhereIn('id', $avecPoches))->get();
        foreach ($demandes as $d) {
            foreach ($this->demande($d) as $k => $n) {
                $total[$k] += $n;
            }
        }

        return $total;
    }

    /** @return array{c5: int, c8: int, pr: int} */
    public function demande(Demande $d): array
    {
        $fait = ['c5' => 0, 'c8' => 0, 'pr' => 0];
        if (! $this->pharmacie->branche() || ! $d->num_demande_clinisys) {
            return $fait;
        }
        // Rien à lire dans Clinisys avant C4 (poches pas encore portées à la pharmacie) : aucune requête.
        // Demande inscrite a posteriori : C4 « non tracée », la pharmacie a déjà reçu les poches.
        $etat = $this->circuit->etat($d->fresh());
        if (! in_array($etat['C4']['statut'] ?? '', ['faite', 'non_tracee'], true)) {
            return $fait;
        }
        $bons = $this->pharmacie->bonsDepot($d->num_demande_clinisys);

        // C5 : bon de dépôt BD de la demande, au nom de l'utilisateur Clinisys qui l'a saisi.
        if ($bons !== [] && ($etat['C5']['statut'] ?? '') === 'disponible') {
            $bon = $bons[0];
            $donnees = ['bon_depot' => $bon->numaffiche] + $this->circuit->bonDepotClinisys($d, $bon->numaffiche);
            if ($this->circuit->enregistrerAuto($d, 'C5', $donnees, Pharmacie::horodatage($bon), (string) $bon->codvend)) {
                $fait['c5']++;
            }
            $etat = $this->circuit->etat($d->fresh());
        }

        // C8 : sortie patient (bon PA) des poches de ces BD, pour ce dossier.
        if ($bons !== [] && ($etat['C8']['statut'] ?? '') === 'disponible') {
            $sorties = $this->pharmacie->sortiesPourBons($d->num_doss, array_map(fn ($b) => $b->numaffiche, $bons));
            if ($sorties !== []) {
                $enStock = Poche::where('demande_id', $d->id)->where('statut', 'en_stock')->get();
                $numeros = array_map(fn ($s) => Pharmacie::normaliser($s->reference), $sorties);
                // Poches sorties d'après leur n° ; sans n° concordant, autant de poches que de lignes PA.
                $ids = $enStock->filter(fn ($p) => in_array(Pharmacie::normaliser($p->numero), $numeros, true))->pluck('id');
                if ($ids->isEmpty()) {
                    $ids = $enStock->take(count($sorties))->pluck('id');
                }
                if ($ids->isNotEmpty()) {
                    $premier = $sorties[0];
                    $donnees = ['poches_delivrees' => $ids->values()->all(), 'bons_pa' => array_values(array_unique(array_map(fn ($s) => $s->numaffiche, $sorties)))];
                    if ($this->circuit->enregistrerAuto($d, 'C8', $donnees, Pharmacie::horodatage($premier), (string) $premier->codvend)) {
                        $fait['c8']++;
                    }
                }
            }
        }

        // Retour au fournisseur : bon PR qui reprend une poche de ces BD.
        if ($bons !== []) {
            $prs = $this->pharmacie->prelevements(array_map(fn ($b) => $b->numaffiche, $bons));
            foreach (Poche::where('demande_id', $d->id)->whereIn('statut', ['en_stock', 'a_retourner', 'delivree'])->get() as $p) {
                $pr = collect($prs)->first(fn ($x) => Pharmacie::normaliser($x->Identifiant) === Pharmacie::normaliser($p->numero));
                if ($pr) {
                    DB::transaction(function () use ($p, $pr, $d) {
                        $p->update(['statut' => 'prelevee', 'bon_prelevement' => $pr->numaffiche, 'motif' => $p->motif ?? 'autre', 'statut_le' => Pharmacie::horodatage($pr)]);
                        $this->circuit->ajouterAction($d, 'prelevement_fournisseur', Circuit::clinisys((string) $pr->codvend),
                            ['poche' => $p->numero, 'motif' => $p->motif, 'bon_prelevement' => $pr->numaffiche, 'source' => 'clinisys'], null,
                            ['fait_le' => Pharmacie::horodatage($pr), 'ip' => null, 'appareil' => 'Clinisys']);
                    });
                    $fait['pr']++;
                }
            }
        }

        return $fait;
    }
}
