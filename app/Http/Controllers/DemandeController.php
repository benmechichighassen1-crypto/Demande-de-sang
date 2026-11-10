<?php

namespace App\Http\Controllers;

use App\Clinisys\DemandesDmi;
use App\Clinisys\Patients;
use App\Clinisys\Pharmacie;
use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use App\Models\Remise;
use App\Services\Alertes;
use App\Services\Circuit;
use App\Services\Journal;
use App\Services\DmiNonInscrites;
use App\Services\Referentiels;
use App\Services\SyncClinisys;
use App\Support\Clinisys as C;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DemandeController extends Controller
{
    public function __construct(private Circuit $circuit, private Referentiels $ref)
    {
    }

    /** Tableau des demandes : une ligne par demande, état actuel et temps écoulé. */
    public function index(Request $request, Alertes $alertes, DmiNonInscrites $dmiNonInscrites, Pharmacie $pharmacie): View
    {
        $f = $request->validate([
            'statut' => ['nullable', 'in:en_cours,cloturee,annulee,toutes'],
            'urgence' => ['nullable', Rule::in(array_keys(config('demandesang.urgences')))],
            'service' => ['nullable', 'string', 'max:20'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:60'],
            'role' => ['nullable', Rule::in(array_keys(config('demandesang.roles_badges')))],
            'suivi' => ['nullable', Rule::in(['toutes', 'retard', 'urgentes', 'moi', 'phase1', 'phase2', 'phase3', 'phase4', 'phase5', 'phase6'])],
        ]);
        $statut = $f['statut'] ?? 'en_cours';
        // Compte à un seul rôle du circuit : « À faire par moi » par défaut ; sinon toutes les demandes.
        $rolesCircuit = array_values(array_intersect($request->user()->roles, ['infirmiere', 'major', 'coursier', 'surveillant', 'pharmacie']));
        // Le surveillant suit tout le circuit : toutes les demandes par défaut.
        $suivi = $f['suivi'] ?? (count($rolesCircuit) === 1 && $rolesCircuit !== ['surveillant'] && ! $request->user()->admin && $statut === 'en_cours' ? 'moi' : 'toutes');

        $demandes = Demande::query()->withCount('poches')->with(['actions:id,demande_id,etape,user_name,nom_acteur,fait_le,annule_action_id,remise_id', 'remises'])
            ->when($statut !== 'toutes', fn ($q) => $q->where('statut', $statut))
            ->when($f['urgence'] ?? null, fn ($q, $v) => $q->where('urgence', $v))
            ->when($f['service'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('service_source', $v)->orWhere('service_destination', $v)))
            ->when($f['du'] ?? null, fn ($q, $v) => $q->where('cree_le', '>=', $v))
            ->when($f['au'] ?? null, fn ($q, $v) => $q->where('cree_le', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('nom_patient', 'like', '%'.str_replace(['%', '_', '['], '', $v).'%')
                ->orWhere('num_doss', $v)->orWhere('num_demande_clinisys', $v)->orWhere('identifiant_patient', $v)->orWhere('cin', strtoupper($v))
                ->when(ctype_digit($v) && strlen($v) <= 9, fn ($q) => $q->orWhere('id', (int) $v))
                ->orWhereHas('poches', fn ($p) => $p->where('numero', 'like', '%'.str_replace(['%', '_', '['], '', $v).'%')->orWhere('bon_depot', $v)->orWhere('bon_prelevement', $v))))
            ->orderByRaw("CASE urgence WHEN 'vitale' THEN 0 WHEN 'urgente' THEN 1 ELSE 2 END")
            ->orderByDesc('cree_le')
            ->limit(300)
            ->get();
        \App\Services\CinPatients::completer($demandes);

        // Suivi de chaque demande (base de l'appli seule) : phase, étape qui bloque, qui doit agir, depuis quand, retard.
        $suivis = [];
        $responsables = [];
        foreach ($demandes as $d) {
            $etat = $this->circuit->etat($d);
            $suivis[$d->id] = \App\Services\SuiviDemande::calculer($d, $etat, $this->ref);
            $responsables[$d->id] = $this->circuit->responsable($d, $etat);
        }
        if ($f['role'] ?? null) {
            $demandes = $demandes->filter(fn ($d) => in_array($f['role'], $suivis[$d->id]['roles'], true))->values();
        }
        // Pastilles : nombre de demandes de chaque filtre (sur la liste déjà filtrée par la recherche et les champs).
        $moi = fn ($d) => array_intersect($suivis[$d->id]['roles'], $rolesCircuit) !== [];
        $filtres = [
            'toutes' => fn ($d) => true,
            'retard' => fn ($d) => $suivis[$d->id]['niveau'] !== 'vert',
            'urgentes' => fn ($d) => in_array($d->urgence, ['vitale', 'urgente'], true),
            'moi' => $moi,
        ];
        foreach (array_keys(\App\Services\SuiviDemande::PHASES) as $p) {
            $filtres["phase$p"] = fn ($d) => ! $suivis[$d->id]['termine'] && $suivis[$d->id]['phase'] === $p;
        }
        $comptes = array_map(fn ($test) => $demandes->filter($test)->count(), $filtres);
        $demandes = $demandes->filter($filtres[$suivi])
            // Rouge d'abord, puis orange, puis l'urgence, puis la plus ancienne.
            ->sortBy(fn ($d) => [['rouge' => 0, 'orange' => 1, 'vert' => 2][$suivis[$d->id]['niveau']],
                ['vitale' => 0, 'urgente' => 1][$d->urgence] ?? 2, $suivis[$d->id]['depuis']->getTimestamp()])->values();
        $enAttente = Remise::whereIn('demande_id', $demandes->pluck('id'))->where('reponse', 'attente')->get()->keyBy('demande_id');

        return view('demandes.index', [
            'demandes' => $demandes, 'f' => $f + ['statut' => $statut, 'suivi' => $suivi], 'enAttente' => $enAttente, 'responsables' => $responsables,
            'suivis' => $suivis, 'comptes' => $comptes, 'rolesCircuit' => $rolesCircuit,
            'lieux' => self::lieux($demandes, $pharmacie),
            'services' => $this->ref->services(), 'ref' => $this->ref, 'alertes' => $alertes,
            'dmiNonInscrites' => $dmiNonInscrites->liste(), 'dmiJours' => $dmiNonInscrites->jours(), 'dmiMinutes' => $dmiNonInscrites->minutes(),
        ]);
    }

    /**
     * Où sont les poches des demandes affichées (Clinisys, une seule lecture pour toute la liste) : [n° DMI => poches].
     */
    public static function lieux(iterable $demandes, Pharmacie $pharmacie): array
    {
        $numeros = collect($demandes)->pluck('num_demande_clinisys')->filter()->map(fn ($n) => (string) $n)->unique()->values()->all();

        return $pharmacie->branche() && $numeros !== []
            ? (array) (C::essayer(fn () => C::enCache('clinisys.lieux.'.md5(implode(',', $numeros)), 60, fn () => $pharmacie->lieuxDesPoches($numeros)), []) ?? [])
            : [];
    }

    /**
     * Nouvelle demande : 1) n° de la demande DMI (ou n° de dossier), 2) vérification
     * du stock (A2), 3) saisie. Avec Clinisys, les champs de la demande DMI sont
     * préremplis et verrouillés.
     */
    public function create(Request $request, Patients $patients, DemandesDmi $dmi, Pharmacie $pharmacie): View
    {
        $numDoss = trim((string) $request->query('num_doss', ''));
        $numDemande = trim((string) $request->query('num_demande', ''));
        $recherche = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        // Un seul champ : un n° qui est une demande DMI ouvre cette demande ; sinon recherche patient (nom, dossier, identifiant).
        // Un n° de dossier ouvre directement le dossier (demandes DMI des 48 h, contrôle A2).
        if ($recherche !== '' && $numDemande === '' && $numDoss === '' && ctype_digit($recherche) && $dmi->actif()) {
            if (C::essayer(fn () => $dmi->demande($recherche))) {
                $numDemande = $recherche;
                $recherche = '';
            } elseif (C::essayer(fn () => $patients->dossier($recherche))) {
                $numDoss = $recherche;
                $recherche = '';
            }
        }
        $resultats = $recherche !== '' ? $this->rechercherPatients($recherche, $patients, $dmi) : [];
        // Demandes déjà inscrites dans l'appli pour cette recherche (ancien écran « Patient »).
        $dossiersTrouves = array_merge([], ...array_map(fn ($p) => $p->dossiers, $resultats));
        $demandesAppli = $recherche === '' ? collect() : Demande::where('num_doss', $recherche)->orWhere('nom_patient', 'like', "%$recherche%")
            ->orWhere('identifiant_patient', $recherche)->orWhere('num_demande_clinisys', $recherche)
            ->when($dossiersTrouves !== [], fn ($r) => $r->orWhereIn('num_doss', array_slice($dossiersTrouves, 0, 500)))
            ->orderByDesc('cree_le')->limit(50)->get();
        $patient = null;
        $demandeDmi = null;
        $prerempli = [];
        $recentes = [];
        $a2 = null;
        $erreur = null;

        if ($numDemande !== '' && $dmi->actif()) {
            $demandeDmi = C::essayer(fn () => $dmi->demande($numDemande));
            if ($demandeDmi) {
                $prerempli = DemandesDmi::preremplissage($demandeDmi);
                $demandeDmi->recue_pharmacie = $pharmacie->recueEnPharmacie($demandeDmi);
                $numDoss = $prerempli['num_doss'];
            } elseif (C::$erreur === null) {
                $erreur = __('Demande n° :n introuvable dans le DMI Clinisys.', ['n' => $numDemande]);
            }
        }

        if ($numDoss !== '') {
            $patient = C::essayer(fn () => C::enCache('clinisys.patient.'.md5($numDoss), 60, fn () => $patients->dossier($numDoss)));
            $recentes = C::essayer(fn () => $dmi->recentes($numDoss), []);
            $a2 = $this->controleA2($numDoss, $patient, $demandeDmi ? (string) $demandeDmi->ID : null, $patients, $dmi, $pharmacie, true);
        }

        return view('demandes.create', [
            'numDoss' => $numDoss, 'numDemande' => $numDemande, 'patient' => $patient, 'clinisys' => $patients->actif(),
            'demandeDmi' => $demandeDmi, 'prerempli' => $prerempli, 'recentes' => $recentes,
            'a2' => $a2, 'recherche' => $recherche, 'resultats' => $resultats, 'demandesAppli' => $demandesAppli,
            // Régularisation (demande DMI déjà réceptionnée, état V) : le surveillant peut aussi inscrire.
            'peutInscrire' => $request->user()->a('infirmiere', 'major') || ($demandeDmi && ! empty($demandeDmi->recue_pharmacie) && $request->user()->a('surveillant')), 'erreur' => $erreur ?? C::$erreur,
        ]);
    }

    public function store(Request $request, Patients $patients, DemandesDmi $dmi, Pharmacie $pharmacie): RedirectResponse
    {
        $u = $request->user();
        $data = $request->validate([
            'num_doss' => ['required', 'string', 'max:20'],
            'nom_patient' => ['nullable', 'string', 'max:150'],
            'num_demande_clinisys' => ['required', 'string', 'max:30'],
            'externe' => ['nullable', 'boolean'],
            'cin' => ['nullable', 'string', 'max:20'],
            'sans_cin' => ['nullable', 'boolean'],
            'piece_autre' => ['nullable', 'string', 'max:40'],
            'cas_patient' => ['nullable', Rule::in(array_keys(config('demandesang.cas_patient')))],
            'motif_poche' => ['nullable', Rule::in(array_keys(config('demandesang.motifs_poche_disponible')))],
            'motif_poche_detail' => ['nullable', 'string', 'max:255'],
            'urgence' => ['required', Rule::in(array_keys(config('demandesang.urgences')))],
            'nature' => ['required', Rule::in(array_keys(config('demandesang.natures')))],
            'produit' => ['required', Rule::in(array_keys(config('demandesang.produits')))],
            'nb_poches' => ['required', 'integer', 'min:1', 'max:20'],
            'groupe_sanguin' => ['nullable', Rule::in(config('demandesang.groupes'))],
            'diagnostic' => ['required', 'string', 'max:255'],
            'medecin' => ['nullable', 'string', 'max:120'],
            'forcer' => ['nullable', 'boolean'],
            'motif_forcage' => ['nullable', 'string', 'max:255'],
            'relance_de' => ['nullable', 'regex:/^[0-9]{1,9}$/'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'regularisation' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $patient = $patients->dossier($data['num_doss']);
            if ($dmi->actif()) {
                $data = $this->appliquerDemandeDmi($data, $dmi->demande($data['num_demande_clinisys']));
            }
            $a2 = $this->controleA2($data['num_doss'], $patient, $data['num_demande_clinisys'], $patients, $dmi, $pharmacie);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['num_demande_clinisys' => __('Clinisys ne répond pas : réessayez dans un instant.')]);
        }
        if ($patients->actif() && ! $patient) {
            throw ValidationException::withMessages(['num_doss' => __('Dossier introuvable dans Clinisys.')]);
        }
        $nom = $patient ? trim($patient->NomCli.' '.$patient->Prenom) : trim((string) ($data['nom_patient'] ?? ''));
        [$cin, $cinSource] = self::cinDeLaDemande($patient, $data);
        if ($nom === '') {
            throw ValidationException::withMessages(['nom_patient' => __('Le nom du patient est obligatoire.')]);
        }

        // La même demande DMI ne s'inscrit qu'une fois.
        if ($a2['deja']) {
            throw ValidationException::withMessages(['num_demande_clinisys' => __('La demande DMI n° :n est déjà inscrite (demande :id).', ['n' => $data['num_demande_clinisys'], 'id' => $a2['deja']->id])]);
        }
        // A2 : autre demande ou poche pour ce patient (tous ses dossiers) → pas de nouvelle demande,
        // sauf « j'ai vérifié » + motif.
        // Régularisation : demande DMI déjà réceptionnée en pharmacie (V) sans inscription au registre.
        // Inscription a posteriori avec motif obligatoire ; infirmière, major ou surveillant.
        // Reçue = état V, ou bon BD déjà saisi alors que le DMI est resté en D.
        $regularisation = ($data['etat_dmi'] ?? null) === 'V'
            || (! empty($data['num_demande_clinisys']) && $pharmacie->recueEnPharmacie((object) ['ID' => $data['num_demande_clinisys'], 'Etat' => $data['etat_dmi'] ?? '']));
        if ($regularisation && trim((string) ($data['regularisation'] ?? '')) === '') {
            throw ValidationException::withMessages(['regularisation' => __('Demande déjà réceptionnée en pharmacie : donnez le motif de l\'inscription a posteriori.')]);
        }
        abort_unless($regularisation ? $u->a('infirmiere', 'major', 'surveillant') : $u->a('infirmiere', 'major'), 403,
            __('Votre rôle ne permet pas d\'inscrire cette demande.'));
        // A2 ne bloque pas une régularisation : les poches trouvées sont celles de la demande elle-même.
        $nbDisponibles = $regularisation ? 0 : $a2['totalAutres'];
        // Poche disponible pour ce patient : on la récupère à la pharmacie. Une nouvelle demande (ou une régularisation) exige un motif.
        $motifPoche = $data['motif_poche'] ?? null;
        if ($a2['stockBS'] !== []) {
            if ($motifPoche === null) {
                throw ValidationException::withMessages(['motif_poche' => __('Une poche est déjà disponible pour ce patient : récupérez-la à la pharmacie, ou choisissez le motif de la nouvelle demande.')]);
            }
            if ($motifPoche === 'autre' && trim((string) ($data['motif_poche_detail'] ?? '')) === '') {
                throw ValidationException::withMessages(['motif_poche_detail' => __('Précisez le motif de la nouvelle demande.')]);
            }
        }
        // Cas du patient ; le contrôle du second dossier (page 5 du diagramme) porte sur le dossier de la demande.
        $cas = $data['cas_patient'] ?? (! empty($data['externe']) ? 'externe' : 'hospitalise');
        if ($cas === 'hors_sejour' && self::hospitalisationEnCours($patient)) {
            throw ValidationException::withMessages(['cas_patient' => __('La demande doit être faite sur un second dossier créé pour la transfusion, pas sur le dossier d\'hospitalisation initial.')]);
        }
        if ($nbDisponibles > 0 && (empty($data['forcer']) || trim((string) ($data['motif_forcage'] ?? '')) === '')) {
            throw ValidationException::withMessages(['forcer' => __(":n autre(s) demande(s) ou poche(s) pour ce patient. Si c'est bien une nouvelle demande, cochez « J'ai vérifié » et donnez le motif.", ['n' => $nbDisponibles])]);
        }
        $disponibles = $a2['disponibles'];
        $stockClinisys = $a2['stockBS'];

        // Relance d'une demande servie en partie : lien vers la demande d'origine si elle est dans l'appli.
        $relanceDe = !empty($data['relance_de']) ? $data['relance_de'] : null;
        if ($relanceDe !== null && ! collect($a2['autresDmi'])->contains(fn ($x) => (string) $x->ID === $relanceDe)) {
            throw ValidationException::withMessages(['relance_de' => __("La demande n° :n n'est pas une demande récente de ce patient.",['n' => $relanceDe])]);
        }
        $origine = $relanceDe ? Demande::where('num_demande_clinisys', $relanceDe)->where('statut', '<>', 'annulee')->first() : null;

        $demande = DB::transaction(function () use ($data, $patient, $nom, $disponibles, $stockClinisys, $nbDisponibles, $a2, $relanceDe, $origine, $u, $regularisation, $cas, $motifPoche, $cin, $cinSource) {
            $d = Demande::create([
                'demande_relancee_id' => $origine?->id,
                'regularisation' => $regularisation ? trim($data['regularisation']) : null,
                'num_demande_clinisys' => $data['num_demande_clinisys'],
                'num_doss' => $data['num_doss'],
                'identifiant_patient' => $patient->Identifiant ?? null,
                'nom_patient' => $nom,
                'cin' => $cin,
                'cin_source' => $cinSource,
                'externe' => $cas !== 'hospitalise',
                'cas_patient' => $cas,
                'urgence' => $data['urgence'],
                'nature' => $data['nature'],
                'produit' => $data['produit'],
                'nb_poches' => $data['nb_poches'],
                'groupe_sanguin' => $data['groupe_sanguin'] ?? null,
                'diagnostic' => $data['diagnostic'],
                'medecin' => $data['medecin'] ?? ($patient->medecin ?? null),
                'service_saisie_dmi' => $patient->service ?? null,
                'statut' => 'en_cours',
                'cree_par' => $u->userName,
                'cree_le' => now(),
                'etape_le' => now(),
            ]);

            $this->circuit->ajouterAction($d, 'creation', $u, collect($data)->only(['urgence', 'nature', 'produit', 'nb_poches', 'diagnostic'])->all()
                + ($regularisation ? ['inscrite_a_posteriori' => true, 'motif_regularisation' => trim($data['regularisation'])] : []), $data['commentaire'] ?? null);
            $this->circuit->ajouterAction($d, 'A2', $u, array_filter([
                'poches_disponibles' => $disponibles->pluck('numero')->all(),
                'poches_stock_clinisys' => $stockClinisys !== [] ? array_map(fn ($p) => $p->Identifiant, $stockClinisys) : null,
                'autres_demandes_dmi' => $a2['autresDmi'] !== [] ? array_map(fn ($x) => (string) $x->ID, $a2['autresDmi']) : null,
                'demandes_en_cours' => $a2['existantes']->isNotEmpty() ? $a2['existantes']->pluck('id')->all() : null,
                'sorties_recentes' => $a2['sorties'] !== [] ? array_map(fn ($s) => $s->numaffiche, $a2['sorties']) : null,
                'dossiers_verifies' => $a2['dossiers'],
                'relance_de_dmi' => $relanceDe,
                'forcee' => $nbDisponibles > 0 || $motifPoche !== null,
                'motif_forcage' => $nbDisponibles > 0 ? $data['motif_forcage'] : null,
                'motif_poche' => $a2['stockBS'] !== [] ? $motifPoche : null,
                'motif_poche_detail' => $a2['stockBS'] !== [] ? (trim((string) ($data['motif_poche_detail'] ?? '')) ?: null) : null,
            ], fn ($v) => $v !== null));
            $this->circuit->ajouterAction($d, 'A5', $u, array_filter([
                'num_demande_clinisys' => $data['num_demande_clinisys'], 'nb_poches' => $data['nb_poches'], 'diagnostic' => $data['diagnostic'],
                'source' => isset($data['etat_dmi']) ? 'clinisys' : null, 'etat_dmi' => $data['etat_dmi'] ?? null,
            ], fn ($v) => $v !== null));
            $this->circuit->ajouterAction($d, 'A8', $u, ['nature' => $data['nature']]);

            return $d;
        });

        if ($regularisation) {
            // Réception pharmacie (C5) et sortie patient (C8) reprises de Clinisys tout de suite.
            C::essayer(fn () => app(SyncClinisys::class)->demande($demande));

            return redirect()->route('demandes.show', $demande)->with('statut', __('Demande inscrite a posteriori (régularisation). La réception pharmacie est reprise de Clinisys.'));
        }

        return redirect()->route('demandes.show', $demande)->with('statut', __('Demande inscrite au registre.'));
    }

    /** Fiche demande : chronologie complète et étapes à saisir. */
    public function show(Request $request, Demande $demande, DemandesDmi $dmi, Pharmacie $pharmacie): View
    {
        \App\Services\CinPatients::completer([$demande]);
        C::essayer(fn () => app(SyncClinisys::class)->demandeSiNecessaire($demande));
        $demande->load('actions', 'remises', 'poches', 'services', 'relanceDe', 'relances');
        $etat = $this->circuit->etat($demande);
        $u = $request->user();

        $suiviFiche = \App\Services\SuiviDemande::calculer($demande, $this->circuit->etat($demande), $this->ref);
        return view('demandes.show', [
            'suivi' => $suiviFiche,
            'clinisys' => $this->lectureClinisys($demande, $dmi, $pharmacie),
            // Au moins deux étapes de préparation possibles : un seul formulaire.
            'preparation' => count($prep = $this->circuit->preparation($demande, $u, $etat)) >= 2 ? $prep : [],
            // Qui a consulté cette demande (journal) : dernière consultation et nombre de fois, par personne.
            'consultations' => DB::table('ds_journal')->where('demande_id', $demande->id)->where('action', 'page')
                ->selectRaw('user_name, max(cree_le) as derniere, count(*) as fois')->groupBy('user_name')->orderByDesc('derniere')->get(),
            'nomsComptes' => DB::table('ds_comptes')->pluck('nom', 'user_name'),
            'd' => $demande,
            'etat' => $etat,
            'possibles' => $this->circuit->possibles($demande, $u, $etat),
            'remisesAConfirmer' => $demande->remises->filter(fn ($r) => $this->circuit->peutConfirmer($r, $u)),
            'annulees' => $demande->actions->pluck('annule_action_id')->filter()->all(),
            'ref' => $this->ref,
            'circuit' => $this->circuit,
        ]);
    }

    public function etape(Request $request, Demande $demande, string $code): RedirectResponse
    {
        abort_unless(isset(config('demandesang.etapes')[$code]), 404);
        if (is_array($request->input('poches'))) {
            // Lignes de poche laissées vides : ignorées.
            $request->merge(['poches' => array_values(array_filter($request->input('poches'), fn ($p) => trim((string) ($p['numero'] ?? '')) !== ''))]);
        }
        $data = $request->validate($this->circuit->regles($code, $demande));
        $this->circuit->enregistrer($demande, $code, $data, $request->user());
        $def = config("demandesang.etapes.$code");
        session()->flash('etapes_faites', [$code]);

        return $this->retour($request, $demande, $def['type'] === 'remise'
            ? __('Remise enregistrée : en attente de confirmation par le receveur.')
            : __('« :etape » enregistré.', ['etape' => __($def['court'])]));
    }

    /** Préparation (A4, A6, A7, A9) en un seul formulaire : une action par étape. */
    public function preparation(Request $request, Demande $demande): RedirectResponse
    {
        $codes = $this->circuit->preparation($demande, $request->user());
        $demandees = array_values(array_intersect($codes, (array) $request->input('etapes', [])));
        abort_if($demandees === [], 422, __('Aucune étape de préparation possible.'));

        $regles = ['commentaire' => ['nullable', 'string', 'max:1000']];
        foreach ($demandees as $code) {
            $regles += $this->circuit->regles($code, $demande);
        }
        $data = $request->validate($regles);
        $this->circuit->enregistrerPreparation($demande, $demandees, $data, $request->user());
        session()->flash('etapes_faites', $demandees);

        return $this->retour($request, $demande, __('Préparation enregistrée : :etapes.', ['etapes' => implode(', ', array_map(fn ($c) => __((string) config("demandesang.etapes.$c.court")), $demandees))]));
    }

    public function confirmerRemise(Request $request, Remise $remise): RedirectResponse
    {
        $this->circuit->repondreRemise($remise, $request->user(), true);

        return $this->retour($request, $remise->demande, __('Réception confirmée.'));
    }

    public function refuserRemise(Request $request, Remise $remise): RedirectResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->circuit->repondreRemise($remise, $request->user(), false, $data['motif']);

        return $this->retour($request, $remise->demande, __('Remise refusée.'));
    }

    public function services(Request $request, Demande $demande): RedirectResponse
    {
        abort_unless($demande->enCours(), 422, __('La demande est close.'));
        $codes = array_keys($this->ref->services());
        $data = $request->validate([
            'service_source' => ['required', Rule::in($codes)],
            'service_destination' => ['required', Rule::in($codes)],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->circuit->changerServices($demande, $data['service_source'], $data['service_destination'], $request->user(), $data['commentaire'] ?? null);

        return back()->with('statut', __('Services enregistrés.'));
    }

    public function commenter(Request $request, Demande $demande): RedirectResponse
    {
        $data = $request->validate(['texte' => ['required', 'string', 'max:1000']]);
        $this->circuit->commenter($demande, $data['texte'], $request->user());

        return back()->with('statut', __('Commentaire ajouté.'));
    }

    public function corriger(Request $request, Action $action): RedirectResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->circuit->corriger($action, $data['motif'], $request->user());

        return back()->with('statut', __('Correction enregistrée : l\'étape est à refaire.'));
    }

    public function annuler(Request $request, Demande $demande): RedirectResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->circuit->annuler($demande, $data['motif'], $request->user());

        return back()->with('statut', __('Demande annulée.'));
    }

    /** Bon imprimé de la demande (accompagne le dossier). */
    public function bon(Demande $demande): View
    {
        \App\Services\CinPatients::completer([$demande]);
        $demande->load('actions', 'remises', 'poches');

        return view('demandes.bon', ['d' => $demande, 'etat' => $this->circuit->etat($demande), 'ref' => $this->ref]);
    }

    /**
     * A2 : tout ce qui existe déjà pour ce patient, sur TOUS ses dossiers (même Identifiant) :
     * demandes en cours dans l'appli, poches disponibles (appli et stock BS Clinisys), autres
     * demandes DMI récentes (hors celle-ci), poches sorties vers lui (bons PA).
     * $affichage : lectures Clinisys protégées (l'écran s'affiche même si Clinisys ne répond pas).
     *
     * @return array{dossiers: string[], existantes: \Illuminate\Support\Collection, disponibles: \Illuminate\Support\Collection,
     *               stockBS: array, autresDmi: array, sorties: array, deja: ?Demande, total: int}
     */
    private function controleA2(string $numDoss, ?object $patient, ?string $numDemande, Patients $patients, DemandesDmi $dmi, Pharmacie $pharmacie, bool $affichage = false): array
    {
        $lire = fn (callable $f, $defaut = []) => $affichage ? C::essayer($f, $defaut) : $f();
        $identifiant = $patient->Identifiant ?? null;
        $tousDossiers = $affichage
            ? fn () => (array) C::enCache('clinisys.dossiers.'.md5((string) $identifiant), 60, fn () => $patients->dossiersDuPatient($identifiant))
            : fn () => $patients->dossiersDuPatient($identifiant);
        $dossiers = array_values(array_unique(array_merge([$numDoss], $lire($tousDossiers))));
        $jours = config('demandesang.a2');

        [$demandes, $disponibles] = $this->verificationStock($numDoss, $identifiant, $dossiers);
        $existantes = $demandes->where('statut', 'en_cours')->values();
        // Une seule lecture du stock, filtrée ici sur les dossiers du patient. Écran : gardée une minute ; enregistrement : lecture fraîche.
        $stockComplet = $affichage
            ? (array) (C::essayer(fn () => C::enCache('clinisys.stock_bs', 60, fn () => $pharmacie->stockBS()), []) ?? [])
            : $pharmacie->stockBS();
        $nomPatient = self::nomComparable(trim((string) ($patient->NomCli ?? '').' '.(string) ($patient->Prenom ?? '')));
        $stockBS = [];
        foreach ($this->disponiblesBS($stockComplet) as $p) {
            $dossierDuPatient = in_array(trim((string) ($p->NumDoss ?? '')), $dossiers, true);
            // Diagramme : la recherche se fait obligatoirement par le nom du patient, en plus de ses dossiers.
            $memeNom = $nomPatient !== '' && self::nomComparable(trim((string) ($p->NomCli ?? '').' '.(string) ($p->Prenom ?? ''))) === $nomPatient;
            if (($dossierDuPatient || $memeNom) && ((string) ($p->numDemande ?? '') === '' || (string) $p->numDemande !== (string) $numDemande)) {
                $p->meme_nom_seul = ! $dossierDuPatient;
                $stockBS[] = $p;
            }
        }
        $autresDmi = $lire(fn () => $dmi->autresDuPatient($dossiers, $jours['jours_demandes'], $numDemande));
        $sorties = $lire(fn () => $pharmacie->sortiesPatient($dossiers, $jours['jours_sorties']));

        // Pour chaque autre demande DMI : son statut dans l'appli et les poches reçues / demandées.
        // Reçues = poches saisies au contrôle (C3) dans l'appli, sinon lignes des bons de dépôt BD.
        $inscrites = Demande::withCount('poches')->whereIn('num_demande_clinisys', array_map(fn ($x) => (string) $x->ID, $autresDmi))
            ->where('statut', '<>', 'annulee')->get()->keyBy('num_demande_clinisys');
        $parBd = $lire(fn () => $pharmacie->pochesParDemande(array_map(fn ($x) => (string) $x->ID, $autresDmi)));
        // Lecture d'information : protégée même à l'enregistrement (n'empêche jamais l'inscription).
        $lieux = C::essayer(fn () => $pharmacie->lieuxDesPoches(array_map(fn ($x) => (string) $x->ID, $autresDmi)), []);
        foreach ($autresDmi as $x) {
            $x->app = $inscrites[(string) $x->ID] ?? null;
            $pr = DemandesDmi::preremplissage($x);
            // Demandées : celles suivies par l'appli (le 1er produit) si la demande y est inscrite, sinon tous les produits DMI.
            $x->demandees = $x->app ? $x->app->nb_poches : ($pr['produit'] ? $pr['nb_poches'] + array_sum($pr['autres_produits']) : 0);
            $x->recues = max($x->app ? $x->app->recues() : 0, $parBd[(string) $x->ID] ?? 0);
            // relance : servie en partie, l'équipe redemande le reste ; servie : tout reçu ;
            // doublon : rien reçu, la demande précédente est peut-être encore attendue.
            $x->classe = $x->recues === 0 ? 'doublon' : ($x->recues < $x->demandees ? 'relance' : 'servie');
            // Où est chaque poche reçue (Clinisys) : délivrée au service (bon PA), retournée (PR) ou encore en stock.
            $x->lieux = $lieux[(string) $x->ID] ?? [];
            $x->delivrees = count(array_filter($x->lieux, fn ($p) => ! empty($p->pa_bon)));
            $x->en_stock = count(array_filter($x->lieux, fn ($p) => empty($p->pa_bon) && empty($p->pr_bon) && ! empty($p->en_stock)));
        }
        // Bloquant (motif obligatoire) : demande précédente sans aucune poche reçue, encore ouverte
        // (dans l'appli ou pas inscrite), ou poche disponible pour le patient. Une relance
        // (demande servie en partie) ou une demande servie ne bloque pas.
        $doublons = array_filter($autresDmi, fn ($x) => $x->classe === 'doublon' && (! $x->app || $x->app->enCours()));
        $enCoursSansPoche = $existantes->filter(fn ($e) => $e->poches()->count() === 0
            && ! collect($doublons)->contains(fn ($x) => $x->app?->id === $e->id));
        $relances = array_values(array_filter($autresDmi, fn ($x) => $x->classe === 'relance'));
        $deja = $numDemande ? Demande::where('num_demande_clinisys', $numDemande)->where('statut', '<>', 'annulee')->first() : null;

        return [
            'dossiers' => $dossiers, 'existantes' => $existantes, 'disponibles' => $disponibles, 'stockBS' => $stockBS,
            'autresDmi' => $autresDmi, 'sorties' => $sorties, 'deja' => $deja, 'relances' => $relances,
            'doublons' => array_values($doublons), 'enCoursSansPoche' => $enCoursSansPoche->values(),
            // Poches sorties (PA) : information seulement (celle d'une demande servie en partie l'est forcément).
            'total' => count($doublons) + $enCoursSansPoche->count() + $disponibles->count() + count($stockBS),
            'totalAutres' => count($doublons) + $enCoursSansPoche->count() + $disponibles->count(),
            'infos' => count($autresDmi) - count($doublons) + count($sorties),
        ];
    }

    /**
     * Recherche par nom, dossier ou identifiant : patients trouvés et, pour chacun, ses demandes
     * DMI des 30 derniers jours (hors supprimées) avec leur statut dans l'appli.
     */
    private function rechercherPatients(string $q, Patients $patients, DemandesDmi $dmi): array
    {
        $trouves = C::essayer(fn () => $patients->chercherPatients($q), []);
        $dossiers = array_merge(...array_map(fn ($p) => array_slice($p->dossiers, 0, 10), $trouves));
        $demandes = collect(C::essayer(fn () => $dmi->autresDuPatient($dossiers, (int) config('demandesang.a2.jours_demandes')), []));
        $inscrites = Demande::whereIn('num_demande_clinisys', $demandes->map(fn ($x) => (string) $x->ID)->all())
            ->where('statut', '<>', 'annulee')->get()->keyBy('num_demande_clinisys');
        foreach ($trouves as $p) {
            $p->demandes = $demandes->filter(fn ($x) => in_array(trim((string) $x->NumDoss), $p->dossiers, true))
                ->each(fn ($x) => $x->app = $inscrites[(string) $x->ID] ?? null)->values()->all();
        }

        return $trouves;
    }

    /**
     * N° CIN exigé par la banque de sang : celui de Clinisys, sinon saisi à l'inscription (1 ou 2 lettres puis des chiffres),
     * sinon, sans CIN (mineur, étranger), le n° d'une autre pièce. Rien n'est écrit dans Clinisys.
     *
     * @return array{0: string, 1: string} [cin, source : clinisys | saisie | autre]
     */
    private static function cinDeLaDemande(?object $patient, array $data): array
    {
        $clinisys = strtoupper(trim((string) ($patient->NumCIN ?? '')));
        if ($clinisys !== '') {
            return [$clinisys, 'clinisys'];
        }
        if (! empty($data['sans_cin'])) {
            $piece = trim((string) ($data['piece_autre'] ?? ''));
            if ($piece === '') {
                throw ValidationException::withMessages(['piece_autre' => __('Indiquez la pièce d\'identité (ex. passeport n° …).')]);
            }

            return [mb_strtoupper($piece), 'autre'];
        }
        $cin = strtoupper(preg_replace('/\s+/', '', (string) ($data['cin'] ?? '')));
        if ($cin === '') {
            throw ValidationException::withMessages(['cin' => __('Le n° CIN du patient est obligatoire (exigé par la banque de sang).')]);
        }
        if (! preg_match('/^[A-Z]{1,2}[0-9]{1,8}$/', $cin)) {
            throw ValidationException::withMessages(['cin' => __('N° CIN invalide : 1 ou 2 lettres puis des chiffres (ex. AB123456).')]);
        }

        return [$cin, 'saisie'];
    }

    /** Nom comparable : minuscules, sans accents, espaces réduits. */
    private static function nomComparable(string $nom): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(preg_replace('/\s+/', ' ', trim($nom))));
    }

    /** Le dossier est-il une hospitalisation en cours (chambre d'un service d'hospitalisation, pas encore de sortie) ? */
    public static function hospitalisationEnCours(?object $patient): bool
    {
        $service = strtoupper(trim((string) ($patient->service_code ?? '')));

        return $service !== '' && ! in_array($service, config('demandesang.services_externes'), true) && empty($patient->DatDep);
    }

    /**
     * Poche disponible : on ne fait pas de demande, on la récupère à la pharmacie. La récupération est notée au journal ;
     * la délivrance est ensuite suivie par la sortie patient (bon PA) dans Clinisys.
     */
    public function recupererPoche(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'poche' => ['required', 'string', 'max:40'],
            'num_doss' => ['nullable', 'string', 'max:20'],
            'num_demande' => ['nullable', 'string', 'max:30'],
        ]);
        Journal::ecrire('recuperation_poche', array_filter(['poche' => $data['poche'], 'dossier' => $data['num_doss'] ?? null, 'dmi' => $data['num_demande'] ?? null]));

        return redirect()->route('stock', ['q' => $data['poche'], 'vue' => 'bs_stock'])
            ->with('statut', __('Récupération notée : allez chercher la poche :n à la pharmacie. La délivrance sera suivie par le bon de sortie dans Clinisys.', ['n' => $data['poche']]));
    }

    /** A2 : une poche périmée du stock BS n'est pas « disponible » pour le patient. */
    private function disponiblesBS(array $stock): array
    {
        return array_values(array_filter($stock, fn ($p) => empty($p->perimee)));
    }

    /**
     * Encart « Clinisys » de la fiche : demande DMI, panier dépôt, bons BD et PR liés,
     * écarts entre les poches des BD et celles saisies au contrôle (C3).
     */
    private function lectureClinisys(Demande $d, DemandesDmi $dmi, Pharmacie $pharmacie): ?array
    {
        if (! $pharmacie->branche() || ! $d->num_demande_clinisys) {
            return null;
        }
        $numero = $d->num_demande_clinisys;
        $bons = C::essayer(fn () => $pharmacie->bonsDepot($numero), []);

        return [
            'demande' => C::essayer(fn () => $dmi->demande($numero)),
            'panier' => C::essayer(fn () => $pharmacie->panier($numero), []),
            'bons' => $bons,
            'prelevements' => C::essayer(fn () => $pharmacie->prelevements(array_map(fn ($b) => $b->numaffiche, $bons)), []),
            'lieux' => C::essayer(fn () => $pharmacie->lieuxDesPoches([$numero]), [])[(string) $numero] ?? [],
            'ecarts' => $d->poches->isNotEmpty() && $bons !== [] ? Pharmacie::ecarts($bons, $d->poches->pluck('numero')->all()) : null,
            'erreur' => C::$erreur,
        ];
    }

    /**
     * Clinisys actif : la demande DMI doit exister et porter le même n° de dossier ;
     * ses valeurs (diagnostic, produit, nombre de poches, groupe, médecin) remplacent
     * celles du formulaire. Les autres produits demandés vont en commentaire.
     */
    private function appliquerDemandeDmi(array $data, ?object $demandeDmi): array
    {
        if (! $demandeDmi) {
            throw ValidationException::withMessages(['num_demande_clinisys' => __('Demande n° :n introuvable dans le DMI Clinisys.', ['n' => $data['num_demande_clinisys']])]);
        }
        if (DemandesDmi::supprimee($demandeDmi)) {
            throw ValidationException::withMessages(['num_demande_clinisys' => __('La demande DMI n° :n est supprimée dans Clinisys.', ['n' => $data['num_demande_clinisys']])]);
        }
        $p = DemandesDmi::preremplissage($demandeDmi);
        if ($p['num_doss'] !== trim($data['num_doss'])) {
            throw ValidationException::withMessages(['num_doss' => __('La demande DMI n° :n concerne le dossier :dossier, pas :saisi.', ['n' => $data['num_demande_clinisys'], 'dossier' => $p['num_doss'], 'saisi' => $data['num_doss']])]);
        }

        foreach (['produit', 'nb_poches', 'groupe_sanguin', 'diagnostic', 'medecin'] as $champ) {
            if ($p[$champ] !== null && $p[$champ] !== '') {
                $data[$champ] = $p[$champ];
            }
        }
        if ($p['autres_produits'] !== []) {
            $note = 'Autres produits de la demande DMI : '.DemandesDmi::libelleProduits($p['autres_produits']).'.';
            $data['commentaire'] = trim($note.' '.($data['commentaire'] ?? ''));
        }
        $data['etat_dmi'] = trim((string) $demandeDmi->Etat);

        return $data;
    }

    /**
     * A2 : demandes en cours et poches disponibles (en stock ou délivrées non
     * transfusées) pour ce patient, par n° de dossier ou identifiant.
     */
    private function verificationStock(string $numDoss, ?string $identifiant, array $dossiers = []): array
    {
        $demandes = Demande::whereIn('num_doss', array_values(array_unique(array_merge([$numDoss], $dossiers))))
            ->when($identifiant, fn ($q) => $q->orWhere('identifiant_patient', $identifiant))
            ->orderByDesc('cree_le')->limit(20)->get();
        $disponibles = Poche::whereIn('demande_id', $demandes->pluck('id'))->whereIn('statut', ['en_stock', 'delivree'])->get();

        return [$demandes, $disponibles];
    }

    private function retour(Request $request, Demande $d, string $message): RedirectResponse
    {
        return match ($request->input('retour')) {
            'accueil' => redirect()->route('accueil')->with('statut', $message),
            'cts' => redirect()->route('cts')->with('statut', $message),
            default => redirect()->route('demandes.show', $d)->with('statut', $message),
        };
    }
}
