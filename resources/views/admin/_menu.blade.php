<div class="ligne non-imprime" style="margin-bottom:12px;">
    <a class="btn petit {{ request()->routeIs('admin.comptes', 'admin.compte*') ? '' : 'gris' }}" href="{{ route('admin.comptes') }}">{{ __('Comptes et rôles') }}</a>
    <a class="btn petit {{ request()->routeIs('admin.services') ? '' : 'gris' }}" href="{{ route('admin.services') }}">{{ __('Services') }}</a>
    <a class="btn petit {{ request()->routeIs('admin.parametres') ? '' : 'gris' }}" href="{{ route('admin.parametres') }}">{{ __('Seuils d\'alerte') }}</a>
    <a class="btn petit {{ request()->routeIs('admin.journal') ? '' : 'gris' }}" href="{{ route('admin.journal') }}">{{ __('Journal') }}</a>
</div>
