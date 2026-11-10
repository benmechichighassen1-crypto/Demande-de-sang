<?php

namespace App\Services;

use App\Auth\Utilisateur;
use App\Clinisys\Pharmacie;
use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use App\Models\Remise;
use App\Models\ServiceDemande;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Circuit d'une demande de sang : quelles étapes sont faites, lesquelles sont
 * possibles, et enregistrement de chaque action dans le registre (ds_actions).
 *
 * L'état se déduit toujours du registre : une étape est faite quand une action
 * non corrigée existe pour elle (pour une remise : quand le receveur l'a
 * confirmée). Les colonnes etape / statut de ds_demandes ne sont qu'un résumé.
 */
class Circuit
{
    public function __construct(private Referentiels $ref, private Pharmacie $pharmacie)
    {
    }

    /** @return array<string, array> définition des étapes du circuit */
    public function etapes(): array
    {
        return config('demandesang.etapes');
    }

    /**
     * État de chaque étape : faite | remise_attente | disponible | bloquee | sans_objet | non_tracee | fermee.
     *
     * @return array<string, array{def: array, statut: string, action: ?Action, remise: ?Remise}>
     */
    public function etat(Demande $d): array
    {
        $d->loadMissing('actions', 'remises');
        $valides = $this->actionsValides($d);
        $etat = [];
        // Inscrite a posteriori (reçue en pharmacie hors registre) : les étapes d'avant la pharmacie (avant C5)
        // non faites restent « non tracées », sans faux horodatage, et ne bloquent pas la suite du circuit.
        $avantPharmacie = $d->regularisation !== null;

        foreach ($this->etapes() as $code => $def) {
            if ($code === 'C5') {
                $avantPharmacie = false;
            }
            $ligne = ['def' => $def, 'statut' => '', 'action' => null, 'remise' => null];

            if (! $this->applicable($def, $d)) {
                $ligne['statut'] = 'sans_objet';
                $etat[$code] = $ligne;
                continue;
            }

            if ($def['type'] === 'remise') {
                $remise = $d->remises->where('etape', $code)->whereIn('reponse', ['attente', 'confirmee'])->last();
                $ligne['remise'] = $remise;
                $ligne['action'] = $remise ? $valides->firstWhere('remise_id', $remise->id) : null;
                if ($remise && $remise->reponse === 'confirmee') {
                    $ligne['statut'] = 'faite';
                } elseif ($remise) {
                    $ligne['statut'] = 'remise_attente';
                }
            } elseif ($action = $valides->where('etape', $code)->last()) {
                $ligne['action'] = $action;
                $ligne['statut'] = 'faite';
            }

            if ($ligne['statut'] === '' && $avantPharmacie) {
                $ligne['statut'] = 'non_tracee';
            }
            if ($ligne['statut'] === '') {
                $pret = collect($def['apres'])->every(fn ($p) => in_array($etat[$p]['statut'] ?? 'sans_objet', ['faite', 'sans_objet', 'non_tracee'], true));
                $ligne['statut'] = ! $d->enCours() ? 'fermee' : ($pret ? 'disponible' : 'bloquee');
            }
            $etat[$code] = $ligne;
        }

        return $etat;
    }

    /** Étapes de préparation du dossier dans le service, saisissables en un seul formulaire. */
    public const PREPARATION = ['A4', 'A6', 'A7', 'A9'];

