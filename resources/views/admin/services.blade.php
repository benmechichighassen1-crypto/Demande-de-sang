@extends('layouts.app')
@section('titre', 'Services')
@section('contenu')
@include('admin._menu')
<h1>{{ __('Services (source / destination)') }}</h1>
@if(config('demandesang.clinisys_actif'))
    <form method="post" action="{{ route('admin.services.clinisys') }}" class="carte">@csrf
        <p style="margin:0 0 8px;">{!! __('La liste vient des <strong>services de soin de Clinisys</strong> (étages des chambres, table prmEtage), comme dans le DMI. Les anciens codes sont désactivés, jamais supprimés.') !!}</p>
        @error('services')<div class="erreur">{{ $message }}</div>@enderror
        <button class="btn sec" type="submit">{{ __('Synchroniser avec Clinisys') }}</button>
    </form>
@endif
{{-- Un formulaire par ligne, relié aux champs par l'attribut form (un <form> ne peut pas entourer un <tr>). --}}
@foreach($services as $s)
    <form id="s-{{ $s->code }}" method="post" action="{{ route('admin.service.enregistrer') }}">@csrf</form>
@endforeach
<form id="s-nouveau" method="post" action="{{ route('admin.service.enregistrer') }}">@csrf</form>
<div class="carte table-defile">
    <table>
        <tr><th>{{ __('Code') }}</th><th>{{ __('Libellé') }}</th><th>{{ __('Ordre') }}</th><th>{{ __('Actif') }}</th><th></th></tr>
        @foreach($services as $s)
            @php($fid = 's-'.$s->code)
            <tr>
                <td><input form="{{ $fid }}" name="code" value="{{ $s->code }}" readonly style="width:110px;"></td>
                <td><input form="{{ $fid }}" name="libelle" value="{{ $s->libelle }}" required style="width:100%;"></td>
                <td><input form="{{ $fid }}" name="ordre" type="number" value="{{ $s->ordre }}" style="width:70px;"></td>
                <td><input form="{{ $fid }}" type="checkbox" name="actif" value="1" @checked($s->actif)></td>
                <td><button form="{{ $fid }}" class="btn petit sec" type="submit">{{ __('Enregistrer') }}</button></td>
            </tr>
        @endforeach
        <tr>
            <td><input form="s-nouveau" name="code" placeholder="CODE" required style="width:110px;"></td>
            <td><input form="s-nouveau" name="libelle" placeholder="{{ __('Nouveau service') }}" required style="width:100%;"></td>
            <td><input form="s-nouveau" name="ordre" type="number" value="0" style="width:70px;"></td>
            <td><input form="s-nouveau" type="checkbox" name="actif" value="1" checked></td>
            <td><button form="s-nouveau" class="btn petit" type="submit">{{ __('Ajouter') }}</button></td>
        </tr>
    </table>
</div>
@endsection
