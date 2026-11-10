<!doctype html>
<html lang="{{ app()->getLocale() }}" @if(app()->getLocale() === 'ar') dir="rtl" @endif><head><meta charset="utf-8"><title>{{ __('Bon demande de sang n° :id', ['id' => $d->id]) }}</title>
<style>
    body { font-family: Arial, sans-serif; font-size:12px; margin:20px; color:#000; }
    h1 { font-size:18px; margin:0; } table { width:100%; border-collapse:collapse; margin-top:10px; }
    td, th { border:1px solid #000; padding:5px; text-align:start; vertical-align:top; } th { background:#eee; }
    .entete { display:flex; justify-content:space-between; border-bottom:2px solid #000; padding-bottom:8px; }
    @media print { button { display:none; } thead { display:table-header-group; } tr { break-inside:avoid; page-break-inside:avoid; } }
</style></head>
<body onload="window.print()">
<div class="entete">
    <div><b>{{ config('demandesang.etablissement') }}</b><h1>{{ __('Demande de produits sanguins n° :id', ['id' => $d->id]) }}</h1></div>
    <div style="text-align:end;">{{ __('N° Clinisys :') }} <b>{{ $d->num_demande_clinisys }}</b><br>{{ __('Inscrite le') }} {{ $d->cree_le->format('d/m/Y H:i') }}</div>
</div>
<table>
    <tr><th>{{ __('Patient') }}</th><td><strong>{{ $d->nom_patient }}</strong></td><th>{{ __('N° CIN') }}</th><td><strong style="font-size:16px;">{{ $d->cin ?: '—' }}</strong>@if($d->cin_source === 'autre') ({{ __('autre pièce') }})@endif</td></tr>
    <tr><th>{{ __('Groupe') }}</th><td>{{ $d->groupe_sanguin ?: '—' }}</td><th>{{ __('N° dossier') }}</th><td>{{ $d->num_doss }} {{ $d->libelleCas() ? '('.$d->libelleCas().')' : '' }}</td></tr>
    @if($paiement = $d->paiement())<tr><th>{{ __('Paiement') }}</th><td colspan="3">{{ $paiement }}</td></tr>@endif
    <tr><th>{{ __('Produit') }}</th><td>{{ $d->nb_poches }} × {{ __(config("demandesang.produits.{$d->produit}")) }} {{ $d->groupe_sanguin }}</td><th>{{ __('Urgence') }}</th><td>{{ __(config("demandesang.urgences.{$d->urgence}")) }}</td></tr>
    <tr><th>{{ __('Diagnostic') }}</th><td>{{ $d->diagnostic }}</td><th>{{ __('Poches au CTS') }}</th><td>{{ __(config("demandesang.natures.{$d->nature}")) }}</td></tr>
    <tr><th>{{ __('Service source') }}</th><td>{{ $ref->service($d->service_source) }}</td><th>{{ __('Destination') }}</th><td>{{ $ref->service($d->service_destination) }}</td></tr>
</table>
<table>
    <thead><tr><th>{{ __('Étape') }}</th><th>{{ __('Fait par') }}</th><th>{{ __('Le') }}</th><th>{{ __('Reçu par') }}</th><th>{{ __('Le') }}</th></tr></thead>
    @foreach($etat as $code => $e)
        @continue($e['statut'] === 'sans_objet')
        <tr><td>{{ $code }} {{ __($e['def']['court']) }}</td>
            @if($e['remise'])
                <td>{{ $e['remise']->nom_donneur }}</td><td>{{ $e['remise']->remis_le->format('d/m H:i') }}</td>
                <td>{{ $e['remise']->nom_receveur }}</td><td>{{ $e['remise']->repondu_le?->format('d/m H:i') }}</td>
            @else
                <td>{{ $e['action']?->nom_acteur }}</td><td>{{ $e['action']?->fait_le->format('d/m H:i') }}</td><td colspan="2" style="background:#f7f7f7;"></td>
            @endif
        </tr>
    @endforeach
</table>
<button onclick="window.print()" style="margin-top:12px;">{{ __('Imprimer') }}</button>
</body></html>
