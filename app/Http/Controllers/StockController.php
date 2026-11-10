<?php

namespace App\Http\Controllers;

use App\Clinisys\Pharmacie;
use App\Models\Demande;
use App\Models\Poche;
use App\Services\Circuit;
use App\Services\Referentiels;
use App\Support\Clinisys as C;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stock banque de sang : Stock = Réceptions − Sorties patient − Prélèvements fournisseur.
 * Tant que la pharmacie Clinisys n'est pas branchée, les poches sont celles
 * saisies au contrôle de conformité (C3) et reçues en pharmacie (C5).
 */
class StockController extends Controller
{
    public const VUES = [
        'en_stock' => ['en_stock'],
        'consommees' => ['delivree', 'transfusee'],
        'retournees' => ['a_retourner', 'prelevee'],
        'attente' => ['attente'],
    ];

    /** Listes lues dans Clinisys (dépôt BS) : état du stock à ce jour, puis mouvements de la période (type de bon). */
    public const VUES_CLINISYS = [
        'bs_stock' => 'En stock (non périmées)',
        'bs_perimees' => 'Périmées encore en stock',
        'bs_bientot' => 'Périment dans :x jours',
        'bs_bd' => 'Réceptions (bons de dépôt BD)',
        'bs_pa' => 'Sorties patient (bons PA)',
        'bs_pr' => 'Prélèvements fournisseur (PR)',
    ];

    /** Produit de l'appli → désignation Clinisys (desart, sans espaces, en majuscules). */
    private const PRODUITS_DESART = ['CGR' => '/GLOBUL/', 'PFC' => '/PFC|PLASMA/', 'CPS' => '/PLAQ(?!.*APHER)|CPS/', 'CPA' => '/APHER|CPA/'];

    /**
     * Poches en stock, pas encore périmées (péremption après aujourd'hui, comme Clinisys), qui périment au plus tard
     * dans X jours (aujourd'hui + X inclus), de la plus proche à la plus lointaine.
     */
    public static function perimentBientot(iterable $stock, int $jours): \Illuminate\Support\Collection
    {
        $limite = today()->addDays($jours)->endOfDay();

        return collect($stock)->filter(fn ($p) => empty($p->perimee) && $p->DatPer && \Illuminate\Support\Carbon::parse($p->DatPer)->lte($limite))
            ->sortBy('DatPer')->values();
    }

