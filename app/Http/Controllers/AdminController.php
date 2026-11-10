<?php

namespace App\Http\Controllers;

use App\Clinisys\Comptes;
use App\Services\Journal;
use App\Services\Referentiels;
use App\Services\ServicesClinisys;
use App\Support\Clinisys as C;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Paramétrage : comptes et rôles, services, seuils d'alerte, journal. */
class AdminController extends Controller
{
    public function comptes(Referentiels $ref): View
    {
        return view('admin.comptes', ['comptes' => DB::table('ds_comptes')->orderBy('nom')->get(), 'ref' => $ref,
            'servicesComptes' => DB::table('ds_compte_services')->get()->groupBy('user_name')]);
    }

    public function compte(Referentiels $ref, Comptes $clinisys, ?string $userName = null): View
    {
        $compte = $userName ? DB::table('ds_comptes')->where('user_name', $userName)->first() : null;
        abort_if($userName && ! $compte, 404);

        return view('admin.compte', ['compte' => $compte, 'services' => $ref->services(),
            'sesServices' => $compte ? DB::table('ds_compte_services')->where('user_name', $compte->user_name)->pluck('service_code')->all() : [],
            // Comptes Clinisys actifs pour choisir le login (10 min en cache) ; vide si Clinisys ne répond pas.
            'comptesClinisys' => $compte ? [] : C::essayer(fn () => $clinisys->actifs(), []), 'erreurClinisys' => C::$erreur]);
    }

    public function enregistrerCompte(Request $request, Referentiels $ref): RedirectResponse
    {
        $data = $request->validate([
            'user_name' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'nom' => ['required', 'string', 'max:120'],
            'roles' => ['nullable', 'array'],
            'roles.*' => [Rule::in(array_keys(config('demandesang.roles')))],
            'services' => ['nullable', 'array'],
            'services.*' => [Rule::in(array_keys($ref->services(true)))],
            'type' => ['required', 'in:clinisys,local'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'admin' => ['nullable', 'boolean'],
            'actif' => ['nullable', 'boolean'],
        ], ['pin.regex' => __('Le code PIN doit faire 4 à 8 chiffres.')]);

        $existant = DB::table('ds_comptes')->where('user_name', $data['user_name'])->first();
        $pinHash = $existant->pin_hash ?? null;
        if ($data['type'] === 'clinisys') {
            $pinHash = null;
        } elseif (! empty($data['pin'])) {
            $pinHash = password_hash($data['pin'], PASSWORD_DEFAULT);
        } elseif ($pinHash === null) {
            return back()->withInput()->withErrors(['pin' => __('Un compte local a besoin d\'un code PIN.')]);
        }

        $valeurs = [
            'nom' => $data['nom'],
            'roles' => implode(',', $data['roles'] ?? []),
            'pin_hash' => $pinHash,
            'admin' => (bool) ($data['admin'] ?? false),
            'actif' => (bool) ($data['actif'] ?? false),
            'modifie_par' => $request->user()->userName,
            'modifie_le' => now(),
        ];
        DB::transaction(function () use ($data, $valeurs) {
            DB::table('ds_comptes')->updateOrInsert(['user_name' => $data['user_name']], $valeurs);
            // Services de rattachement (plusieurs possibles).
            DB::table('ds_compte_services')->where('user_name', $data['user_name'])->delete();
            foreach (array_unique($data['services'] ?? []) as $code) {
                DB::table('ds_compte_services')->insert(['user_name' => $data['user_name'], 'service_code' => $code]);
            }
        });
        Journal::ecrire('compte', ['user_name' => $data['user_name'], 'roles' => $valeurs['roles'], 'type' => $data['type'],
            'services' => $data['services'] ?? [], 'pin_change' => ! empty($data['pin']), 'admin' => $valeurs['admin'], 'actif' => $valeurs['actif']]);

        return redirect()->route('admin.comptes')->with('statut', __('Compte :login enregistré.', ['login' => $data['user_name']]));
    }

    public function services(): View
    {
        return view('admin.services', ['services' => DB::table('ds_services')->orderBy('ordre')->orderBy('libelle')->get()]);
    }

    public function enregistrerService(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/'],
            'libelle' => ['required', 'string', 'max:120'],
            'ordre' => ['nullable', 'integer'],
            'actif' => ['nullable', 'boolean'],
        ], ['code.regex' => __('Code en majuscules, chiffres et _ uniquement.')]);

        DB::table('ds_services')->updateOrInsert(['code' => $data['code']], [
            'libelle' => $data['libelle'], 'ordre' => $data['ordre'] ?? 0, 'actif' => (bool) ($data['actif'] ?? false),
        ]);
        Journal::ecrire('service', $data);

