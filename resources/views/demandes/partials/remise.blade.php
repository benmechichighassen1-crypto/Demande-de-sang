{{-- Remise à confirmer par le receveur. Variables : $r (remise), $retour. --}}
@php($receveurs = config("demandesang.etapes.{$r->etape}.receveur", []))
<div class="carte" style="border-left:4px solid #f59e0b;" data-roles="{{ implode(' ', $receveurs) }}">
    <div style="margin-bottom:6px;">@include('partials.role', ['roles' => $receveurs, 'prefixe' => 'À confirmer par'])</div>
    <div class="ligne espace">
        <strong>{{ __(config("demandesang.etapes.{$r->etape}.libelle")) }}</strong>
        <span class="badge u-{{ $r->demande->urgence }}">{{ __(config("demandesang.urgences.{$r->demande->urgence}")) }}</span>
    </div>
    <p style="margin:6px 0;">
        <a class="loupe" href="{{ route('demandes.show', $r->demande) }}">{{ $r->demande->nom_patient }}</a> · {{ __('dossier') }} {{ $r->demande->num_doss }} ·
        {{ $r->demande->nb_poches }} {{ $r->demande->produit }}<br>
        <span class="doux">{{ __('Remis par :nom à :heure', ['nom' => $r->nom_donneur, 'heure' => $r->remis_le->format('H:i')]) }} ({{ $r->remis_le->diffForHumans() }})</span>
    </p>
    <form method="post" action="{{ route('remises.confirmer', $r) }}" style="margin-bottom:8px;">
        @csrf @if($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endif
        <button class="btn vert large" type="submit">✔ {!! __("J'ai bien reçu") !!}</button>
    </form>
    <details><summary class="doux petit">{!! __("Je n'ai rien reçu / refuser") !!}</summary>
        <form method="post" action="{{ route('remises.refuser', $r) }}" class="ligne">
            @csrf @if($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endif
            <input name="motif" placeholder="{{ __('Motif du refus') }}" required style="flex:1;">
            <button class="btn gris" type="submit">{{ __('Refuser') }}</button>
        </form>
    </details>
</div>
