<?php

namespace App\Demo;

use App\Clinisys\Comptes;
use App\Clinisys\DemandesDmi;
use App\Clinisys\Patients;
use App\Clinisys\Pharmacie;
use App\Services\ServicesClinisys;
use Illuminate\Contracts\Foundation\Application;

/**
 * Mode dÃ©monstration (DS_DEMO=true) : Clinisys est remplacÃ© par des donnÃ©es fictives,
 * pour les vidÃ©os et formations. Aucun vrai patient, aucune lecture du serveur Clinisys.
 * Ã€ n'utiliser qu'avec une base de dÃ©monstration sÃ©parÃ©e (ex. database/demo.sqlite).
 */
class ClinisysDemo
{
    /** Patients fictifs. */
    public const PATIENTS = [
        '90000101' => ['I9000101', 'BENALI', 'Samira', 'REANIMATION', 'RE', 'RE02'],
        '90000102' => ['I9000102', 'MOUSSAOUI', 'Karim', 'HOSPITALISATION 2 EME ETAGE', '02', '204'],
        '90000103' => ['I9000103', 'EL AMRANI', 'Nadia', 'URGENCE', 'UR', 'UR01'],
    ];

    /** Demandes DMI fictives (nÂ° => dossier, CGR, plaquettes, PFC, diagnostic, groupe, heures passÃ©es). */
    public const DEMANDES = [
        2001 => ['90000101', 2, 0, 0, 'ANEMIE SEVERE SUR HEMORRAGIE DIGESTIVE', 'O+', 1],
        2002 => ['90000102', 3, 0, 0, 'PREPARATION CHIRURGIE ORTHOPEDIQUE', 'A+', 2],
        2003 => ['90000103', 0, 0, 2, 'TROUBLE DE LA COAGULATION', 'B+', 3],
    ];

