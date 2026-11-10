<?php

namespace App\Clinisys;

use App\Support\Clinisys as C;

/**
 * Patients et admissions lus dans Clinisys (base ERP, lecture seule).
 * Mêmes tables que l'appli oncologie : Client, chambre, Reception, Medecin.
 * Service de soin = étage de la chambre (chambre.Etage → prmEtage.libelle, ex. « REANIMATION ») ;
 * Reception.LibRecep n'est que la réception d'admission (presque toujours « PRINCIPALE »).
 */
class Patients
{
    public function actif(): bool
    {
        return (bool) config('demandesang.clinisys_actif');
    }

    /** Admission par n° de dossier, avec le service de soin, la réception d'admission et le médecin traitant. */
    public function dossier(string $numDoss): ?object
    {
        if (! $this->actif()) {
            return null;
        }

        return C::db()->selectOne(
            'SELECT TOP 1 c.NumDoss, c.Identifiant, c.NomCli, c.Prenom, c.NumCIN, c.DatNai, c.DatArr, c.DatDep, c.NumCha,
                    pe.libelle AS service, ch.Etage AS service_code, r.LibRecep AS reception, m.NomMed AS medecin
             FROM '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::t('erp', 'dbo.prmEtage').' pe WITH (NOLOCK) ON pe.etage = ch.Etage
             LEFT JOIN '.C::t('erp', 'dbo.Reception').' r WITH (NOLOCK) ON r.NumSer = ch.NumSer
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = c.MedTrait
             WHERE c.NumDoss = ?',
            [$numDoss]
        );
    }

    /** N° de dossier de toutes les admissions d'un même patient (même Identifiant), les plus récentes d'abord. */
    public function dossiersDuPatient(?string $identifiant): array
    {
        $identifiant = trim((string) $identifiant);
        if (! $this->actif() || $identifiant === '') {
            return [];
        }

        return array_map(fn ($c) => trim((string) $c->NumDoss), C::db()->select(
            'SELECT TOP 20 c.NumDoss FROM '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK) WHERE c.Identifiant = ? ORDER BY c.DatArr DESC',
            [$identifiant]
        ));
    }

    /**
     * Recherche de patients pour une nouvelle demande : n° de dossier ou identifiant exact, ou
     * début du nom / prénom (« KHAR », « KHARBACH NA »). Admissions les plus récentes d'abord,
     * regroupées par patient (identifiant) : dossiers récents, service de soin, dernière arrivée.
     *
     * @return array<int, object{Identifiant: string, NomCli: string, Prenom: string, dossiers: string[], service: ?string, DatArr: ?string}>
     */
    public function chercherPatients(string $q, int $limite = 30): array
    {
        $q = trim(preg_replace('/\s+/', ' ', $q));
        if (! $this->actif() || mb_strlen($q) < 2) {
            return [];
        }
        // Les jokers LIKE tapés par l'utilisateur sont pris littéralement.
        $debut = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q).'%';

        $admissions = C::db()->select(
            'SELECT TOP 60 c.NumDoss, c.Identifiant, c.NomCli, c.Prenom, c.NumCIN, c.DatArr, pe.libelle AS service
             FROM '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::t('erp', 'dbo.prmEtage')." pe WITH (NOLOCK) ON pe.etage = ch.Etage
             WHERE c.NumDoss = ? OR c.Identifiant = ? OR c.NumCIN = ? OR c.NomCli LIKE ? OR c.Prenom LIKE ?
                OR c.NomCli + ' ' + c.Prenom LIKE ? OR c.Prenom + ' ' + c.NomCli LIKE ?
             ORDER BY c.DatArr DESC",
            [$q, $q, $q, $debut, $debut, $debut, $debut]
        );

        $patients = [];
        foreach ($admissions as $a) {
            $cle = trim((string) $a->Identifiant) ?: 'dossier:'.trim((string) $a->NumDoss);
            if (! isset($patients[$cle])) {
                if (count($patients) >= $limite) {
                    continue;
                }
                $patients[$cle] = (object) ['Identifiant' => trim((string) $a->Identifiant), 'NomCli' => trim((string) $a->NomCli),
                    'Prenom' => trim((string) $a->Prenom), 'cin' => strtoupper(trim((string) ($a->NumCIN ?? ''))), 'dossiers' => [], 'service' => $a->service ? trim($a->service) : null, 'DatArr' => $a->DatArr];
            }
            $patients[$cle]->dossiers[] = trim((string) $a->NumDoss);
        }

        return array_values($patients);
    }

    /** N° CIN par n° de dossier (Client.NumCIN), par lots : [n° dossier => CIN en majuscules], CIN vides ignorés. */
    public function cinParDossiers(array $dossiers): array
    {
        $dossiers = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $dossiers))));
        if (! $this->actif() || $dossiers === []) {
            return [];
        }
        $cins = [];
        foreach (array_chunk($dossiers, 200) as $lot) {
            foreach (C::db()->select('SELECT NumDoss, NumCIN FROM '.C::t('erp', 'dbo.Client').' WITH (NOLOCK) WHERE NumDoss IN ('.C::in($lot).')', $lot) as $l) {
                $cin = strtoupper(trim((string) $l->NumCIN));
                if ($cin !== '') {
                    $cins[trim((string) $l->NumDoss)] = $cin;
                }
            }
        }

        return $cins;
    }

    /** N° de dossier des admissions d'un patient d'après son n° CIN (recherche Stock). */
    public function dossiersParCin(string $cin): array
    {
        $cin = strtoupper(trim($cin));
        if (! $this->actif() || $cin === '') {
            return [];
        }

        return array_map(fn ($c) => trim((string) $c->NumDoss), C::db()->select(
            'SELECT TOP 20 c.NumDoss FROM '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK) WHERE c.NumCIN = ? ORDER BY c.DatArr DESC', [$cin]
        ));
    }

    /** Recherche par nom, identifiant ou n° de dossier (100 admissions les plus récentes). */
    public function chercher(string $q): array
    {
        if (! $this->actif() || mb_strlen($q) < 3) {
            return [];
        }

        return C::db()->select(
            'SELECT TOP 100 c.NumDoss, c.Identifiant, c.NomCli, c.Prenom, c.DatNai, c.DatArr,
                    pe.libelle AS service, ch.Etage AS service_code, r.LibRecep AS reception
             FROM '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::t('erp', 'dbo.prmEtage').' pe WITH (NOLOCK) ON pe.etage = ch.Etage
             LEFT JOIN '.C::t('erp', 'dbo.Reception')." r WITH (NOLOCK) ON r.NumSer = ch.NumSer
             WHERE c.NumDoss = ? OR c.Identifiant = ?
                OR c.NomCli + ' ' + c.Prenom LIKE ? OR c.Prenom + ' ' + c.NomCli LIKE ?
             ORDER BY c.DatArr DESC",
            [$q, $q, "%$q%", "%$q%"]
        );
    }
}
