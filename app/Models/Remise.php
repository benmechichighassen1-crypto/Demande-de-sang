<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Remise extends Model
{
    protected $table = 'ds_remises';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'remis_le' => 'datetime',
        'repondu_le' => 'datetime',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    /** @return string[] */
    public function rolesReceveur(): array
    {
        return config("demandesang.etapes.{$this->etape}.receveur", []);
    }
}
