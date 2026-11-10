{{-- Champ « Rechercher / inscrire » : n° DMI, nom, dossier ou identifiant → écran de recherche et d'inscription. --}}
<form method="get" action="{{ route('demandes.create') }}" class="ligne" style="margin:0 0 10px;">
    <input name="q" placeholder="🔍 {{ __('N° DMI, nom, n° de dossier ou identifiant') }}" style="flex:1; min-width:200px;" autocomplete="off">
    <button class="btn" type="submit">{{ auth()->user()->a('infirmiere', 'major') ? __('Rechercher / inscrire') : __('Rechercher') }}</button>
</form>