    public static function enregistrer(Application $app): void
    {
        $app->instance(Patients::class, new class extends Patients {
            public function actif(): bool { return true; }

            public function dossier(string $numDoss): ?object
            {
                return ClinisysDemo::patient($numDoss);
            }

            public function chercher(string $q): array
            {
                return $this->chercherAdmissions($q);
            }

            public function chercherPatients(string $q, int $limite = 30): array
            {
                return array_map(fn ($p) => (object) ['Identifiant' => $p->Identifiant, 'NomCli' => $p->NomCli, 'Prenom' => $p->Prenom,
                    'dossiers' => [$p->NumDoss], 'service' => $p->service, 'DatArr' => $p->DatArr], $this->chercherAdmissions($q));
            }

            public function dossiersDuPatient(?string $identifiant): array
            {
                return array_map('strval', array_values(array_filter(array_keys(ClinisysDemo::PATIENTS), fn ($d) => ClinisysDemo::PATIENTS[$d][0] === $identifiant)));
            }

            private function chercherAdmissions(string $q): array
            {
                $q = mb_strtoupper(trim($q));

                return array_values(array_filter(array_map(fn ($d) => ClinisysDemo::patient($d), array_keys(ClinisysDemo::PATIENTS)),
                    fn ($p) => $q !== '' && ($p->NumDoss === $q || $p->Identifiant === $q || str_starts_with($p->NomCli.' '.$p->Prenom, $q) || str_starts_with(mb_strtoupper($p->Prenom), $q))));
            }
        });

        $app->instance(DemandesDmi::class, new class extends DemandesDmi {
            public function actif(): bool { return true; }

            public function demande(string $numero): ?object { return ClinisysDemo::dmi((int) $numero); }

            public function recentes(string $numDoss, int $heures = 48): array { return ClinisysDemo::dmiDe([$numDoss]); }

            public function parDossiers(array $dossiers): array { return ClinisysDemo::dmiDe($dossiers); }

            public function autresDuPatient(array $dossiers, int $jours, ?string $sauf = null): array
            {
                return array_values(array_filter(ClinisysDemo::dmiDe($dossiers), fn ($x) => (string) $x->ID !== (string) $sauf));
            }

            public function dernieres(int $heures = 168, array $etats = ['D']): array
            {
                return array_values(array_filter(array_map(fn ($x) => ClinisysDemo::avecPatient($x), ClinisysDemo::dmiDe(array_keys(ClinisysDemo::PATIENTS))),
                    fn ($x) => in_array($x->Etat, $etats, true)));
            }
        });

        $app->instance(Pharmacie::class, new class extends Pharmacie {
            public function branche(): bool { return true; }

            public function libelleDepot(string $code = self::DEPOT): string { return 'Banque de sang'; }

            // Demande 2001 : bon de dépôt et sortie patient fictifs (étapes C5 et C8 automatiques).
            public function bonsDepot(string $numDemande): array
            {
                return $numDemande === '2001' ? [(object) ['numbon' => 'BDBD990010', 'numaffiche' => 'BD990010', 'datbon' => now()->toDateString(),
                    'datesys' => now()->toDateString(), 'heuresys' => '1900-01-01 '.now()->format('H:i:s'), 'Reffrs' => '', 'memop' => 'O+', 'coddep' => 'BS',
                    'codvend' => 'pharmacie.demo', 'numDemandeS' => '2001', 'poches' => [
                        (object) ['numbon' => 'BDBD990010', 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'Identifiant' => 'DEMO-0101', 'NumSerie' => '', 'lot' => '', 'DatPer' => now()->addDays(30)->toDateString()],
                        (object) ['numbon' => 'BDBD990010', 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1, 'Identifiant' => 'DEMO-0102', 'NumSerie' => '', 'lot' => '', 'DatPer' => now()->addDays(30)->toDateString()],
                    ]]] : [];
            }

            public function bonsDepotPour(array $numeros): array
            {
                return in_array('2001', array_map('strval', $numeros), true) ? ['2001' => $this->bonsDepot('2001')] : [];
            }

            public function sortiesPourBons(string $numDoss, array $numerosBd): array
            {
                return in_array('BD990010', $numerosBd, true) ? array_map(fn ($n) => (object) ['numbon' => 'FAFBS99001', 'numaffiche' => 'FBS99001',
                    'datesys' => now()->toDateString(), 'heuresys' => '1970-01-01 '.now()->format('H:i:s'), 'codvend' => 'pharmacie.demo', 'numdoss' => $numDoss,
                    'NumBon_Depot' => 'BD990010', 'reference' => $n, 'codart' => 'F1900001', 'desart' => 'CULOT GLOBULAIRE 1', 'quantite' => 1], ['DEMO-0101', 'DEMO-0102']) : [];
            }

            public function prelevements(array $numerosBd): array { return []; }

            // Demande 2001 : deux poches fictives délivrées au service.
            public function lieuxDesPoches(array $numeros): array
            {
                return in_array('2001', array_map('strval', $numeros), true) ? ['2001' => array_map(fn ($n) => (object) ['numero' => $n, 'desart' => 'CULOT GLOBULAIRE 1',
                    'DatPer' => now()->addDays(30)->toDateString(), 'bon_bd' => 'BD990010', 'date_bd' => now()->toDateString(), 'memop' => 'O+', 'numDemande' => '2001',
                    'pa_bon' => 'FBS99001', 'pa_date' => now()->toDateString(), 'pa_service' => 'HOSPITALISATION DÉMO', 'pr_bon' => null, 'pr_date' => null, 'en_stock' => 0], ['DEMO-0101', 'DEMO-0102'])] : [];
            }

            public function panier(string $numDemande): array { return []; }

            public function pochesParDemande(array $numeros): array { return []; }

            public function sortiesPatient(array $dossiers, int $jours): array { return []; }

            public function stockBS(?string $numDoss = null): array
            {
                return array_values(array_filter(ClinisysDemo::stock(), fn ($p) => $numDoss === null || $p->NumDoss === $numDoss));
            }

            // Mouvements fictifs de la période : réceptions = poches du stock fictif, une sortie patient, aucun prélèvement.
            public function lignesMouvements(string $type, ?\DateTimeInterface $du, ?\DateTimeInterface $au, ?string $numero = null): array
            {
                $ligne = fn ($p, string $bon, ?string $origine, ?string $doss) => (object) ['numero' => $p->Identifiant, 'desart' => $p->desart, 'quantite' => 1,
                    'DatPer' => $p->DatPer, 'bon' => $bon, 'datesys' => now()->subDays(3)->toDateString(), 'heuresys' => '1900-01-01 10:30:00', 'bon_origine' => $origine,
                    'memop' => $p->memop, 'numDemande' => null, 'NumDoss' => $doss, 'NomCli' => $doss ? 'PATIENT' : null, 'Prenom' => $doss ? 'Démo' : null,
                    'service' => $doss ? 'HOSPITALISATION DÉMO' : null];

                return match ($type) {
                    'BD' => array_map(fn ($p) => $ligne($p, $p->bon_depot, null, null), ClinisysDemo::stock()),
                    'PA' => [$ligne(ClinisysDemo::stock()[0], 'FBS99001', 'BD990001', '99000001')],
                    default => [],
                };
            }

            public function mouvements(\DateTimeInterface $du, \DateTimeInterface $au): ?object
            {
                $s = collect(ClinisysDemo::stock());

                return (object) ['receptions' => 24, 'sorties' => 19, 'prelevements' => 3,
                    'en_stock' => $s->where('perimee', 0)->count(), 'perimees' => $s->where('perimee', 1)->count()];
            }
        });

        $app->instance(\App\Clinisys\Medecins::class, new class extends \App\Clinisys\Medecins {
            public function noms(): array { return ['DR ALAOUI DEMO', 'DR BENNANI DEMO', 'DR CHRAIBI DEMO']; }
        });

        $app->instance(Comptes::class, new class extends Comptes {
            public function actifs(): array
            {
                return [(object) ['UserName' => 'DEMO.INF', 'Description' => 'INFIRMIERE DEMO'], (object) ['UserName' => 'DEMO.MAJOR', 'Description' => 'MAJOR DEMO']];
            }
        });

        $app->instance(ServicesClinisys::class, new class extends ServicesClinisys {
            public function liste(): array
            {
                return ['02' => 'HOSPITALISATION 2 EME ETAGE', '03' => 'HOSPITALISATION 3 EME ETAGE', 'RE' => 'REANIMATION', 'UR' => 'URGENCE', 'C1' => 'CONSULTATION 1 ER ETAGE'];
            }
        });
    }

