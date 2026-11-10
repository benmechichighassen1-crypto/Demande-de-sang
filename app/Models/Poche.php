<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Poche extends Model
{
    public const STATUTS = [
        'attente' => 'Arrivée, pas encore reçue en pharmacie',
        'en_stock' => 'En stock (pharmacie)',
        'delivree' => 'Délivrée au service (sortie patient)',
        'transfusee' => 'Transfusée',
        'a_retourner' => 'Non utilisée, à retourner',
        'prelevee' => 'Retournée (prélèvement fournisseur)',
    ];

    protected $table = 'ds_poches';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'peremption' => 'date',
        'statut_le' => 'datetime',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }
}
