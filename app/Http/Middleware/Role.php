<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** role:major,surveillant — au moins un des rôles ; role:admin — administrateur de l'appli. */
class Role
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $u = $request->user();
        // L'administrateur voit aussi ce que voit la direction (menu « comme la direction »).
        $ok = $u && (in_array('admin', $roles, true) ? $u->admin : ($u->a(...$roles) || ($u->admin && in_array('direction', $roles, true))));
        abort_unless($ok, 403, 'Votre rôle ne donne pas accès à cette page.');

        return $next($request);
    }
}
