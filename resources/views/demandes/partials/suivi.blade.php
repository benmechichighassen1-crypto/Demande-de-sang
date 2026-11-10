{{-- Suivi d'une demande : frise des 6 phases, étape qui bloque, qui doit agir (avec téléphone), depuis quand.
     Variables : $d (demande), $s (App\Services\SuiviDemande::calculer), $ref ; $grand = version de la fiche. --}}
@php($qui = \App\Services\SuiviDemande::quiDoitAgir($d, $s, $ref))
@php($couleurs = ['vert' => '#15803d', 'orange' => '#c2410c', 'rouge' => '#b91c1c'])
<div class="suivi suivi-{{ $s['niveau'] }} {{ ($grand ?? false) ? 'suivi-grand' : '' }}">
    <ol class="frise" aria-label="{{ __('Avancement de la demande') }}">
        @foreach(\App\Services\SuiviDemande::PHASES as $p => $libelle)
            @php($etatPhase = $s['termine'] || $p < $s['phase'] ? 'faite' : ($p === $s['phase'] ? 'actuelle' : 'avenir'))
            <li class="{{ $etatPhase }}" title="{{ __($libelle) }}"><span>{{ __($libelle) }}</span></li>
        @endforeach
    </ol>
    <div class="suivi-texte">
        @if($d->statut === 'annulee')
            <strong>{{ __('Annulée') }}</strong>
        @elseif($s['termine'])
            <strong style="color:#15803d;">✔ {{ __('Circuit terminé') }}</strong>
        @else
            <strong>{{ __('Bloquée à :') }}</strong> {{ __($s['etape']) }}
            @if($s['type'] === 'confirmer')<span class="doux">({{ __('remise à confirmer') }})</span>@endif
            · <strong>{{ $s['type'] === 'clinisys' ? __('Automatique (Clinisys) :') : __('À faire par :') }}</strong> {{ $qui['libelle'] }}@if($qui['service']) · {{ $qui['service'] }}@endif
            @foreach($qui['telephones'] as $cle)
                @php($numero = $ref->parametre("tel_$cle", config("demandesang.telephones.$cle.numero")))
                <a class="non-imprime" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $numero) }}" dir="ltr" style="white-space:nowrap;">📞 {{ $numero }}</a>
            @endforeach
            · <span style="color:{{ $couleurs[$s['niveau']] }}; font-weight:600;">{{ __('depuis :duree', ['duree' => app(\App\Services\Alertes::class)->duree($s['minutes'])]) }}</span>
            @if($s['instance'])<span class="doux">({{ __('poches laissées en instance au CTS') }})</span>@endif
        @endif
        @if($s['par'])<span class="doux petit"> · {{ __('dernière étape par :nom', ['nom' => $s['par']]) }}</span>@endif
    </div>
</div>