        return back()->with('statut', __('Service enregistré.'));
    }

    /** Recopie les services de soin de Clinisys (prmEtage) ; les anciens codes sont désactivés, pas supprimés. */
    public function synchroniserServices(ServicesClinisys $services): RedirectResponse
    {
        abort_unless(config('demandesang.clinisys_actif'), 422, __('Lecture Clinisys désactivée.'));
        try {
            $r = $services->synchroniser();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['services' => __('Synchronisation impossible : :erreur', ['erreur' => mb_substr($e->getMessage(), 0, 200)])]);
        }

        return back()->with('statut', __('Services de soin Clinisys : :ajoutes ajouté(s), :maj mis à jour', ['ajoutes' => $r['ajoutes'], 'maj' => $r['mis_a_jour']])
            .($r['desactives'] ? __(', désactivés (gardés pour l\'historique) : :codes', ['codes' => implode(', ', $r['desactives'])]) : '').'.');
    }

    public function parametres(Referentiels $ref): View
    {
        return view('admin.parametres', ['ref' => $ref]);
    }

    public function enregistrerParametres(Request $request): RedirectResponse
    {
        $regles = [
            'peremption_jours' => ['required', 'integer', 'min:0', 'max:60'],
            'dmi_jours' => ['nullable', 'integer', 'min:1', 'max:60'],
            'dmi_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'instance_jours' => ['nullable', 'integer', 'min:1', 'max:60'],
            'clinisys_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'perime_bientot_jours' => ['nullable', 'integer', 'min:1', 'max:90'],
            'tel_coursier' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ .()-]+$/'],
            'tel_surveillant' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ .()-]+$/'],
            'tel_major' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ .()-]+$/'],
        ];
        foreach (config('demandesang.seuils_defaut') as $urgence => $phases) {
            foreach (array_keys($phases) as $phase) {
                $regles["seuil.$urgence.$phase"] = ['required', 'integer', 'min:1', 'max:10080'];
            }
        }
        $data = $request->validate($regles);

        $valeurs = ['peremption_jours' => $data['peremption_jours']];
        foreach (['dmi_jours', 'dmi_minutes', 'instance_jours', 'clinisys_minutes', 'perime_bientot_jours', 'tel_coursier', 'tel_surveillant', 'tel_major'] as $cle) {
            if (isset($data[$cle])) {
                $valeurs[$cle] = $data[$cle];
            }
        }
        foreach ($data['seuil'] as $urgence => $phases) {
            foreach ($phases as $phase => $minutes) {
                $valeurs["seuil.$urgence.$phase"] = $minutes;
            }
        }
        foreach ($valeurs as $cle => $valeur) {
            DB::table('ds_parametres')->updateOrInsert(['cle' => $cle], [
                'valeur' => (string) $valeur, 'modifie_par' => $request->user()->userName, 'modifie_le' => now(),
            ]);
        }
        Journal::ecrire('parametres', $valeurs);

        return back()->with('statut', __('Paramètres enregistrés.'));
    }

    /** Journal des connexions et consultations, lisible, avec filtres. */
    public function journal(Request $request): View
    {
        [$f, $requete] = $this->requeteJournal($request);

        return view('admin.journal', [
            'lignes' => $requete->paginate(50)->withQueryString(), 'f' => $f,
            'noms' => DB::table('ds_comptes')->pluck('nom', 'user_name'),
            'utilisateurs' => DB::table('ds_journal')->whereNotNull('user_name')->distinct()->orderBy('user_name')->pluck('user_name'),
        ]);
    }

    /** Export CSV du journal filtré (séparateur « ; », BOM pour Excel). */
    public function exporterJournal(Request $request): StreamedResponse
    {
        [, $requete] = $this->requeteJournal($request);
        $noms = DB::table('ds_comptes')->pluck('nom', 'user_name');

        return response()->streamDownload(function () use ($requete, $noms) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Heure', 'Login', 'Nom', 'Action', 'Demande', 'Poste (IP)'], ';');
            foreach ($requete->limit(20000)->cursor() as $l) {
                $x = Journal::libelle($l);
                $t = \Illuminate\Support\Carbon::parse($l->cree_le);
                fputcsv($out, [$t->format('d/m/Y'), $t->format('H:i:s'), $l->user_name, $noms[$l->user_name] ?? '', $x['texte'], $x['demande'] ?? '', $l->ip], ';');
            }
            fclose($out);
        }, 'journal-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function requeteJournal(Request $request): array
    {
        $f = $request->validate([
            'du' => ['nullable', 'date'], 'au' => ['nullable', 'date'], 'user' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::in(array_keys(Journal::TYPES))], 'demande' => ['nullable', 'string', 'max:60'],
        ]);
        $route = fn ($q, array $routes) => $q->where(fn ($q) => collect($routes)->each(fn ($r) => $q->orWhere('details', 'like', '%"route":"'.$r.'"%')));
        $requete = DB::table('ds_journal')
            ->when($f['du'] ?? null, fn ($q, $v) => $q->where('cree_le', '>=', $v))
            ->when($f['au'] ?? null, fn ($q, $v) => $q->where('cree_le', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('user_name', $v))
            ->when($f['demande'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('demande_id', ctype_digit($v) ? (int) $v : 0)
                ->orWhere('details', 'like', '%"dossier":"'.$v.'"%')->orWhere('details', 'like', '%"dmi":"'.$v.'"%')
                // Recherche libre : utilisateur (login) ou texte enregistré (page, n° affiché…).
                ->orWhere('user_name', 'like', '%'.str_replace(['%', '_', '['], '', $v).'%')->orWhere('details', 'like', '%'.str_replace(['%', '_', '['], '', $v).'%')))
            ->when($f['type'] ?? null, fn ($q, $v) => match ($v) {
                'connexion', 'deconnexion', 'connexion_refusee' => $q->where('action', $v),
                'demande' => $route($q->where('action', 'page'), ['demandes.show']),
                'export' => $route($q->where('action', 'page'), ['demandes.bon', 'indicateurs.export', 'admin.journal.export']),
                'page' => $q->where('action', 'page')->where('details', 'not like', '%"route":"demandes.show"%')
                    ->where('details', 'not like', '%"route":"demandes.bon"%')->where('details', 'not like', '%.export"%'),
                'parametrage' => $q->whereIn('action', ['compte', 'service', 'parametres', 'services_clinisys']),
            })
            ->orderByDesc('id');

        return [$f, $requete];
    }
}
