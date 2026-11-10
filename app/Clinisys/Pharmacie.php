<?php

namespace App\Clinisys;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;

/**
 * Lecture du module pharmacie de Clinisys, dépôt « Banque de sang » (lecture seule).
 *
 * Tables (base Gclinique_Maroc sur le serveur de test) :
 *  - FactureBD_PR : en-têtes des bons de dépôt FRS (typbon BD, numbon « BDBD261456 »,
 *    numaffiche « BD261456 ») et des bons de prélèvement FRS (typbon PR) ;
 *    numDemandeS = n° de la demande DMI, memop = groupe sanguin, Reffrs = réf. fournisseur ;
 *  - MvtStoBD_PR : lignes des bons ; Identifiant = n° de poche ; pour un PR,
 *    NumBon_Depot = n° affiché du BD d'origine ;
 *  - Panier_Depot_Frs : panier dépôt fournisseur (NumDoss, numDemande, numBon_depot, Identifiant) ;
 *  - DepSto : stock par dépôt (coddep « BS ») ; NumBon = numbon du BD d'origine.
 * FactureBD_PR.numdoss est vide pour la banque de sang : le patient se retrouve par le panier.
 */
class Pharmacie
{
    public const DEPOT = 'BS';

    public function branche(): bool
    {
        return (bool) config('demandesang.clinisys_actif');
    }

    /** Désignation du dépôt dans Clinisys (Depot.desdep, « Banque de sang » pour BS), gardée 1 h en cache. */
    public function libelleDepot(string $code = self::DEPOT): string
    {
        if (! $this->branche() || ! preg_match('/^[A-Z0-9]{1,3}$/', $code)) {
            return $code;
        }

        return Cache::remember("clinisys.depot.$code", 3600, fn () => trim((string) (C::distant(
            'SELECT desdep FROM '.C::local('pharmacie', 'dbo.Depot')." WITH (NOLOCK) WHERE coddep = '$code'"
        )[0]->desdep ?? '')) ?: $code);
    }

    /** Bons de dépôt FRS (BD) d'une demande DMI, avec leurs poches. */
    public function bonsDepot(string $numDemande): array
    {
        if (! $this->branche() || ! $this->numeroValide($numDemande)) {
            return [];
        }

        $bons = C::db()->select(
            "SELECT f.numbon, f.numaffiche, f.datbon, f.datesys, f.heuresys, f.Reffrs, f.memop, f.coddep, f.codvend, f.numDemandeS
             FROM ".C::t('pharmacie', 'dbo.FactureBD_PR')." f WITH (NOLOCK)
             WHERE f.typbon = 'BD' AND f.numDemandeS = ?
             ORDER BY f.datbon, f.numbon",
            [trim($numDemande)]
        );

        return $this->avecLignes($bons);
    }

