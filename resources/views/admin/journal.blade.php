@extends('layouts.app')
@section('titre', 'Journal')
@section('contenu')
@include('admin._menu')
<div class="ligne espace">
    <h1 style="margin:0;">{{ __('Journal des connexions et consultations') }}</h1>
    <a class="btn gris" href="{{ route('admin.journal.export', request()->query()) }}">{{ __('Exporter (Excel)') }}</a>
</div>
<p class="doux petit">{{ __('Qui s\'est connecté, quelles pages et quelles demandes ont été consultées, imprimées ou exportées. Les étapes des demandes sont dans le registre.') }}</p>

<div class="collant">
<form class="carte filtres" method="get">
    @include('partials.champ_recherche', ['nom' => 'demande', 'placeholder' => __('Utilisateur, n° demande, dossier, DMI…')])
    <label class="petit">{{ __('Du') }} <input type="date" name="du" value="{{ $f['du'] ?? '' }}"></label>
    <label class="petit">{{ __('au') }} <input type="date" name="au" value="{{ $f['au'] ?? '' }}"></label>
    <select name="user"><option value="">{{ __('Tous les utilisateurs') }}</option>
        @foreach($utilisateurs as $u)<option value="{{ $u }}" @selected(($f['user'] ?? '') === $u)>{{ $noms[$u] ?? $u }} ({{ $u }})</option>@endforeach</select>
    <select name="type"><option value="">{{ __('Toutes les actions') }}</option>
        @foreach(\App\Services\Journal::TYPES as $c => $l)<option value="{{ $c }}" @selected(($f['type'] ?? '') === $c)>{{ __($l) }}</option>@endforeach</select>
    <button class="btn sec" type="submit">{{ __('Filtrer') }}</button>
    @include('partials.effacer_filtres')
</form>
</div>
@include('partials.filtres_actifs', ['resultat' => __(':n événement(s)', ['n' => $lignes->total()]), 'actifs' => [
    'du' => __('Du :date', ['date' => ! empty($f['du']) ? \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y') : '']),
    'au' => __('Au :date', ['date' => ! empty($f['au']) ? \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y') : '']),
    'user' => __('Utilisateur : :valeur', ['valeur' => $noms[$f['user'] ?? ''] ?? ($f['user'] ?? '')]),
    'type' => __('Action : :valeur', ['valeur' => __(\App\Services\Journal::TYPES[$f['type'] ?? ''] ?? '')]),
    'demande' => __('Demande / dossier : :valeur', ['valeur' => $f['demande'] ?? '']),
]])

@php($icones = ['connexion' => ['🔓', '#15803d'], 'deconnexion' => ['🔒', '#57534e'], 'connexion_refusee' => ['⛔', '#b91c1c'],
    'demande' => ['📄', '#1d4ed8'], 'page' => ['👁', '#78716c'], 'export' => ['🖨', '#7c3aed'], 'parametrage' => ['⚙', '#c2410c']])
<div class="carte table-defile">
    <table class="petit">
        <tr><th>{{ __('Date / heure') }}</th><th>{{ __('Utilisateur') }}</th><th>{{ __('Action') }}</th><th>{{ __('Poste (IP)') }}</th></tr>
        @forelse($lignes as $l)
            @php($x = \App\Services\Journal::libelle($l))
            @php([$icone, $couleur] = $icones[$x['type']] ?? ['•', '#78716c'])
            <tr>
                <td style="white-space:nowrap;">{{ \Illuminate\Support\Carbon::parse($l->cree_le)->format('d/m/Y H:i:s') }}</td>
                <td>{{ $noms[$l->user_name] ?? '—' }}<div class="doux">{{ $l->user_name }}</div></td>
                <td>
                    <span style="color:{{ $couleur }}; font-weight:600;">{{ $icone }}</span>
                    @if($x['demande'])
                        <a class="loupe" href="{{ route('demandes.show', $x['demande']) }}">{{ $x['texte'] }}</a>
                    @else
                        {{ $x['texte'] }}
                    @endif
                    <details class="non-imprime"><summary class="doux" style="font-weight:400; padding:2px 0;">{{ __('détail technique') }}</summary>
                        <div class="doux" style="overflow-wrap:anywhere;">{{ $l->url }}<br>{{ $l->navigateur }}</div></details>
                </td>
                <td>{{ $l->ip }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="doux">{{ __('Aucun événement pour ces filtres.') }}</td></tr>
        @endforelse
    </table>
    {{ $lignes->links() }}
</div>
@endsection
