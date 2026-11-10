<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Utilisateur connecté : compte Clinisys (AccessControl) ou compte local de
 * l'application (coursier sans compte Clinisys), avec ses rôles lus dans
 * ds_comptes. Aucun mot de passe, hash ou PIN n'est conservé dans l'objet.
 */
class Utilisateur implements Authenticatable
{
    /**
     * @param string[] $roles
     * @param string[] $services codes des services de soin de rattachement (ds_compte_services)
     */
    public function __construct(
        public readonly string $userName,
        public readonly string $nom,
        public readonly array $roles,
        public readonly array $services,
        public readonly bool $admin,
        public readonly bool $local,
    ) {
    }

    /** Premier service de rattachement (service source proposé à A10 à défaut du service du patient). */
    public function premierService(): ?string
    {
        return $this->services[0] ?? null;
    }

    /**
     * « À faire » limité à ses services : infirmière / major rattachée à au moins un service.
     * Coursier, surveillant, pharmacie, direction, et un compte sans service voient tout.
     */
    public function limiteAuxServices(): bool
    {
        return $this->services !== [] && $this->a('infirmiere', 'major') && ! $this->a('coursier', 'surveillant', 'pharmacie', 'direction');
    }

    public function a(string ...$roles): bool
    {
        return array_intersect($roles, $this->roles) !== [];
    }

    public function getAuthIdentifierName(): string
    {
        return 'userName';
    }

    public function getAuthIdentifier(): string
    {
        return $this->userName;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
