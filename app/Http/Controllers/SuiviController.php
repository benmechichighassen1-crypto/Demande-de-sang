<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Services\Alertes;
use App\Services\Indicateurs;
use App\Services\Referentiels;
use App\Support\Clinisys as C;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Alertes, indicateurs, export Excel (CSV) et registre imprimable. */
class SuiviController extends Controller
{
    /** Étapes de remise de main à main (remis par / reçu par dans le registre et l'export). */
    private const REMISES = ['A11', 'C2', 'C4', 'C8'];

    public function alertes(Alertes $alertes): View
    {
        return view('suivi.alertes', ['alertes' => $alertes->liste()]);
    }

    public function indicateurs(Request $request, Indicateurs $indicateurs, Referentiels $ref, Alertes $alertes): View
    {
        $f = $this->filtres($request);

        return view('suivi.indicateurs', [
            'f' => $f, 'ind' => $indicateurs->calculer($this->demandes($f)), 'ref' => $ref, 'duree' => fn ($m) => $m === null ? '—' : $alertes->duree($m),
        ]);
    }

    /** Export : une ligne par demande, l'heure et l'auteur de chaque étape. Séparateur « ; » et BOM pour Excel. */
    public function export(Request $request, Indicateurs $indicateurs, Referentiels $ref): StreamedResponse
    {
        $demandes = $this->demandes($this->filtres($request));
        $etapes = config('demandesang.etapes');

        return response()->streamDownload(function () use ($demandes, $etapes, $indicateurs, $ref) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(
                ['N°', 'N° demande Clinisys', 'N° dossier', 'Patient', 'Externe', 'Urgence', 'Poches au CTS', 'Produit', 'Nb poches', 'Diagnostic',
                    'Service source', 'Service destination', 'Service DMI', 'Statut', 'Créée le', 'Créée par', 'Paiement'],
                array_map(fn ($e) => $e['court'], $etapes),
                ...array_map(fn ($c) => [$etapes[$c]['court'].' : remis le', $etapes[$c]['court'].' : remis par', $etapes[$c]['court'].' : reçu par'], self::REMISES)
            ), ';');
            foreach ($demandes as $d) {
                $h = $indicateurs->heures($d);
                $r = $indicateurs->remises($d);
                fputcsv($out, array_merge([
                    $d->id, $d->num_demande_clinisys, $d->num_doss, $d->nom_patient, $d->externe ? 'oui' : 'non',
                    config("demandesang.urgences.{$d->urgence}"), config("demandesang.natures.{$d->nature}"), $d->produit, $d->nb_poches, $d->diagnostic,
                    $ref->service($d->service_source), $ref->service($d->service_destination), $d->service_saisie_dmi, $d->statut,
                    $d->cree_le->format('d/m/Y H:i'), $d->cree_par, $d->paiement() ?? '',
                ], array_map(fn ($code) => isset($h[$code]) ? $h[$code]->format('d/m/Y H:i') : '', array_keys($etapes)),
                ...array_map(fn ($c) => isset($r[$c]) ? [$r[$c]->remis_le->format('d/m/Y H:i'), $r[$c]->nom_donneur,
                    match ($r[$c]->reponse) { 'confirmee' => $r[$c]->nom_receveur, 'refusee' => 'refusée par '.$r[$c]->nom_receveur, default => 'en attente' }] : ['', '', ''], self::REMISES)), ';');
            }
            fclose($out);
        }, 'demandes-sang-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Registre des demandes (remplace le registre papier PQ-ORG-017-002). */
    public function registre(Request $request, Indicateurs $indicateurs, Referentiels $ref): View
    {
        $f = $this->filtres($request);
        $demandes = $this->demandes($f)->sortBy('cree_le');
        \App\Services\CinPatients::completer($demandes);

        return view('suivi.registre', [
            'f' => $f, 'demandes' => $demandes, 'heures' => $demandes->mapWithKeys(fn ($d) => [$d->id => $indicateurs->heures($d)]), 'ref' => $ref,
            'remises' => $demandes->mapWithKeys(fn ($d) => [$d->id => $indicateurs->remises($d)]),
            'lieux' => DemandeController::lieux($demandes, app(\App\Clinisys\Pharmacie::class)),
        ]);
    }

    private function filtres(Request $request): array
    {
        $f = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'service' => ['nullable', 'string', 'max:20'],
            'urgence' => ['nullable', Rule::in(array_keys(config('demandesang.urgences')))],
            'pharmacie' => ['nullable', 'in:recues'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $f['q'] = trim((string) ($f['q'] ?? '')) ?: null;
        // N° exact (dossier, demande, poche, bon) : recherche sur tout l'historique, pas seulement Du / Au.
        $f['historique'] = $f['q'] !== null && \App\Clinisys\Pharmacie::numeroRecherche($f['q']);

        return $f + ['du' => now()->subDays(30)->toDateString(), 'au' => now()->toDateString()];
    }

    private function demandes(array $f): Collection
    {
        $q = $f['q'] ?? null;
        // Poche ou bon Clinisys (BD, PA, PR) : les demandes DMI auxquelles ils sont rattachés.
        $dmiTrouvees = [];
        if (! empty($f['historique'])) {
            $pharmacie = app(\App\Clinisys\Pharmacie::class);
            foreach (['BD', 'PA', 'PR'] as $type) {
                $lignes = C::essayer(fn () => json_decode(Cache::remember('clinisys.registre.'.$type.'.'.strtoupper($q), 60,
                    fn () => json_encode($pharmacie->lignesMouvements($type, null, null, $q)))), []) ?? [];
                foreach ($lignes as $l) {
                    if (! empty($l->numDemande)) {
                        $dmiTrouvees[] = (string) $l->numDemande;
                    }
                }
            }
        }
        $like = $q !== null ? '%'.str_replace(['%', '_', '['], '', $q).'%' : null;

        return Demande::with('actions', 'remises')->withCount('poches')
            ->when(empty($f['historique']), fn ($r) => $r->where('cree_le', '>=', $f['du'])
                ->where('cree_le', '<', date('Y-m-d', strtotime($f['au'].' +1 day'))))
            // Recherche : nom, dossier, identifiant, n° de la demande (appli ou DMI), poches et bons de la demande.
            ->when($q, fn ($r) => $r->where(fn ($w) => $w->where('nom_patient', 'like', $like)->orWhere('num_doss', 'like', $like)
                ->orWhere('identifiant_patient', $q)->orWhere('cin', strtoupper($q))->orWhere('num_demande_clinisys', $q)
                ->when(ctype_digit($q) && strlen($q) <= 9, fn ($w) => $w->orWhere('id', (int) $q))
                ->when($dmiTrouvees !== [], fn ($w) => $w->orWhereIn('num_demande_clinisys', array_unique($dmiTrouvees)))
                ->orWhereHas('poches', fn ($p) => $p->where('numero', 'like', $like)->orWhere('bon_depot', 'like', $like)->orWhere('bon_prelevement', 'like', $like))))
            ->when($f['service'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('service_source', $v)->orWhere('service_destination', $v)))
            ->when($f['urgence'] ?? null, fn ($q, $v) => $q->where('urgence', $v))
            // Réceptionnées par la pharmacie : réception C5 (bon de dépôt BD) enregistrée.
            ->when(($f['pharmacie'] ?? null) === 'recues', fn ($q) => $q->whereHas('actions', fn ($q) => $q->where('etape', 'C5')))
            ->orderByDesc('cree_le')->limit(5000)->get();
    }
}
