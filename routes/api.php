<?php

use Illuminate\Support\Facades\Route;

/*
| Toutes les routes de ce fichier sont servies sous /api/v1 (bootstrap/app.php).
| Ne jamais mettre de ->where('adresse', …) : une adresse mal écrite doit arriver
| jusqu'au contrôleur pour recevoir une erreur du contrat.
*/

// Porte réseau : ouverte en lecture, débit limité (B1 à B6).
Route::middleware('throttle:reseau')->group(function () {
    //
});

// Porte application : l'application web Angular. Déclarer /app/hasard avant les routes {adresse}.
Route::prefix('app')->group(function () {
    //
});
