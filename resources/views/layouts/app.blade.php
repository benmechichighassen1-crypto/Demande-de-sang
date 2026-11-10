<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#7f1d1d">
    @stack('tete')
    <title>{{ __(trim($__env->yieldContent('titre', 'Demandes de sang'))) }} · {{ __('Demandes de sang') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
</head>
<body>
@auth
@php($u = auth()->user())
<header>
    <div class="barre">
        <a class="marque" href="{{ route('accueil') }}">🩸 {{ __('Demandes de sang') }}</a>
        <div class="droite">
            <span class="masque-mobile">{{ $u->nom }}</span>
            {{-- Changement de langue : bascule vers l'autre langue. --}}
            @if(app()->getLocale() === 'ar')
                <a class="langue-bouton" href="{{ route('langue', 'fr') }}" lang="fr" title="Afficher en français">Français</a>
            @else
                <a class="langue-bouton" href="{{ route('langue', 'ar') }}" lang="ar" title="عرض بالعربية">العربية</a>
            @endif
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" title="{{ __('Se déconnecter') }}" style="display:inline-flex; align-items:center; gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>{{ __('Déconnexion') }}</button></form>
        </div>
    </div>
    {{-- Menu selon le rôle : seulement les écrans utiles ; un compte à plusieurs rôles voit l'union, dans cet ordre.
         Administrateur : comme la direction, plus le paramétrage. --}}
    @php($r = fn (string ...$roles) => $u->a(...$roles) || ($u->admin && in_array('direction', $roles, true)))
    @php($menu = array_filter([
        ['accueil', 'À faire', 'accueil', $r('infirmiere', 'major', 'coursier', 'surveillant')],
        ['cts', 'CTS', 'cts', $r('coursier', 'surveillant')],
        ['pharmacie', 'Pharmacie', 'pharmacie', $r('surveillant', 'pharmacie')],
        ['demandes.index', 'Demandes', 'demandes.*', $r('infirmiere', 'major', 'surveillant', 'pharmacie', 'direction')],
        ['registre', 'Registre', 'registre', $r('infirmiere', 'major', 'direction')],
        ['stock', 'Stock', 'stock', $r('pharmacie', 'direction')],
        ['alertes', 'Alertes', 'alertes', $r('surveillant', 'direction')],
        ['indicateurs', 'Indicateurs', 'indicateurs', $r('direction')],
        ['admin.comptes', 'Paramétrage', 'admin.*', $u->admin],
    ], fn ($m) => $m[3]))
    <nav id="menu">
        @foreach(array_values($menu) as $i => [$route, $libelle, $actif])
            <a href="{{ route($route) }}" class="{{ request()->routeIs($actif) ? 'actif' : '' }} {{ $i >= 3 && count($menu) > 4 && ! request()->routeIs($actif) ? 'extra' : '' }}">{{ __($libelle) }}</a>
        @endforeach
        @if(count($menu) > 4)
            <a href="#" class="plus" onclick="document.getElementById('menu').classList.toggle('ouvert'); return false;">{{ __('Plus') }} ▾</a>
        @endif
    </nav>
</header>
@endauth
<main>
    {{-- Administrateur : mise à jour décompressée mais mise-a-jour.bat pas lancé (cache de configuration ancien, base pas à jour). --}}
    @if(auth()->user()?->admin && ($problemesInstallation = \App\Services\EtatInstallation::problemes()) !== [])
        <div class="alerte rouge non-imprime"><strong>⚠ {{ __('Mise à jour incomplète : lancez mise-a-jour.bat sur le serveur.') }}</strong> ({{ implode(' ; ', $problemesInstallation) }})</div>
    @endif
    @if(session('statut'))
        <div class="notif" id="notif" role="status" onclick="this.remove()">{{ session('statut') }}</div>
        <script>setTimeout(() => { const n = document.getElementById('notif'); if (n) { n.style.opacity = 0; setTimeout(() => n.remove(), 400); } }, 4000);</script>
    @endif
    @if($errors->any())
        <div class="alerte rouge">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif
    @yield('contenu')
</main>
<script>window.DS = {!! json_encode(['T' => ['toutes' => __('Toutes'), 'vue' => __('cette vue'), 'aucune' => __('Aucune tâche pour :role dans :etape'), 'voir' => __(' : voir '), 'rien' => __('Rien à faire pour :role pour le moment ✓')], 'resultats' => __(':n résultat(s)'), 'effacerRecherche' => __('Effacer la recherche'), 'nouvelles' => __('Nouvelles données : actualiser')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