    public static function patient(string $numDoss): ?object
    {
        $p = self::PATIENTS[trim($numDoss)] ?? null;

        return $p ? (object) ['NumDoss' => trim($numDoss), 'Identifiant' => $p[0], 'NomCli' => $p[1], 'Prenom' => $p[2], 'NumCIN' => 'ZZ'.substr(preg_replace('/\D/', '', trim($numDoss)), -6), 'service' => $p[3],
            'service_code' => $p[4], 'NumCha' => $p[5], 'reception' => 'PRINCIPALE', 'medecin' => 'DR DEMO', 'DatArr' => now()->subDays(2)->toDateString(),
            'DatNai' => '1970-01-01', 'DatDep' => null] : null;
    }

    public static function dmi(int $id): ?object
    {
        $d = self::DEMANDES[$id] ?? null;

        return $d ? (object) ['ID' => $id, 'NumDoss' => $d[0], 'GlobuleRouge' => $d[1], 'Plaquette' => $d[2], 'Plasma' => $d[3], 'Diagnostic' => $d[4],
            'determination1' => $d[5], 'determination2' => $d[5], 'dateTransfusionPrevu' => null, 'Medecin_prescripteur' => 'M0001',
            'medecin' => 'DR DEMO', 'user_create' => 'demo', 'date_create' => now()->subHours($d[6])->format('Y-m-d H:i:s'), 'Etat' => 'D'] : null;
    }

    public static function dmiDe(array $dossiers): array
    {
        return array_values(array_filter(array_map(fn ($id) => self::dmi($id), array_keys(self::DEMANDES)), fn ($x) => in_array($x->NumDoss, array_map('strval', $dossiers), true)));
    }

    public static function avecPatient(object $x): object
    {
        $p = self::patient($x->NumDoss);
        $x->NomCli = $p->NomCli;
        $x->Prenom = $p->Prenom;
        $x->service = $p->service;

        return $x;
    }

    /** Stock fictif du dÃ©pÃ´t banque de sang (6 poches dont 1 pÃ©rimÃ©e), sans patient rÃ©el. */
    public static function stock(): array
    {
        $l = [
            ['F1900001', 'CULOT GLOBULAIRE 1', 'DEMO-0001', 20, 'BD990001', 'O+'],
            ['F1900001', 'CULOT GLOBULAIRE 1', 'DEMO-0002', 12, 'BD990001', 'O+'],
            ['F1900002', 'CULOT GLOBULAIRE 2', 'DEMO-0003', 25, 'BD990002', 'A+'],
            ['F2000003', 'PFC 3', 'DEMO-0004', 180, 'BD990003', 'B+'],
            ['F2000004', 'PFC 4', 'DEMO-0005', 150, 'BD990003', 'AB+'],
            ['F1900002', 'CULOT GLOBULAIRE 2', 'DEMO-0007', 3, 'BD990002', 'A+'],
            ['F1900001', 'CULOT GLOBULAIRE 1', 'DEMO-0006', -5, 'BD990000', 'O-'],
        ];

        return array_map(fn ($x, $i) => (object) ['Num' => $i + 1, 'codart' => $x[0], 'desart' => $x[1], 'qte' => 1, 'Identifiant' => $x[2], 'Reference' => $x[2],
            'DatPer' => now()->addDays($x[3])->format('Y-m-d 00:00:00'), 'NumBon' => 'BD'.$x[4], 'bon_depot' => $x[4], 'datbon' => now()->subDays(3)->toDateString(),
            'memop' => $x[5], 'NumDoss' => null, 'numDemande' => null, 'source_patient' => null, 'NomCli' => null, 'Prenom' => null,
            'perimee' => $x[3] <= 0 ? 1 : 0], $l, array_keys($l));
    }
}
