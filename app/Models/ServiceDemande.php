<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Historique des services source / destination saisis par le major. */
class ServiceDemande extends Model
{
    protected $table = 'ds_services_demande';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'transfert' => 'boolean',
        'saisi_le' => 'datetime',
    ];
}
