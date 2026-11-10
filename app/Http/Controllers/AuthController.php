<?php

namespace App\Http\Controllers;

use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Connexion : login et mot de passe Clinisys, ou login et code PIN d'un compte local. */
class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.connexion');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $cle = 'connexion:'.mb_strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            throw ValidationException::withMessages([
                'username' => __('Trop de tentatives. Réessayez dans :n secondes.', ['n' => RateLimiter::availableIn($cle)]),
            ]);
        }

        if (! Auth::attempt(['username' => $data['username'], 'password' => $data['password']])) {
            RateLimiter::hit($cle, 300);
            Journal::ecrire('connexion_refusee', ['login' => $data['username']], $data['username']);
            throw ValidationException::withMessages(['username' => __('Identifiant ou mot de passe incorrect, compte inactif, ou compte sans rôle dans l\'application.')]);
        }

        RateLimiter::clear($cle);
        $request->session()->regenerate();
        // Langue : celle enregistrée sur le compte, sinon celle choisie sur la page de connexion (gardée sur le compte).
        $choisie = $request->session()->get('langue') ?? $request->cookie('langue');
        $compte = DB::table('ds_comptes')->where('user_name', Auth::user()->getAuthIdentifier());
        if ($request->filled('langue') && array_key_exists($request->input('langue'), \App\Http\Middleware\Langue::LANGUES)) {
            $choisie = $request->input('langue');
            $compte->update(['langue' => $choisie]);
        } elseif ($enregistree = $compte->value('langue')) {
            $choisie = $enregistree;
        } elseif ($choisie) {
            $compte->update(['langue' => $choisie]);
        }
        if ($choisie) {
            $request->session()->put('langue', $choisie);
            \Illuminate\Support\Facades\Cookie::queue('langue', $choisie, 60 * 24 * 365);
        }
        Journal::ecrire('connexion');

        return redirect()->intended(route('accueil'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Journal::ecrire('deconnexion');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
