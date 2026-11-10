{{-- Numéros utiles (diagramme ; modifiables dans Paramétrage). Variable : $quoi = clés de config('demandesang.telephones'). --}}
@php($refTel = app(\App\Services\Referentiels::class))
<div class="petit telephones" style="margin:4px 0;">
    @foreach($quoi as $cle)
        @php($t = config("demandesang.telephones.$cle"))
        @php($numero = $refTel->parametre("tel_$cle", $t['numero']))
        <span style="white-space:nowrap; margin-inline-end:12px;">📞 {{ __($t['libelle']) }} : <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $numero) }}" dir="ltr" style="font-weight:600;">{{ $numero }}</a></span>
    @endforeach
</div>
