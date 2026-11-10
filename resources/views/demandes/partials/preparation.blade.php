{{-- Préparation du dossier (A4, A6, A7, A9) en un seul formulaire. Variables : $d, $codes, $retour. --}}
<form method="post" action="{{ route('demandes.preparation', $d) }}" class="carte" id="etape-preparation" style="border-left:4px solid var(--rouge);">
    @csrf
    @if($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endif
    <h3>{{ __('Préparation de la demande') }}</h3>
    <p class="doux petit">{!! __("Chaque étape est enregistrée séparément au registre, avec votre nom et l'heure. Décochez une étape pour la faire plus tard.") !!}</p>

    @foreach($codes as $code)
        @php($def = config("demandesang.etapes.$code"))
        <fieldset style="border:1px solid var(--bord); border-radius:8px; padding:8px 10px; margin:0 0 10px;">
            <legend><label><input type="checkbox" name="etapes[]" value="{{ $code }}" checked
                onchange="this.closest('fieldset').querySelectorAll('[data-req]').forEach(e => e.required = this.checked)"> {{ $code }} · {{ __($def['libelle']) }}</label></legend>
            @switch($code)
                @case('A4')
                    <label><input type="checkbox" name="identite_ok" value="1" data-req required> {!! __("J'ai vérifié l'identité du patient (étiquette)") !!}</label>
                    @break
                @case('A6')
                    <div class="choix">
                        <label><input type="radio" name="carte_groupage" value="oui" data-req required> {{ __('Carte de groupage : 1 tube') }}</label>
                        <label><input type="radio" name="carte_groupage" value="non"> {{ __('Pas de carte : 2 tubes') }}</label>
                    </div>
                    @break
                @case('A7')
                    <div class="choix" style="flex-direction:column; align-items:stretch;">
                        <label><input type="checkbox" name="pieces[demande]" value="1" data-req required> {{ __('Demande papier + fiche Clinisys') }}</label>
                        <label><input type="checkbox" name="pieces[analyses]" value="1"> {{ __('Analyses précédentes') }}</label>
                    </div>
                    @break
                @case('A9')
                    @include('demandes.partials.telephones', ['quoi' => ['coursier']])
                    <label>{{ __('Heure (si différente de maintenant)') }} <input type="time" name="heure" style="max-width:160px"></label>
                    @break
            @endswitch
        </fieldset>
    @endforeach

    <details><summary class="doux petit">{{ __('Commentaire') }}</summary>
        <textarea name="commentaire" rows="2" style="width:100%;">{{ old('commentaire') }}</textarea>
    </details>
    <button class="btn large" type="submit" style="margin-top:8px;">{{ __('Valider la préparation') }}</button>
</form>
