{{-- Paiement : reçu ou patient à la charge d'un médecin, du personnel ou d'une association (n° / nom facultatifs).
     Variables : $requis (bool), $attr (attribut des champs requis, ex. data-req). --}}
@php($choix = old('paiement'))
@php($charge = old('paiement_charge'))
@php($medecins = \App\Support\Clinisys::essayer(fn () => app(\App\Clinisys\Medecins::class)->noms(), []))
<div class="champ" data-paiement>
    <label>{{ __('Paiement') }} @unless($requis)<span class="doux petit">{{ __('(facultatif)') }}</span>@endunless</label>
    <div class="choix">
        @foreach(config('demandesang.paiements') as $c => $p)
            <label><input type="radio" name="paiement" value="{{ $c }}" @checked($choix === $c) {!! $requis ? ($attr ?? '').' required' : '' !!}
                onchange="paiementChoisi(this)"> {{ __($p['libelle']) }}</label>
        @endforeach
    </div>
    <div data-zone="charge" style="margin-top:6px; {{ $choix === 'charge' ? '' : 'display:none;' }}">
        <div class="choix">
            @foreach(config('demandesang.charges') as $c => $l)
                <label><input type="radio" name="paiement_charge" value="{{ $c }}" @checked($charge === $c) @if($choix === 'charge') required @endif onchange="paiementChoisi(this)"> {{ __($l) }}</label>
            @endforeach
        </div>
    </div>
    <div data-zone="ref" style="margin-top:6px; {{ $choix ? '' : 'display:none;' }}">
        <label data-ref-label>{{ $choix === 'recu' ? __('N° du reçu (facultatif)') : __('Nom (facultatif)') }}</label>
        <input name="paiement_ref" value="{{ old('paiement_ref') }}" maxlength="60" style="width:100%;" autocomplete="off"
            @if($charge === 'medecin') list="medecins-clinisys" @endif>
        @if($medecins)
            <datalist id="medecins-clinisys">@foreach($medecins as $m)<option value="{{ $m }}">@endforeach</datalist>
        @endif
    </div>
</div>
@once
<script>
    // Affiche les bons champs selon le choix : reçu → n° ; à la charge → qui (médecin / personnel / association) puis son nom.
    function paiementChoisi(el) {
        const z = el.closest('[data-paiement]');
        const paiement = z.querySelector('input[name=paiement]:checked')?.value;
        const charge = z.querySelector('input[name=paiement_charge]:checked')?.value;
        const zc = z.querySelector('[data-zone=charge]'), zr = z.querySelector('[data-zone=ref]'), ref = z.querySelector('input[name=paiement_ref]');
        zc.style.display = paiement === 'charge' ? '' : 'none';
        z.querySelectorAll('input[name=paiement_charge]').forEach(r => r.required = paiement === 'charge');
        zr.style.display = paiement ? '' : 'none';
        const libelles = {medecin: @json(__('Nom du médecin (facultatif)')), personnel: @json(__("Nom de l'agent (facultatif)")), association: @json(__("Nom de l'association (facultatif)"))};
        z.querySelector('[data-ref-label]').textContent = paiement === 'recu' ? @json(__('N° du reçu (facultatif)')) : (libelles[charge] || @json(__('Nom (facultatif)')));
        if (paiement === 'charge' && charge === 'medecin') { ref.setAttribute('list', 'medecins-clinisys'); } else { ref.removeAttribute('list'); }
    }
</script>
@endonce
