@extends('layouts.app')
@section('titre', 'Indicateurs')
@section('contenu')
<div class="ligne espace">
    <h1>{{ __('Indicateurs') }}</h1>
    <a class="btn gris" href="{{ route('indicateurs.export', $f) }}">{{ __('Export Excel') }}</a>
</div>
@include('suivi._filtres')

<div class="cartes" style="grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));">
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['total'] }}</div><div class="doux petit">{{ __('Demandes (:besoins besoin(s), :relances relance(s))', ['besoins' => $ind['besoins'], 'relances' => $ind['relances']]) }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['taux_servies_partiellement'] ?? '—' }}{{ $ind['taux_servies_partiellement'] !== null ? ' %' : '' }}</div><div class="doux petit">{{ __('Demandes servies partiellement par le CTS (:n)', ['n' => $ind['servies_partiellement']]) }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['taux_hors_registre'] ?? '—' }}{{ $ind['taux_hors_registre'] !== null ? ' %' : '' }}</div><div class="doux petit">{{ __('Demandes reçues en pharmacie hors registre, inscrites a posteriori (:n)', ['n' => $ind['hors_registre']]) }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['par_statut']['en_cours'] }}</div><div class="doux petit">{{ __('En cours') }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['par_statut']['cloturee'] }}</div><div class="doux petit">{{ __('Clôturées') }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['par_statut']['annulee'] }}</div><div class="doux petit">{{ __('Annulées') }}</div></div>
    <div class="carte"><div style="font-size:26px; font-weight:700;">{{ $ind['poches']['taux_non_consommees'] ?? '—' }}{{ $ind['poches']['taux_non_consommees'] !== null ? ' %' : '' }}</div><div class="doux petit">{{ __('Poches non consommées') }}</div></div>
</div>

<div class="carte table-defile">
    <h2>{{ __('Délais par étape') }}</h2>
    <table>
        <tr><th>{{ __('Segment') }}</th><th>{{ __('Demandes') }}</th><th>{{ __('Moyenne') }}</th><th>{{ __('Médiane') }}</th><th>{{ __('Maximum') }}</th></tr>
        @foreach($ind['segments'] as $libelle => $s)
            <tr><td>{{ __($libelle) }}</td><td>{{ $s['n'] }}</td><td>{{ $duree($s['moyenne']) }}</td><td>{{ $duree($s['mediane']) }}</td><td>{{ $duree($s['max']) }}</td></tr>
        @endforeach
    </table>
</div>

<div class="carte">
    <h2>{{ __('Poches') }}</h2>
    <div class="grille">
        <div><span>{{ __('Reçues (saisies)') }}</span>{{ $ind['poches']['total'] }}</div>
        <div><span>{{ __('En stock') }}</span>{{ $ind['poches']['en_stock'] }}</div>
        <div><span>{{ __('Consommées') }}</span>{{ $ind['poches']['consommees'] }}</div>
        <div><span>{{ __('Non consommées / retournées') }}</span>{{ $ind['poches']['retournees'] }}</div>
    </div>
    @if($ind['poches']['motifs'] !== [])
        <h3 style="margin-top:12px;">{{ __('Motifs de non-consommation') }}</h3>
        @foreach($ind['poches']['motifs'] as $m => $n)
            <div>{{ __(config("demandesang.motifs_non_utilisation.$m") ?? $m) }} : <b>{{ $n }}</b></div>
        @endforeach
    @endif
</div>
@endsection
