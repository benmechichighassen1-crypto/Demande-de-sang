@extends('layouts.app')
@section('titre', 'Seuils')
@section('contenu')
@include('admin._menu')
<h1>{{ __('Seuils d\'alerte (minutes)') }}</h1>
<form method="post" action="{{ route('admin.parametres.enregistrer') }}" class="carte table-defile">
    @csrf
    @php($phases = ['service' => __('Au service (jusqu\'à la remise au coursier)'), 'cts' => __('Au CTS (jusqu\'aux poches prêtes)'), 'retour' => __('Retour (arrivée → reçu dans le service)'), 'remise' => __('Remise non confirmée')])
    <table>
        <tr><th>{{ __('Phase') }}</th>@foreach(config('demandesang.urgences') as $u => $l)<th>{{ __($l) }}</th>@endforeach</tr>
        @foreach($phases as $p => $l)
            <tr><td>{{ $l }}</td>
                @foreach(array_keys(config('demandesang.urgences')) as $u)
                    <td><input type="number" min="1" name="seuil[{{ $u }}][{{ $p }}]" value="{{ $ref->seuil($u, $p) }}" style="width:90px;" required></td>
                @endforeach
            </tr>
        @endforeach
    </table>
    <div class="champ" style="margin-top:12px;"><label>{{ __('Alerte péremption : poches à moins de N jours') }}</label>
        <input type="number" min="0" name="peremption_jours" value="{{ $ref->parametre('peremption_jours', config('demandesang.peremption_jours')) }}" style="width:90px;" required></div>
    <h2 style="margin-top:14px;">{{ __('Numéros utiles') }}</h2>
    @foreach(config('demandesang.telephones') as $cle => $t)
        <div class="champ"><label>{{ __($t['libelle']) }}</label>
            <input name="tel_{{ $cle }}" value="{{ $ref->parametre('tel_'.$cle, $t['numero']) }}" maxlength="30" dir="ltr" style="width:200px;"></div>
    @endforeach
    <div class="champ"><label>{{ __('Stock : pastille « Périment dans X jours », valeur de X par défaut') }}</label>
        <input type="number" min="1" max="90" name="perime_bientot_jours" value="{{ $ref->parametre('perime_bientot_jours', config('demandesang.perime_bientot_jours')) }}" style="width:90px;"></div>
    <div class="champ"><label>{{ __('Demandes DMI non inscrites : afficher les N derniers jours') }}</label>
        <input type="number" min="1" max="60" name="dmi_jours" value="{{ $ref->parametre('dmi_jours', config('demandesang.dmi_non_inscrites.jours')) }}" style="width:90px;"></div>
    <div class="champ"><label>{{ __('Alerte si une demande DMI n\'est pas inscrite dans l\'appli après N minutes') }}</label>
        <input type="number" min="1" name="dmi_minutes" value="{{ $ref->parametre('dmi_minutes', config('demandesang.dmi_non_inscrites.minutes')) }}" style="width:90px;"></div>
    <div class="champ"><label>{{ __('Alerte si des poches restent en instance au CTS plus de N jours') }}</label>
        <input type="number" min="1" max="60" name="instance_jours" value="{{ $ref->parametre('instance_jours', config('demandesang.instance_jours')) }}" style="width:90px;"></div>
    <div class="champ"><label>{{ __('Alerte si la pharmacie n\'a pas saisi dans Clinisys le bon de dépôt ou la sortie patient après N minutes') }}</label>
        <input type="number" min="5" name="clinisys_minutes" value="{{ $ref->parametre('clinisys_minutes', config('demandesang.clinisys_minutes')) }}" style="width:90px;"></div>
    <p class="doux petit">{{ __('Le délai court depuis la dernière étape faite. Pas d\'alerte quand les poches sont gardées en instance au CTS, ni après l\'accusé de réception dans le service.') }}</p>
    <button class="btn" type="submit">{{ __('Enregistrer') }}</button>
</form>
@endsection
