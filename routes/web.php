<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CtsController;
use App\Http\Controllers\DemandeController;
use App\Http\Controllers\LangueController;
use App\Http\Controllers\PharmacieController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SuiviController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/a-faire');
// Choix de la langue (page de connexion et en-tête) : fr ou ar.
Route::get('/langue/{langue}', [LangueController::class, 'choisir'])->whereIn('langue', ['fr', 'ar'])->name('langue');

Route::middleware('guest')->group(function (): void {
    Route::get('/connexion', [AuthController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthController::class, 'store'])->name('login.store');
});

// Écrans de suivi : l'écran demande toutes les minutes si quelque chose a changé (sans journal : ce n'est pas une consultation).
Route::get('/suivi/empreinte', function (\App\Services\EtatEcran $etat) {
    \App\Support\Clinisys::essayer(fn () => app(\App\Services\SyncClinisys::class)->siNecessaire());

    return response()->json(['empreinte' => $etat->empreinte()]);
})->middleware('auth')->name('suivi.empreinte');

Route::middleware(['auth', 'journal'])->group(function (): void {
    Route::post('/deconnexion', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/a-faire', [AccueilController::class, 'index'])->name('accueil');
    Route::get('/demandes', [DemandeController::class, 'index'])->name('demandes.index');
    Route::get('/demandes/nouvelle', [DemandeController::class, 'create'])->name('demandes.create'); // « Rechercher / inscrire » : recherche pour tous, inscription (store) infirmière / major
    Route::post('/demandes/recuperer-poche', [DemandeController::class, 'recupererPoche'])->middleware('role:infirmiere,major,surveillant')->name('demandes.recuperer_poche');
    Route::post('/demandes', [DemandeController::class, 'store'])->middleware('role:infirmiere,major,surveillant')->name('demandes.store'); // surveillant : régularisation seulement (contrôlé dans store)
    Route::get('/demandes/{demande}', [DemandeController::class, 'show'])->whereNumber('demande')->name('demandes.show');
    Route::get('/demandes/{demande}/bon', [DemandeController::class, 'bon'])->whereNumber('demande')->name('demandes.bon');
    Route::post('/demandes/{demande}/etapes/{code}', [DemandeController::class, 'etape'])->whereNumber('demande')->name('demandes.etape');
    Route::post('/demandes/{demande}/preparation', [DemandeController::class, 'preparation'])->whereNumber('demande')->name('demandes.preparation');
    Route::post('/demandes/{demande}/services', [DemandeController::class, 'services'])->middleware('role:major')->name('demandes.services');
    Route::post('/demandes/{demande}/commentaires', [DemandeController::class, 'commenter'])->name('demandes.commentaire');
    Route::post('/demandes/{demande}/annulation', [DemandeController::class, 'annuler'])->middleware('role:major,surveillant')->name('demandes.annuler');
    Route::post('/remises/{remise}/confirmation', [DemandeController::class, 'confirmerRemise'])->name('remises.confirmer');
    Route::post('/remises/{remise}/refus', [DemandeController::class, 'refuserRemise'])->name('remises.refuser');
    Route::post('/actions/{action}/correction', [DemandeController::class, 'corriger'])->name('actions.corriger');

    // Ancien écran « Patient » : fusionné dans « Rechercher / inscrire ».
    Route::get('/patients', fn (\Illuminate\Http\Request $r) => redirect()->route('demandes.create', array_filter(['q' => $r->query('q')])))->name('patients');
    Route::get('/stock', [StockController::class, 'index'])->name('stock');
    Route::get('/pharmacie', [PharmacieController::class, 'index'])->middleware('role:pharmacie,surveillant,major,direction')->name('pharmacie');
    Route::get('/cts', [CtsController::class, 'index'])->middleware('role:coursier,surveillant,major,direction')->name('cts');
    Route::post('/poches/{poche}/prelevement', [StockController::class, 'prelever'])->middleware('role:pharmacie')->name('poches.prelever');

    Route::get('/alertes', [SuiviController::class, 'alertes'])->name('alertes');
    Route::get('/registre', [SuiviController::class, 'registre'])->name('registre');
    Route::get('/indicateurs', [SuiviController::class, 'indicateurs'])->middleware('role:major,surveillant,direction')->name('indicateurs');
    Route::get('/indicateurs/export', [SuiviController::class, 'export'])->middleware('role:major,surveillant,direction')->name('indicateurs.export');

    Route::middleware('role:admin')->name('admin.')->prefix('parametrage')->group(function (): void {
        Route::get('/comptes', [AdminController::class, 'comptes'])->name('comptes');
        Route::get('/comptes/nouveau', [AdminController::class, 'compte'])->name('compte.nouveau');
        Route::get('/comptes/{userName}', [AdminController::class, 'compte'])->name('compte');
        Route::post('/comptes', [AdminController::class, 'enregistrerCompte'])->name('compte.enregistrer');
        Route::get('/services', [AdminController::class, 'services'])->name('services');
        Route::post('/services', [AdminController::class, 'enregistrerService'])->name('service.enregistrer');
        Route::post('/services/clinisys', [AdminController::class, 'synchroniserServices'])->name('services.clinisys');
        Route::get('/seuils', [AdminController::class, 'parametres'])->name('parametres');
        Route::post('/seuils', [AdminController::class, 'enregistrerParametres'])->name('parametres.enregistrer');
        Route::get('/journal', [AdminController::class, 'journal'])->name('journal');
        Route::get('/journal/export', [AdminController::class, 'exporterJournal'])->name('journal.export');
    });
});
