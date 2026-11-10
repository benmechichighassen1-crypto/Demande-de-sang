@extends('layouts.app')
@section('titre', 'Compte')
@section('contenu')
@include('admin._menu')
<h1>{{ $compte ? __('Compte :login', ['login' => $compte->user_name]) : __('Nouveau compte') }}</h1>
@php($roles = $compte ? explode(',', $compte->roles) : [])
<form method="post" action="{{ route('admin.compte.enregistrer') }}" class="carte" style="max-width:640px;">
    @csrf
    <div class="champ"><label>{{ __('Login') }}</label>
        <input name="user_name" id="user_name" value="{{ old('user_name', $compte->user_name ?? '') }}" required @if($compte) readonly @endif
            @if($comptesClinisys) list="comptes-clinisys" placeholder="{{ __('Tapez un login ou un nom pour chercher') }}" autocomplete="off" @endif>
        @if($comptesClinisys)
            {{-- Comptes Clinisys actifs : la liste se filtre pendant la saisie (login ou nom). --}}
            <datalist id="comptes-clinisys">
                @foreach($comptesClinisys as $c)<option value="{{ $c->UserName }}" data-nom="{{ $c->Description }}">{{ $c->Description }}</option>@endforeach
            </datalist>
            <div class="doux petit">{{ __(':n comptes Clinisys actifs : choisissez dans la liste, le nom affiché se remplit tout seul.', ['n' => count($comptesClinisys)]) }}</div>
            <script>
                document.getElementById('user_name').addEventListener('change', function () {
                    const o = [...document.querySelectorAll('#comptes-clinisys option')].find(x => x.value.toLowerCase() === this.value.trim().toLowerCase());
                    const nom = document.querySelector('input[name=nom]');
                    if (o) { this.value = o.value; if (! nom.value) nom.value = o.dataset.nom; }
                });
            </script>
        @elseif(! $compte)
            <div class="doux petit">{{ __('Pour un compte Clinisys : le UserName Clinisys exact.') }}@if($erreurClinisys) {{ __('(Liste Clinisys indisponible.)') }}@endif</div>
        @endif</div>
    <div class="champ"><label>{{ __('Nom affiché') }}</label><input name="nom" value="{{ old('nom', $compte->nom ?? '') }}" required></div>
    <div class="champ"><label>{{ __('Type de compte') }}</label>
        <div class="choix">
            <label><input type="radio" name="type" value="clinisys" @checked(old('type', ($compte->pin_hash ?? null) ? 'local' : 'clinisys') === 'clinisys')> {{ __('Clinisys (mot de passe Clinisys)') }}</label>
            <label><input type="radio" name="type" value="local" @checked(old('type', ($compte->pin_hash ?? null) ? 'local' : 'clinisys') === 'local')> {{ __('Local (code PIN)') }}</label>
        </div></div>
    <div class="champ"><label>{{ __('Code PIN (compte local, 4 à 8 chiffres)') }}</label>
        <input name="pin" type="password" inputmode="numeric" autocomplete="new-password" placeholder="{{ ($compte->pin_hash ?? null) ? __('Laisser vide pour garder le PIN actuel') : '' }}"></div>
    <div class="champ"><label>{{ __('Rôles') }}</label>
        <div class="choix" style="flex-direction:column; align-items:stretch;">
            @foreach(config('demandesang.roles') as $c => $l)
                <label><input type="checkbox" name="roles[]" value="{{ $c }}" @checked(in_array($c, old('roles', $roles), true))> {{ __($l) }}</label>
            @endforeach
        </div>
        {{-- Aide : qui confirme chaque remise de main à main (lue dans la configuration du circuit). --}}
        <div class="doux petit" style="margin-top:6px;">{{ __('Qui confirme les remises :') }} @foreach(collect(config('demandesang.etapes'))->where('type', 'remise') as $code => $e){{ $code }} « {{ __($e['court']) }} » : {{ implode(' '.__('ou').' ', array_map(fn ($r) => mb_strtolower(__(config("demandesang.roles.$r"))), $e['receveur'])) }}@if(! $loop->last) · @endif @endforeach.</div></div>
    <div class="champ"><label>{{ __('Services de rattachement (plusieurs possibles)') }}</label>
        <div class="choix">
            @foreach($services as $c => $l)
                <label><input type="checkbox" name="services[]" value="{{ $c }}" @checked(in_array($c, old('services', $sesServices), true))> {{ $l }}</label>
            @endforeach
        </div>
        <div class="doux petit">{{ __('À quoi ça sert : une infirmière ou un major ne voit dans « À faire » que les demandes de ses services (service de soin du patient, service source ou de destination) ; à l\'étape A10, son premier service est proposé si celui du patient est inconnu. Aucun service coché = voit tout. Coursier, surveillant, pharmacie et direction voient toujours tout.') }}</div></div>
    <div class="champ"><label><input type="checkbox" name="admin" value="1" @checked(old('admin', $compte->admin ?? false))> {{ __('Administrateur de l\'application') }}</label></div>
    <div class="champ"><label><input type="checkbox" name="actif" value="1" @checked(old('actif', $compte->actif ?? true))> {{ __('Compte actif') }}</label></div>
    <button class="btn" type="submit">{{ __('Enregistrer') }}</button>
</form>
@endsection
