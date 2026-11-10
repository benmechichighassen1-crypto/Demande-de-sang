<?php

namespace Tests\Feature;

use App\Auth\FournisseurUtilisateurs;
use App\Clinisys\DemandesDmi;
use App\Clinisys\Patients;
use App\Clinisys\Pharmacie;
use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use App\Models\Remise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branchement Clinisys simulé : les classes de lecture sont remplacées par des
 * doublures qui renvoient des lignes au format des tables Clinisys (valeurs
 * reprises des extraits du serveur de test).
 */
class ClinisysTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @var array<int, object> demandes DMI simulées, par ID */
    private array $dmi = [];

    private array $stockBS = [];

    private array $bons = [];

    /** Poches sorties vers le patient (bons PA) simulées. */
    public array $sorties = [];

    /** Lignes de sortie patient (PA) et de prélèvement fournisseur (PR) simulées pour la synchronisation. */
    public array $sortiesPa = [];

    public array $prs = [];

    /** Lignes de mouvements du dépôt BS (écran Stock), par type de bon BD / PA / PR. */
    public array $lignesBs = [];

    /** Où est chaque poche, par n° de demande DMI (Pharmacie::lieuxDesPoches). */
    public array $lieux = [];

    /** Autres demandes DMI du patient (tous dossiers) simulées pour le contrôle A2. */
    public array $autres = [];

    /** Poches reçues par n° de demande d'après les bons BD, simulées. */
    public array $bdParDemande = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['demandesang.clinisys_actif' => true]);
        // Une lecture Clinisys oubliée par les doublures échoue tout de suite (jamais de vrai serveur).
        config(['database.connections.clinisys' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $this->dmi = [
            1449 => $this->ligneDmi(1449, '26030752', 'D', ['GlobuleRouge' => 2]),
            1450 => $this->ligneDmi(1450, '26030752', 'D', ['GlobuleRouge' => 3, 'Plaquette' => 10]),
            1451 => $this->ligneDmi(1451, '26030752', 'S', ['Plasma' => 2]),
        ];
        $this->bons = [(object) [
            'numbon' => 'BDBD261455', 'numaffiche' => 'BD261455', 'datbon' => '2026-10-07 00:00:00', 'datesys' => '2026-10-07 00:00:00', 'heuresys' => '1900-01-01 13:01:00', 'Reffrs' => '0000001858384',
            'memop' => 'A+', 'coddep' => 'BS', 'codvend' => 'agent.pharma', 'numDemandeS' => '1449',
            'poches' => [
                (object) ['numbon' => 'BDBD261455', 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'Identifiant' => '07264553226', 'NumSerie' => '', 'lot' => '', 'DatPer' => '2026-11-16 00:00:00'],
                (object) ['numbon' => 'BDBD261455', 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'Identifiant' => '0726455339-', 'NumSerie' => '', 'lot' => '', 'DatPer' => '2026-11-18 00:00:00'],
            ],
        ]];

        $test = $this;
        $this->app->instance(Patients::class, new class extends Patients {
            public function dossier(string $numDoss): ?object
            {
                return $numDoss === '26030752'
                    ? (object) ['NumDoss' => $numDoss, 'Identifiant' => '000123', 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'NumCIN' => 'ZZ100200', 'service' => 'REANIMATION', 'medecin' => 'DR X']
                    : null;
            }

            public function chercherPatients(string $q, int $limite = 30): array
            {
                return str_starts_with('PATIENT', strtoupper($q)) || $q === '000123'
                    ? [(object) ['Identifiant' => '000123', 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'dossiers' => ['26030752', '26099999'], 'service' => 'REANIMATION', 'DatArr' => '2026-10-09']]
                    : [];
            }

            public function cinParDossiers(array $dossiers): array
            {
                return in_array('26030752', $dossiers, true) ? ['26030752' => 'ZZ100200'] : [];
            }

            public function dossiersDuPatient(?string $identifiant): array
            {
                return $identifiant === '000123' ? ['26030752', '26099999'] : [];
            }

            public function chercher(string $q): array
            {
                return $q === '26030752' ? [(object) ['NumDoss' => '26030752', 'Identifiant' => '000123', 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'DatArr' => '2026-10-09', 'service' => 'PRINCIPALE']] : [];
            }
        });
        $this->app->instance(DemandesDmi::class, new class($test) extends DemandesDmi {
            public function __construct(private ClinisysTest $t) {}

            public function demande(string $numero): ?object
            {
                return $this->t->dmi()[(int) $numero] ?? null;
            }

            public function recentes(string $numDoss, int $heures = 48): array
            {
                return array_values(array_filter($this->t->dmi(), fn ($d) => $d->NumDoss === $numDoss));
            }

            public function autresDuPatient(array $dossiers, int $jours, ?string $sauf = null): array
            {
                return array_values(array_filter($this->t->autres, fn ($d) => in_array($d->NumDoss, $dossiers, true) && (string) $d->ID !== (string) $sauf));
            }

            public function parDossiers(array $dossiers): array
            {
                return array_values(array_filter($this->t->dmi(), fn ($d) => in_array($d->NumDoss, $dossiers, true)));
            }

            public function dernieres(int $heures = 168, array $etats = ['D']): array
            {
                return array_values(array_map(fn ($d) => (object) ((array) $d + ['NomCli' => 'PATIENT', 'Prenom' => 'Clinisys']),
                    array_filter($this->t->dmi(), fn ($d) => in_array($d->Etat, $etats, true))));
            }
        });
        $this->app->instance(Pharmacie::class, new class($test) extends Pharmacie {
            public function __construct(private ClinisysTest $t) {}

            public function bonsDepot(string $numDemande): array
            {
                return array_values(array_filter($this->t->bons(), fn ($b) => $b->numDemandeS === $numDemande));
            }

            public function bonsDepotPour(array $numeros): array
            {
                return collect($this->t->bons())->filter(fn ($b) => in_array((string) $b->numDemandeS, array_map('strval', $numeros), true))->groupBy(fn ($b) => (string) $b->numDemandeS)->map->all()->all();
            }

            public function prelevements(array $numerosBd): array
            {
                return $this->t->prs;
            }

            public function sortiesPourBons(string $numDoss, array $numerosBd): array
            {
                return $this->t->sortiesPa;
            }

            public function panier(string $numDemande): array
            {
                return [];
            }

            public function lignesMouvements(string $type, ?\DateTimeInterface $du, ?\DateTimeInterface $au, ?string $numero = null): array
            {
                return array_values(array_filter($this->t->lignesBs[$type] ?? [], fn ($l) => $numero === null
                    || in_array($numero, array_map(fn ($v) => (string) $v, [$l->numero, $l->bon, $l->bon_origine, $l->NumDoss, $l->numDemande]), true)));
            }

            public function lieuxDesPoches(array $numeros): array
            {
                return array_intersect_key($this->t->lieux, array_flip(array_map('strval', $numeros)));
            }

            public function stockBS(?string $numDoss = null): array
            {
                return array_values(array_filter($this->t->stock(), fn ($p) => $numDoss === null || $p->NumDoss === $numDoss));
            }

            public function pochesParDemande(array $numeros): array
            {
                return $this->t->bdParDemande;
            }

            public function sortiesPatient(array $dossiers, int $jours): array
            {
                return $this->t->sorties;
            }

            public function libelleDepot(string $code = self::DEPOT): string
            {
                return 'Banque de sang';
            }

            public function mouvements(\DateTimeInterface $du, \DateTimeInterface $au): ?object
            {
                return (object) ['receptions' => 12, 'sorties' => 9, 'prelevements' => 2,
                    'en_stock' => count(array_filter($this->t->stock(), fn ($p) => ! $p->perimee)),
                    'perimees' => count(array_filter($this->t->stock(), fn ($p) => $p->perimee))];
            }
        });
    }

    public function dmi(): array
    {
        return $this->dmi;
    }

    public function bons(): array
    {
        return $this->bons;
    }

    public function stock(): array
    {
        return $this->stockBS;
    }

    private function ligneDmi(int $id, string $numDoss, string $etat, array $quantites): object
    {
        return (object) ($quantites + ['GlobuleRouge' => 0, 'Plaquette' => 0, 'Plasma' => 0] + [
            'ID' => $id, 'NumDoss' => $numDoss, 'Diagnostic' => 'HEMORAGIE DIGESTIVE ACTIVE', 'determination1' => 'A+', 'determination2' => 'A+',
            'dateTransfusionPrevu' => null, 'Medecin_prescripteur' => 'M0168', 'medecin' => 'POLE REANIMATION',
            'user_create' => 'agent.inf', 'date_create' => now()->subHour()->format('Y-m-d H:i:s'), 'Etat' => $etat,
        ]);
    }

    private function en(string $login): static
    {
        return $this->actingAs(app(FournisseurUtilisateurs::class)->retrieveById($login));
    }

    private function poster(array $extra = [])
    {
        return $this->en('inf')->post('/demandes', $extra + [
            'num_doss' => '26030752', 'num_demande_clinisys' => '1449',
            'urgence' => 'urgente', 'nature' => 'acheminement', 'produit' => 'PFC', 'nb_poches' => 9, 'diagnostic' => 'saisi à la main',
        ]);
    }

    public function test_la_demande_dmi_preremplit_et_impose_ses_valeurs(): void
    {
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1450')->assertOk()
            ->assertSee('lue dans le DMI : n° 1450', false)->assertSee('10 × CPS')->assertSee('Non réceptionnée');

        $this->poster(['num_demande_clinisys' => '1450'])->assertSessionHasNoErrors()->assertRedirect();
        $d = Demande::firstOrFail();
        $this->assertSame(['CGR', 3, 'A+', 'HEMORAGIE DIGESTIVE ACTIVE', 'POLE REANIMATION', 'PATIENT Clinisys'],
            [$d->produit, $d->nb_poches, $d->groupe_sanguin, $d->diagnostic, $d->medecin, $d->nom_patient]);
        $creation = Action::where('etape', 'creation')->firstOrFail();
        $this->assertStringContainsString('10 × CPS', $creation->commentaire);
        $this->assertSame('clinisys', Action::where('etape', 'A5')->firstOrFail()->donnees['source']);
    }

    public function test_demande_dmi_inconnue_supprimee_ou_d_un_autre_dossier_refusee(): void
    {
        $this->poster(['num_demande_clinisys' => '9999'])->assertSessionHasErrors('num_demande_clinisys');
        $this->poster(['num_demande_clinisys' => '1451'])->assertSessionHasErrors('num_demande_clinisys');
        $this->dmi[1452] = $this->ligneDmi(1452, '26000001', 'D', ['GlobuleRouge' => 1]);
        $this->poster(['num_demande_clinisys' => '1452'])->assertSessionHasErrors('num_doss');
        $this->assertSame(0, Demande::count());

        $this->en('inf')->get('/demandes/nouvelle?num_demande=9999')->assertOk()->assertSee('introuvable dans le DMI');
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1451')->assertOk()->assertSee('supprimée dans Clinisys');
    }

    public function test_a2_bloque_si_une_poche_du_stock_bs_est_au_patient(): void
    {
        $this->stockBS = [(object) [
            'Num' => 1, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => '07264459889',
            'DatPer' => now()->addDays(30)->format('Y-m-d 00:00:00'), 'NumBon' => 'BDBD261453', 'bon_depot' => 'BD261453', 'datbon' => '2026-10-06', 'memop' => 'A+',
            'NumDoss' => '26030752', 'numDemande' => 1375, 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'perimee' => 0,
        ], (object) [
            'Num' => 2, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => 'PERIME-0001',
            'DatPer' => '2026-02-01 00:00:00', 'NumBon' => 'BDBD260028', 'bon_depot' => 'BD260028', 'datbon' => '2026-01-22', 'memop' => '',
            'NumDoss' => '26030752', 'numDemande' => 1, 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'perimee' => 1,
        ]];

        // La poche réservée au patient est mise en évidence, avec un lien vers l'écran Stock (recherche préremplie).
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee('07264459889')
            ->assertSee('1 poche(s) déjà disponible(s) pour ce patient : pas de nouvelle demande, à récupérer à la pharmacie.', false)->assertSee('Récupérer la poche à la pharmacie')
            ->assertSee(route('stock', ['q' => '07264459889', 'vue' => 'bs_stock']));
        $this->poster()->assertSessionHasErrors('motif_poche');
        $this->poster(['motif_poche' => 'reservee'])->assertSessionHasNoErrors();
        // La poche périmée n'est pas comptée comme disponible.
        $this->assertSame(['07264459889'], Action::where('etape', 'A2')->firstOrFail()->donnees['poches_stock_clinisys']);

        // Sortie patient de la période (bon PA) : patient, n° dossier, service en clair, « hors registre ».
        $this->lignesBs['PA'] = [(object) ['numero' => '07264459889', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'DatPer' => null, 'bon' => 'FBS00999',
            'datesys' => now()->subDays(2)->toDateString(), 'heuresys' => '1900-01-01 10:15:00', 'bon_origine' => 'BD261453', 'memop' => 'A+', 'numDemande' => 999999,
            'NumDoss' => '26030752', 'NomCli' => 'PATIENT', 'Prenom' => 'Sortie', 'service' => 'HOSPITALISATION 2 EME ETAGE']];

        // État du dépôt comme Clinisys, enrichi : contrôle des totaux, sections périmés / non périmés, patient.
        $this->en('pharma')->get('/stock')->assertOk()->assertSee('État du dépôt Banque de sang (Clinisys, enrichi)', false)->assertSee('BD261453')
            ->assertSee('Sorties patient (bons PA)')->assertSee('Suivi dans l\'appli', false)
            ->assertSee('Contrôle : 2 poche(s) en stock = total Clinisys · 1 périmée(s) · 1 non périmée(s)', false)
            ->assertSee('Articles non périmés (1)')->assertSee('PATIENT Clinisys')->assertSee('Imprimer l\'état', false)
            // Pastilles Clinisys : liste affichée par défaut « En stock (non périmées) », titre avec le nombre de lignes.
            ->assertSee('Clinisys · En stock (non périmées) (1)')->assertSee('à ce jour')->assertSee('lecture seule')
            ->assertDontSee('30 derniers jours par défaut')
            // L'état imprimé est complet : périmés et « à périmer dans X jours » (5 par défaut), cachés à l'écran.
            ->assertSeeInOrder(['<section class="imprime-seulement">', 'Articles périmés (1)', '<section class="imprime-seulement">', 'À périmer dans 5 jours (0)', '<section class="">', 'Articles non périmés (1)'], false);
        // Pastille « Périmées » : la liste des périmées, et le nombre de la pastille = le nombre de lignes.
        $this->en('pharma')->get('/stock?vue=bs_perimees')->assertOk()->assertSee('Articles périmés (1)')->assertSee('PERIME-0001')->assertSee('Clinisys · Périmées encore en stock (1)');
        // Filtre groupe appliqué aux listes Clinisys : O+ → aucune poche.
        $this->en('pharma')->get('/stock?vue=bs_stock&groupe=O%2B')->assertOk()->assertSee('Clinisys · En stock (non périmées) (0)')->assertSee('Groupe : O+');
        // Mouvements de la période : Du / Au préremplis (30 derniers jours), une ligne par poche.
        $this->en('pharma')->get('/stock?vue=bs_pa')->assertOk()->assertSee('Clinisys · Sorties patient (bons PA) (1)')
            ->assertSee('name="du" value="'.now()->subDays(30)->format('Y-m-d').'"', false)->assertSee('name="au" value="'.now()->format('Y-m-d').'"', false)
            ->assertSee('FBS00999')->assertSee('PATIENT Sortie')->assertSee('26030752')->assertSee('HOSPITALISATION 2 EME ETAGE')->assertSee('hors registre');
        $this->en('pharma')->get('/stock?vue=bs_bd')->assertOk()->assertSee('Clinisys · Réceptions (bons de dépôt BD) (0)')->assertSee('Aucune poche.');
        // « Périment dans X jours » : X de l'écran gardé dans l'URL, badge J-n ; la poche à J+30 n'entre qu'avec X assez grand.
        $jn = 30;
        if ($jn >= 1) {
            $this->en('pharma')->get('/stock?vue=bs_bientot&jours='.$jn)->assertOk()->assertSee('Clinisys · Périment dans '.$jn.' jours (1)')->assertSee('J-'.$jn)
                ->assertSee('name="jours" min="1" max="90" value="'.$jn.'"', false);
            $this->en('pharma')->get('/stock?vue=bs_bientot&jours='.max(1, $jn - 1))->assertOk()->assertSee('(0)');
        }
        // Valeur par défaut de X dans Paramétrage.
        $this->en('direction')->post('/parametrage/seuils', ['peremption_jours' => 3, 'perime_bientot_jours' => 12, 'seuil' => config('demandesang.seuils_defaut')])->assertSessionHasNoErrors();
        $this->en('pharma')->get('/stock?vue=bs_bientot')->assertOk()->assertSee('Périment dans 12 jours');
        // En arabe : libellés traduits.
        $this->en('pharma')->withSession(['langue' => 'ar'])->get('/stock?vue=bs_bientot')->assertOk()->assertSee('تنتهي صلاحيتها خلال 12 أيام')->assertSee('قراءة فقط');
    }

    public function test_recherche_stock_et_lieu_de_chaque_poche(): void
    {
        $this->stockBS = [(object) [
            'Num' => 1, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => '07264560936', 'Reference' => '07264560936',
            'DatPer' => now()->addDays(20)->format('Y-m-d 00:00:00'), 'NumBon' => 'BDBD261560', 'bon_depot' => 'BD261560', 'datbon' => now()->subDays(4)->toDateString(), 'memop' => 'AB+',
            'NumDoss' => '26030752', 'numDemande' => 1375, 'source_patient' => 'panier', 'NomCli' => 'BÉNI', 'Prenom' => 'Élodie', 'service' => 'REANIMATION', 'perimee' => 0,
        ]];
        // Mouvement hors période (60 jours) : trouvé par son n° de poche dans tout l'historique, pas par la période Du / Au.
        $this->lignesBs['BD'] = [(object) ['numero' => '07264560936', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'DatPer' => null, 'bon' => 'BD261560',
            'datesys' => now()->subDays(60)->toDateString(), 'heuresys' => null, 'bon_origine' => null, 'memop' => 'AB+', 'numDemande' => 1375,
            'NumDoss' => '26030752', 'NomCli' => 'BÉNI', 'Prenom' => 'Élodie', 'service' => 'REANIMATION']];

        // Recherche par n° de poche : la liste « En stock » et la ligne « trouvé dans » avec les réceptions de tout l'historique.
        $this->en('pharma')->get('/stock?q=07264560936')->assertOk()->assertSee('Clinisys · En stock (non périmées) (1)')
            ->assertSee('« 07264560936 » trouvé dans :', false)->assertSee('Réceptions (bons de dépôt BD) (1)')->assertSee('tout l\'historique')
            ->assertSee('name="q" value="07264560936"', false)->assertSee('Recherche : 07264560936')
            // Recherche : résumé de l'état replié, poche cherchée surlignée avec son lieu ; loupe devant les liens de détail.
            ->assertSee('<details class="resume-etat" >', false)->assertSee('background:#fef9c3', false)
            ->assertSee('En stock pharmacie depuis le '.now()->subDays(4)->format('d/m').' (bon BD261560)')->assertSee('class="loupe"', false)
            // Bloc collant (pastilles + filtres) et bouton « Effacer les filtres » qui garde la vue.
            ->assertSee('<div class="collant">', false)->assertSee('Effacer les filtres')->assertSee('class="btn effacer-filtres non-imprime" href="'.route('stock').'"', false);
        $this->en('pharma')->get('/stock?vue=bs_stock')->assertOk()->assertDontSee('class="btn effacer-filtres', false);
        $this->en('pharma')->get('/stock')->assertOk()->assertSee('<details class="resume-etat"  open ', false);
        // Nom du patient sans accents ni majuscules, n° de dossier, n° de bon : même poche ; autre texte : rien.
        foreach (['beni elodie', '26030752', 'bd261560', '1375'] as $q) {
            $this->en('pharma')->get('/stock?vue=bs_stock&q='.urlencode($q))->assertOk()->assertSee('Clinisys · En stock (non périmées) (1)');
        }
        $this->en('pharma')->get('/stock?vue=bs_stock&q=inconnu')->assertOk()->assertSee('Clinisys · En stock (non périmées) (0)');
        $this->en('pharma')->withSession(['langue' => 'ar'])->get('/stock?q=07264560936')->assertOk()->assertSee('رقم الكيس، رقم الملف');
        $this->withSession(['langue' => 'fr']);

        // Nouvelle demande (DMI 1449) pour le même dossier : la poche de la demande 1375 attend déjà en pharmacie.
        $this->poster(['motif_poche' => 'reservee'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        foreach (['pharma' => '/pharmacie', 'surv' => '/a-faire'] as $qui => $url) {
            $this->en($qui)->get($url)->assertOk()->assertSee('1 poche(s) attendent déjà en pharmacie pour un patient qui a une nouvelle demande en cours')
                ->assertSee('Poche de la demande n° 1375')->assertSee('07264560936')->assertSee('nouvelle demande '.$d->id);
        }

        // Où sont les poches de la demande (fiche, liste, registre) : une en stock, les autres pas encore arrivées.
        $this->lieux = ['1449' => [(object) ['numero' => '07264561111', 'desart' => 'PFC 3', 'DatPer' => now()->addDays(300)->toDateString(), 'bon_bd' => 'BD261600',
            'date_bd' => now()->toDateString(), 'memop' => 'O+', 'numDemande' => '1449', 'pa_bon' => null, 'pa_date' => null, 'pa_service' => null, 'pr_bon' => null, 'pr_date' => null, 'en_stock' => 1]]];
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('Où sont les poches')->assertSee('07264561111')
            ->assertSee('En stock pharmacie depuis le '.now()->format('d/m').' (bon BD261600)')->assertSee('Pas encore arrivée à la pharmacie');
        $this->en('inf')->get('/demandes')->assertOk()->assertSee('07264561111')->assertSee('En stock pharmacie depuis le');
        $this->en('surv')->get('/registre')->assertOk()->assertSee('07264561111');

        // Alerte sur une poche de l'appli : lien « vérifier dans le stock » avec la recherche préremplie.
        Poche::create(['demande_id' => $d->id, 'numero' => '07264562222', 'produit' => 'CGR', 'statut' => 'en_stock', 'peremption' => now()->addDay()]);
        $this->en('surv')->get('/alertes')->assertOk()->assertSee('Vérifier la poche 07264562222 dans le stock')
            ->assertSee(route('stock', ['q' => '07264562222', 'vue' => 'bs_stock']));
        $this->en('pharma')->get('/stock?vue=en_stock&q=07264562222')->assertOk()->assertSee("Suivi dans l'appli · En stock (1)");
    }

    public function test_recherche_registre_demandes_journal_et_ecrans(): void
    {
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $d->update(['cree_le' => now()->subDays(90)]);
        Poche::create(['demande_id' => $d->id, 'numero' => '07264569999', 'produit' => 'CGR', 'statut' => 'en_stock', 'bon_depot' => 'BD261777']);
        // Bon PA Clinisys rattaché à la demande DMI 1449 (cherché dans tout l'historique).
        $this->lignesBs['PA'] = [(object) ['numero' => '07264569999', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'DatPer' => null, 'bon' => 'FBS00999',
            'datesys' => now()->subDays(80)->toDateString(), 'heuresys' => null, 'bon_origine' => 'BD261777', 'memop' => 'A+', 'numDemande' => 1449,
            'NumDoss' => '26030752', 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'service' => null]];

        // Registre : demande d'il y a 90 jours, hors période par défaut, retrouvée par n° exact (tout l'historique).
        $this->en('surv')->get('/registre')->assertOk()->assertDontSee('07264569999');
        foreach (['07264569999', 'BD261777', 'FBS00999', '26030752', '1449'] as $q) {
            $this->en('surv')->get('/registre?q='.$q)->assertOk()->assertSee(route('demandes.show', $d))->assertSee('recherche sur tout l\'historique');
        }
        $this->en('surv')->get('/registre?q=patient&du='.now()->subDays(100)->toDateString())->assertOk()->assertSee(route('demandes.show', $d))
            ->assertSee('name="q" value="patient"', false)->assertSee('effacer-filtres', false);
        $this->en('surv')->get('/registre?q=inconnu&du='.now()->subDays(100)->toDateString())->assertOk()->assertDontSee(route('demandes.show', $d));

        // Demandes : n° de poche et n° de l'appli.
        $this->en('inf')->get('/demandes?statut=toutes&q=07264569999')->assertOk()->assertSee(route('demandes.show', $d));
        $this->en('inf')->get('/demandes?statut=toutes&q='.$d->id)->assertOk()->assertSee(route('demandes.show', $d));

        // Journal : recherche par utilisateur.
        \Illuminate\Support\Facades\DB::table('ds_journal')->insert(['user_name' => 'pharma', 'action' => 'connexion', 'details' => null, 'cree_le' => now()]);
        $this->en('direction')->get('/parametrage/journal?demande=pharm')->assertOk()->assertSee('pharma')->assertSee('1 événement(s)');

        // Tableaux de bord : recherche dans la page.
        foreach (['pharma' => '/pharmacie', 'surv' => '/alertes', 'coursier' => '/cts', 'direction' => '/parametrage/comptes'] as $qui => $url) {
            $this->en($qui)->get($url)->assertOk()->assertSee('data-filtre-local', false);
        }
    }

    public function test_c5_enregistree_aussitot_apres_c4_meme_dans_la_minute(): void
    {
        // Cas de la demande DMI 1521 : bon BD saisi avant C4 ; fiche ouverte à C3, puis C4 saisie et fiche rouverte aussitôt.
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        foreach (['A4', 'A6', 'A7', 'A9', 'A10', 'B1', 'B2', 'C1', 'C3'] as $code) {
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now()]);
        }
        foreach (['A11', 'C2'] as $code) {
            $r = Remise::create(['demande_id' => $d->id, 'etape' => $code, 'donneur' => 'x', 'nom_donneur' => 'x', 'remis_le' => now(), 'reponse' => 'confirmee', 'receveur' => 'y', 'nom_receveur' => 'y', 'repondu_le' => now()]);
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now(), 'remise_id' => $r->id]);
        }
        $d->update(['etape' => 'C4']);
        $this->en('pharma')->get("/demandes/{$d->id}")->assertOk();
        $this->assertSame(0, Action::where('etape', 'C5')->count());

        Action::create(['demande_id' => $d->id, 'etape' => 'C4', 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now()]);
        $d->update(['etape' => 'C5']);
        $this->en('pharma')->get("/demandes/{$d->id}")->assertOk();
        // C5 reprise du bon BD (date et heure du bon, saisi par l'utilisateur Clinisys), sans attendre la minute.
        $c5 = Action::where('etape', 'C5')->firstOrFail();
        $this->assertSame(['clinisys', '2026-10-07 13:01'], [$c5->user_name, $c5->fait_le->format('Y-m-d H:i')]);
    }

    public function test_performances_pages_sans_rechargement_ni_ressource_externe(): void
    {
        // Connexion : aucune ressource externe (police, CDN) qui gèlerait la page sur le réseau de la clinique.
        $this->get('/connexion')->assertOk()->assertDontSee('fonts.googleapis.com', false)->assertDontSee('https://', false);

        // Écrans de suivi : plus de rechargement complet, empreinte pour proposer d'actualiser.
        foreach (['surv' => ['/a-faire', '/pharmacie', '/alertes', '/cts']] as $qui => $urls) {
            foreach ($urls as $url) {
                $this->en($qui)->get($url)->assertOk()->assertDontSee('http-equiv="refresh"', false)->assertSee('name="suivi-empreinte"', false)
                    ->assertSee('css/app.css?v=', false)->assertSee('js/app.js?v=', false)->assertDontSee('<style>', false);
            }
        }
        $avant = $this->en('surv')->getJson('/suivi/empreinte')->assertOk()->json('empreinte');
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $this->assertNotSame($avant, $this->en('surv')->getJson('/suivi/empreinte')->json('empreinte'));
        $this->post('/deconnexion');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/suivi/empreinte')->assertUnauthorized();

        // La page n'interroge pas la synchronisation : C5 est reprise par l'empreinte, juste après l'affichage.
        $d = Demande::firstOrFail();
        foreach (['A4', 'A6', 'A7', 'A9', 'A10', 'B1', 'B2', 'C1', 'C3', 'C4'] as $code) {
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now()]);
        }
        foreach (['A11', 'C2'] as $code) {
            $r = Remise::create(['demande_id' => $d->id, 'etape' => $code, 'donneur' => 'x', 'nom_donneur' => 'x', 'remis_le' => now(), 'reponse' => 'confirmee', 'receveur' => 'y', 'nom_receveur' => 'y', 'repondu_le' => now()]);
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now(), 'remise_id' => $r->id]);
        }
        $d->update(['etape' => 'C5']);
        \Illuminate\Support\Facades\Cache::forget('clinisys.sync');
        $this->en('surv')->get('/a-faire')->assertOk();
        $this->assertSame(0, Action::where('etape', 'C5')->count());
        $this->en('surv')->getJson('/suivi/empreinte')->assertOk();
        $this->assertSame(1, Action::where('etape', 'C5')->count());

        // Mesure : réservée à l'administrateur (en-tête + ligne en bas de page).
        $this->en('direction')->get('/parametrage/comptes')->assertOk()->assertHeader('Server-Timing')->assertSee('mesure-page', false);
        $this->en('inf')->get('/demandes')->assertOk()->assertHeaderMissing('Server-Timing')->assertDontSee('mesure-page', false);
    }

    public function test_poche_trouvee_par_le_nom_bloque_la_demande_sauf_motif(): void
    {
        // Poche sur un AUTRE dossier, sans lien d'identifiant : retrouvée par le nom (accents et majuscules ignorés).
        $this->stockBS = [(object) [
            'Num' => 1, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => '07264570001', 'Reference' => '07264570001',
            'DatPer' => now()->addDays(20)->format('Y-m-d 00:00:00'), 'NumBon' => 'BDBD261600', 'bon_depot' => 'BD261600', 'datbon' => now()->subDay()->toDateString(), 'memop' => 'A+',
            'NumDoss' => '26099998', 'numDemande' => 1300, 'NomCli' => 'PÂTIENT', 'Prenom' => 'CLINISYS', 'perimee' => 0,
        ], (object) [
            // Autre patient et poche périmée du même patient : jamais proposées.
            'Num' => 2, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => 'AUTRE-0001', 'Reference' => 'AUTRE-0001',
            'DatPer' => now()->addDays(20)->format('Y-m-d 00:00:00'), 'NumBon' => 'BDBD1', 'bon_depot' => 'BD1', 'datbon' => null, 'memop' => 'O+',
            'NumDoss' => '11111111', 'numDemande' => null, 'NomCli' => 'AUTRE', 'Prenom' => 'Personne', 'perimee' => 0,
        ], (object) [
            'Num' => 3, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'qte' => 1, 'Identifiant' => 'PERIMEE-01', 'Reference' => 'PERIMEE-01',
            'DatPer' => now()->subDay()->format('Y-m-d 00:00:00'), 'NumBon' => 'BDBD2', 'bon_depot' => 'BD2', 'datbon' => null, 'memop' => 'A+',
            'NumDoss' => '26030752', 'numDemande' => null, 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'perimee' => 1,
        ]];
        $page = $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()
            ->assertSee('07264570001')->assertSee('26099998')->assertSee('BD261600')->assertSee('Même nom, autre dossier : à vérifier')
            ->assertDontSee('À vérifier :', false)->assertSee('name="motif_poche"', false)->assertDontSee('AUTRE-0001')->assertDontSee('PERIMEE-01')->getContent();
        $this->assertStringContainsString(route('demandes.recuperer_poche'), $page);

        // Récupérer : aucune demande créée, noté au journal, ouvre le stock sur cette poche.
        $this->en('inf')->post('/demandes/recuperer-poche', ['poche' => '07264570001', 'num_doss' => '26099998', 'num_demande' => '1449'])
            ->assertRedirect(route('stock', ['q' => '07264570001', 'vue' => 'bs_stock']))->assertSessionHas('statut');
        $this->assertSame(0, Demande::count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('ds_journal')->where('action', 'recuperation_poche')->count());
        $this->en('coursier')->post('/demandes/recuperer-poche', ['poche' => 'x'])->assertForbidden();

        // Inscription : bloquée sans motif ; « Autre » exige une précision ; motif gardé à l'étape A2.
        $this->poster()->assertSessionHasErrors('motif_poche');
        $this->poster(['motif_poche' => 'autre'])->assertSessionHasErrors('motif_poche_detail');
        $this->poster(['motif_poche' => 'invente'])->assertSessionHasErrors('motif_poche');
        $this->poster(['motif_poche' => 'quantite', 'motif_poche_detail' => '2 poches nécessaires'])->assertSessionHasNoErrors();
        $a2 = Action::where('etape', 'A2')->firstOrFail()->donnees;
        $this->assertSame(['quantite', '2 poches nécessaires', ['07264570001']], [$a2['motif_poche'], $a2['motif_poche_detail'], $a2['poches_stock_clinisys']]);
    }

    public function test_regularisation_avec_poche_disponible_exige_aussi_un_motif(): void
    {
        // La poche de la demande elle-même ne bloque pas ; celle d'une autre demande du patient, si.
        $poche = fn (string $n, int $dmi) => (object) ['Num' => 1, 'codart' => 'F', 'desart' => 'CULOT', 'qte' => 1, 'Identifiant' => $n, 'Reference' => $n,
            'DatPer' => now()->addDays(20)->format('Y-m-d 00:00:00'), 'NumBon' => 'B', 'bon_depot' => 'BD1', 'datbon' => null, 'memop' => 'A+',
            'NumDoss' => '26030752', 'numDemande' => $dmi, 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'perimee' => 0];
        $this->dmi[1449]->Etat = 'V';
        $this->stockBS = [$poche('ELLE-MEME', 1449)];
        $regul = ['regularisation' => 'Urgence de nuit'];
        $this->poster($regul)->assertSessionDoesntHaveErrors('motif_poche');
        Action::query()->delete();
        Remise::query()->delete();
        Poche::query()->delete();
        Demande::query()->delete();

        $this->stockBS = [$poche('ELLE-MEME', 1449), $poche('AUTRE-POCHE', 1300)];
        $this->poster($regul)->assertSessionHasErrors('motif_poche');
        $this->poster($regul + ['motif_poche' => 'non_compatible'])->assertSessionHasNoErrors();
    }

    public function test_les_trois_cas_du_patient_et_le_second_dossier(): void
    {
        $page = $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk();
        foreach (['Patient hospitalisé', 'Patient externe (consultation / urgence)', 'Hospitalisé : transfusion hors séjour (second dossier)'] as $libelle) {
            $page->assertSee($libelle);
        }
        // Hospitalisé : pas d'étape A3. Le dossier de test est en cours d'hospitalisation (réanimation).
        $this->poster(['cas_patient' => 'hospitalise'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $this->assertSame(['hospitalise', false], [$d->cas_patient, $d->externe]);
        $this->assertSame('sans_objet', app(\App\Services\Circuit::class)->etat($d)['A3']['statut']);
        Action::query()->delete();
        Demande::query()->delete();

        // Hors séjour sur un dossier d'hospitalisation en cours : refusé.
        $hospit = fn (?string $sortie, string $service = 'RA') => new class($sortie, $service) extends Patients {
            public function __construct(private ?string $sortie, private string $service) {}

            public function dossier(string $numDoss): ?object
            {
                return (object) ['NumDoss' => $numDoss, 'Identifiant' => '000123', 'NomCli' => 'PATIENT', 'Prenom' => 'Clinisys', 'NumCIN' => 'ZZ100200', 'service' => 'X', 'service_code' => $this->service, 'DatDep' => $this->sortie, 'medecin' => 'DR X'];
            }

            public function dossiersDuPatient(?string $identifiant): array
            {
                return ['26030752'];
            }
        };
        $this->app->instance(Patients::class, $hospit(null));
        $this->poster(['cas_patient' => 'hors_sejour'])->assertSessionHasErrors('cas_patient');

        // Second dossier (service de consultation) : accepté, passe par l'ouverture du dossier + paiement.
        $this->app->instance(Patients::class, $hospit(null, 'C1'));
        $this->poster(['cas_patient' => 'hors_sejour'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $this->assertSame(['hors_sejour', true], [$d->cas_patient, $d->externe]);
        // Externe / hors séjour : la vérification du paiement (coursier) attend la remise du dossier (A11).
        $this->assertSame('bloquee', app(\App\Services\Circuit::class)->etat($d)['A3']['statut']);
        $this->assertSame('hors séjour, second dossier', $d->libelleCas());
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('hors séjour, second dossier')
            ->assertSee('Vérification du paiement (reçu ou prise en charge)');
    }

    public function test_numeros_utiles_modifiables_et_libelles_du_diagramme(): void
    {
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        // Infirmière, à l'étape « Informer le coursier » : numéro de la flotte, avec lien d'appel.
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('06 68 80 17 02')->assertSee('tel:0668801702', false)
            ->assertSee("Vérification de l'identification patient")->assertSee('Informer le coursier');
        // Écran du coursier : les trois numéros.
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('06 68 80 17 02')->assertSee('06 68 86 09 46')->assertSee('06 68 82 13 78');

        // Paramétrage : numéro modifié, repris partout ; un texte qui n'est pas un numéro est refusé.
        $seuils = ['peremption_jours' => 3, 'seuil' => config('demandesang.seuils_defaut')];
        $this->en('direction')->get('/parametrage/seuils')->assertOk()->assertSee('name="tel_coursier"', false)->assertSee('Numéros utiles');
        $this->en('direction')->post('/parametrage/seuils', $seuils + ['tel_coursier' => 'pas un numéro'])->assertSessionHasErrors('tel_coursier');
        $this->en('direction')->post('/parametrage/seuils', $seuils + ['tel_coursier' => '06 00 00 00 01'])->assertSessionHasNoErrors();
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('06 00 00 00 01')->assertDontSee('06 68 80 17 02');

        // Arabe : libellés traduits.
        $this->en('coursier')->withSession(['langue' => 'ar'])->get('/cts')->assertOk()->assertSee('هاتف الساعي');
    }

    /** Étapes faites directement (raccourci de test) ; une remise est faite et confirmée. */
    private function etapesJusquA(Demande $d, array $codes): void
    {
        foreach ($codes as $code) {
            $r = config("demandesang.etapes.$code.type") === 'remise'
                ? Remise::create(['demande_id' => $d->id, 'etape' => $code, 'donneur' => 'x', 'nom_donneur' => 'x', 'remis_le' => now(), 'reponse' => 'confirmee', 'receveur' => 'y', 'nom_receveur' => 'y', 'repondu_le' => now()])
                : null;
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now(), 'remise_id' => $r?->id]);
        }
    }

    public function test_cin_du_patient_repris_de_clinisys_ou_saisi_et_sur_le_bon(): void
    {
        // CIN connu dans Clinisys : affiché à l'inscription, enregistré sur la demande, en tête du bon pour la banque de sang.
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee('ZZ100200')->assertDontSee('name="cin"', false);
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $this->assertSame(['ZZ100200', 'clinisys'], [$d->cin, $d->cin_source]);
        $this->en('inf')->get("/demandes/{$d->id}/bon")->assertOk()->assertSee('N° CIN')->assertSee('ZZ100200');
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('ZZ100200')->assertDontSee("saisi dans l'appli", false);
        // Écran CTS (dès que la demande y arrive) : le CIN sur la carte.
        $this->etapesJusquA($d, ['A4', 'A6', 'A7', 'A9', 'A10', 'A11']);
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('CIN ZZ100200');

        // Recherche par CIN : Demandes, Registre, Stock (demande de l'appli).
        $this->en('inf')->get('/demandes?statut=toutes&q=zz100200')->assertOk()->assertSee(route('demandes.show', $d));
        $this->en('surv')->get('/registre?q=ZZ100200')->assertOk()->assertSee(route('demandes.show', $d))->assertSee('CIN ZZ100200');
        Poche::create(['demande_id' => $d->id, 'numero' => '07264580000', 'produit' => 'CGR', 'statut' => 'en_stock']);
        $this->en('pharma')->get('/stock?vue=en_stock&q=ZZ100200')->assertOk()->assertSee('07264580000');

        // Sans CIN dans Clinisys : saisie obligatoire, format contrôlé, ou autre pièce.
        \Illuminate\Support\Facades\Cache::flush();
        $this->app->instance(Patients::class, new class extends Patients {
            public function dossier(string $numDoss): ?object
            {
                return (object) ['NumDoss' => $numDoss, 'Identifiant' => '000124', 'NomCli' => 'SANS', 'Prenom' => 'Cin', 'NumCIN' => '', 'service' => 'REANIMATION', 'medecin' => 'DR X'];
            }

            public function dossiersDuPatient(?string $identifiant): array
            {
                return ['26030752'];
            }

            public function cinParDossiers(array $dossiers): array
            {
                return [];
            }
        });
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1450')->assertOk()->assertSee('name="cin"', false)->assertSee('absent de Clinisys');
        $base = ['num_demande_clinisys' => '1450', 'forcer' => '1', 'motif_forcage' => 'x'];
        $this->poster($base)->assertSessionHasErrors('cin');
        $this->poster($base + ['cin' => '123'])->assertSessionHasErrors('cin');
        $this->poster($base + ['sans_cin' => '1'])->assertSessionHasErrors('piece_autre');
        $this->poster($base + ['cin' => 'ab 765432'])->assertSessionHasNoErrors();
        $n = Demande::where('num_demande_clinisys', '1450')->firstOrFail();
        $this->assertSame(['AB765432', 'saisie'], [$n->cin, $n->cin_source]);
        $this->en('inf')->get("/demandes/{$n->id}")->assertOk()->assertSee('AB765432')->assertSee("saisi dans l'appli");
        $this->en('inf')->get("/demandes/{$n->id}/bon")->assertOk()->assertSee('AB765432');
        // Arabe : libellé traduit.
        $this->en('inf')->withSession(['langue' => 'ar'])->get("/demandes/{$n->id}")->assertOk()->assertSee('رقم البطاقة الوطنية');
    }

    public function test_cin_vide_dans_l_appli_repris_de_clinisys_a_l_affichage(): void
    {
        // Cas de la demande 53 : CIN vide dans l'appli (inscrite avant l'ajout du CIN), connu dans Clinisys.
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $vider = fn () => \Illuminate\Support\Facades\DB::table('ds_demandes')->where('id', $d->id)->update(['cin' => null, 'cin_source' => null]);
        $vider();
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('ZZ100200');
        $this->assertSame(['ZZ100200', 'clinisys'], [$d->fresh()->cin, $d->fresh()->cin_source]);

        // Listes : reprise en une lecture ; commande de mise à jour (idempotente).
        $vider();
        $this->en('inf')->get('/demandes')->assertOk()->assertSee('CIN ZZ100200');
        $vider();
        $this->artisan('ds:cin')->expectsOutput('1 CIN repris de Clinisys.')->assertSuccessful();
        $this->artisan('ds:cin')->expectsOutput('0 CIN repris de Clinisys.')->assertSuccessful();
        $this->assertSame('ZZ100200', $d->fresh()->cin);
    }

    public function test_recherche_dans_demandes_resultats_avant_le_bloc_clinisys(): void
    {
        // Sans recherche, pour qui inscrit : le bloc « pas encore inscrites » reste en haut.
        $page = $this->en('inf')->get('/demandes')->assertOk()->getContent();
        $this->assertLessThan(strpos($page, 'pastilles-suivi'), strpos($page, 'Dans Clinisys, pas encore inscrites'));

        // Recherche d'une demande DMI pas encore inscrite : rien dans l'appli → message + bloc déplié, limité à la recherche.
        $page = $this->en('inf')->get('/demandes?q=1450')->assertOk()->assertSee("Cette demande n'est pas encore inscrite dans l'appli.")
            ->assertSee(route('demandes.create', ['num_demande' => 1450]), false)->getContent();
        $this->assertGreaterThan(strpos($page, 'pastilles-suivi'), strpos($page, 'Dans Clinisys, pas encore inscrites'));
        $this->assertStringContainsString('<details class="carte" style="border-left:4px solid #f59e0b;"  open >', $page);
        $this->assertStringNotContainsString('<td>1449', $page);

        // Demande inscrite trouvée : résultats d'abord, bloc Clinisys replié en dessous.
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $page = $this->en('inf')->get('/demandes?q=PATIENT')->assertOk()->assertDontSee("Cette demande n'est pas encore inscrite")->getContent();
        $this->assertLessThan(strpos($page, 'Dans Clinisys, pas encore inscrites'), strpos($page, 'carte carte-suivi'));
        // Coursier (n'inscrit pas) : bloc toujours en dessous, replié.
        $page = $this->en('coursier')->get('/demandes')->assertOk()->getContent();
        $this->assertStringNotContainsString('<h2>Dans Clinisys, pas encore inscrites', $page);
    }

    public function test_perime_bientot_inclut_aujourd_hui_et_j_plus_x(): void
    {
        $p = fn (string $num, ?string $date, int $perimee = 0) => (object) ['Identifiant' => $num, 'DatPer' => $date, 'perimee' => $perimee];
        $stock = [$p('J0', today()->format('Y-m-d 00:00:00'), 1), $p('J1', today()->addDay()->format('Y-m-d 00:00:00')),
            $p('J5', today()->addDays(5)->format('Y-m-d 00:00:00')), $p('J6', today()->addDays(6)->format('Y-m-d 00:00:00')),
            $p('PERIMEE', today()->subDay()->format('Y-m-d 00:00:00'), 1), $p('SANS', null)];
        // Aujourd'hui = déjà périmée pour Clinisys (péremption au plus tard aujourd'hui) ; J+5 inclus, J+6 exclu.
        $this->assertSame(['J1', 'J5'], \App\Http\Controllers\StockController::perimentBientot($stock, 5)->pluck('Identifiant')->all());
        $this->assertSame(['J1', 'J5', 'J6'], \App\Http\Controllers\StockController::perimentBientot($stock, 6)->pluck('Identifiant')->all());
    }

    public function test_c5_c8_et_retour_enregistres_depuis_clinisys(): void
    {
        $this->poster()->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        // Raccourci jusqu'à C4 (portées à la pharmacie) : poches saisies au contrôle (C3).
        foreach (['A4', 'A6', 'A7', 'A9', 'A10', 'B1', 'B2', 'C1', 'C3', 'C4'] as $code) {
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now()]);
        }
        foreach (['A11', 'C2'] as $code) {
            $r = Remise::create(['demande_id' => $d->id, 'etape' => $code, 'donneur' => 'x', 'nom_donneur' => 'x', 'remis_le' => now(), 'reponse' => 'confirmee', 'receveur' => 'y', 'nom_receveur' => 'y', 'repondu_le' => now()]);
            Action::create(['demande_id' => $d->id, 'etape' => $code, 'user_name' => 'x', 'nom_acteur' => 'x', 'fait_le' => now(), 'remise_id' => $r->id]);
        }
        Poche::create(['demande_id' => $d->id, 'numero' => '07264553226', 'produit' => 'CGR', 'statut' => 'attente']);
        Poche::create(['demande_id' => $d->id, 'numero' => '99999999999', 'produit' => 'CGR', 'statut' => 'attente']);

        // La pharmacie ne saisit rien : C5 n'est pas proposée, et une saisie forcée est refusée.
        $this->en('pharma')->post("/demandes/{$d->id}/etapes/C5", ['bon_depot' => 'bd261455'])->assertSessionHasErrors('etape');

        // L'ouverture de la fiche lit Clinisys : le bon de dépôt BD261455 enregistre C5.
        $this->en('pharma')->get("/demandes/{$d->id}")->assertOk()->assertSee('Clinisys (agent.pharma)')->assertSee('⏳ Automatique')
            ->assertSee('sortie patient PA dans Clinisys');
        $c5 = Action::where('etape', 'C5')->firstOrFail();
        $this->assertSame(['clinisys', 'clinisys', '2026-10-07 13:01'], [$c5->user_name, $c5->donnees['source'], $c5->fait_le->format('Y-m-d H:i')]);
        $this->assertSame('BDBD261455', $c5->donnees['bon_clinisys']);
        $this->assertSame(['0726455339'], $c5->donnees['ecarts']['manquantes']);
        $this->assertSame(['99999999999'], $c5->donnees['ecarts']['en_trop']);
        $this->assertSame('2026-11-16', Poche::where('numero', '07264553226')->first()->peremption->toDateString());
        $this->assertSame(2, Poche::where('statut', 'en_stock')->count());

        // Sortie patient FBS00718 de la poche 07264553226 : C8, poche délivrée ; bon PR pour l'autre poche : retournée.
        $this->sortiesPa = [(object) ['numbon' => 'FAFBS00718', 'numaffiche' => 'FBS00718', 'datesys' => '2026-10-08 00:00:00', 'heuresys' => '1970-01-01 09:15:00',
            'codvend' => 'agent.major', 'numdoss' => '26030752', 'NumBon_Depot' => 'BD261455', 'reference' => '07264553226', 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1]];
        $this->prs = [(object) ['numbon' => 'PRPR260400', 'numaffiche' => 'PR260400', 'NumBon_Depot' => 'BD261455', 'Identifiant' => '99999999999',
            'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'DatPer' => null, 'datbon' => '2026-10-09', 'datesys' => '2026-10-09 00:00:00', 'heuresys' => '1900-01-01 10:00:00', 'Reffrs' => '', 'codvend' => 'agent.major']];
        $r = app(\App\Services\SyncClinisys::class)->synchroniser();
        $this->assertSame(['c5' => 0, 'c8' => 1, 'pr' => 1], $r);
        $c8 = Action::where('etape', 'C8')->firstOrFail();
        $this->assertSame(['FBS00718'], $c8->donnees['bons_pa']);
        $this->assertSame('2026-10-08 09:15', $c8->fait_le->format('Y-m-d H:i'));
        $this->assertSame('delivree', Poche::where('numero', '07264553226')->first()->statut);
        $this->assertSame(['prelevee', 'PR260400'], [Poche::where('numero', '99999999999')->first()->statut, Poche::where('numero', '99999999999')->first()->bon_prelevement]);
        // Le service accuse réception (C9) ; rien de plus à faire pour la pharmacie.
        $this->en('inf')->get('/a-faire')->assertOk()->assertSee('Accusé de réception');
        $this->assertSame([], app(\App\Services\Circuit::class)->possibles($d->fresh(), app(FournisseurUtilisateurs::class)->retrieveById('pharma')));
        $this->assertSame(['c5' => 0, 'c8' => 0, 'pr' => 0], app(\App\Services\SyncClinisys::class)->synchroniser(), 'rien en double');
    }

    public function test_demande_supprimee_dans_le_dmi_alors_qu_en_cours_ici(): void
    {
        $this->poster()->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $this->dmi[1449]->Etat = 'S';
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('supprimée</strong> dans le DMI', false);
    }

    public function test_bon_bd_recu_hors_circuit_dans_les_nouvelles_receptions(): void
    {
        // Cas du BD261569 : bon BD saisi hier soir pour la demande DMI 1449, restée en D dans le DMI et jamais inscrite.
        $this->bons[0]->datesys = now()->subHours(15)->toDateString();
        $this->bons[0]->heuresys = '1900-01-01 '.now()->subHours(15)->format('H:i:s');
        $this->bdParDemande = ['1449' => 2];
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertSee('Reçues hors registre (1)')->assertSee('Nouvelles réceptions (24 h) (1)')
            ->assertSee('Reçue dans Clinisys, demande pas inscrite au registre : à inscrire (régularisation).')->assertSee('BD261455');
        $this->en('surv')->get('/a-faire')->assertOk()->assertSee('Réceptions pharmacie des dernières 24 h (1)')
            ->assertSee('Réceptionnée en pharmacie sans inscription');
        // Inscription : régularisation (motif obligatoire), puisque les poches sont déjà en pharmacie.
        $this->en('surv')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee('Inscrire (régularisation)');
        $regul = ['num_doss' => '26030752', 'num_demande_clinisys' => '1449', 'urgence' => 'urgente', 'nature' => 'acheminement', 'produit' => 'CGR', 'nb_poches' => 2, 'diagnostic' => 'x'];
        $this->en('surv')->post('/demandes', $regul)->assertSessionHasErrors('regularisation');

        // Demande inscrite normalement (1450) mais C4 jamais saisie : son bon BD apparaît quand même, avec l'avertissement.
        $this->poster(['num_demande_clinisys' => '1450', 'forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasNoErrors();
        $this->bdParDemande['1450'] = 1;
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertSee('C4 non saisie')->assertSee('1 poche(s) au bon BD')
            ->assertSee('Nouvelles réceptions (24 h) (2)');
    }

    public function test_demande_dmi_non_inscrite_signalee_puis_inscrite(): void
    {
        // 1450 : état D, saisie il y a 1 h, absente de l'appli (cas du dossier 26031703).
        // 1449 : déjà réceptionnée en pharmacie (V, bon BD261455) sans jamais avoir été inscrite : hors registre.
        $this->dmi[1449]->Etat = 'V';
        $this->en('surv')->get('/a-faire')->assertOk()->assertSee('Dans Clinisys, pas encore inscrites (2)', false)
            ->assertSee('Réceptionnée en pharmacie sans inscription')->assertSee('Inscrire (régularisation)');
        $this->en('inf')->get('/demandes')->assertOk()->assertSee('pas encore inscrites (2)')->assertSee('num_demande=1450', false);
        $this->en('surv')->get('/alertes')->assertOk()->assertSee('Demande DMI n° 1450 non inscrite')
            ->assertSee('Demande DMI n° 1449 réceptionnée en pharmacie sans inscription au registre');
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertSee('Reçues hors registre (1)')->assertSee('BD261455')->assertSee('07264553226');
        $this->en('inf')->get('/demandes/nouvelle?q=26030752')->assertOk()
            ->assertSee('Demandes DMI des dernières 48 h')->assertSee('num_demande=1450', false)->assertSee('Supprimée (S)');

        $this->poster(['num_demande_clinisys' => '1450'])->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $this->en('inf')->get('/demandes')->assertOk()->assertSee('pas encore inscrites (1)');
        $this->en('surv')->get('/alertes')->assertOk()->assertDontSee('Demande DMI n° 1450');
        $this->en('inf')->get('/demandes/nouvelle?q=26030752')->assertOk()->assertSee(route('demandes.show', $d), false);

        // Régularisation de 1449 : motif obligatoire ; le surveillant peut l'inscrire (pas une demande ordinaire).
        $this->en('surv')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee('Régularisation')->assertSee('Inscrire (régularisation)');
        $regul = ['num_doss' => '26030752', 'num_demande_clinisys' => '1449', 'urgence' => 'urgente', 'nature' => 'acheminement',
            'produit' => 'CGR', 'nb_poches' => 2, 'diagnostic' => 'x'];
        $this->en('surv')->post('/demandes', $regul)->assertSessionHasErrors('regularisation');
        $this->en('surv')->post('/demandes', $regul + ['regularisation' => 'Urgence de nuit, demande portée directement au CTS'])->assertSessionHasNoErrors();
        $r = Demande::where('num_demande_clinisys', '1449')->firstOrFail();
        $this->assertSame('Urgence de nuit, demande portée directement au CTS', $r->regularisation);
        $etat = app(\App\Services\Circuit::class)->etat($r->fresh());
        // Étapes d'avant la pharmacie non tracées (pas d'heure inventée) ; C5 reprise de Clinisys ; la suite est à faire.
        $this->assertSame(['non_tracee', 'non_tracee', 'faite'], [$etat['A11']['statut'], $etat['C4']['statut'], $etat['C5']['statut']]);
        $this->assertSame('BD261455', $etat['C5']['action']->donnees['bon_depot']);
        $this->assertSame('disponible', $etat['C6']['statut']);
        $this->en('inf')->get("/demandes/{$r->id}")->assertOk()->assertSee('Inscrite a posteriori (régularisation)')->assertSee('Non tracée');
        $this->en('surv')->get('/a-faire')->assertOk()->assertDontSee('Réceptionnée en pharmacie sans inscription');
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertDontSee('Reçues hors registre');
        $this->en('direction')->get('/indicateurs')->assertOk()->assertSee('hors registre, inscrites a posteriori (1)');

        // Seuil paramétrable.
        $this->en('direction')->post('/parametrage/seuils', ['peremption_jours' => 3, 'dmi_jours' => 3, 'dmi_minutes' => 120,
            'seuil' => config('demandesang.seuils_defaut')])->assertSessionHasNoErrors();
        $this->assertSame('120', \Illuminate\Support\Facades\DB::table('ds_parametres')->where('cle', 'dmi_minutes')->value('valeur'));
    }

    public function test_services_de_soin_clinisys_et_service_source_par_defaut(): void
    {
        $this->app->instance(\App\Services\ServicesClinisys::class, new class extends \App\Services\ServicesClinisys {
            public function liste(): array
            {
                return ['02' => 'HOSPITALISATION 2 EME ETAGE', 'RE' => 'REANIMATION'];
            }
        });
        $this->en('direction')->post('/parametrage/services/clinisys')->assertSessionHasNoErrors()->assertSessionHas('statut');
        $services = \Illuminate\Support\Facades\DB::table('ds_services')->pluck('actif', 'code');
        $this->assertEquals(1, $services['RE']);
        $this->assertEquals(0, $services['URG'], 'ancien code désactivé');
        $this->assertTrue($services->has('URG'), 'ancien code gardé pour l\'historique');

        // Le service de soin du patient (Clinisys) devient le service source proposé à A10.
        $this->poster()->assertSessionHasNoErrors();
        $d = Demande::firstOrFail();
        $d->update(['service_saisie_dmi' => 'REANIMATION']);
        foreach (['A4' => ['identite_ok' => '1'], 'A6' => ['carte_groupage' => 'oui'], 'A7' => ['pieces' => ['demande' => '1']]] as $code => $data) {
            $this->en('inf')->post("/demandes/{$d->id}/etapes/$code", $data)->assertSessionHasNoErrors();
        }
        $this->en('major')->get("/demandes/{$d->id}")->assertOk()->assertSee('<option value="RE" selected>REANIMATION</option>', false);
    }

    public function test_patient_de_consultation_ou_urgence_coche_externe_par_defaut(): void
    {
        $case = '<input type="radio" name="cas_patient" value="externe" checked';
        // Hospitalisé (REANIMATION) : hospitalisé proposé.
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertDontSee($case, false);

        // Aux urgences (UR) : coché, avec l'explication.
        \Illuminate\Support\Facades\Cache::flush();
        $this->app->instance(Patients::class, new class extends Patients {
            public function dossier(string $numDoss): ?object
            {
                return (object) ['NumDoss' => $numDoss, 'Identifiant' => '000123', 'NomCli' => 'PATIENT', 'Prenom' => 'Urgence', 'NumCIN' => 'ZZ100200',
                    'service' => 'URGENCE', 'service_code' => 'UR', 'medecin' => 'DR X'];
            }
        });
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee($case, false)->assertSee('Patient externe proposé car le patient est en URGENCE');
    }

    public function test_liste_des_comptes_clinisys_pour_choisir_le_login(): void
    {
        $this->app->instance(\App\Clinisys\Comptes::class, new class extends \App\Clinisys\Comptes {
            public function actifs(): array
            {
                return [(object) ['UserName' => 'ADMIN', 'Description' => 'ADMIN TEST'], (object) ['UserName' => 'agent.inf', 'Description' => 'AGENT INF']];
            }
        });
        $this->en('direction')->get('/parametrage/comptes/nouveau')->assertOk()
            ->assertSee('list="comptes-clinisys"', false)->assertSee('<option value="agent.inf" data-nom="AGENT INF">', false)
            ->assertSee('2 comptes Clinisys actifs');
    }

    public function test_a2_verifie_tous_les_dossiers_du_patient_et_les_doublons(): void
    {
        // Rien d'autre : bandeau vert avec les dossiers vérifiés.
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()
            ->assertSee('Aucune autre demande ni poche pour ce patient')->assertSee('26030752, 26099999');

        // Sur un AUTRE dossier du même patient : 1388 sans aucune poche reçue (doublon possible, bloquant),
        // 1300 servie en partie (1/3 d'après les bons BD : relance probable), une poche sortie hier (information).
        $this->autres = [$this->ligneDmi(1388, '26099999', 'V', ['Plasma' => 2]), $this->ligneDmi(1300, '26099999', 'D', ['GlobuleRouge' => 3])];
        $this->bdParDemande = ['1300' => 1];
        $this->lieux = ['1300' => [(object) ['numero' => '07264557251', 'desart' => 'CULOT GLOBULAIRE 1', 'DatPer' => '2026-11-25', 'bon_bd' => 'BD261541', 'date_bd' => '2026-10-20',
            'memop' => 'AB+', 'numDemande' => '1300', 'pa_bon' => 'FBS00758', 'pa_date' => '2026-10-21', 'pa_service' => 'HOSPITALISATION 2 EME ETAGE', 'pr_bon' => null, 'pr_date' => null, 'en_stock' => 0]]];
        $this->sorties = [(object) ['numaffiche' => 'FBS00718', 'datesys' => now()->subDay()->format('Y-m-d'), 'numdoss' => '26099999',
            'codart' => 'F2200003', 'desart' => 'PFC 3', 'quantite' => 1, 'NumBon_Depot' => 'BD261412']];
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()
            ->assertSee('À vérifier : 1 demande(s) ou poche(s)', false)
            ->assertSee('Ce patient a déjà une demande pour laquelle aucune poche n\'est arrivée : n° 1388')->assertSee('2 plasmas frais congelés (PFC)')
            ->assertSee('autre dossier du même patient : 26099999')->assertSee('Doublon possible')
            ->assertSee('Pour information')->assertSee('Information seulement, l\'inscription reste possible.')
            ->assertSee('Ce patient a déjà une demande non complète : n° 1300')->assertSee('3 culots globulaires demandé(s)')
            // Où sont les poches de la 1300 : une délivrée au service, une en stock, une pas encore arrivée.
            ->assertSee('1 reçue(s) sur 3 à la pharmacie · 1 délivrée(s) au service · 0 en stock', false)
            ->assertSee('Délivrée au service le 21/10 (FBS00758, HOSPITALISATION 2 EME ETAGE)')->assertSee('Pas encore arrivée à la pharmacie')
            // Bouton « Oui, c'est une relance » : coche le choix « relance de la n° 1300 » du formulaire.
            ->assertSee('Oui, c\'est une relance de la n° 1300')->assertSee('onclick="choisirRelance(\'1300\')"', false)
            ->assertSee('Une poche a été donnée à ce patient le')->assertSee('bon FBS00718')
            ->assertSee('C\'est une relance de la demande n° 1300 (le CTS a livré 1/3)', false);

        $this->poster()->assertSessionHasErrors('forcer');
        $this->poster(['relance_de' => '9999', 'forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasErrors('relance_de');
        $this->poster(['relance_de' => '1300', 'forcer' => '1', 'motif_forcage' => 'Relance de la demande DMI n° 1300 : le CTS a livré 1/3'])->assertSessionHasNoErrors();
        $a2 = Action::where('etape', 'A2')->firstOrFail()->donnees;
        $this->assertSame(['1388', '1300'], $a2['autres_demandes_dmi']);
        $this->assertSame('1300', $a2['relance_de_dmi']);
        $this->assertSame(['FBS00718'], $a2['sorties_recentes']);
        $this->assertSame(['26030752', '26099999'], $a2['dossiers_verifies']);

        // La même demande DMI ne s'inscrit pas deux fois.
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()->assertSee('Cette demande DMI est déjà inscrite');
        $this->poster(['forcer' => '1', 'motif_forcage' => 'x'])->assertSessionHasErrors('num_demande_clinisys');
        $this->assertSame(1, Demande::count());
    }

    public function test_relance_reliee_a_la_demande_d_origine_dans_l_appli(): void
    {
        // Demande d'origine 1450 inscrite, 1 poche reçue sur 3 au contrôle C3.
        $this->poster(['num_demande_clinisys' => '1450'])->assertSessionHasNoErrors();
        $origine = Demande::firstOrFail();
        Poche::create(['demande_id' => $origine->id, 'numero' => 'P1', 'produit' => 'CGR', 'statut' => 'attente']);
        $this->autres = [$this->dmi[1450]];

        // Servie en partie et en cours : relance probable, rien de bloquant (pas de motif demandé).
        $this->en('inf')->get('/demandes/nouvelle?num_demande=1449')->assertOk()
            ->assertDontSee('À vérifier :', false)->assertSee('relance de la demande n° 1450 (le CTS a livré 1/3)', false);
        $this->poster(['relance_de' => '1450'])->assertSessionHasNoErrors();
        $relance = Demande::latest('id')->firstOrFail();
        $this->assertSame($origine->id, $relance->demande_relancee_id);

        $this->en('inf')->get("/demandes/{$relance->id}")->assertOk()->assertSee('Relance de')->assertSee("demande {$origine->id}");
        $this->en('inf')->get("/demandes/{$origine->id}")->assertOk()->assertSee('Relancée par')->assertSee('1 sur 3')->assertSee('servie partiellement');
        $this->en('direction')->get('/indicateurs')->assertOk()->assertSee('1 besoin(s), 1 relance(s)', false);
        $this->en('inf')->get('/registre')->assertOk()->assertSee('relance de n° '.$origine->id);
    }

    public function test_recherche_patient_par_nom_dossier_ou_identifiant(): void
    {
        $this->autres = [$this->dmi[1449], $this->dmi[1450]];
        $this->en('inf')->get('/demandes/nouvelle?q=PATI')->assertOk()
            ->assertSee('Patients trouvés pour « PATI » (1)', false)->assertSee('26030752, 26099999')->assertSee('REANIMATION')
            ->assertSee('num_demande=1449', false)->assertSee('num_demande=1450', false);
        $this->en('inf')->get('/demandes/nouvelle?q=ZZZ')->assertOk()->assertSee('Aucun patient trouvé');
    }

    public function test_preremplissage_et_ecarts(): void
    {
        $p = DemandesDmi::preremplissage($this->ligneDmi(1, '1', 'D', ['GlobuleRouge' => 0, 'Plasma' => 2, 'Plaquette' => 5]));
        $this->assertSame(['PFC', 2, ['CPS' => 5]], [$p['produit'], $p['nb_poches'], $p['autres_produits']]);
        $this->assertNull(DemandesDmi::preremplissage((object) ['NumDoss' => '1', 'determination1' => 'Non identifié', 'GlobuleRouge' => 1])['groupe_sanguin']);
        $this->assertSame('0726455339', Pharmacie::normaliser("07264 55339-\t"));
    }
}