    /** Texte comparable : minuscules, sans accents ni espaces superflus. */
    private static function comparable(?string $t): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(preg_replace('/\s+/', ' ', trim((string) $t))));
    }

    /**
     * Recherche libre dans une ligne Clinisys : n° poche (référence, identifiant), n° dossier, nom du patient,
     * n° demande DMI, n° de la demande de l'appli et n° de bon (BD, PA, PR, bon d'origine).
     */
    public static function correspond(object $l, string $q): bool
    {
        $q = self::comparable($q);
        if ($q === '') {
            return true;
        }
        $champs = [$l->numero ?? null, $l->Identifiant ?? null, $l->Reference ?? null, $l->NumDoss ?? null, $l->numDemande ?? null,
            $l->bon ?? null, $l->bon_depot ?? null, $l->bon_origine ?? null, $l->NumBon ?? null,
            trim(($l->NomCli ?? '').' '.($l->Prenom ?? '')), trim(($l->Prenom ?? '').' '.($l->NomCli ?? ''))];
        foreach ($champs as $c) {
            if ($c !== null && $c !== '' && str_contains(self::comparable((string) $c), $q)) {
                return true;
            }
        }

        // N° de la demande dans l'appli : égalité exacte (« 46 » ne doit pas trouver toutes les poches contenant 46).
        return isset($l->demande_appli) && ctype_digit($q) && (int) $q === (int) $l->demande_appli;
    }

    /** Une ligne Clinisys passe-t-elle les filtres groupe / produit / recherche ? */
    private static function passeFiltres(object $l, array $f): bool
    {
        if (! empty($f['q']) && ! self::correspond($l, $f['q']) && ! in_array(trim((string) ($l->NumDoss ?? '')), $f['dossiers_cin'] ?? [], true)) {
            return false;
        }
        if (! empty($f['groupe']) && strtoupper(trim((string) ($l->memop ?? ''))) !== strtoupper($f['groupe'])) {
            return false;
        }
        if (! empty($f['produit'])) {
            $desart = strtoupper(str_replace(' ', '', (string) ($l->desart ?? '')));
            $motif = self::PRODUITS_DESART[$f['produit']] ?? null;
            $connu = collect(self::PRODUITS_DESART)->contains(fn ($m) => preg_match($m, $desart));

            return $motif ? (bool) preg_match($motif, $desart) : ! $connu;
        }

        return true;
    }

    public function index(Request $request, Referentiels $ref, Pharmacie $pharmacie): View
    {
        // Stock réel du dépôt BS dans Clinisys (lecture seule), à côté du suivi de l'appli.
        // Gardés 60 s en cache : plusieurs écrans Stock ouverts = une seule lecture du serveur Clinisys.
        // Stockés en JSON (le cache Laravel ne désérialise pas les objets stdClass).
        $enCache = fn (string $cle, callable $lecture) => json_decode(Cache::remember($cle, 60, fn () => json_encode($lecture())));
        $stockClinisys = $pharmacie->branche()
            ? C::essayer(fn () => $enCache('clinisys.stock_bs', fn () => $pharmacie->stockBS()), [])
            : null;

        // Rapprochement avec les poches suivies dans l'appli (même n° de poche) : patient quand Clinisys
        // ne le donne pas, et écarts (poche « en stock » dans l'appli mais absente du stock Clinisys).
        $ecarts = collect();
        if ($stockClinisys !== null) {
            $suivies = Poche::with('demande')->whereIn('statut', ['attente', 'en_stock', 'delivree'])->get()
                ->keyBy(fn ($p) => Pharmacie::normaliser($p->numero));
            // Demande de l'appli par n° DMI : une poche dont la demande DMI n'est pas inscrite est « hors registre ».
            $parDmi = Demande::whereIn('num_demande_clinisys', collect($stockClinisys)->pluck('numDemande')->filter()->map(fn ($n) => (string) $n)->unique()->values()->all())
                ->where('statut', '<>', 'annulee')->pluck('id', 'num_demande_clinisys');
            $premiereDmi = Demande::whereNotNull('num_demande_clinisys')->pluck('num_demande_clinisys')->map(fn ($n) => (int) $n)->filter()->min();
            foreach ($stockClinisys as $p) {
                $app = $suivies[Pharmacie::normaliser($p->Identifiant)] ?? null;
                $p->demande_appli = $app?->demande_id ?? ($p->numDemande ? ($parDmi[(string) $p->numDemande] ?? null) : null);
                // Seulement depuis la mise en service de l'appli (n° DMI ≥ la première demande inscrite) : les poches
                // d'avant ne sont pas « hors registre ».
                $p->hors_registre = $p->numDemande && ! $p->demande_appli && $premiereDmi !== null && (int) $p->numDemande >= $premiereDmi;
                if (! $p->NumDoss && $app) {
                    [$p->NumDoss, $p->NomCli, $p->Prenom, $p->source_patient] = [$app->demande->num_doss, $app->demande->nom_patient, '', 'appli'];
                }
            }
            $dansClinisys = collect($stockClinisys)->map(fn ($p) => Pharmacie::normaliser($p->Identifiant))->filter()->flip();
            $ecarts = $suivies->filter(fn ($p, $num) => $p->statut === 'en_stock' && ! $dansClinisys->has($num))->values();
        }
        $periode = [
            $request->date('du') ?? now()->subDays(30)->startOfDay(),
            $request->date('au') ?? now(),
        ];
        $cle = 'clinisys.mouvements_bs.'.$periode[0]->format('Ymd').'.'.$periode[1]->format('Ymd');
        $mouvements = $pharmacie->branche()
            ? C::essayer(fn () => $enCache($cle, fn () => $pharmacie->mouvements(...$periode)))
            : null;

        $f = $request->validate([
            'vue' => ['nullable', Rule::in([...array_keys(self::VUES), ...array_keys(self::VUES_CLINISYS)])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'service_source' => ['nullable', 'string', 'max:20'],
            'service_destination' => ['nullable', 'string', 'max:20'],
            'groupe' => ['nullable', 'string', 'max:4'],
            'produit' => ['nullable', 'string', 'max:10'],
            'motif' => ['nullable', 'string', 'max:30'],
            'jours' => ['nullable', 'integer', 'min:1', 'max:90'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $f['q'] = trim((string) ($f['q'] ?? '')) ?: null;
        // Un n° exact (poche, bon, dossier, demande) est cherché dans tout l'historique des mouvements, pas seulement Du / Au.
        $historique = $f['q'] !== null && Pharmacie::numeroRecherche($f['q']);
        // Recherche par n° CIN : dossiers du patient (appli et Clinisys) ; les lignes Clinisys de ces dossiers correspondent.
        $f['dossiers_cin'] = $f['q'] !== null && preg_match('/^[A-Za-z]{1,2}[0-9]{3,8}$/', $f['q'])
            ? array_values(array_unique(array_merge(Demande::where('cin', strtoupper($f['q']))->pluck('num_doss')->map(fn ($n) => trim((string) $n))->all(),
                (array) (C::essayer(fn () => app(\App\Clinisys\Patients::class)->dossiersParCin($f['q']), []) ?? []))))
            : [];
        // « Périment dans X jours » : X de l'écran, sinon la valeur de Paramétrage (5 par défaut).
        $jours = (int) ($f['jours'] ?? $ref->parametre('perime_bientot_jours', config('demandesang.perime_bientot_jours')));
        // Par défaut : le stock Clinisys à ce jour (ou le suivi de l'appli si Clinisys n'est pas branché).
        $vue = $f['vue'] ?? ($stockClinisys !== null ? 'bs_stock' : 'en_stock');

        // Listes Clinisys : stock (à ce jour) et mouvements de la période, filtrées par groupe / produit ;
        // chaque pastille compte les lignes de sa liste (même source, mêmes filtres).
        $listesBs = [];
        if ($stockClinisys !== null) {
            $listesBs['bs_stock'] = collect($stockClinisys)->where('perimee', 0)->values();
            $listesBs['bs_perimees'] = collect($stockClinisys)->where('perimee', 1)->values();
            $listesBs['bs_bientot'] = self::perimentBientot($stockClinisys, $jours);
            foreach (['bs_bd' => 'BD', 'bs_pa' => 'PA', 'bs_pr' => 'PR'] as $cle => $type) {
                $listesBs[$cle] = collect($historique
                    ? C::essayer(fn () => $enCache("clinisys.lignes_bs.$type.n.".strtoupper($f['q']), fn () => $pharmacie->lignesMouvements($type, null, null, $f['q'])), [])
                    : C::essayer(fn () => $enCache("clinisys.lignes_bs.$type.".$periode[0]->format('Ymd').'.'.$periode[1]->format('Ymd'),
                        fn () => $pharmacie->lignesMouvements($type, ...$periode)), []) ?? []);
            }
            // Demande de l'appli et « hors registre » pour les lignes de mouvements (même règle que le stock).
            $numeros = collect($listesBs)->only(['bs_bd', 'bs_pa', 'bs_pr'])->flatten(1)->pluck('numDemande')->filter()->map(fn ($n) => (string) $n)->unique()->values()->all();
            $parDmiMvt = Demande::whereIn('num_demande_clinisys', $numeros)->where('statut', '<>', 'annulee')->pluck('id', 'num_demande_clinisys');
            $premiere = Demande::whereNotNull('num_demande_clinisys')->pluck('num_demande_clinisys')->map(fn ($n) => (int) $n)->filter()->min();
            foreach (['bs_bd', 'bs_pa', 'bs_pr'] as $cle) {
                foreach ($listesBs[$cle] as $l) {
                    $l->demande_appli = $l->numDemande ? ($parDmiMvt[(string) $l->numDemande] ?? null) : null;
                    $l->hors_registre = $l->numDemande && ! $l->demande_appli && $premiere !== null && (int) $l->numDemande >= $premiere;
                }
            }
            $listesBs = array_map(fn ($liste) => $liste->filter(fn ($l) => self::passeFiltres($l, $f))->values(), $listesBs);
        }

        $base = Poche::query()->join('ds_demandes as d', 'd.id', '=', 'ds_poches.demande_id')
            ->when($f['du'] ?? null, fn ($q, $v) => $q->where('ds_poches.statut_le', '>=', $v))
            ->when($f['au'] ?? null, fn ($q, $v) => $q->where('ds_poches.statut_le', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($f['service_source'] ?? null, fn ($q, $v) => $q->where('d.service_source', $v))
            ->when($f['service_destination'] ?? null, fn ($q, $v) => $q->where('d.service_destination', $v))
            ->when($f['groupe'] ?? null, fn ($q, $v) => $q->where('ds_poches.groupe_sanguin', $v))
            ->when($f['produit'] ?? null, fn ($q, $v) => $q->where('ds_poches.produit', $v))
            ->when($f['motif'] ?? null, fn ($q, $v) => $q->where('ds_poches.motif', $v))
            ->when($f['q'], function ($q, $v) {
                // Jokers LIKE retirés de la saisie (SQL Server et SQLite ne les échappent pas de la même façon).
                $like = '%'.str_replace(['%', '_', '['], '', $v).'%';
                $q->where(fn ($w) => $w->where('ds_poches.numero', 'like', $like)->orWhere('d.cin', strtoupper($v))->orWhere('d.num_doss', 'like', $like)->orWhere('d.nom_patient', 'like', $like)
                    ->orWhere('ds_poches.bon_depot', 'like', $like)->orWhere('ds_poches.bon_prelevement', 'like', $like)->orWhere('d.num_demande_clinisys', $v)
                    ->when(ctype_digit($v) && strlen($v) <= 9, fn ($w) => $w->orWhere('d.id', (int) $v)));
            });

        $compteurs = [];
        foreach (self::VUES as $cle => $statuts) {
            $compteurs[$cle] = (clone $base)->whereIn('ds_poches.statut', $statuts)->count();
        }

        $poches = (clone $base)->whereIn('ds_poches.statut', self::VUES[$vue] ?? self::VUES['en_stock'])
            ->select('ds_poches.*', 'd.nom_patient', 'd.num_doss', 'd.service_source', 'd.service_destination')
            ->orderBy($vue === 'en_stock' ? 'ds_poches.peremption' : 'ds_poches.statut_le', $vue === 'en_stock' ? 'asc' : 'desc')
            ->limit(500)->get();

        return view('stock.index', [
            'poches' => $poches, 'compteurs' => $compteurs, 'vue' => $vue, 'f' => $f, 'ref' => $ref,
            'ecarts' => $ecarts,
            'depot' => $pharmacie->branche() ? C::essayer(fn () => $pharmacie->libelleDepot(), Pharmacie::DEPOT) : Pharmacie::DEPOT,
            'stockClinisys' => $stockClinisys, 'mouvements' => $mouvements, 'periode' => $periode, 'erreurClinisys' => C::$erreur,
            'listesBs' => $listesBs, 'jours' => $jours, 'historique' => $historique,
        ]);
    }

    public function prelever(Request $request, Poche $poche, Circuit $circuit): RedirectResponse
    {
        $data = $request->validate([
            'motif' => ['required', Rule::in(array_keys(config('demandesang.motifs_non_utilisation')))],
            'bon_prelevement' => ['nullable', 'string', 'max:30'],
            'commentaire' => ['nullable', 'string', 'max:1000', 'required_if:motif,autre'],
        ]);
        $circuit->preleverPoche($poche, $data['motif'], $data['bon_prelevement'] ?? null, $data['commentaire'] ?? null, $request->user());

        return back()->with('statut', __('Prélèvement fournisseur enregistré pour la poche :numero.', ['numero' => $poche->numero]));
    }
}
