@extends('layouts.app')
@section('titre', 'Comptes')
@section('contenu')
@include('admin._menu')
<div class="ligne espace"><h1>{{ __('Comptes et rôles') }}</h1><a class="btn" href="{{ route('admin.compte.nouveau') }}">{{ __('+ Ajouter un compte') }}</a></div>
<p class="doux petit">{{ __('Un compte Clinisys se connecte avec son mot de passe Clinisys ; un compte local (coursier sans compte Clinisys) avec un code PIN. Sans rôle, la connexion est refusée.') }}</p>
@include('partials.champ_recherche', ['local' => true, 'placeholder' => __('Login, nom, rôle, service…')])
<div class="carte table-defile">
    <table>
        <tr><th>{{ __('Login') }}</th><th>{{ __('Nom') }}</th><th>{{ __('Type') }}</th><th>{{ __('Rôles') }}</th><th>{{ __('Services') }}</th><th>{{ __('État') }}</th><th></th></tr>
        @forelse($comptes as $c)
            <tr>
                <td>{{ $c->user_name }}</td><td>{{ $c->nom }}</td><td>{{ $c->pin_hash ? __('Local (PIN)') : 'Clinisys' }}</td>
                <td>{{ collect(explode(',', $c->roles))->filter()->map(fn ($r) => __(config("demandesang.roles.$r") ?? ''))->implode(', ') }}@if($c->admin) <b>· {{ __('admin') }}</b>@endif</td>
                <td>{{ collect($servicesComptes[$c->user_name] ?? [])->map(fn ($s) => $ref->service($s->service_code))->implode(", ") ?: "—" }}</td><td>{{ $c->actif ? __('Actif') : __('Inactif') }}</td>
                <td><a class="btn petit sec" href="{{ route('admin.compte', $c->user_name) }}">{{ __('Modifier') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="doux">{{ __('Aucun compte.') }}</td></tr>
        @endforelse
    </table>
</div>
@endsection
