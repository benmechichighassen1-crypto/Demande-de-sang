<?php

namespace App\Auth;

use App\Support\Clinisys;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;

/**
 * Deux sortes de comptes :
 *  - compte Clinisys : login et mot de passe vérifiés dans AccessControl (lecture seule) ;
 *  - compte local : login + code PIN gérés dans l'appli (ds_comptes.pin_hash),
 *    pour le coursier s'il n'a pas de compte Clinisys.
 * Dans les deux cas, les rôles viennent de ds_comptes : un compte sans rôle
 * (et non administrateur) ne peut pas se connecter.
 */
class FournisseurUtilisateurs implements UserProvider
{
    public function __construct(private VerificateurMotDePasse $verificateur)
    {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->construire((string) $identifier, true);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $userName = trim((string) ($credentials['username'] ?? ''));

        return $userName === '' ? null : $this->construire($userName);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $secret = (string) ($credentials['password'] ?? '');
        if ($secret === '') {
            return false;
        }

        /** @var Utilisateur $user */
        if ($user->local) {
            $hash = DB::table('ds_comptes')->where('user_name', $user->userName)->value('pin_hash');

            return $hash !== null && password_verify($secret, $hash);
        }

        $ligne = Clinisys::db()->selectOne(
            'SELECT hasched_password, salt, hash_iterations FROM '.Clinisys::t('erp', config('demandesang.auth.table')).' WITH (NOLOCK) WHERE UserName = ?',
            [$user->userName]
        );

        return $ligne !== null && $this->verificateur->verifier($secret, $ligne->hasched_password, $ligne->salt, $ligne->hash_iterations) !== null;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }

    /** $enCache : page ordinaire d'un utilisateur déjà connecté ; à la connexion, le compte Clinisys est toujours relu. */
    private function construire(string $userName, bool $enCache = false): ?Utilisateur
    {
        $compte = DB::table('ds_comptes')->where('user_name', $userName)->first();
        if ($compte && ! $compte->actif) {
            return null;
        }
        $local = $compte && $compte->pin_hash !== null;

        $nom = $compte->nom ?? null;
        if (! $local) {
            if (! config('demandesang.clinisys_actif')) {
                return null;
            }
            $ligne = $enCache
                ? Clinisys::enCache('clinisys.compte.'.md5(mb_strtolower($userName)), 600, fn () => $this->ligneClinisys($userName))
                : $this->ligneClinisys($userName);
            if (! $ligne || ! $this->compteClinisysValide($ligne)) {
                return null;
            }
            $nom = $nom ?: (trim((string) $ligne->Description) ?: $userName);
        }

        $roles = $compte ? array_values(array_filter(array_map('trim', explode(',', (string) $compte->roles)))) : [];
        $admin = (bool) ($compte->admin ?? false)
            || in_array(mb_strtolower($userName), array_map('mb_strtolower', config('demandesang.auth.admins')), true);

        if ($roles === [] && ! $admin) {
            return null;
        }

        $services = $compte ? DB::table('ds_compte_services')->where('user_name', $userName)->orderBy('service_code')->pluck('service_code')->all() : [];

        return new Utilisateur($userName, (string) $nom, $roles, $services, $admin, $local);
    }

    private function ligneClinisys(string $userName): ?object
    {
        return Clinisys::db()->selectOne(
            'SELECT UserName, Description, Actif, expire, CompteExpire, dateExpirationCompte
             FROM '.Clinisys::t('erp', config('demandesang.auth.table')).' WITH (NOLOCK) WHERE UserName = ?',
            [$userName]
        );
    }

    private function compteClinisysValide(object $ligne): bool
    {
        if ((string) $ligne->Actif !== '1' || (int) ($ligne->expire ?? 0) === 1) {
            return false;
        }

        return ! ((int) ($ligne->CompteExpire ?? 0) === 1 && $ligne->dateExpirationCompte !== null
            && strtotime((string) $ligne->dateExpirationCompte) < time());
    }
}
