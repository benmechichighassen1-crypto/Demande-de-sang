@extends('layouts.app')
@section('titre', 'CTS')
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
@php($u = auth()->user())
<div class="ligne espace">
    <h1>{{ __('CTS : à récupérer, déposer, ramener') }}</h1>
    <span class="doux petit">{{ __('Vérifié toutes les minutes, sans recharger la page') }}</span>
</div>
@include('demandes.partials.telephones', ['quoi' => ['coursier', 'surveillant', 'major']])

@include('partials.onglets', ['ecran' => 'cts', 'titre' => 'CTS', 'compteurs' => array_map('count', $blocs)])
@include('partials.champ_recherche', ['local' => true, 'placeholder' => __('Nom, n° dossier, n° demande, n° poche, n° bon…')])

@foreach(\App\Http\Controllers\CtsController::BLOCS as $c => $l)
    @php([$court, $couleur, $icone] = config("demandesang.onglets.cts.$c"))
    <section data-bloc="{{ $c }}" style="--c:{{ $couleur }};">
    <h2 id="bloc-{{ $c }}">{{ $icone }} {{ __($l) }} ({{ count($blocs[$c]) }})</h2>
    @if($c === 'instance')<p class="doux petit">{{ __('Poches gardées au CTS. Alerte au-delà de :jours jour(s).', ['jours' => $instanceJours]) }}</p>@endif
    <div class="cartes">
        @forelse($blocs[$c] as $l)
            @php($d = $l['demande'])
            @php($vieille = $c === 'instance' && $l['depuis']->lt(now()->subDays($instanceJours)))
            <div class="carte" @if($vieille) style="border-left:4px solid #b91c1c;" @endif>
                <a href="{{ route('demandes.show', $d) }}" style="display:block; text-decoration:none; color:inherit;">
                    <div style="margin-bottom:6px;"><span class="badge-etape">{{ $icone }} {{ __($court) }}</span></div>
                    <div class="ligne espace">
                        <strong>{{ $d->nom_patient }}</strong>
                        <span class="badge u-{{ $d->urgence }}">{{ __(config("demandesang.urgences.{$d->urgence}")) }}</span>
                    </div>
                    <div class="doux petit">{{ __('Dossier') }} {{ $d->num_doss }} · @if($d->cin){{ __('CIN') }} {{ $d->cin }} · @endif{{ __('DMI n°') }} {{ $d->num_demande_clinisys ?? '—' }} · {{ $d->nb_poches }} {{ $d->produit }}
                        @if($l['nb_pretes']) · {{ __('prêtes') }} {{ $l['nb_pretes'] }}/{{ $d->nb_poches }}@endif
                        · {{ __('vers') }} {{ $ref->service($d->service_destination) }}</div>
                    @if($d->nature === 'instance')<div class="petit" style="margin-top:2px;"><span class="badge" style="background:#7c3aed;">{{ __('À laisser en instance') }}</span> {{ __('(jusqu\'au besoin)') }}</div>@endif
                    <div class="petit" style="margin-top:4px; {{ $vieille ? 'color:#b91c1c; font-weight:600;' : '' }}">
                        {{ __($d->libelleEtape()) }} · {{ __('depuis') }} {{ $alertes->duree((int) $l['depuis']->diffInMinutes(now())) }}@if($vieille) : {{ __('instance trop longue') }} @endif</div>
                </a>
                <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:6px;">
                    @switch($c)
                        @case('recuperer')
                            @if($l['remise'] && $l['remise']->reponse === 'attente' && $circuit->peutConfirmer($l['remise'], $u))
                                <form method="post" action="{{ route('remises.confirmer', $l['remise']) }}">@csrf <input type="hidden" name="retour" value="cts">
                                    <button class="btn vert" type="submit">✔ {!! __("J'ai bien reçu le dossier") !!}</button></form>
                            @else
                                <span class="doux petit">{!! __("Le service ne l'a pas encore remis (A11).") !!}</span>
                            @endif
                            @break
                        @case('deposer')
                            {{-- Patient externe / hors séjour : le coursier vérifie d'abord le paiement (A3), puis transporte la demande au CTS. --}}
                            @if($u->a('coursier') && ($circuit->etat($d)['A3']['statut'] ?? '') === 'disponible')
                                <form method="post" action="{{ route('demandes.etape', [$d, 'A3']) }}" style="width:100%;">@csrf <input type="hidden" name="retour" value="cts">
                                    <strong class="petit">{{ __(config('demandesang.etapes.A3.libelle')) }}</strong>
                                    @include('demandes.partials.paiement', ['requis' => true, 'attr' => ''])
                                    <button class="btn" type="submit">✔ {{ __(config('demandesang.etapes.A3.court')) }}</button></form>
                            @elseif($u->a('coursier'))
                                <form method="post" action="{{ route('demandes.etape', [$d, 'B1']) }}">@csrf <input type="hidden" name="retour" value="cts">
                                    <button class="btn" type="submit">✔ {{ __(config('demandesang.etapes.B1.court')) }}</button></form>
                            @endif
                            @break
                        @case('attente')
                            @if($u->a('coursier'))
                                <form method="post" action="{{ route('demandes.etape', [$d, 'B2']) }}">@csrf <input type="hidden" name="retour" value="cts">
                                    <input type="hidden" name="etat_cts" value="pretes"><input type="hidden" name="nb_pretes" value="{{ $d->nb_poches }}">
                                    <button class="btn {{ $d->nature === 'instance' ? 'sec' : '' }}" type="submit">✔ {{ __('À ramener : poches prêtes') }} ({{ $d->nb_poches }}/{{ $d->nb_poches }})</button></form>
                                <form method="post" action="{{ route('demandes.etape', [$d, 'B2']) }}">@csrf <input type="hidden" name="retour" value="cts">
                                    {{-- Demande inscrite « en instance » : c'est l'action attendue, bouton principal. --}}
                                    <input type="hidden" name="etat_cts" value="instance"><button class="btn {{ $d->nature === 'instance' ? '' : 'sec' }}" type="submit">{{ __('À laisser en instance') }}</button></form>
                                <a class="btn sec" href="{{ route('demandes.show', [$d, 'etape' => 'B2']) }}#etape-B2">{{ __('Le CTS a donné moins de poches (ex. 1/3)…') }}</a>
                            @endif
                            @break
                        @case('ramener')
                        @case('instance')
                            @if($u->a('coursier'))
                                <form method="post" action="{{ route('demandes.etape', [$d, 'C1']) }}">@csrf <input type="hidden" name="retour" value="cts">
                                    <button class="btn" type="submit">{{ $c === 'instance' ? '🚚 '.__('Ramener maintenant : arrivée à la clinique') : '✔ '.__('Arrivée à la clinique') }}</button></form>
                            @endif
                            @break
                    @endswitch
                </div>
            </div>
        @empty
            <div class="carte doux">{{ __('Aucune demande.') }}</div>
        @endforelse
    </div>
    </section>
@endforeach
@endsection