    /**
     * Étapes de préparation que l'utilisateur peut faire maintenant, y compris celles
     * qui n'attendent qu'une autre étape de préparation (A6 après A4).
     */
    public function preparation(Demande $d, Utilisateur $u, ?array $etat = null): array
    {
        $etat ??= $this->etat($d);
        $codes = [];
        foreach (self::PREPARATION as $code) {
            $e = $etat[$code];
            $prerequisOk = collect($e['def']['apres'])->every(fn ($p) => in_array($p, $codes, true)
                || in_array($etat[$p]['statut'] ?? 'sans_objet', ['faite', 'sans_objet'], true));
            if (in_array($e['statut'], ['disponible', 'bloquee'], true) && $prerequisOk && $d->enCours() && $u->a(...$e['def']['roles'])) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Enregistre plusieurs étapes de préparation d'un coup : une action par étape
     * (même traçabilité et mêmes contrôles qu'une saisie une par une), tout ou rien.
     */
    public function enregistrerPreparation(Demande $d, array $codes, array $data, Utilisateur $u): void
    {
        DB::transaction(function () use ($d, $codes, $data, $u) {
            foreach ($codes as $i => $code) {
                $champs = array_keys($this->regles($code, $d));
                $donnees = array_intersect_key($data, array_flip(array_map(fn ($c) => explode('.', $c)[0], $champs)));
                $donnees['commentaire'] = $i === 0 ? ($data['commentaire'] ?? null) : null;
                $this->enregistrer($d->fresh(), $code, $donnees, $u);
            }
        });
    }

    /** Étape déduite de Clinisys (C5 bon de dépôt, C8 sortie patient) : personne ne la saisit quand Clinisys est branché. */
    public static function automatique(array $def): bool
    {
        return ! empty($def['auto']) && (bool) config('demandesang.clinisys_actif');
    }

    /**
     * Enregistre une étape lue dans Clinisys : même registre et mêmes effets qu'une saisie,
     * auteur « Clinisys (utilisateur du bon) », heure = heure du bon Clinisys.
     */
    public function enregistrerAuto(Demande $d, string $code, array $donnees, \DateTimeInterface $le, string $utilisateurClinisys): ?Action
    {
        return DB::transaction(function () use ($d, $code, $donnees, $le, $utilisateurClinisys) {
            $d = Demande::lockForUpdate()->findOrFail($d->id);
            if (($this->etat($d)[$code]['statut'] ?? '') !== 'disponible') {
                return null;
            }
            $u = self::clinisys($utilisateurClinisys);
            $action = $this->ajouterAction($d, $code, $u, $donnees + ['source' => 'clinisys'], null, ['fait_le' => $le, 'ip' => null, 'appareil' => 'Clinisys']);
            $this->effets($d, $code, $donnees, $u);
            $this->resumer($d);

            return $action;
        });
    }

    /** Auteur des étapes lues dans Clinisys. */
    public static function clinisys(string $utilisateur = ''): Utilisateur
    {
        return new Utilisateur('clinisys', 'Clinisys'.($utilisateur !== '' ? ' ('.$utilisateur.')' : ''), [], [], false, true);
    }

    /** Étape sans champ obligatoire (seulement l'heure facultative) : validable d'un seul bouton. */
    public static function sansSaisie(array $def): bool
    {
        return array_diff($def['champs'], ['heure']) === [];
    }

    /** Étapes que l'utilisateur peut saisir maintenant. */
    public function possibles(Demande $d, Utilisateur $u, ?array $etat = null): array
    {
        $etat ??= $this->etat($d);

        return array_keys(array_filter($etat, fn ($e) => $e['statut'] === 'disponible' && ! self::automatique($e['def']) && $u->a(...$e['def']['roles'])));
    }

    public function peutConfirmer(Remise $r, Utilisateur $u): bool
    {
        return $r->reponse === 'attente' && $r->donneur !== $u->userName && $u->a(...$r->rolesReceveur());
    }

    /** Règles de validation des champs d'une étape. */
    public function regles(string $code, Demande $d): array
    {
        $r = ['commentaire' => ['nullable', 'string', 'max:1000']];
        $groupes = config('demandesang.groupes');

        foreach ($this->etapes()[$code]['champs'] as $champ) {
            $r += match ($champ) {
                // Vérifié par le coursier à A3 (obligatoire) ; une option « à la charge de » exige qui la prend en charge.
                'paiement' => [
                    'paiement' => ['required', Rule::in(array_keys(config('demandesang.paiements')))],
                    'paiement_charge' => ['required_if:paiement,charge', 'nullable', Rule::in(array_keys(config('demandesang.charges')))],
                    // N° du reçu / nom de qui prend en charge : facultatifs.
                    'paiement_ref' => ['nullable', 'string', 'max:60'],
                ],
                'identite_ok' => ['identite_ok' => ['accepted']],
                'carte_groupage' => ['carte_groupage' => ['required', 'in:oui,non']],
                'pieces' => [
                    'pieces' => ['required', 'array'],
                    'pieces.demande' => ['accepted'],
                    'pieces.analyses' => ['nullable'],
                ],
                'heure' => ['heure' => ['nullable', 'date_format:H:i']],
                'services' => [
                    'service_source' => ['required', Rule::in(array_keys($this->ref->services()))],
                    'service_destination' => ['required', Rule::in(array_keys($this->ref->services()))],
                ],
                'etat_cts' => [
                    'etat_cts' => ['required', 'in:pretes,instance'],
                    // Livraison partielle repérée dès le CTS (ex. 1 poche prête sur 3).
                    // Absent = toutes les poches demandées.
                    'nb_pretes' => ['nullable', 'integer', 'min:1', 'max:'.max($d->nb_poches, 1)],
                ],
                'conformite' => [
                    'conforme' => ['required', 'in:oui,non'],
                    'motif_non_conformite' => ['required_if:conforme,non', 'nullable', 'string', 'max:255'],
                ],
                'poches' => [
                    // N° de poche facultatif au contrôle : sinon repris du bon de dépôt Clinisys à la réception (C5).
                    'poches' => ['nullable', 'array', 'max:20'],
                    'poches.*.numero' => ['required', 'string', 'max:40', 'distinct'],
                    'poches.*.produit' => ['required', Rule::in(array_keys(config('demandesang.produits')))],
                    'poches.*.groupe_sanguin' => ['nullable', Rule::in($groupes)],
                    'poches.*.peremption' => ['nullable', 'date'],
                ],
                'bon_depot' => ['bon_depot' => ['required', 'string', 'max:30']],
                'poches_delivrees' => [
                    'poches_delivrees' => ['required', 'array', 'min:1'],
                    'poches_delivrees.*' => ['integer', Rule::exists('ds_poches', 'id')->where('demande_id', $d->id)->where('statut', 'en_stock')],
                ],
                'issue' => [
                    'issue' => ['required', 'array', 'min:1'],
                    'issue.*' => ['required', 'in:transfusee,non_utilisee'],
                    'motif' => ['nullable', 'array'],
                    'motif.*' => ['nullable', Rule::in(array_keys(config('demandesang.motifs_non_utilisation')))],
                ],
                default => [],
            };
        }

        return $r;
    }

    /** Enregistre une étape (ou la remise, pour une étape à double validation). */
    public function enregistrer(Demande $d, string $code, array $data, Utilisateur $u): Action
    {
        return DB::transaction(function () use ($d, $code, $data, $u) {
            $d = Demande::lockForUpdate()->findOrFail($d->id);
            $etat = $this->etat($d);
            $def = $etat[$code]['def'] ?? abort(404);

            if ($etat[$code]['statut'] !== 'disponible') {
                throw ValidationException::withMessages(['etape' => __('Cette étape n\'est pas possible maintenant (:statut).', ['statut' => $this->libelleStatut($etat[$code]['statut'])])]);
            }
            abort_unless($u->a(...$def['roles']), 403, __('Votre rôle ne permet pas cette étape.'));
            if (self::automatique($def)) {
                throw ValidationException::withMessages(['etape' => __('Cette étape est enregistrée automatiquement depuis Clinisys (:source).', ['source' => $def['auto']])]);
            }

            $donnees = $this->donnees($code, $data, $d);
            $remise = null;
            if ($def['type'] === 'remise') {
                $remise = Remise::create([
                    'demande_id' => $d->id, 'etape' => $code, 'donneur' => $u->userName, 'nom_donneur' => $u->nom,
                    'remis_le' => now(), 'reponse' => 'attente',
                ]);
            }

            $action = $this->ajouterAction($d, $code, $u, $donnees, $data['commentaire'] ?? null, ['remise_id' => $remise?->id]);
            $this->effets($d, $code, $donnees, $u);
            $this->resumer($d);

            return $action;
        });
    }

    /** Le receveur confirme (ou refuse) une remise de main à main. */
    public function repondreRemise(Remise $r, Utilisateur $u, bool $accepte, ?string $motif = null): void
    {
        DB::transaction(function () use ($r, $u, $accepte, $motif) {
            $r = Remise::lockForUpdate()->findOrFail($r->id);
            if ($r->reponse !== 'attente') {
                throw ValidationException::withMessages(['remise' => __('Cette remise a déjà reçu une réponse.')]);
            }
            if ($r->donneur === $u->userName) {
                throw ValidationException::withMessages(['remise' => __('La remise doit être confirmée par une autre personne que celle qui remet.')]);
            }
            abort_unless($u->a(...$r->rolesReceveur()), 403, __('Votre rôle ne permet pas de recevoir cette remise.'));
            if (! $accepte && trim((string) $motif) === '') {
                throw ValidationException::withMessages(['motif' => __('Indiquez pourquoi la remise est refusée.')]);
            }

            $r->update([
                'receveur' => $u->userName, 'nom_receveur' => $u->nom, 'repondu_le' => now(),
                'reponse' => $accepte ? 'confirmee' : 'refusee', 'motif_refus' => $accepte ? null : $motif,
            ]);

            $d = $r->demande;
            $this->ajouterAction($d, $accepte ? 'remise_confirmee' : 'remise_refusee', $u,
                ['etape' => $r->etape, 'donneur' => $r->nom_donneur], $accepte ? null : $motif, ['remise_id' => $r->id]);

            if ($accepte && $r->etape === 'C8') {
                $ids = Action::where('remise_id', $r->id)->where('etape', 'C8')->first()?->donnees['poches_delivrees'] ?? [];
                Poche::whereIn('id', $ids)->where('statut', 'en_stock')->update(['statut' => 'delivree', 'statut_le' => now()]);
            }
            $this->resumer($d);
        });
    }

    /**
     * Corrige une étape simple : l'action reste dans le registre, une action
     * « correction » l'annule et l'étape redevient possible.
     */
    public function corriger(Action $a, string $motif, Utilisateur $u): void
    {
        DB::transaction(function () use ($a, $motif, $u) {
            $d = Demande::lockForUpdate()->findOrFail($a->demande_id);
            $etat = $this->etat($d);
            $code = $a->etape;

            if (! isset($etat[$code]) || $etat[$code]['def']['type'] !== 'simple' || $etat[$code]['action']?->id !== $a->id) {
                throw ValidationException::withMessages(['correction' => __('Seule la dernière saisie d\'une étape simple peut être corrigée.')]);
            }
            abort_unless($u->a(...$etat[$code]['def']['roles']), 403);
            $suivantes = array_keys(array_filter($etat, fn ($e) => in_array($code, $e['def']['apres'], true)
                && in_array($e['statut'], ['faite', 'remise_attente'], true)));
            if ($suivantes !== []) {
                throw ValidationException::withMessages(['correction' => __('Étape déjà suivie de : :etapes. Corrigez d\'abord celles-ci.', ['etapes' => implode(', ', $suivantes)])]);
            }

            $this->ajouterAction($d, 'correction', $u, ['etape' => $code], $motif, ['annule_action_id' => $a->id]);
            $this->annulerEffets($d, $code, $a->donnees ?? []);
            $this->resumer($d);
        });
    }

    public function annuler(Demande $d, string $motif, Utilisateur $u): void
    {
        DB::transaction(function () use ($d, $motif, $u) {
            $d = Demande::lockForUpdate()->findOrFail($d->id);
            if (! $d->enCours()) {
                throw ValidationException::withMessages(['annulation' => __('La demande n\'est plus en cours.')]);
            }
            $this->ajouterAction($d, 'annulation', $u, [], $motif);
            Remise::where('demande_id', $d->id)->where('reponse', 'attente')->update(['reponse' => 'annulee', 'repondu_le' => now()]);
            $d->update(['statut' => 'annulee']);
        });
    }

    /** Service source / destination (saisie du major, ou transfert du patient). */
    public function changerServices(Demande $d, ?string $source, ?string $destination, Utilisateur $u, ?string $commentaire = null): void
    {
        DB::transaction(function () use ($d, $source, $destination, $u, $commentaire) {
            $transfert = $d->service_destination !== null && $destination !== $d->service_destination;
            ServiceDemande::create([
                'demande_id' => $d->id, 'service_source' => $source, 'service_destination' => $destination,
                'transfert' => $transfert, 'saisi_par' => $u->userName, 'saisi_le' => now(),
            ]);
            $this->ajouterAction($d, 'services', $u, [
                'service_source' => $source, 'service_destination' => $destination, 'transfert' => $transfert,
            ], $commentaire);
            $d->update(['service_source' => $source, 'service_destination' => $destination]);
        });
    }

    public function commenter(Demande $d, string $texte, Utilisateur $u): void
    {
        $this->ajouterAction($d, 'commentaire', $u, [], $texte);
    }

    /** Prélèvement fournisseur : poche non consommée retournée à la banque de sang. */
    public function preleverPoche(Poche $p, string $motif, ?string $bon, ?string $commentaire, Utilisateur $u): void
    {
        DB::transaction(function () use ($p, $motif, $bon, $commentaire, $u) {
            $p = Poche::lockForUpdate()->findOrFail($p->id);
            if (! in_array($p->statut, ['en_stock', 'a_retourner'], true)) {
                throw ValidationException::withMessages(['poche' => __('Seule une poche en stock ou à retourner peut faire l\'objet d\'un prélèvement fournisseur.')]);
            }
            $p->update(['statut' => 'prelevee', 'motif' => $motif, 'bon_prelevement' => $bon, 'statut_le' => now()]);
            $this->ajouterAction($p->demande, 'prelevement_fournisseur', $u,
                ['poche' => $p->numero, 'motif' => $motif, 'bon_prelevement' => $bon], $commentaire);
        });
    }

    public function ajouterAction(Demande $d, string $etape, Utilisateur $u, array $donnees = [], ?string $commentaire = null, array $extra = []): Action
    {
        $request = request();

        // $extra d'abord : il peut fixer l'heure (étape lue dans Clinisys) ou lier une remise / correction.
        return Action::create($extra + [
            'demande_id' => $d->id,
            'etape' => $etape,
            'user_name' => $u->userName,
            'nom_acteur' => $u->nom,
            'fait_le' => now(),
            'donnees' => $donnees === [] ? null : $donnees,
            'commentaire' => $commentaire !== null && trim($commentaire) !== '' ? trim($commentaire) : null,
            'ip' => $request->ip(),
            'appareil' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Responsable actuel d'une demande en cours : ['type' => 'confirmer' | 'faire' | 'clinisys', 'roles' => [...]] —
     * receveur d'une remise en attente, sinon rôles de la prochaine étape à faire, sinon Clinisys (C5 / C8 automatiques).
     */
    public function responsable(Demande $d, ?array $etat = null): ?array
    {
        if (! $d->enCours()) {
            return null;
        }
        $etat ??= $this->etat($d);
        foreach ($etat as $e) {
            if ($e['statut'] === 'remise_attente') {
                return ['type' => 'confirmer', 'roles' => $e['def']['receveur'] ?? []];
            }
        }
        foreach ($etat as $e) {
            if ($e['statut'] === 'disponible') {
                return self::automatique($e['def']) ? ['type' => 'clinisys', 'roles' => ['pharmacie']] : ['type' => 'faire', 'roles' => $e['def']['roles']];
            }
        }

        return null;
    }

    public function libelleStatut(string $statut): string
    {
        return [
            'faite' => __('faite'), 'remise_attente' => __('remise en attente de confirmation'), 'disponible' => __('à faire'),
            'bloquee' => __('étapes précédentes non faites'), 'sans_objet' => __('sans objet'), 'fermee' => __('demande close'),
            'non_tracee' => __('non tracée (demande inscrite a posteriori)'),
        ][$statut] ?? $statut;
    }

    /** Recalcule le résumé de la demande (dernière étape faite, statut). */
    public function resumer(Demande $d): void
    {
        $d->unsetRelation('actions')->unsetRelation('remises');
        $etat = $this->etat($d);
        $derniere = null;
        $le = null;
        foreach ($etat as $code => $e) {
            if ($e['statut'] === 'faite') {
                $derniere = $code;
                $le = $e['remise']?->repondu_le ?? $e['action']?->fait_le;
            }
        }

        $statut = $d->statut === 'annulee' ? 'annulee' : (($etat['C10']['statut'] ?? '') === 'faite' ? 'cloturee' : 'en_cours');
        $d->update(['etape' => $derniere, 'etape_le' => $le ?? $d->cree_le, 'statut' => $statut]);
    }

    private function applicable(array $def, Demande $d): bool
    {
        return match ($def['si'] ?? null) {
            'externe' => $d->externe,
            default => true,
        };
    }

    private function actionsValides(Demande $d)
    {
        $annulees = $d->actions->pluck('annule_action_id')->filter()->all();

        return $d->actions->reject(fn ($a) => in_array($a->id, $annulees, true));
    }

    /** Données de l'étape conservées dans le registre (sans le commentaire). */
    private function donnees(string $code, array $data, Demande $d): array
    {
        $donnees = collect($data)->except(['commentaire', '_token'])->all();
        if ($code === 'A6') {
            $donnees['nb_tubes'] = $data['carte_groupage'] === 'oui' ? 1 : 2;
        }
        if ($code === 'C3') {
            $donnees['nb_attendu'] = $d->nb_poches;
        }
        if ($code === 'B2') {
            $donnees['nb_demandees'] = $d->nb_poches;
            if (($donnees['etat_cts'] ?? '') !== 'pretes') {
                unset($donnees['nb_pretes']);
            } else {
                $donnees['nb_pretes'] = (int) ($donnees['nb_pretes'] ?? $d->nb_poches);
            }
        }
        if ($code === 'C5') {
            $donnees += $this->bonDepotClinisys($d, $data['bon_depot']);
        }
        if ($code === 'C10') {
            foreach ($data['issue'] as $id => $issue) {
                if ($issue === 'non_utilisee' && empty($data['motif'][$id])) {
                    throw ValidationException::withMessages(["motif.$id" => __('Le motif est obligatoire pour une poche non utilisée.')]);
                }
            }
            $ids = array_map('intval', array_keys($data['issue']));
            $valides = Poche::where('demande_id', $d->id)->where('statut', 'delivree')->pluck('id')->all();
            if (array_diff($ids, $valides) !== []) {
                throw ValidationException::withMessages(['issue' => __('Poche inconnue ou non délivrée pour cette demande.')]);
            }
        }

        return $donnees;
    }

    /**
     * C5 : rapproche le bon de dépôt saisi des bons BD Clinisys de la demande DMI.
     * Trouvé → source « clinisys », poches du bon et écarts avec les poches du contrôle (C3).
     */
    public function bonDepotClinisys(Demande $d, string $bonSaisi): array
    {
        if (! $this->pharmacie->branche() || ! $d->num_demande_clinisys) {
            return ['source' => 'saisie'];
        }
        try {
            $bons = $this->pharmacie->bonsDepot($d->num_demande_clinisys);
        } catch (\Throwable $e) {
            report($e);

            return ['source' => 'saisie', 'clinisys' => 'indisponible'];
        }

        $saisi = strtoupper(trim($bonSaisi));
        $bon = collect($bons)->first(fn ($b) => in_array($saisi, [strtoupper($b->numaffiche), strtoupper($b->numbon)], true));
        if (! $bon) {
            return ['source' => 'saisie', 'bons_clinisys' => array_map(fn ($b) => $b->numaffiche, $bons)];
        }

        $numeros = Poche::where('demande_id', $d->id)->where('statut', 'attente')->pluck('numero')->all();

        return [
            'source' => 'clinisys',
            'bon_clinisys' => $bon->numbon,
            'poches_clinisys' => array_map(fn ($p) => ['numero' => $p->Identifiant, 'article' => $p->desart,
                'peremption' => $p->DatPer ? substr((string) $p->DatPer, 0, 10) : null], $bon->poches),
            'ecarts' => Pharmacie::ecarts([$bon], $numeros),
        ];
    }

    private function effets(Demande $d, string $code, array $donnees, Utilisateur $u): void
    {
        if ($code === 'C5' && ! Poche::where('demande_id', $d->id)->where('statut', 'attente')->exists()) {
            // Aucune poche saisie au contrôle (C3) : poches du bon BD Clinisys, sinon une poche « sans n° » par poche demandée.
            $pc = $donnees['poches_clinisys'] ?? [];
            $lignes = $pc !== [] ? $pc : array_map(fn ($i) => ['numero' => 'sans n° '.$d->id.'-'.$i, 'peremption' => null], range(1, max($d->nb_poches, 1)));
            foreach ($lignes as $l) {
                Poche::create(['demande_id' => $d->id, 'numero' => $l['numero'], 'produit' => $d->produit, 'groupe_sanguin' => $d->groupe_sanguin,
                    'peremption' => $l['peremption'], 'statut' => 'attente', 'statut_le' => now()]);
            }
        }
        if ($code === 'C5') {
            // Péremption manquante au contrôle : reprise du bon BD Clinisys.
            foreach ($donnees['poches_clinisys'] ?? [] as $pc) {
                if ($pc['peremption']) {
                    Poche::where('demande_id', $d->id)->where('statut', 'attente')->whereNull('peremption')->get()
                        ->filter(fn ($p) => Pharmacie::normaliser($p->numero) === Pharmacie::normaliser($pc['numero']))
                        ->each(fn ($p) => $p->update(['peremption' => $pc['peremption']]));
                }
            }
        }

        match ($code) {
            'A10' => $this->changerServices($d, $donnees['service_source'], $donnees['service_destination'], $u),
            'C3' => collect($donnees['poches'] ?? [])->each(fn ($p) => Poche::create([
                'demande_id' => $d->id, 'numero' => trim($p['numero']), 'produit' => $p['produit'],
                'groupe_sanguin' => $p['groupe_sanguin'] ?? null, 'peremption' => $p['peremption'] ?? null,
                'statut' => 'attente', 'statut_le' => now(),
            ])),
            'C5' => Poche::where('demande_id', $d->id)->where('statut', 'attente')
                ->update(['statut' => 'en_stock', 'bon_depot' => $donnees['bon_depot'], 'statut_le' => now()]),
            // C8 : poches délivrées au service (saisie, ou sortie patient lue dans Clinisys).
            'C8' => Poche::whereIn('id', $donnees['poches_delivrees'] ?? [])->where('statut', 'en_stock')->update(['statut' => 'delivree', 'statut_le' => now()]),
            'C10' => collect($donnees['issue'])->each(fn ($issue, $id) => Poche::where('id', $id)->update($issue === 'transfusee'
                ? ['statut' => 'transfusee', 'statut_le' => now()]
                : ['statut' => 'a_retourner', 'motif' => $donnees['motif'][$id], 'statut_le' => now()])),
            default => null,
        };
    }

    private function annulerEffets(Demande $d, string $code, array $donnees): void
    {
        match ($code) {
            'C3' => Poche::where('demande_id', $d->id)->where('statut', 'attente')->delete(),
            'C5' => Poche::where('demande_id', $d->id)->where('statut', 'en_stock')
                ->update(['statut' => 'attente', 'bon_depot' => null, 'statut_le' => now()]),
            'C8' => Poche::whereIn('id', $donnees['poches_delivrees'] ?? [])->where('statut', 'delivree')->update(['statut' => 'en_stock', 'statut_le' => now()]),
            'C10' => Poche::whereIn('id', array_keys($donnees['issue'] ?? []))->whereIn('statut', ['transfusee', 'a_retourner'])
                ->update(['statut' => 'delivree', 'motif' => null, 'statut_le' => now()]),
            default => null,
        };
    }
}
