<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Connexion') }} · {{ __('Demandes de sang') }} · {{ __('Clinique Internationale Ibn Sina') }}</title>
    <meta name="theme-color" content="#7f1d1d">
    {{-- Même présentation que l'appli Oncologie, aux couleurs des demandes de sang (bordeaux). Police système si internet est coupé. --}}
    <style>
        :root { --marine:#7f1d1d; --sarcelle:#991b1b; --sarcelle-fonce:#7f1d1d; --or:#fca5a5; --texte:#2b2d42; --doux:#6b7280; --bord:#dfe3ea; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
               font-family:system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color:var(--texte);
               background:linear-gradient(135deg, #fdf2f2 0%, #f5f5f4 55%, #fbeaea 100%); }
        .carte { width:100%; max-width:1160px; min-height:min(720px, calc(100vh - 48px)); display:grid; grid-template-columns:52% 48%;
                 background:#fff; border-radius:26px; overflow:hidden; box-shadow:0 30px 70px rgba(127, 29, 29, .18); }
        .visuel { position:relative; background:#450a0a url('{{ asset('images/reception-ibnsina.jpg') }}') center 35% / cover no-repeat; }
        .visuel::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(69,10,10,.05) 35%, rgba(127,29,29,.6) 62%, rgba(69,10,10,.94) 100%); }
        .visuel .texte { position:absolute; left:34px; right:34px; bottom:18px; z-index:1; color:#fff; }
        .surtitre { color:var(--or); font-size:13px; font-weight:500; letter-spacing:.16em; text-transform:uppercase; }
        .visuel h2 { font-size:32px; font-weight:700; margin:10px 0 12px; }
        .visuel p { font-size:15.5px; font-weight:300; line-height:1.5; margin:0 0 26px; max-width:560px; }
        .trait { width:70px; height:4px; border-radius:2px; background:#ef4444; }
        .formulaire { padding:44px 48px 26px; display:flex; flex-direction:column; }
        .marque { display:flex; align-items:center; gap:16px; }
        .marque img { width:56px; height:56px; border-radius:50%; }
        .marque b { display:block; color:var(--marine); font-size:16px; font-weight:700; }
        .marque span { color:var(--doux); font-size:13px; }
        h1 { color:var(--marine); font-size:30px; font-weight:700; margin:30px 0 8px; }
        .intro { color:var(--doux); font-size:15px; margin:0 0 16px; }
        label { display:block; font-size:15px; margin:16px 0 8px; }
        .champ { position:relative; }
        input { width:100%; font:inherit; font-size:15px; color:var(--texte); padding:10px 14px; border:1px solid var(--bord); border-radius:12px; outline:none; }
        input::placeholder { color:#9ca3af; font-weight:300; }
        input:focus { border-color:var(--sarcelle); box-shadow:0 0 0 3px rgba(153,27,27,.15); }
        .oeil { position:absolute; right:10px; top:50%; transform:translateY(-50%); border:0; background:none; cursor:pointer; color:#374151; padding:6px; }
        .bouton { width:100%; margin-top:18px; padding:12px; font:inherit; font-size:15px; font-weight:500; color:#fff; background:var(--sarcelle); border:0; border-radius:12px; cursor:pointer; }
        .bouton:hover { background:var(--sarcelle-fonce); }
        .erreur { color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:10px 14px; margin:16px 0 0; font-size:15px; }
        .avertissement { display:flex; gap:14px; margin-top:38px; padding:14px 18px; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; font-size:13px; line-height:1.55; color:#374151; }
        .avertissement b { color:var(--marine); }
        .avertissement svg { flex:none; margin-top:3px; }
        .pied { color:var(--doux); font-size:12px; margin-top:18px; line-height:1.6; }
        /* Choix de la langue, en haut du formulaire. */
        .langues { display:flex; justify-content:flex-end; gap:6px; margin-bottom:6px; }
        .langues a { padding:6px 14px; border-radius:999px; border:1px solid var(--bord); color:var(--texte); text-decoration:none; font-size:14px; font-weight:600; }
        .langues a.actif { background:var(--sarcelle); border-color:var(--sarcelle); color:#fff; }
        /* Arabe : de droite à gauche, police arabe sans CDN. */
        [dir=rtl] body { font-family:'Segoe UI', Tahoma, 'Noto Naskh Arabic', Arial, sans-serif; }
        [dir=rtl] .langues { justify-content:flex-start; }
        [dir=rtl] .oeil { right:auto; left:10px; }
        [dir=rtl] #password { padding-right:14px !important; padding-left:48px; }
        @media (max-width: 900px) {
            body { padding:12px; }
            .carte { grid-template-columns:1fr; min-height:0; }
            .visuel { min-height:185px; background-position:center 30%; }
            .visuel .texte { left:24px; right:24px; }
            .visuel h2 { font-size:24px; margin:6px 0 10px; } .visuel p { display:none; } .surtitre { font-size:11px; }
            .formulaire { padding:28px 22px; }
        }
    </style>
</head>
<body>
<main class="carte">
    <section class="visuel" aria-hidden="true">
        <div class="texte">
            <div class="surtitre">{{ __('Clinique Internationale Ibn Sina') }} · {{ __('Tanger') }}</div>
            <h2>🩸 {{ __('Demandes de sang') }}</h2>
            <p>{{ __('Traçabilité des demandes de sang, de la prescription à la transfusion, à partir des données de Clinisys.') }}</p>
            <div class="trait"></div>
        </div>
    </section>

    <section class="formulaire">
        <div class="langues" aria-label="Langue / اللغة">
            @foreach(\App\Http\Middleware\Langue::LANGUES as $code => $nom)
                <a href="{{ route('langue', $code) }}" lang="{{ $code }}" class="{{ app()->getLocale() === $code ? 'actif' : '' }}">{{ $nom }}</a>
            @endforeach
        </div>
        <div class="marque">
            <img src="{{ asset('images/logo-ibnsina.png') }}" alt="{{ __('Logo Clinique Internationale Ibn Sina') }}">
            <div><b>{{ __('Clinique Internationale Ibn Sina') }}</b><span>{{ __('Tanger') }} · {{ __('Demandes de sang') }}</span></div>
        </div>

        <h1>{{ __('Connexion') }}</h1>
        <p class="intro">{{ __('Accès réservé au personnel autorisé de la clinique') }}</p>

        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <input type="hidden" name="langue" value="{{ app()->getLocale() }}">
            <label for="username">{{ __('Identifiant') }}</label>
            <input id="username" name="username" value="{{ old('username') }}" placeholder="{{ __('identifiant Clinisys') }}" autocapitalize="none" required autofocus autocomplete="username">

            <label for="password">{{ __('Mot de passe') }} <span style="color:var(--doux); font-size:13px;">{{ __('(ou code PIN pour un compte local)') }}</span></label>
            <div class="champ">
                <input id="password" type="password" name="password" placeholder="{{ __('mot de passe Clinisys ou code PIN') }}" required autocomplete="current-password" style="padding-right:48px">
                <button type="button" class="oeil" title="{{ __('Afficher le mot de passe') }}" aria-label="{{ __('Afficher le mot de passe') }}"
                        onclick="const c = document.getElementById('password'); c.type = c.type === 'password' ? 'text' : 'password';">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            @error('username')<p class="erreur">{{ $message }}</p>@enderror
            <button class="bouton" type="submit">{{ __('Se connecter') }}</button>
        </form>

        <div class="avertissement">
            <svg width="16" height="18" viewBox="0 0 24 24" fill="var(--marine)"><path d="M6 10V7a6 6 0 1 1 12 0v3h1a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1zm2 0h8V7a4 4 0 1 0-8 0v3zm4 4a2 2 0 0 0-1 3.73V20h2v-2.27A2 2 0 0 0 12 14z"/></svg>
            <div><b>{{ __('Accès réservé.') }}</b> {{ __('Cette application contient des données de santé, couvertes par le secret médical et professionnel. Toute connexion ou tentative de connexion sans autorisation est interdite et expose son auteur à des poursuites (loi n° 07-03 relative aux atteintes aux systèmes de traitement automatisé des données ; loi n° 09-08 relative à la protection des données à caractère personnel). Chaque connexion est enregistrée.') }}</div>
        </div>
        <p class="pied">@php $duree = (int) config('session.lifetime'); @endphp
            {{ __('Identifiant et mot de passe Clinisys, ou code PIN pour un compte local') }} ·
            {{ $duree % 60 === 0 ? trans_choice('session fermée après :n heure sans activité|session fermée après :n heures sans activité', $duree / 60, ['n' => $duree / 60]) : __('session fermée après :n minutes sans activité', ['n' => $duree]) }}{{ config('session.expire_on_close') ? __(' ou à la fermeture du navigateur') : '' }}. {{ __('Chaque action est enregistrée avec votre identifiant, la date et l\'heure.') }}</p>
    </section>
</main>
</body>
</html>
