{{-- Badge du responsable d'une étape : « À faire par : Coursier » dans la couleur du rôle.
     Variables : $roles (codes de rôle), $prefixe (défaut « À faire par »). --}}
@php($badges = config('demandesang.roles_badges'))
@php($connus = array_values(array_filter($roles, fn ($r) => isset($badges[$r]))))
@if($connus !== [])
    <span class="role-puce" style="--r:{{ $badges[$connus[0]][1] }};">👤 {{ __($prefixe ?? 'À faire par') }} : {{ implode(' / ', array_map(fn ($r) => __($badges[$r][0]), $connus)) }}</span>
@endif