    /** Bons de dépôt FRS (BD) de plusieurs demandes DMI, en deux lectures : [n° demande => bons, avec leurs poches]. */
    public function bonsDepotPour(array $numeros): array
    {
        $numeros = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $numeros), fn ($n) => $this->numeroValide($n))));
        if (! $this->branche() || $numeros === []) {
            return [];
        }
        $bons = C::db()->select(
            "SELECT f.numbon, f.numaffiche, f.datbon, f.datesys, f.heuresys, f.Reffrs, f.memop, f.coddep, f.codvend, f.numDemandeS
             FROM ".C::t('pharmacie', 'dbo.FactureBD_PR')." f WITH (NOLOCK)
             WHERE f.typbon = 'BD' AND f.numDemandeS IN (".C::in($numeros).')
             ORDER BY f.datbon, f.numbon',
            $numeros
        );

        return collect($this->avecLignes($bons))->groupBy(fn ($b) => trim((string) $b->numDemandeS))->map->all()->all();
    }

    /** Bons de prélèvement FRS (PR) qui reprennent des poches des BD donnés (n° affichés « BD… »). */
    public function prelevements(array $numerosBd): array
    {
        $numerosBd = array_values(array_filter(array_map('trim', $numerosBd)));
        if (! $this->branche() || $numerosBd === []) {
            return [];
        }

        return array_map([$this, 'nettoyer'], C::db()->select(
            "SELECT m.numbon, m.numaffiche, m.NumBon_Depot, m.Identifiant, m.codart, m.desart, m.quantite, m.DatPer,
                    f.datbon, f.datesys, f.heuresys, f.Reffrs, f.codvend
             FROM ".C::t('pharmacie', 'dbo.MvtStoBD_PR').' m WITH (NOLOCK)
             JOIN '.C::t('pharmacie', 'dbo.FactureBD_PR')." f WITH (NOLOCK) ON f.numbon = m.numbon
             WHERE m.typbon = 'PR' AND m.NumBon_Depot IN (".C::in($numerosBd).')
             ORDER BY f.datbon, m.numbon',
            $numerosBd
        ));
    }

    /**
     * Demande DMI déjà reçue en pharmacie : état V dans le DMI, ou bon de dépôt BD saisi pour elle
     * (le DMI reste souvent en D après la saisie du BD).
     */
    public function recueEnPharmacie(object $demandeDmi): bool
    {
        return trim((string) ($demandeDmi->Etat ?? '')) === 'V'
            || ($this->branche() && (C::essayer(fn () => $this->pochesParDemande([(string) $demandeDmi->ID]), [])[(string) $demandeDmi->ID] ?? 0) > 0);
    }

    /**
     * Poches reçues par demande DMI d'après les bons de dépôt FRS (lignes BD dont
     * numDemandeS = n° de demande) : [n° demande => nombre de poches].
     */
    public function pochesParDemande(array $numeros): array
    {
        $numeros = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $numeros), 'ctype_digit')));
        if (! $this->branche() || $numeros === []) {
            return [];
        }
        $lignes = C::db()->select(
            "SELECT f.numDemandeS AS numero, SUM(m.quantite) AS poches
             FROM ".C::t('pharmacie', 'dbo.FactureBD_PR').' f WITH (NOLOCK)
             JOIN '.C::t('pharmacie', 'dbo.MvtStoBD_PR')." m WITH (NOLOCK) ON m.numbon = f.numbon
             WHERE f.typbon = 'BD' AND f.numDemandeS IN (".C::in($numeros).')
             GROUP BY f.numDemandeS',
            $numeros
        );

        return collect($lignes)->mapWithKeys(fn ($l) => [trim((string) $l->numero) => (int) $l->poches])->all();
    }

    /**
     * Où est chaque poche reçue pour ces demandes DMI (une seule lecture pour toute la liste) :
     * [n° demande => [poche, …]] ; poche = n° (Identifiant), désignation, groupe, péremption, bon BD et sa date,
     * puis sa sortie patient (bon PA, date, service du patient), son prélèvement fournisseur (bon PR, date)
     * et en_stock = encore dans le dépôt BS.
     */
    public function lieuxDesPoches(array $numeros): array
    {
        $numeros = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $numeros), fn ($n) => $this->numeroValide($n))));
        if (! $this->branche() || $numeros === []) {
            return [];
        }
        // N° de demande : chiffres seulement (contrôlés ci-dessus), sûrs à injecter (OPENQUERY n'accepte pas de paramètres).
        $liste = "'".implode("', '", $numeros)."'";
        $lignes = C::distant(
            "SELECT m.Identifiant AS numero, m.desart, m.DatPer, f.numaffiche AS bon_bd, f.datbon AS date_bd, f.memop, f.numDemandeS AS numDemande,
                    pa.bon AS pa_bon, pa.datesys AS pa_date, pa.service AS pa_service, pr.bon AS pr_bon, pr.datbon AS pr_date,
                    CASE WHEN st.Num IS NULL THEN 0 ELSE 1 END AS en_stock
             FROM ".C::local('pharmacie', 'dbo.MvtStoBD_PR').' m WITH (NOLOCK)
             JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR').' f WITH (NOLOCK) ON f.numbon = m.numbon
             OUTER APPLY (SELECT TOP 1 fa.numaffiche AS bon, fa.datesys, pe.libelle AS service
                          FROM '.C::local('pharmacie', 'dbo.mvtsto').' ma WITH (NOLOCK)
                          JOIN '.C::local('pharmacie', 'dbo.facture').' fa WITH (NOLOCK) ON fa.numbon = ma.numbon
                          LEFT JOIN '.C::local('erp', 'dbo.Client').' c WITH (NOLOCK) ON c.NumDoss = fa.numdoss
                          LEFT JOIN '.C::local('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
                          LEFT JOIN '.C::local('erp', 'dbo.prmEtage')." pe WITH (NOLOCK) ON pe.etage = ch.Etage
                          WHERE fa.typbon = 'PA' AND ma.reference = m.Identifiant AND ma.NumBon_Depot = f.numaffiche ORDER BY fa.datesys DESC) pa
             OUTER APPLY (SELECT TOP 1 fp.numaffiche AS bon, fp.datbon
                          FROM ".C::local('pharmacie', 'dbo.MvtStoBD_PR').' mp WITH (NOLOCK)
                          JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR')." fp WITH (NOLOCK) ON fp.numbon = mp.numbon
                          WHERE mp.typbon = 'PR' AND mp.Identifiant = m.Identifiant AND mp.NumBon_Depot = f.numaffiche) pr
             OUTER APPLY (SELECT TOP 1 d.Num FROM ".C::local('pharmacie', 'dbo.DepSto')." d WITH (NOLOCK)
                          WHERE d.coddep = '".self::DEPOT."' AND d.Identifiant = m.Identifiant AND d.NumBon = m.numbon AND d.qte > 0) st
             WHERE m.typbon = 'BD' AND m.coddep = '".self::DEPOT."' AND f.numDemandeS IN ($liste)
             ORDER BY f.datbon, m.Identifiant"
        );

        return collect(array_map([$this, 'nettoyer'], $lignes))->groupBy(fn ($l) => (string) $l->numDemande)->map->all()->all();
    }

    /**
     * Poches sorties du dépôt BS vers ces dossiers depuis N jours (bons PA « FBS… ») :
     * délivrées au patient, peut-être pas encore transfusées.
     */
    public function sortiesPatient(array $dossiers, int $jours): array
    {
        $dossiers = array_values(array_unique(array_filter(array_map('trim', $dossiers))));
        if (! $this->branche() || $dossiers === []) {
            return [];
        }

        return array_map([$this, 'nettoyer'], C::db()->select(
            "SELECT TOP 50 f.numaffiche, f.datesys, f.numdoss, m.codart, m.desart, m.quantite, m.NumBon_Depot
             FROM ".C::t('pharmacie', 'dbo.facture').' f WITH (NOLOCK)
             JOIN '.C::t('pharmacie', 'dbo.mvtsto')." m WITH (NOLOCK) ON m.numbon = f.numbon
             WHERE f.typbon = 'PA' AND f.coddep = '".self::DEPOT."' AND f.numdoss IN (".C::in($dossiers).') AND f.datesys >= ?
             ORDER BY f.datesys DESC',
            [...$dossiers, now()->subDays($jours)->startOfDay()->format('Y-m-d\TH:i:s')]
        ));
    }

    /**
     * Sorties patient (bons PA « FBS… ») d'un dossier pour les poches de ces bons de dépôt (n° affichés BD…) :
     * une ligne par poche (reference = n° de poche), avec la date et l'heure du bon.
     */
    public function sortiesPourBons(string $numDoss, array $numerosBd): array
    {
        $numerosBd = array_values(array_filter(array_map('trim', $numerosBd)));
        if (! $this->branche() || $numerosBd === [] || trim($numDoss) === '') {
            return [];
        }

        return array_map([$this, 'nettoyer'], C::db()->select(
            "SELECT f.numbon, f.numaffiche, f.datesys, f.heuresys, f.codvend, f.numdoss, m.NumBon_Depot, m.reference, m.codart, m.desart, m.quantite
             FROM ".C::t('pharmacie', 'dbo.facture').' f WITH (NOLOCK)
             JOIN '.C::t('pharmacie', 'dbo.mvtsto')." m WITH (NOLOCK) ON m.numbon = f.numbon
             WHERE f.typbon = 'PA' AND f.coddep = '".self::DEPOT."' AND f.numdoss = ? AND m.NumBon_Depot IN (".C::in($numerosBd).')
             ORDER BY f.datesys, f.heuresys',
            [trim($numDoss), ...$numerosBd]
        ));
    }

    /** Date d'un bon Clinisys + heure (heuresys, stockée sur une date fictive 1900 / 1970). */
    public static function horodatage(object $bon): \Illuminate\Support\Carbon
    {
        $jour = \Illuminate\Support\Carbon::parse($bon->datesys ?? $bon->datbon ?? now());
        $heure = ! empty($bon->heuresys) ? \Illuminate\Support\Carbon::parse($bon->heuresys)->format('H:i:s') : '00:00:00';

        return \Illuminate\Support\Carbon::parse($jour->format('Y-m-d').' '.$heure);
    }

    /** Lignes du panier dépôt fournisseur d'une demande DMI. */
    public function panier(string $numDemande): array
    {
        if (! $this->branche() || ! $this->numeroValide($numDemande)) {
            return [];
        }

        return array_map([$this, 'nettoyer'], C::db()->select(
            'SELECT p.Numero, p.DateSys, p.UserName, p.NumDoss, p.CodDep, p.CodArt, s.desart, p.Identifiant, p.DatPer,
                    p.Qte, p.Etat, p.NumQuittance, p.numDemande, p.numBon_depot
             FROM '.C::t('pharmacie', 'dbo.Panier_Depot_Frs').' p WITH (NOLOCK)
             LEFT JOIN '.C::t('pharmacie', 'dbo.Stock').' s WITH (NOLOCK) ON s.codart = p.CodArt
             WHERE p.numDemande = ?
             ORDER BY p.Numero',
            [(int) $numDemande]
        ));
    }

    /**
     * Stock du dépôt Banque de sang (qte > 0), avec le patient et la demande DMI
     * retrouvés par le panier dépôt (même BD et même poche) quand ils existent.
     * Exécuté sur le serveur Clinisys (OPENQUERY via le serveur lié) : les jointures
     * se font là-bas ; seules des valeurs validées sont injectées dans le SQL.
     */
    public function stockBS(?string $numDoss = null): array
    {
        if (! $this->branche()) {
            return [];
        }
        $filtre = '';
        if ($numDoss !== null) {
            if (! $this->numeroValide($numDoss)) {
                return [];
            }
            $filtre = " AND a.NumDoss = '".trim($numDoss)."'";
        }

        return array_map([$this, 'nettoyer'], C::distant(
            // Patient : panier dépôt (même BD et même poche), sinon la demande DMI du bon BD (numDemandeS).
            // Périmée comme dans l'état Clinisys : péremption <= aujourd'hui.
            'SELECT d.Num, d.codart, s.desart, d.qte, d.Identifiant, d.Reference, d.DatPer, d.NumBon,
                    f.numaffiche AS bon_depot, f.datbon, f.memop,
                    a.NumDoss, a.numDemande, a.source_patient, c.NomCli, c.Prenom, pe.libelle AS service,
                    CASE WHEN d.DatPer <= CAST(GETDATE() AS date) THEN 1 ELSE 0 END AS perimee
             FROM '.C::local('pharmacie', 'dbo.DepSto').' d WITH (NOLOCK)
             LEFT JOIN '.C::local('pharmacie', 'dbo.Stock').' s WITH (NOLOCK) ON s.codart = d.codart
             LEFT JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR').' f WITH (NOLOCK) ON f.numbon = d.NumBon
             OUTER APPLY (SELECT TOP 1 pp.NumDoss, pp.numDemande FROM '.C::local('pharmacie', 'dbo.Panier_Depot_Frs').' pp WITH (NOLOCK)
                          WHERE pp.numBon_depot = d.NumBon AND pp.Identifiant = d.Identifiant ORDER BY pp.Numero DESC) p
             LEFT JOIN '.C::local('erp', 'dbo.Demande_Sang_Maroc').' ds WITH (NOLOCK) ON p.NumDoss IS NULL AND ds.ID = TRY_CAST(f.numDemandeS AS int)
             CROSS APPLY (SELECT COALESCE(p.NumDoss, ds.NumDoss) AS NumDoss, COALESCE(p.numDemande, ds.ID) AS numDemande,
                                 CASE WHEN p.NumDoss IS NOT NULL THEN \'panier\' WHEN ds.NumDoss IS NOT NULL THEN \'bon BD\' END AS source_patient) a
             LEFT JOIN '.C::local('erp', 'dbo.Client').' c WITH (NOLOCK) ON c.NumDoss = a.NumDoss
             LEFT JOIN '.C::local('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::local('erp', 'dbo.prmEtage')." pe WITH (NOLOCK) ON pe.etage = ch.Etage
             WHERE d.coddep = '".self::DEPOT."' AND d.qte > 0".$filtre.'
             ORDER BY d.DatPer, d.Num'
        ));
    }

    /**
     * Mouvements du dépôt BS sur une période (lignes de bons) :
     * réceptions = bons de dépôt FRS (BD), sorties patient = bons PA (FBS…),
     * prélèvements fournisseur = bons PR ; et l'état du stock (valide / périmé).
     */
    public function mouvements(\DateTimeInterface $du, \DateTimeInterface $au): ?object
    {
        if (! $this->branche()) {
            return null;
        }
        // Dates formatées par l'appli (ISO 8601) : sûres à injecter, indépendantes du DATEFORMAT.
        [$d, $a] = [$du->format('Y-m-d\T00:00:00'), $au->format('Y-m-d\T23:59:59')];
        $bdpr = fn (string $type) => "(SELECT COALESCE(SUM(m.quantite), 0) FROM ".C::local('pharmacie', 'dbo.MvtStoBD_PR').' m WITH (NOLOCK)
            JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR')." f WITH (NOLOCK) ON f.numbon = m.numbon
            WHERE m.typbon = '$type' AND m.coddep = '".self::DEPOT."' AND f.datbon BETWEEN '$d' AND '$a')";

        return C::distant(
            'SELECT '.$bdpr('BD').' AS receptions, '.$bdpr('PR').' AS prelevements,
                (SELECT COALESCE(SUM(m.quantite), 0) FROM '.C::local('pharmacie', 'dbo.mvtsto').' m WITH (NOLOCK)
                    JOIN '.C::local('pharmacie', 'dbo.facture')." f WITH (NOLOCK) ON f.numbon = m.numbon
                    WHERE f.typbon = 'PA' AND f.coddep = '".self::DEPOT."' AND f.datesys BETWEEN '$d' AND '$a') AS sorties,
                (SELECT COUNT(*) FROM ".C::local('pharmacie', 'dbo.DepSto')." WITH (NOLOCK) WHERE coddep = '".self::DEPOT."' AND qte > 0
                    AND (DatPer IS NULL OR DatPer > CAST(GETDATE() AS date))) AS en_stock,
                (SELECT COUNT(*) FROM ".C::local('pharmacie', 'dbo.DepSto')." WITH (NOLOCK) WHERE coddep = '".self::DEPOT."' AND qte > 0
                    AND DatPer <= CAST(GETDATE() AS date)) AS perimees"
        )[0] ?? null;
    }

    /**
     * Lignes des mouvements du dépôt BS sur une période (mêmes sources que mouvements(), une ligne par poche) :
     * BD = réceptions (bons de dépôt FRS), PR = prélèvements fournisseur, PA = sorties patient.
     * Patient : dossier du bon PA, sinon celui de la demande DMI du bon de dépôt ; service de soin du patient.
     * $numero : recherche exacte sur tout l'historique (n° poche, n° de bon, bon BD d'origine, n° dossier ou n° demande DMI),
     * sans période ; au plus 500 lignes.
     */
    public function lignesMouvements(string $type, ?\DateTimeInterface $du, ?\DateTimeInterface $au, ?string $numero = null): array
    {
        $numero = $numero !== null && self::numeroRecherche($numero) ? strtoupper(trim($numero)) : null;
        if (! $this->branche() || ! in_array($type, ['BD', 'PR', 'PA'], true) || ($numero === null && (! $du || ! $au))) {
            return [];
        }
        // Dates formatées par l'appli (ISO 8601), type et numéro contrôlés ci-dessus (lettres et chiffres seulement) :
        // sûrs à injecter (OPENQUERY n'accepte pas de paramètres).
        [$d, $a] = $du && $au ? [$du->format('Y-m-d\T00:00:00'), $au->format('Y-m-d\T23:59:59')] : [null, null];
        $top = $numero !== null ? 'TOP 500 ' : '';
        $quand = fn (string $col) => $numero !== null ? '' : " AND $col BETWEEN '$d' AND '$a'";
        $quoi = fn (string ...$cols) => $numero === null ? '' : ' AND ('.implode(' OR ', array_map(fn ($c) => "$c = '$numero'", $cols)).')';
        $patient = 'LEFT JOIN '.C::local('erp', 'dbo.Client').' c WITH (NOLOCK) ON c.NumDoss = x.NumDoss
             LEFT JOIN '.C::local('erp', 'dbo.chambre').' ch WITH (NOLOCK) ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::local('erp', 'dbo.prmEtage').' pe WITH (NOLOCK) ON pe.etage = ch.Etage';
        $sql = $type === 'PA'
            ? "SELECT $top x.*, c.NomCli, c.Prenom, pe.libelle AS service FROM (
                 SELECT m.reference AS numero, m.desart, m.quantite, CAST(NULL AS datetime) AS DatPer, f.numaffiche AS bon, f.datesys, f.heuresys,
                        m.NumBon_Depot AS bon_origine, fb.memop, fb.numDemandeS AS numDemande, f.numdoss AS NumDoss
                 FROM ".C::local('pharmacie', 'dbo.mvtsto').' m WITH (NOLOCK)
                 JOIN '.C::local('pharmacie', 'dbo.facture').' f WITH (NOLOCK) ON f.numbon = m.numbon
                 LEFT JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR')." fb WITH (NOLOCK) ON fb.numaffiche = m.NumBon_Depot AND fb.typbon = 'BD'
                 WHERE f.typbon = 'PA' AND f.coddep = '".self::DEPOT."'".$quand('f.datesys').$quoi('m.reference', 'f.numaffiche', 'm.NumBon_Depot', 'f.numdoss', 'fb.numDemandeS').") x
               $patient ORDER BY x.datesys DESC, x.heuresys DESC"
            : "SELECT $top x.*, c.NomCli, c.Prenom, pe.libelle AS service FROM (
                 SELECT m.Identifiant AS numero, m.desart, m.quantite, m.DatPer, f.numaffiche AS bon, f.datbon AS datesys, f.heuresys,
                        m.NumBon_Depot AS bon_origine, COALESCE(f.memop, fb.memop) AS memop,
                        COALESCE(f.numDemandeS, fb.numDemandeS) AS numDemande, ds.NumDoss
                 FROM ".C::local('pharmacie', 'dbo.MvtStoBD_PR').' m WITH (NOLOCK)
                 JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR').' f WITH (NOLOCK) ON f.numbon = m.numbon
                 LEFT JOIN '.C::local('pharmacie', 'dbo.FactureBD_PR')." fb WITH (NOLOCK) ON fb.numaffiche = m.NumBon_Depot AND fb.typbon = 'BD'
                 LEFT JOIN ".C::local('erp', 'dbo.Demande_Sang_Maroc').' ds WITH (NOLOCK) ON ds.ID = TRY_CAST(COALESCE(f.numDemandeS, fb.numDemandeS) AS int)
                 WHERE m.typbon = \''.$type."' AND m.coddep = '".self::DEPOT."'".$quand('f.datbon')
                   .$quoi('m.Identifiant', 'f.numaffiche', 'm.NumBon_Depot', 'ds.NumDoss', 'COALESCE(f.numDemandeS, fb.numDemandeS)').") x
               $patient ORDER BY x.datesys DESC, x.heuresys DESC";

        return array_map([$this, 'nettoyer'], C::distant($sql));
    }

    /**
     * Écarts entre les poches du bon BD Clinisys et celles saisies dans l'appli :
     * ['manquantes' => dans le BD mais pas saisies, 'en_trop' => saisies mais absentes du BD].
     */
    public static function ecarts(array $bons, array $numerosSaisis): array
    {
        $clinisys = [];
        foreach ($bons as $b) {
            foreach ($b->poches as $p) {
                $clinisys[] = self::normaliser($p->Identifiant);
            }
        }
        $saisis = array_map([self::class, 'normaliser'], $numerosSaisis);

        return [
            'manquantes' => array_values(array_diff(array_filter($clinisys), $saisis)),
            'en_trop' => array_values(array_diff(array_filter($saisis), $clinisys)),
        ];
    }

    /** N° de poche comparable : sans espaces, tabulations ni tiret final (saisis tels quels dans Clinisys). */
    public static function normaliser(?string $numero): string
    {
        return strtoupper(rtrim(preg_replace('/\s+/', '', (string) $numero), '-'));
    }

    private function avecLignes(array $bons): array
    {
        if ($bons === []) {
            return [];
        }
        $numeros = array_map(fn ($b) => $b->numbon, $bons);
        $lignes = collect(C::db()->select(
            'SELECT m.numbon, m.codart, m.desart, m.quantite, m.Identifiant, m.NumSerie, m.lot, m.DatPer
             FROM '.C::t('pharmacie', 'dbo.MvtStoBD_PR').' m WITH (NOLOCK)
             WHERE m.numbon IN ('.C::in($numeros).')
             ORDER BY m.numbon, m.numordre',
            $numeros
        ))->map([$this, 'nettoyer'])->groupBy('numbon');

        return array_map(function ($b) use ($lignes) {
            $b = $this->nettoyer($b);
            $b->poches = $lignes->get($b->numbon, collect())->values()->all();

            return $b;
        }, $bons);
    }

    /** Supprime les espaces / tabulations parasites des colonnes texte. */
    public function nettoyer(object $ligne): object
    {
        foreach ($ligne as $k => $v) {
            if (is_string($v)) {
                $ligne->$k = trim($v);
            }
        }

        return $ligne;
    }

    /** N° cherché dans l'historique (poche, bon, dossier, demande) : lettres et chiffres seulement, 3 à 20 caractères, au moins un chiffre. */
    public static function numeroRecherche(string $q): bool
    {
        return (bool) preg_match('/^(?=.*\d)[A-Za-z0-9]{3,20}$/', trim($q));
    }

    private function numeroValide(string $numero): bool
    {
        $numero = trim($numero);

        return $numero !== '' && ctype_digit($numero) && strlen($numero) <= 9;
    }
}
