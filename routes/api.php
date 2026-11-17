<?php

use App\Http\Controllers\App\CompteController;
use App\Http\Controllers\App\InfosController;
use App\Http\Controllers\App\LectureController;
use App\Http\Controllers\App\MarquePageController;
use App\Http\Controllers\App\VoteController;
use App\Http\Controllers\Reseau\DecouverteController;
use App\Http\Controllers\Reseau\IdentiteController;
use App\Http\Controllers\Reseau\PageController;
use App\Http\Controllers\Reseau\RechercheController;
use App\Http\Controllers\Reseau\TexteBanniController;
use App\Http\Middleware\CompteActif;
use App\Http\Middleware\JetonReseau;
use App\Http\Middleware\PasDeCache;
use Illuminate\Support\Facades\Route;

/*
| Toutes les routes de ce fichier sont servies sous /api/v1 (bootstrap/app.php).
| Ne jamais mettre de ->where('adresse', …) : une adresse mal écrite doit arriver
| jusqu'au contrôleur pour recevoir une erreur du contrat.
*/

// Porte réseau : ouverte en lecture, débit limité (B1 à B6).
Route::middleware('throttle:reseau')->group(function () {
    Route::get('/identite', IdentiteController::class);
    Route::get('/pages/{adresse}', [PageController::class, 'show']);
    Route::post('/lectures', [PageController::class, 'lectures']);
    Route::post('/recherches', RechercheController::class);
    Route::get('/collections', [DecouverteController::class, 'collections']);
    Route::get('/collections/{id}', [DecouverteController::class, 'collection']);
    Route::get('/pages-populaires', [DecouverteController::class, 'pagesPopulaires']);
    Route::get('/textes-bannis', TexteBanniController::class)->middleware([PasDeCache::class, JetonReseau::class]);
});

// Porte application : l'application web Angular. Déclarer /app/hasard avant les routes {adresse}.
Route::prefix('app')->group(function () {
    // Configuration et lecture, ouvertes aux visiteurs ; un jeton de membre est pris en compte.
    Route::middleware('throttle:application')->group(function () {
        Route::get('/infos', InfosController::class);
        Route::get('/hasard', [LectureController::class, 'hasard']);
        Route::get('/pages/{adresse}', [LectureController::class, 'page']);
    });

    // Comptes (V19, M1, M9, M10).
    Route::middleware('throttle:comptes')->group(function () {
        Route::post('/auth/inscription', [CompteController::class, 'inscription']);
        Route::post('/auth/connexion', [CompteController::class, 'connexion']);
    });

    // Routes « membre » : 401 sans jeton valide, 403 pour un compte suspendu.
    Route::middleware(['auth:sanctum', CompteActif::class])->group(function () {
        Route::post('/auth/deconnexion', [CompteController::class, 'deconnexion']);
        Route::get('/moi', [CompteController::class, 'moi']);
        Route::patch('/moi', [CompteController::class, 'modifier']);
        Route::delete('/moi', [CompteController::class, 'supprimer']);

        // Votes (M7).
        Route::put('/pages/{adresse}/vote', [VoteController::class, 'voter']);
        Route::delete('/pages/{adresse}/vote', [VoteController::class, 'retirer']);
        Route::get('/moi/votes', [VoteController::class, 'mesVotes']);

        // Marque-pages (M2, M3, M13).
        Route::get('/moi/marque-pages', [MarquePageController::class, 'index']);
        Route::post('/moi/marque-pages', [MarquePageController::class, 'store']);
        Route::patch('/moi/marque-pages/{id}', [MarquePageController::class, 'update']);
        Route::delete('/moi/marque-pages/{id}', [MarquePageController::class, 'destroy']);
    });
});
