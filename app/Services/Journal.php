<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Journal technique : connexions, refus, pages consultées, paramétrage.
 * Les étapes des demandes sont dans ds_actions (le registre), pas ici.
 */
class Journal
{
    /** Écrans, en clair (route => libellé). */
    public const ECRANS = [
        'accueil' => 'À faire', 'demandes.index' => 'la liste des demandes', 'demandes.create' => 'l\'écran Rechercher / inscrire',
        'patients' => 'la recherche patient', 'stock' => 'le stock', 'cts' => 'l\'écran CTS', 'pharmacie' => 'l\'écran Pharmacie', 'alertes' => 'les alertes',
        'registre' => 'le registre', 'indicateurs' => 'les indicateurs', 'admin.comptes' => 'Paramétrage : comptes',
        'admin.compte' => 'Paramétrage : un compte', 'admin.compte.nouveau' => 'Paramétrage : nouveau compte',
        'admin.services' => 'Paramétrage : services', 'admin.parametres' => 'Paramétrage : seuils', 'admin.journal' => 'le journal',
    ];

    /** Types d'action pour le filtre (clé => libellé). */
    public const TYPES = [
        'connexion' => 'Connexion', 'deconnexion' => 'Déconnexion', 'connexion_refusee' => 'Échec de connexion',
        'demande' => 'Consultation d\'une demande', 'page' => 'Consultation d\'un écran', 'export' => 'Export / impression',
        'parametrage' => 'Paramétrage',
    ];

    public static function ecrire(string $action, array $details = [], ?string $userName = null, ?int $demandeId = null): void
    {
        $request = request();

        // Le journal ne doit jamais empêcher une page de s'afficher : en cas d'erreur, on la consigne et on continue.
        try {
        DB::table('ds_journal')->insert([
            'user_name' => $userName ?? optional($request->user())->getAuthIdentifier(),
            'action' => $action,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            'demande_id' => $demandeId,
            'ip' => $request->ip(),
            'navigateur' => mb_substr((string) $request->userAgent(), 0, 255),
            'url' => mb_substr($request->fullUrl(), 0, 500),
            'cree_le' => now(),
        ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Ligne du journal en clair : ['type' => clé de TYPES, 'texte' => …, 'demande' => id ou null].
     */
    public static function libelle(object $l): array
    {
        $d = json_decode((string) $l->details, true) ?: [];
        $route = $d['route'] ?? '';
        $filtre = fn () => implode(', ', array_filter([
            isset($d['q']) ? __('recherche « :q »', ['q' => $d['q']]) : null, isset($d['num_demande']) ? __('demande DMI n° :n', ['n' => $d['num_demande']]) : null,
            isset($d['num_doss']) ? __('dossier :n', ['n' => $d['num_doss']]) : null, isset($d['du']) ? __('du :date', ['date' => $d['du']]) : null, isset($d['au']) ? __('au :date', ['date' => $d['au']]) : null,
        ]));

        return match ($l->action) {
            'connexion' => ['type' => 'connexion', 'texte' => __('Connexion'), 'demande' => null],
            'deconnexion' => ['type' => 'deconnexion', 'texte' => __('Déconnexion'), 'demande' => null],
            'connexion_refusee' => ['type' => 'connexion_refusee', 'texte' => __('Échec de connexion (identifiant « :login »)', ['login' => $d['login'] ?? '?']), 'demande' => null],
            'compte' => ['type' => 'parametrage', 'texte' => __('A modifié le compte :compte', ['compte' => $d['user_name'] ?? '']).(isset($d['roles']) ? ' '.__('(rôles : :roles)', ['roles' => $d['roles'] ?: __('aucun')]) : ''), 'demande' => null],
            'service' => ['type' => 'parametrage', 'texte' => __('A modifié le service :code :libelle', ['code' => $d['code'] ?? '', 'libelle' => $d['libelle'] ?? '']), 'demande' => null],
            'parametres' => ['type' => 'parametrage', 'texte' => __('A modifié les seuils d\'alerte'), 'demande' => null],
            'services_clinisys' => ['type' => 'parametrage', 'texte' => __('A synchronisé les services de soin avec Clinisys'), 'demande' => null],
            'recuperation_poche' => ['type' => 'demande', 'texte' => __('A choisi de récupérer la poche :n à la pharmacie (dossier :d)', ['n' => $d['poche'] ?? '', 'd' => $d['dossier'] ?? '']), 'demande' => null],
            'page' => match (true) {
                $route === 'demandes.show' => ['type' => 'demande', 'texte' => __('A consulté la demande n° :n', ['n' => $d['demande'] ?? '?']).(isset($d['dossier']) ? ' — '.__('dossier :n', ['n' => $d['dossier']]) : '').(isset($d['dmi']) ? ' '.__('(DMI n° :n)', ['n' => $d['dmi']]) : ''), 'demande' => $d['demande'] ?? null],
                $route === 'demandes.bon' => ['type' => 'export', 'texte' => __('A imprimé le bon de la demande n° :n', ['n' => $d['demande'] ?? '?']).(isset($d['dossier']) ? ' — '.__('dossier :n', ['n' => $d['dossier']]) : ''), 'demande' => $d['demande'] ?? null],
                $route === 'indicateurs.export' => ['type' => 'export', 'texte' => __('A exporté les demandes (Excel)').($filtre() ? ' · '.$filtre() : ''), 'demande' => null],
                $route === 'admin.journal.export' => ['type' => 'export', 'texte' => __('A exporté le journal'), 'demande' => null],
                default => ['type' => 'page', 'texte' => __('A consulté :ecran', ['ecran' => isset(self::ECRANS[$route]) ? __(self::ECRANS[$route]) : ($route ?: __('une page'))]).($filtre() ? ' · '.$filtre() : ''), 'demande' => null],
            },
            default => ['type' => 'parametrage', 'texte' => $l->action, 'demande' => null],
        };
    }
}
