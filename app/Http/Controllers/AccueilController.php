<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Demande;
use App\Models\Remise;
use App\Services\Circuit;
use App\Support\Clinisys as C;
use App\Services\DmiNonInscrites;
use App\Services\Referentiels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « À faire » : écran d'accueil sur smartphone. Les remises à confirmer et
 * les étapes que l'utilisateur peut saisir (remplace les échanges WhatsApp).
 */
class AccueilController extends Controller
{
    public function index(Request $request, Circuit $circuit, Referentiels $ref, DmiNonInscrites $dmi): View|RedirectResponse
    {
        $u = $request->user();
        // Pharmacie, direction : pas d'étape à saisir, « À faire » n'est pas dans leur menu → leur premier écran.
        if (! $u->a('infirmiere', 'major', 'coursier', 'surveillant')) {
            return redirect()->route($u->a('pharmacie') ? 'pharmacie' : 'demandes.index');
        }
        // Demandes saisies dans le DMI à inscrire : pour le service et le surveillant.
        $dmiNonInscrites = $u->a('infirmiere', 'major', 'surveillant') ? $dmi->liste() : [];

        // Infirmière / major rattachée à des services : seulement les demandes de ses services
        // (source, destination ou service de soin du patient) et celles qu'elle a créées.
        $mesServices = $u->limiteAuxServices() ? $u->services : null;
        $mesLibelles = $mesServices ? array_map(fn ($c) => mb_strtoupper(trim($ref->service($c))), $mesServices) : [];
        $deMesServices = fn (Demande $d) => $mesServices === null || $d->cree_par === $u->userName
            || in_array($d->service_source, $mesServices, true) || in_array($d->service_destination, $mesServices, true)
            || in_array(mb_strtoupper(trim((string) $d->service_saisie_dmi)), $mesLibelles, true);
        if ($mesServices !== null) {
            $dmiNonInscrites = array_values(array_filter($dmiNonInscrites, fn ($x) => in_array(mb_strtoupper(trim((string) ($x->service ?? ''))), $mesLibelles, true)));
        }
        $dmiJours = $dmi->jours();
        $dmiMinutes = $dmi->minutes();

        $aConfirmer = Remise::with('demande')->where('reponse', 'attente')->orderBy('remis_le')->get()
            ->filter(fn ($r) => $circuit->peutConfirmer($r, $u) && $deMesServices($r->demande));
        $mesRemises = Remise::with('demande')->where('reponse', 'attente')->where('donneur', $u->userName)->orderBy('remis_le')->get();

        $aFaire = [];
        // État du circuit seulement : sans les colonnes texte (donnees, commentaire, appareil), lourdes à transférer.
        foreach (Demande::with(['actions:id,demande_id,etape,user_name,nom_acteur,fait_le,annule_action_id,remise_id', 'remises'])->where('statut', 'en_cours')
            ->orderByRaw("CASE urgence WHEN 'vitale' THEN 0 WHEN 'urgente' THEN 1 ELSE 2 END")->orderBy('cree_le')->get() as $d) {
            if (! $deMesServices($d)) {
                continue;
            }
            $possibles = $circuit->possibles($d, $u);
            if ($possibles !== []) {
                $aFaire[] = ['demande' => $d, 'etapes' => $possibles];
            }
        }

        // Coursier : poches gardées en instance au CTS (B2 « instance », pas encore ramenées).
        $enInstance = $u->a('coursier') ? Action::where('etape', 'B2')
            ->whereIn('demande_id', Demande::where('statut', 'en_cours')->where('etape', 'B2')->pluck('id'))->get(['id', 'donnees'])
            ->filter(fn ($a) => ($a->donnees['etat_cts'] ?? null) === 'instance')->count() : 0;

        // Surveillant : poches qui attendent déjà en pharmacie pour un patient ayant une nouvelle demande.
        $pochesEnAttente = $u->a('surveillant', 'pharmacie') ? app(\App\Services\PochesEnAttente::class)->liste() : [];

        return view('accueil', compact('aConfirmer', 'mesRemises', 'aFaire', 'ref', 'dmiNonInscrites', 'dmiJours', 'dmiMinutes', 'enInstance', 'pochesEnAttente'));
    }
}
