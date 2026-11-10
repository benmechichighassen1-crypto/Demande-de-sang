<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Demande extends Model
{
    protected $table = 'ds_demandes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'externe' => 'boolean',
        'nb_poches' => 'integer',
        'cree_le' => 'datetime',
        'etape_le' => 'datetime',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'demande_id')->orderBy('fait_le')->orderBy('id');
    }

    public function remises(): HasMany
    {
        return $this->hasMany(Remise::class, 'demande_id')->orderBy('remis_le');
    }

    public function poches(): HasMany
    {
        return $this->hasMany(Poche::class, 'demande_id')->orderBy('id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ServiceDemande::class, 'demande_id')->orderBy('saisi_le')->orderBy('id');
    }

    /** Demande d'origine que celle-ci relance (CTS qui a livré moins que demandé). */
    public function relanceDe(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_relancee_id');
    }

    /** Relances faites ensuite pour le reste des poches. */
    public function relances(): HasMany
    {
        return $this->hasMany(Demande::class, 'demande_relancee_id')->orderBy('cree_le');
    }

    /** Poches reçues pour cette demande (saisies au contrôle C3). */
    public function recues(): int
    {
        return (int) ($this->poches_count ?? $this->poches()->count());
    }

    /** « 1/3 » : poches reçues / demandées. */
    public function ratioPoches(): string
    {
        return $this->recues().'/'.$this->nb_poches;
    }

    /** Le CTS a livré une partie des poches seulement. */
    public function serviePartiellement(): bool
    {
        $r = $this->recues();

        return $r > 0 && $r < $this->nb_poches;
    }

    /** « Reçu de paiement : R-123 » ou « Patient à la charge (prise en charge) : CNSS 456 », d'après A3 / A7. */
    public function paiement(): ?string
    {
        $annulees = $this->actions->pluck('annule_action_id')->filter()->all();
        $a = $this->actions->whereIn('etape', ['A3', 'A7'])->reject(fn ($a) => in_array($a->id, $annulees, true))
            ->filter(fn ($a) => ! empty($a->donnees['paiement']) || ! empty($a->donnees['numero_recu']))->last();
        if (! $a) {
            return null;
        }
        $x = $a->donnees;

        return ! empty($x['paiement']) ? self::libellePaiement($x) : __('Reçu de paiement : :ref', ['ref' => $x['numero_recu']]);
    }

    /**
     * « Reçu de paiement : R-123 » ou « À la charge de : Médecin — Dr X ».
     * Saisies faites avant le choix médecin / personnel / association : type « autre ».
     */
    public static function libellePaiement(array $x): string
    {
        if (($x['paiement'] ?? '') === 'charge') {
            $qui = __(config('demandesang.charges.'.($x['paiement_charge'] ?? '')) ?? 'autre');

            // Référence facultative : pas de « : » ni de « — » quand elle est vide.
            $ref = trim((string) ($x['paiement_ref'] ?? ''));

            return $ref !== '' ? __('À la charge de : :qui — :ref', ['qui' => $qui, 'ref' => $ref]) : __('À la charge de : :qui', ['qui' => $qui]);
        }
        $ref = trim((string) ($x['paiement_ref'] ?? ''));
        $libelle = __(config('demandesang.paiements.'.($x['paiement'] ?? '').'.libelle') ?? ($x['paiement'] ?? ''));

        return $ref !== '' ? $libelle.' : '.$ref : $libelle;
    }

    public function enCours(): bool
    {
        return $this->statut === 'en_cours';
    }

    /** N° CIN avec sa provenance quand il ne vient pas de Clinisys. */
    public function libelleCin(): string
    {
        return (string) $this->cin.match ($this->cin_source) {
            'saisie' => ' ('.__('saisi dans l\'appli').')',
            'autre' => ' ('.__('autre pièce').')',
            default => '',
        };
    }

    /** Cas du patient à afficher à côté du dossier : rien pour un patient hospitalisé. */
    public function libelleCas(): ?string
    {
        return match ($this->cas_patient) {
            'externe' => __('externe'),
            'hors_sejour' => __('hors séjour, second dossier'),
            default => null,
        };
    }

    public function libelleEtape(): string
    {
        if ($this->statut === 'annulee') {
            return __('Annulée');
        }
        if ($this->etape === null) {
            return __('Inscrite au registre');
        }

        return __(config("demandesang.etapes.{$this->etape}.court") ?? $this->etape);
    }
}
