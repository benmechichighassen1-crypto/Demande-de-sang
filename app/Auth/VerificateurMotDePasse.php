<?php

namespace App\Auth;

/**
 * Vérifie un mot de passe saisi contre les colonnes hasched_password, salt et
 * hash_iterations de AccessControl (dérivation PBKDF2, encodage base64).
 *
 * L'algorithme exact n'est pas documenté par Clinisys : en mode « auto », les
 * variantes courantes sont essayées. Une fois identifié avec
 * « php artisan ds:tester-mot-de-passe », figer CLINISYS_HASH dans .env.
 */
class VerificateurMotDePasse
{
    public const SCHEMAS = ['pbkdf2-sha512', 'pbkdf2-sha256', 'pbkdf2-sha1'];

    /** Retourne le schéma qui correspond, ou null. */
    public function verifier(string $motDePasse, ?string $hashB64, ?string $selB64, $iterations): ?string
    {
        $attendu = base64_decode((string) $hashB64, true);
        $sel = base64_decode((string) $selB64, true);
        $iterations = (int) $iterations;

        if ($attendu === false || $attendu === '' || $sel === false || $iterations <= 0 || $motDePasse === '') {
            return null;
        }

        $config = (string) config('demandesang.auth.hash', 'auto');
        $schemas = $config === 'auto' ? self::SCHEMAS : [$config];

        foreach ($schemas as $schema) {
            foreach ($this->candidats($schema, $motDePasse, $sel, $iterations, strlen($attendu)) as $calcule) {
                if ($calcule !== null && hash_equals($attendu, $calcule)) {
                    return $schema;
                }
            }
        }

        return null;
    }

    /** @return iterable<string|null> */
    private function candidats(string $schema, string $motDePasse, string $sel, int $iterations, int $longueur): iterable
    {
        if (! str_starts_with($schema, 'pbkdf2-')) {
            return [];
        }
        $algo = substr($schema, 7);
        if (! in_array($algo, hash_hmac_algos(), true)) {
            return [];
        }

        // .NET encode souvent la chaîne en UTF-8 ; certaines applis utilisent UTF-16LE.
        $encodages = [$motDePasse, mb_convert_encoding($motDePasse, 'UTF-16LE', 'UTF-8')];
        foreach ($encodages as $mdp) {
            yield hash_pbkdf2($algo, $mdp, $sel, $iterations, $longueur, true);
        }
    }
}
