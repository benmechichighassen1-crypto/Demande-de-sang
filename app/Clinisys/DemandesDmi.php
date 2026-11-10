<?php

namespace App\Clinisys;

use App\Support\Clinisys as C;

/**
 * Demandes de sang saisies dans le DMI Clinisys (table Demande_Sang_Maroc, lecture seule).
 *
 * ID = n° de la demande affiché dans Clinisys ; quantités par produit :
 * GlobuleRouge (CGR), Plasma (PFC), Plaquette (CPS) ; determination1/2 = groupe.
 * Etat : S = supprimée, D = non réceptionnée, V = réceptionnée (config demandesang.etats_dmi).
 */
class DemandesDmi
{
    /** Ordre de priorité quand la demande DMI porte plusieurs produits. */
    public const PRODUITS = ['GlobuleRouge' => 'CGR', 'Plasma' => 'PFC', 'Plaquette' => 'CPS'];

    private const COLONNES = 's.ID, s.NumDoss, s.Diagnostic, s.determination1, s.determination2, s.GlobuleRouge, s.Plaquette, s.Plasma,
        s.dateTransfusionPrevu, s.Medecin_prescripteur, m.NomMed AS medecin, s.user_create, s.date_create, s.Etat';

    public function actif(): bool
    {
        return (bool) config('demandesang.clinisys_actif');
    }

    /** Demande DMI par son n° (ID). */
    public function demande(string $numero): ?object
    {
        $numero = trim($numero);
        if (! $this->actif() || ! ctype_digit($numero) || strlen($numero) > 9) {
            return null;
        }

        return C::db()->selectOne(
            'SELECT TOP 1 '.self::COLONNES.'
             FROM '.C::t('erp', 'dbo.Demande_Sang_Maroc').' s WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = s.Medecin_prescripteur
             WHERE s.ID = ?',
            [(int) $numero]
        );
    }

    /** Demandes DMI du dossier saisies depuis N heures (les plus récentes d'abord). */
    public function recentes(string $numDoss, int $heures = 48): array
    {
        if (! $this->actif() || trim($numDoss) === '') {
            return [];
        }

        return C::db()->select(
            'SELECT TOP 20 '.self::COLONNES.'
             FROM '.C::t('erp', 'dbo.Demande_Sang_Maroc').' s WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = s.Medecin_prescripteur
             WHERE s.NumDoss = ? AND s.date_create >= ?
             ORDER BY s.date_create DESC',
            // Format ISO 8601 : indépendant de la langue / du DATEFORMAT du serveur SQL.
            [trim($numDoss), now()->subHours($heures)->format('Y-m-d\TH:i:s')]
        );
    }

