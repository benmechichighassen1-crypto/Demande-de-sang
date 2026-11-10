<?php

namespace Tests\Feature;

use App\Auth\FournisseurUtilisateurs;
use App\Auth\Utilisateur;
use App\Models\Action;
use App\Models\Demande;
use App\Models\Poche;
use App\Models\Remise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function u(string $login): Utilisateur
    {
        return app(FournisseurUtilisateurs::class)->retrieveById($login);
    }

    private function en(string $login): static
    {
        return $this->actingAs($this->u($login));
    }

    private function creer(array $extra = []): Demande
    {
        $this->en('inf')->post('/demandes', $extra + [
            'num_doss' => '26032758', 'nom_patient' => 'PATIENT Test', 'num_demande_clinisys' => '694', 'cin' => 'ZZ100200',
            'urgence' => 'urgente', 'nature' => 'acheminement', 'produit' => 'CGR', 'nb_poches' => 2,
            'diagnostic' => 'HEMORAGIE DIGESTIVE ACTIVE',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Demande::latest('id')->first();
    }

    private function etape(string $login, Demande $d, string $code, array $data = []): void
    {
        $this->en($login)->post("/demandes/{$d->id}/etapes/$code", $data)->assertSessionHasNoErrors()->assertRedirect();
    }

    private function confirmer(string $login, Demande $d, string $code): void
    {
        $r = Remise::where('demande_id', $d->id)->where('etape', $code)->where('reponse', 'attente')->firstOrFail();
        $this->en($login)->post("/remises/{$r->id}/confirmation")->assertSessionHasNoErrors()->assertRedirect();
    }

    private function jusquaA10(Demande $d): void
    {
        $this->etape('inf', $d, 'A4', ['identite_ok' => '1']);
        $this->etape('inf', $d, 'A6', ['carte_groupage' => 'non']);
        $this->etape('inf', $d, 'A7', ['pieces' => ['demande' => '1']]);
        $this->etape('inf', $d, 'A9');
        $this->etape('major', $d, 'A10', ['service_source' => 'CONS1', 'service_destination' => 'HOSP2']);
    }

    public function test_circuit_complet_de_bout_en_bout(): void
    {
        $d = $this->creer();
        $this->assertSame(['creation', 'A2', 'A5', 'A8'], $d->actions->pluck('etape')->all());

        $this->jusquaA10($d);
        $this->assertSame('HOSP2', $d->fresh()->service_destination);

        $this->etape('inf', $d, 'A11');
        $this->assertSame('A10', $d->fresh()->etape, 'une remise non confirmée ne compte pas');
        $this->confirmer('coursier', $d, 'A11');
        $this->assertSame('A11', $d->fresh()->etape);

        $this->etape('coursier', $d, 'B1');
        $this->etape('coursier', $d, 'B2', ['etat_cts' => 'pretes']);
        $this->etape('coursier', $d, 'C1');
        $this->etape('coursier', $d, 'C2');
        $this->confirmer('surv', $d, 'C2');
        $this->etape('surv', $d, 'C3', ['conforme' => 'oui', 'poches' => [
            ['numero' => '07250486683', 'produit' => 'CGR', 'groupe_sanguin' => 'O+', 'peremption' => now()->addDays(20)->toDateString()],
            ['numero' => 'B07250183674', 'produit' => 'CGR', 'groupe_sanguin' => 'O+'],
            ['numero' => '', 'produit' => 'CGR'],
        ]]);
        $this->assertSame(2, Poche::where('statut', 'attente')->count());

        $this->etape('coursier', $d, 'C4');
        $this->etape('pharma', $d, 'C5', ['bon_depot' => 'BD261545']);
        $this->assertSame(2, Poche::where('statut', 'en_stock')->where('bon_depot', 'BD261545')->count());

        $this->etape('surv', $d, 'C6');
        $this->etape('pharma', $d, 'C8', ['poches_delivrees' => Poche::pluck('id')->all()]);
        // C8 n'est plus une remise : les poches passent « délivrées » dès la saisie (ou la sortie patient Clinisys).
        $this->assertSame(2, Poche::where('statut', 'delivree')->count());

        $this->etape('inf', $d, 'C9');
        [$p1, $p2] = Poche::orderBy('id')->get()->all();
        $this->etape('inf', $d, 'C10', ['issue' => [$p1->id => 'transfusee', $p2->id => 'non_utilisee'], 'motif' => [$p2->id => 'annulee']]);

        $d->refresh();
        $this->assertSame('cloturee', $d->statut);
        $this->assertSame('transfusee', $p1->fresh()->statut);
        $this->assertSame('a_retourner', $p2->fresh()->statut);

        $this->en('pharma')->post("/poches/{$p2->id}/prelevement", ['motif' => 'annulee', 'bon_prelevement' => 'PR260361'])->assertSessionHasNoErrors();
        $this->assertSame('prelevee', $p2->fresh()->statut);

        foreach (['/a-faire', '/demandes?statut=toutes', "/demandes/{$d->id}", "/demandes/{$d->id}/bon", '/stock?vue=retournees', '/alertes', '/registre', '/demandes/nouvelle?q=PATIENT'] as $url) {
            $this->en('inf')->get($url)->assertOk();
        }
        // Ancien écran « Patient » → « Rechercher / inscrire » ; pharmacie et direction arrivent sur leur premier écran.
        $this->en('inf')->get('/patients?q=PATIENT')->assertRedirect('/demandes/nouvelle?q=PATIENT');
        $this->en('pharma')->get('/a-faire')->assertRedirect('/pharmacie');
        $this->en('direction')->get('/a-faire')->assertRedirect('/demandes');
        $this->en('pharma')->get('/demandes/nouvelle?q=PATIENT')->assertOk()->assertSee('Rechercher un patient');
        $this->en('direction')->get('/indicateurs')->assertOk()->assertSee('Création → reçu dans le service');
        $csv = $this->en('direction')->get('/indicateurs/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('PATIENT Test', $csv);
        // Remises : qui a remis et qui a reçu, dans le registre et l'export.
        $this->assertStringContainsString('Remis au coursier : reçu par', $csv);
        $this->assertStringContainsString('Coursier (démo)', $csv);
        $this->en('inf')->get('/registre')->assertOk()->assertSee('Coursier (A11)')->assertSee('Reçu ', false)->assertSee('Coursier (démo)')->assertSee('Surveillant de garde (démo)');
    }

    public function test_preparation_en_un_formulaire_et_boutons_directs(): void
    {
        $d = $this->creer();
        // Fiche : A4, A6, A7, A9 regroupées pour l'infirmière.
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('Préparation de la demande')->assertSee('A6 · ');

        // Champ obligatoire manquant (A6) : rien n'est enregistré.
        $this->en('inf')->post("/demandes/{$d->id}/preparation", ['etapes' => ['A4', 'A6', 'A7', 'A9'], 'identite_ok' => '1', 'pieces' => ['demande' => '1']])
            ->assertSessionHasErrors('carte_groupage');
        $this->assertFalse(Action::where('etape', 'A4')->exists());

        $this->en('inf')->post("/demandes/{$d->id}/preparation", ['etapes' => ['A4', 'A6', 'A7', 'A9'], 'identite_ok' => '1',
            'carte_groupage' => 'non', 'pieces' => ['demande' => '1'], 'commentaire' => 'ok'])->assertSessionHasNoErrors()->assertRedirect();
        // Une action par étape, tracée avec l'auteur.
        $this->assertSame(['A4', 'A6', 'A7', 'A9'], Action::where('demande_id', $d->id)->whereIn('etape', ['A4', 'A6', 'A7', 'A9'])->orderBy('id')->pluck('etape')->all());
        $this->assertSame(2, Action::where('etape', 'A6')->first()->donnees['nb_tubes']);
        $this->assertSame(['inf'], Action::whereIn('etape', ['A4', 'A6', 'A7', 'A9'])->distinct()->pluck('user_name')->all());

        // Le major n'a pas les étapes A4… mais valide A10 ; puis À faire propose « Je remets » en un bouton.
        $this->etape('major', $d, 'A10', ['service_source' => 'CONS1', 'service_destination' => 'HOSP2']);
        $this->en('inf')->get('/a-faire')->assertOk()->assertSee('Je remets : Remis au coursier')->assertSee('À faire par : Infirmière');
        $this->en('inf')->get('/demandes?role=infirmiere')->assertOk()->assertSee('Responsable : Infirmière')->assertSee('PATIENT Test');
        $this->en('inf')->get('/demandes?role=pharmacie')->assertOk()->assertDontSee('PATIENT Test');
        $this->en('inf')->post("/demandes/{$d->id}/etapes/A11", ['retour' => 'accueil'])->assertRedirect('/a-faire');
        $this->confirmer('coursier', $d, 'A11');
        $this->en('coursier')->get('/a-faire')->assertOk()->assertSee('✔ Transport de la demande au CTS');
        // Étape avec champ obligatoire (B2) : lien vers la fiche, pas de bouton direct.
        $this->etape('coursier', $d, 'B1');
        $this->en('coursier')->get('/a-faire')->assertOk()->assertSee('À ramener : poches prêtes')->assertSee('À laisser en instance');
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/B2", ['retour' => 'accueil', 'etat_cts' => 'pretes'])->assertSessionHasNoErrors()->assertRedirect('/a-faire');
        $this->assertSame('B2', $d->fresh()->etape);
    }

    public function test_services_multiples_et_a_faire_limite_aux_services(): void
    {
        // Une infirmière rattachée à deux services.
        $this->en('direction')->post('/parametrage/comptes', ['user_name' => 'inf2', 'nom' => 'Infirmière 2', 'type' => 'local', 'pin' => '5678',
            'roles' => ['infirmiere'], 'services' => ['REA', 'URG'], 'actif' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['REA', 'URG'], $this->u('inf2')->services);
        $this->en('direction')->get('/parametrage/comptes')->assertOk()->assertSee('Réanimation, Urgences');

        $d = $this->creer(); // créée par « inf », pas encore de service
        $this->en('inf2')->get('/a-faire')->assertOk()->assertDontSee('PATIENT Test');
        $d->update(['service_destination' => 'REA']);
        $this->en('inf2')->get('/a-faire')->assertOk()->assertSee('PATIENT Test');
        // Le coursier voit tout ; un compte sans service aussi.
        $this->en('coursier')->get('/a-faire')->assertOk();
        $this->assertFalse($this->u('coursier')->limiteAuxServices());
        $this->assertSame('HOSP2', $this->u('inf')->premierService());
    }

    public function test_a_faire_par_role_pour_un_compte_a_plusieurs_roles(): void
    {
        // Compte infirmière + coursier : une remise A11 à faire (infirmière) et une remise A11 à confirmer (coursier).
        \Illuminate\Support\Facades\DB::table('ds_comptes')->insert(['user_name' => 'multi', 'nom' => 'Multi rôles', 'roles' => 'infirmiere,coursier',
            'actif' => true, 'admin' => false, 'pin_hash' => password_hash('1234', PASSWORD_DEFAULT)]);
        $aFaire = $this->creer();
        $this->jusquaA10($aFaire);
        $aConfirmer = $this->creer(['num_demande_clinisys' => '695', 'num_doss' => '26032759', 'nom_patient' => 'PATIENT Deux']);
        $this->jusquaA10($aConfirmer);
        $this->etape('inf', $aConfirmer, 'A11');

        $page = $this->en('multi')->get('/a-faire')->assertOk()
            ->assertSee('Tous les rôles')->assertSee('data-onglets="accueil_roles"', false)->assertDontSee('data-onglets="accueil"', false)
            // Chaque tâche porte ses rôles (le filtre combine étape et rôle côté navigateur) et son badge.
            ->assertSee('data-roles="coursier"', false)->assertSee('data-roles="infirmiere major"', false)
            ->assertSee('À confirmer par : Coursier')->assertSee('À faire par : Infirmière / Major');
        // Compteurs par rôle : 1 tâche infirmière, 1 tâche coursier.
        $this->assertMatchesRegularExpression('/data-onglet="infirmiere"[^>]*>[^<]*<span class="n">1</', $page->getContent());
        $this->assertMatchesRegularExpression('/data-onglet="coursier"[^>]*>[^<]*<span class="n">1</', $page->getContent());
        // Compte à un seul rôle : pas de rangée « par rôle », mais le badge.
        $this->en('coursier')->get('/a-faire')->assertOk()->assertDontSee('Tous les rôles')->assertSee('À confirmer par : Coursier');
    }

    public function test_ecran_cts_du_coursier_et_instance(): void
    {
        $d = $this->creer();
        $this->jusquaA10($d);
        $this->etape('inf', $d, 'A11');
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('À récupérer dans les services (1)')->assertSee('J\'ai bien reçu le dossier', false);
        $r = Remise::where('demande_id', $d->id)->where('etape', 'A11')->firstOrFail();
        $this->en('coursier')->post("/remises/{$r->id}/confirmation", ['retour' => 'cts'])->assertRedirect('/cts');

        $this->en('coursier')->get('/cts')->assertOk()->assertSee('À déposer au CTS (1)');
        $this->etape('coursier', $d, 'B1');
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('Déposées, en attente au CTS (1)')->assertSee('À laisser en instance');

        // B2 : moins de poches que demandé, et gardées en instance.
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/B2", ['etat_cts' => 'pretes', 'nb_pretes' => 5])->assertSessionHasErrors('nb_pretes');
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/B2", ['etat_cts' => 'instance', 'retour' => 'cts'])->assertRedirect('/cts');
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('En instance au CTS (1)')->assertSee('Ramener maintenant');
        $this->en('coursier')->get('/a-faire')->assertOk()->assertSee('poches en instance (1)');
        $this->en('surv')->get('/alertes')->assertOk()->assertDontSee('Poches en instance au CTS');
        $this->travel(4)->days();
        $this->en('surv')->get('/alertes')->assertOk()->assertSee('Poches en instance au CTS depuis');
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('instance trop longue');

        $this->en('coursier')->post("/demandes/{$d->id}/etapes/C1", ['retour' => 'cts'])->assertRedirect('/cts');
        $this->assertSame('C1', $d->fresh()->etape);
        $this->en('inf')->get('/cts')->assertForbidden();
    }

    public function test_c3_sans_numero_de_poche_et_c2_reserve_au_surveillant(): void
    {
        $d = $this->creer();
        $this->jusquaA10($d);
        $this->etape('inf', $d, 'A11');
        $this->confirmer('coursier', $d, 'A11');
        foreach (['B1' => [], 'B2' => ['etat_cts' => 'pretes'], 'C1' => [], 'C2' => []] as $code => $data) {
            $this->etape('coursier', $d, $code, $data);
        }
        // Seul le surveillant général / de garde confirme C2 (pas le major).
        $r = Remise::where('demande_id', $d->id)->where('etape', 'C2')->firstOrFail();
        $this->en('major')->post("/remises/{$r->id}/confirmation")->assertForbidden();
        $this->confirmer('surv', $d, 'C2');

        // C3 sans aucun n° de poche (lignes vides) : accepté.
        $this->etape('surv', $d, 'C3', ['conforme' => 'oui', 'poches' => [['numero' => '', 'produit' => 'CGR', 'groupe_sanguin' => '', 'peremption' => '']]]);
        $this->assertSame(0, Poche::where('demande_id', $d->id)->count());
        $this->etape('coursier', $d, 'C4');
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertSee('Portées à la pharmacie, bon BD pas encore saisi (1)');
        $this->en('coursier')->get('/pharmacie')->assertForbidden();
        // C5 : sans bon Clinisys, une poche « sans n° » par poche demandée, en stock, pour pouvoir délivrer (C8).
        $this->etape('pharma', $d, 'C5', ['bon_depot' => 'BD000001']);
        $this->assertSame(['sans n° '.$d->id.'-1', 'sans n° '.$d->id.'-2'], Poche::where('demande_id', $d->id)->where('statut', 'en_stock')->orderBy('id')->pluck('numero')->all());
        // Écran Pharmacie : nouvelle réception (BD, badge « Nouveau ») et à délivrer ; lien sur « À faire » ; filtre du registre.
        $this->en('pharma')->get('/pharmacie')->assertOk()->assertSee('Nouvelles réceptions (24 h) (1)')->assertSee('BD000001')
            ->assertSee('Nouveau')->assertSee('Reçues, à délivrer au service (1)')->assertSee('Portées à la pharmacie, bon BD pas encore saisi (0)');
        $this->en('surv')->get('/a-faire')->assertOk()->assertSee('Réceptions pharmacie des dernières 24 h (1)');
        $this->en('surv')->get('/registre?pharmacie=recues')->assertOk()->assertSee($d->nom_patient);
        $this->etape('surv', $d, 'C6');
        $this->en('major')->post("/demandes/{$d->id}/etapes/C6")->assertSessionHasErrors('etape');
    }

    public function test_le_depart_est_bloque_tant_que_le_major_n_a_pas_valide(): void
    {
        $d = $this->creer();
        $this->etape('inf', $d, 'A9');
        $this->en('inf')->post("/demandes/{$d->id}/etapes/A11")->assertSessionHasErrors('etape');
        $this->etape('inf', $d, 'A4', ['identite_ok' => '1']);
        $this->etape('inf', $d, 'A6', ['carte_groupage' => 'non']);
        $this->etape('inf', $d, 'A7', ['pieces' => ['demande' => '1']]);
        // Seul le major valide.
        $this->en('inf')->post("/demandes/{$d->id}/etapes/A10", ['service_source' => 'URG', 'service_destination' => 'HOSP2'])->assertForbidden();
    }

    public function test_remise_double_validation_et_refus(): void
    {
        $d = $this->creer();
        $this->jusquaA10($d);
        $this->etape('inf', $d, 'A11');
        $r = Remise::firstOrFail();

        // Une infirmière ne peut pas confirmer à la place du coursier.
        $this->en('inf')->post("/remises/{$r->id}/confirmation")->assertSessionHasErrors('remise');
        $this->en('major')->post("/remises/{$r->id}/confirmation")->assertForbidden();

        // « Je n'ai rien reçu » : la remise est refusée, l'étape est à refaire.
        $this->en('coursier')->post("/remises/{$r->id}/refus", ['motif' => 'Rien reçu'])->assertSessionHasNoErrors();
        $this->assertSame('refusee', $r->fresh()->reponse);
        $this->en('coursier')->get('/a-faire')->assertDontSee('J\'ai bien reçu');
        $this->etape('inf', $d, 'A11');
        $this->confirmer('coursier', $d, 'A11');
        $this->assertSame('A11', $d->fresh()->etape);
        $this->assertTrue(Action::where('etape', 'remise_refusee')->exists());
    }

    public function test_a2_bloque_une_nouvelle_demande_si_une_poche_est_disponible(): void
    {
        $d = $this->creer();
        Poche::create(['demande_id' => $d->id, 'numero' => 'X1', 'produit' => 'CGR', 'statut' => 'en_stock']);

        $this->en('inf')->post('/demandes', [
            'num_doss' => '26032758', 'nom_patient' => 'PATIENT Test', 'num_demande_clinisys' => '700', 'cin' => 'ZZ100200',
            'urgence' => 'urgente', 'nature' => 'acheminement', 'produit' => 'CGR', 'nb_poches' => 1, 'diagnostic' => 'X',
        ])->assertSessionHasErrors('forcer');
        $this->assertSame(1, Demande::count());

        $this->creer(['num_demande_clinisys' => '701', 'forcer' => '1', 'motif_forcage' => 'Besoin de PFC en plus']);
        $this->assertSame(2, Demande::count());
    }

    public function test_correction_garde_l_historique_et_rouvre_l_etape(): void
    {
        $d = $this->creer();
        $this->en('inf')->post("/demandes/{$d->id}/etapes/A6", ['carte_groupage' => 'oui'])->assertSessionHasErrors('etape'); // A4 pas faite
        $this->assertFalse(Action::where('etape', 'A6')->exists());

        $this->etape('inf', $d, 'A4', ['identite_ok' => '1']);
        $this->etape('inf', $d, 'A6', ['carte_groupage' => 'oui']);
        $a6 = Action::where('etape', 'A6')->firstOrFail();

        $this->en('inf')->post("/actions/{$a6->id}/correction", ['motif' => 'Pas de carte en fait'])->assertSessionHasNoErrors();
        $this->etape('inf', $d, 'A6', ['carte_groupage' => 'non']);
        $this->assertSame(2, Action::where('etape', 'A6')->count());
        $this->assertSame(2, Action::where('etape', 'A6')->latest('id')->first()->donnees['nb_tubes']);

        $this->expectException(\LogicException::class);
        $a6->update(['commentaire' => 'modifié']);
    }

    public function test_paiement_verifie_par_le_coursier_avant_le_transport_au_cts(): void
    {
        $d = $this->creer(['externe' => '1']);
        // L'infirmière ne voit plus le paiement : ni A3, ni paiement dans A7. A10 n'attend plus A3.
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertDontSee('A3 · ')->assertDontSee('Patient à la charge de');
        $this->en('inf')->post("/demandes/{$d->id}/etapes/A3", ['paiement' => 'recu'])->assertSessionHasErrors('etape');
        $this->jusquaA10($d);
        $this->assertSame(0, Action::where('etape', 'A3')->count());
        // Avant la remise au coursier, la vérification du paiement n'est pas encore à faire.
        $this->assertSame('bloquee', app(\App\Services\Circuit::class)->etat($d->fresh())['A3']['statut']);
        $this->en('coursier')->get('/a-faire')->assertOk()->assertDontSee('Vérification du paiement');

        // Remis au coursier (A11) : la vérification du paiement lui revient, avant le transport au CTS (B1).
        $this->etape('inf', $d, 'A11');
        $this->confirmer('coursier', $d, 'A11');
        $this->en('coursier')->get('/a-faire')->assertOk()->assertSee('Vérification du paiement')->assertSee('À faire par : Coursier', false);
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/B1")->assertSessionHasErrors('etape');
        $this->en('coursier')->get('/cts')->assertOk()->assertSee('Vérification du paiement (reçu ou prise en charge)')->assertSee('name="paiement"', false);
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/A3", [])->assertSessionHasErrors('paiement');
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/A3", ['paiement' => 'charge'])->assertSessionHasErrors('paiement_charge')->assertSessionDoesntHaveErrors('paiement_ref');
        $this->etape('coursier', $d, 'A3', ['paiement' => 'charge', 'paiement_charge' => 'medecin', 'paiement_ref' => 'DR ALAOUI']);
        $this->assertSame('coursier', Action::where('etape', 'A3')->firstOrFail()->user_name);
        $this->etape('coursier', $d, 'B1');

        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('À la charge de : Médecin — DR ALAOUI', false)
            ->assertSee('Vérification du paiement (reçu ou prise en charge)');
        $this->en('inf')->get("/demandes/{$d->id}/bon")->assertOk()->assertSee('DR ALAOUI');
        $this->en('inf')->get('/registre')->assertOk()->assertSee('À la charge de : Médecin — DR ALAOUI', false);
        // Ancienne saisie sans le type : affichée « autre ».
        $this->assertSame('À la charge de : autre — CNSS 1', Demande::libellePaiement(['paiement' => 'charge', 'paiement_ref' => 'CNSS 1']));

        // Patient hospitalisé : pas de vérification du paiement, le coursier passe directement au CTS.
        $n = $this->creer(['num_demande_clinisys' => '695', 'num_doss' => '26000695']);
        $this->jusquaA10($n);
        $this->etape('inf', $n, 'A11');
        $this->confirmer('coursier', $n, 'A11');
        $this->etape('coursier', $n, 'B1');
    }

    public function test_poches_au_cts_avec_les_mots_du_diagramme(): void
    {
        $d = $this->creer(['nature' => 'instance']);
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('Poches au CTS')->assertSee("À laisser en instance au CTS (jusqu'au besoin)")->assertDontSee('Nature');
        $this->en('inf')->get("/demandes/{$d->id}/bon")->assertOk()->assertSee('Poches au CTS');
        $this->en('inf')->get('/registre')->assertOk()->assertSee('En instance')->assertSee('Poches au CTS');
        $this->en('inf')->get('/demandes/nouvelle?num_doss=26032758')->assertOk()->assertSee("À ramener directement (dès qu'elles sont prêtes)");
    }

    public function test_suivi_phase_etape_qui_bloque_et_qui_doit_agir(): void
    {
        $suivi = fn (Demande $d) => \App\Services\SuiviDemande::calculer($d->fresh(), app(\App\Services\Circuit::class)->etat($d->fresh()), app(\App\Services\Referentiels::class));

        // Externe : service (préparation), puis coursier (remise, paiement, transport), CTS, clinique, pharmacie.
        $d = $this->creer(['externe' => '1', 'urgence' => 'urgente']);
        $s = $suivi($d);
        $this->assertSame([1, 'faire', ['infirmiere', 'major']], [$s['phase'], $s['type'], $s['roles']]);
        $this->jusquaA10($d);
        $this->assertSame([1, 'A11'], [$suivi($d)['phase'], $suivi($d)['code']]);
        $this->etape('inf', $d, 'A11');
        $s = $suivi($d);
        $this->assertSame([2, 'A11', 'confirmer', ['coursier']], [$s['phase'], $s['code'], $s['type'], $s['roles']]);
        $this->confirmer('coursier', $d, 'A11');
        $s = $suivi($d);
        // Le paiement (A3) est vérifié par le coursier avant le transport au CTS.
        $this->assertSame([2, 'A3', ['coursier']], [$s['phase'], $s['code'], $s['roles']]);
        $this->assertSame('Vérification du paiement (reçu ou prise en charge)', $s['etape']);
        $this->etape('coursier', $d, 'A3', ['paiement' => 'recu']);
        $this->assertSame([2, 'B1'], [$suivi($d)['phase'], $suivi($d)['code']]);
        $this->etape('coursier', $d, 'B1');
        $this->assertSame([3, 'B2'], [$suivi($d)['phase'], $suivi($d)['code']]);

        // Hospitalisé : pas de paiement, transport au CTS juste après la remise.
        $h = $this->creer(['num_demande_clinisys' => '701', 'num_doss' => '26000701', 'urgence' => 'programmee']);
        $this->jusquaA10($h);
        $this->etape('inf', $h, 'A11');
        $this->confirmer('coursier', $h, 'A11');
        $this->assertSame([2, 'B1'], [$suivi($h)['phase'], $suivi($h)['code']]);

        // Retard : vert, orange au-delà du délai d'alerte, rouge au-delà du double (urgente, coursier : délai « cts »).
        $seuil = app(\App\Services\Referentiels::class)->seuil('urgente', 'cts');
        $this->assertSame('vert', $suivi($d)['niveau']);
        $this->travel($seuil + 1)->minutes();
        $this->assertSame('orange', $suivi($d)['niveau']);
        $this->travel($seuil)->minutes();
        $this->assertSame('rouge', $suivi($d)['niveau']);

        // Écran Demandes : frise, étape qui bloque, qui doit agir avec le service ; pastilles avec leur nombre.
        $page = $this->en('surv')->get('/demandes?suivi=toutes')->assertOk()->assertSee('Bloquée à :')->assertSee('À faire par :')
            ->assertSee('Poches prêtes au CTS')->assertSee('En retard <b>1</b>', false)->assertSee('CTS <b>1</b>', false);
        $this->en('surv')->get('/demandes?suivi=retard')->assertOk()->assertSee(route('demandes.show', $d))->assertDontSee(route('demandes.show', $h).'"', false);
        $this->en('surv')->get('/demandes?suivi=phase2')->assertOk()->assertSee(route('demandes.show', $h).'"', false)->assertDontSee(route('demandes.show', $d).'"', false);
        // Coursier (un seul rôle) : « À faire par moi » par défaut.
        $this->en('coursier')->get('/demandes')->assertOk()->assertSee('À faire par moi')
            ->assertSee(route('demandes.show', $h).'"', false)->assertSee(route('demandes.show', $d).'"', false);
        $this->en('inf')->get('/demandes')->assertOk()->assertDontSee(route('demandes.show', $h).'"', false);

        // Fiche : même frise en haut, dans l'en-tête qui reste visible en défilant ; étape enregistrée mise en évidence.
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('entete-fiche', false)->assertSee('Bloquée à :');
        $this->en('coursier')->post("/demandes/{$d->id}/etapes/B2", ['etat_cts' => 'pretes', 'nb_pretes' => 2])->assertRedirect();
        $this->en('coursier')->get("/demandes/{$d->id}")->assertOk()->assertSee('id="etape-ligne-B2"  class="etape-juste-faite"', false);
    }

    public function test_bandeau_mise_a_jour_incomplete_pour_l_administrateur(): void
    {
        // Base à jour (tests) : pas de bandeau.
        $this->en('direction')->get('/demandes')->assertOk()->assertDontSee('Mise à jour incomplète');
        // Une migration pas encore passée : bandeau rouge pour l'administrateur seulement.
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_11_06_000001_cin_patient')->delete();
        \Illuminate\Support\Facades\Cache::forget('installation.problemes');
        $this->en('direction')->get('/demandes')->assertOk()->assertSee('Mise à jour incomplète : lancez mise-a-jour.bat sur le serveur.')
            ->assertSee('des changements de la base ne sont pas encore appliqués');
        $this->en('inf')->get('/demandes')->assertOk()->assertDontSee('Mise à jour incomplète');
    }

    public function test_paiement_deja_saisi_par_l_infirmiere_reste_dans_l_historique(): void
    {
        // Ancienne demande : A3 saisie par l'infirmière avant ce changement. Elle reste faite, le coursier n'a rien à refaire.
        $d = $this->creer(['externe' => '1']);
        Action::create(['demande_id' => $d->id, 'etape' => 'A3', 'user_name' => 'inf', 'nom_acteur' => 'Infirmière', 'fait_le' => now(), 'donnees' => ['paiement' => 'recu', 'paiement_ref' => 'R-123']]);
        $this->jusquaA10($d);
        $this->etape('inf', $d, 'A11');
        $this->confirmer('coursier', $d, 'A11');
        $this->assertSame('faite', app(\App\Services\Circuit::class)->etat($d->fresh())['A3']['statut']);
        $this->etape('coursier', $d, 'B1');
        $this->en('inf')->get("/demandes/{$d->id}")->assertOk()->assertSee('R-123');
    }

    public function test_langue_arabe_choisie_a_la_connexion(): void
    {
        // Français par défaut, inchangé.
        $this->get('/connexion')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Se connecter')->assertSee('العربية');
        // Choix de l'arabe sur la page de connexion : page en arabe, de droite à gauche.
        $this->get('/langue/ar')->assertRedirect();
        $this->get('/connexion')->assertOk()->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');
        // Connexion : la langue est gardée sur le compte et appliquée à l'appli.
        $this->post('/connexion', ['username' => 'coursier', 'password' => '1234', 'langue' => 'ar'])->assertRedirect('/a-faire');
        $this->assertSame('ar', \Illuminate\Support\Facades\DB::table('ds_comptes')->where('user_name', 'coursier')->value('langue'));
        $this->get('/a-faire')->assertOk()->assertSee('dir="rtl"', false)->assertSee('المهام')->assertSee('تسجيل الخروج');
        // Retour au français depuis l'en-tête.
        $this->get('/langue/fr')->assertRedirect();
        $this->get('/a-faire')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Déconnexion');
        $this->assertSame('fr', \Illuminate\Support\Facades\DB::table('ds_comptes')->where('user_name', 'coursier')->value('langue'));
    }

    public function test_connexion_par_code_pin_et_compte_sans_role(): void
    {
        $this->post('/connexion', ['username' => 'coursier', 'password' => '0000'])->assertSessionHasErrors('username');
        $this->post('/connexion', ['username' => 'coursier', 'password' => '1234'])->assertRedirect('/a-faire');
        $this->assertAuthenticated();
    }

    public function test_alerte_remise_non_confirmee_et_admin(): void
    {
        $d = $this->creer(['urgence' => 'vitale']);
        $this->jusquaA10($d);
        $this->etape('inf', $d, 'A11');
        $this->travel(20)->minutes();
        $this->en('surv')->get('/alertes')->assertOk()->assertSee('Remise non confirmée');

        $this->en('direction')->get('/parametrage/comptes')->assertOk();
        $this->en('direction')->post('/parametrage/comptes', ['user_name' => 'coursier2', 'nom' => 'Coursier 2', 'type' => 'local', 'pin' => '5678', 'roles' => ['coursier'], 'actif' => '1'])->assertSessionHasNoErrors();
        $this->en('direction')->post('/parametrage/seuils', ['peremption_jours' => 5, 'seuil' => array_map(fn ($p) => $p, config('demandesang.seuils_defaut'))])->assertSessionHasNoErrors();
        foreach (['/parametrage/services', '/parametrage/seuils', '/parametrage/journal', '/parametrage/comptes/coursier2'] as $url) {
            $this->en('direction')->get($url)->assertOk();
        }
        $this->en('inf')->get('/parametrage/comptes')->assertForbidden();
    }

    public function test_pagination_du_journal_traduite_sans_grande_icone(): void
    {
        $lignes = [];
        for ($i = 0; $i < 120; $i++) {
            $lignes[] = ['user_name' => 'direction', 'action' => 'connexion', 'details' => "essai $i", 'ip' => '127.0.0.1', 'cree_le' => now()->subMinutes($i)];
        }
        \Illuminate\Support\Facades\DB::table('ds_journal')->insert($lignes);

        foreach (['fr' => ['Précédent', 'Suivant', 'Affichage de 1 à 50 sur'], 'ar' => ['السابق', 'التالي', 'عرض 1 إلى 50 من']] as $langue => $textes) {
            $html = $this->en('direction')->withSession(['langue' => $langue])->get('/parametrage/journal')->assertOk()->getContent();
            foreach ($textes as $texte) {
                $this->assertStringContainsString($texte, $html);
            }
            $this->assertStringContainsString('aria-current="page"', $html);
            // Ni clé de traduction brute, ni texte anglais, ni icône SVG (la vue Tailwind de Laravel).
            $this->assertStringNotContainsString('pagination.', $html);
            $this->assertStringNotContainsString('Showing', $html);
            $this->assertDoesNotMatchRegularExpression('/<svg(?![^>]*\bwidth=)/', $html);
            $this->assertStringNotContainsString('viewBox="0 0 20 20"', $html);
            $this->assertDoesNotMatchRegularExpression('/>\s*(validation|auth|passwords)\.[a-z_]+\s*</', $html);
        }
        $this->en('direction')->withSession(['langue' => 'fr'])->get('/parametrage/journal?page=3')->assertOk()->assertSee('Affichage de 101 à');
    }
}
