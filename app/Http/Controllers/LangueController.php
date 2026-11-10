<?php

namespace App\Http\Controllers;

use App\Http\Middleware\Langue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/** Changement de langue (page de connexion ou en-tête) : session, cookie d'un an et compte de l'utilisateur. */
class LangueController extends Controller
{
    public function choisir(Request $request, string $langue): RedirectResponse
    {
        abort_unless(array_key_exists($langue, Langue::LANGUES), 404);
        $request->session()->put('langue', $langue);
        if ($u = $request->user()) {
            DB::table('ds_comptes')->where('user_name', $u->getAuthIdentifier())->update(['langue' => $langue]);
        }
        Cookie::queue('langue', $langue, 60 * 24 * 365);

        return redirect()->back(fallback: route('login'));
    }
}