    /**
     * Autres demandes DMI du même patient sur N jours, tous dossiers confondus (même Identifiant
     * dans Client), hors supprimées (S) et hors la demande en cours.
     */
    public function autresDuPatient(array $dossiers, int $jours, ?string $sauf = null): array
    {
        $dossiers = array_values(array_unique(array_filter(array_map('trim', $dossiers))));
        if (! $this->actif() || $dossiers === []) {
            return [];
        }

        return C::db()->select(
            'SELECT TOP 50 '.self::COLONNES.'
             FROM '.C::t('erp', 'dbo.Demande_Sang_Maroc').' s WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = s.Medecin_prescripteur
             WHERE s.NumDoss IN ('.C::in($dossiers).") AND s.Etat <> 'S' AND s.date_create >= ? AND s.ID <> ?
             ORDER BY s.date_create DESC",
            [...$dossiers, now()->subDays($jours)->format('Y-m-d\TH:i:s'), ctype_digit((string) $sauf) ? (int) $sauf : 0]
        );
    }

    /** Toutes les demandes DMI des dossiers donnés (les 50 plus récentes). */
    public function parDossiers(array $dossiers): array
    {
        $dossiers = array_values(array_unique(array_filter(array_map('trim', $dossiers))));
        if (! $this->actif() || $dossiers === []) {
            return [];
        }

        return C::db()->select(
            'SELECT TOP 50 '.self::COLONNES.'
             FROM '.C::t('erp', 'dbo.Demande_Sang_Maroc').' s WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = s.Medecin_prescripteur
             WHERE s.NumDoss IN ('.C::in($dossiers).')
             ORDER BY s.date_create DESC',
            $dossiers
        );
    }

    /**
     * Demandes DMI saisies depuis N heures, tous dossiers (avec le patient et son service de soin) :
     * non réceptionnées (D) par défaut, ou aussi réceptionnées (V) pour repérer celles reçues hors registre.
     */
    public function dernieres(int $heures = 168, array $etats = ['D']): array
    {
        if (! $this->actif()) {
            return [];
        }

        return C::db()->select(
            'SELECT TOP 300 '.self::COLONNES.', c.NomCli, c.Prenom, pe.libelle AS service
             FROM '.C::t('erp', 'dbo.Demande_Sang_Maroc').' s WITH (NOLOCK)
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m WITH (NOLOCK) ON m.CodMed = s.Medecin_prescripteur
             LEFT JOIN '.C::t('erp', 'dbo.Client').' c WITH (NOLOCK) ON c.NumDoss = s.NumDoss
             LEFT JOIN '.C::t('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::t('erp', 'dbo.prmEtage')." pe WITH (NOLOCK) ON pe.etage = ch.Etage
             WHERE s.date_create >= ? AND s.Etat IN (".C::in($etats).')
             ORDER BY s.date_create DESC',
            array_merge([now()->subHours($heures)->format('Y-m-d\TH:i:s')], array_values($etats))
        );
    }

    /**
     * Champs de la demande de l'appli déduits de la demande DMI :
     * produit (le premier demandé), nombre de poches, groupe, diagnostic, médecin,
     * et les autres produits demandés (à noter en commentaire).
     */
    public static function preremplissage(object $dmi): array
    {
        $demandes = [];
        foreach (self::PRODUITS as $colonne => $produit) {
            if ((int) ($dmi->$colonne ?? 0) > 0) {
                $demandes[$produit] = (int) $dmi->$colonne;
            }
        }
        $produit = array_key_first($demandes);
        $autres = array_slice($demandes, 1, null, true);

        $groupe = null;
        foreach ([$dmi->determination1 ?? null, $dmi->determination2 ?? null] as $g) {
            $g = strtoupper(trim((string) $g));
            if (in_array($g, config('demandesang.groupes'), true)) {
                $groupe = $g;
                break;
            }
        }

        $medecin = trim((string) ($dmi->medecin ?? ''));

        return [
            'num_doss' => trim((string) $dmi->NumDoss),
            'produit' => $produit,
            'nb_poches' => $produit ? min($demandes[$produit], 20) : null,
            'groupe_sanguin' => $groupe,
            'diagnostic' => trim((string) ($dmi->Diagnostic ?? '')),
            'medecin' => $medecin !== '' ? $medecin : (trim((string) ($dmi->Medecin_prescripteur ?? '')) ?: null),
            'autres_produits' => $autres,
        ];
    }

    /** « Réceptionnée (V) » */
    public static function libelleEtat(?string $etat): string
    {
        $etat = strtoupper(trim((string) $etat));

        return __(config("demandesang.etats_dmi.$etat") ?? 'État inconnu').($etat !== '' ? " ($etat)" : '');
    }

    public static function supprimee(?object $dmi): bool
    {
        return $dmi !== null && strtoupper(trim((string) $dmi->Etat)) === 'S';
    }

    /** « 10 × CPS, 1 × PFC » pour le commentaire. */
    public static function libelleProduits(array $produits): string
    {
        return implode(', ', array_map(fn ($p, $n) => "$n × $p", array_keys($produits), $produits));
    }
}
