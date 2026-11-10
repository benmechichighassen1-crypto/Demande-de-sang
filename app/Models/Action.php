<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne du registre : jamais modifiée ni supprimée (voir boot()). */
class Action extends Model
{
    protected $table = 'ds_actions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'fait_le' => 'datetime',
        'donnees' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Une action du registre ne se modifie pas : enregistrer une correction.'));
        static::deleting(fn () => throw new \LogicException('Une action du registre ne se supprime pas.'));
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function libelle(): string
    {
        return config("demandesang.etapes.{$this->etape}.libelle")
            ?? config("demandesang.etapes_creation.{$this->etape}")
            ?? config("demandesang.actions.{$this->etape}")
            ?? $this->etape;
    }
}
