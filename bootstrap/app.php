<?php

use App\Exceptions\RenduDesErreurs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sur l'API, un visiteur non connecté reçoit une 401 JSON, jamais une redirection.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/admin/login');

        // Une page fait exactement 3 200 caractères, espaces de bord comprises :
        // on ne retire jamais d'espaces à ces champs (contrat, « Pièges de Laravel »).
        $middleware->trimStrings(except: [
            'texte',
            'passage',
            'mot_de_passe',
            'mot_de_passe_actuel',
        ]);

        // Sur l'API, une chaîne vide reste une chaîne vide : un texte vide doit donner
        // ERR_EMPTY_QUERY, et une description vide se renvoie "".
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        RenduDesErreurs::enregistrer($exceptions);
    })->create();
