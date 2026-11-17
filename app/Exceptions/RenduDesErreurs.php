<?php

namespace App\Exceptions;

use Babel\Moteur\Erreur\ErreurMoteur;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Traduit les exceptions en réponses au format du contrat : { "erreurs": [ { code, champ, message } ] }.
 *
 * Seules les routes de l'API sont concernées. Une CleInvalide du moteur n'est pas traduite :
 * c'est une erreur d'installation, elle remonte en 500.
 */
class RenduDesErreurs
{
    public static function enregistrer(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => self::estApi($request) || $request->expectsJson());

        $exceptions->render(function (ErreurMoteur $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            return ErreurApi::depuisListe($e->versTableau())->render();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            $erreurs = [];
            foreach ($e->errors() as $champ => $messages) {
                $erreurs[] = ['code' => 'ERR_INVALID_PARAMETER', 'champ' => $champ, 'message' => $messages[0]];
            }

            return (new ErreurApi($erreurs, 422))->render();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            return ErreurApi::une('ERR_UNAUTHENTICATED', null, 'Connexion requise : jeton absent ou invalide.', 401)->render();
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            $secondes = max(1, (int) ($e->getHeaders()['Retry-After'] ?? 60));

            return ErreurApi::une(
                'ERR_TOO_MANY_REQUESTS',
                null,
                "Trop de requêtes, réessayez dans {$secondes} secondes.",
                429,
                ['Retry-After' => $secondes],
            )->render();
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            return ErreurApi::une('ERR_NOT_FOUND', null, 'Cette route n\'accepte pas cette méthode.', 405, $e->getHeaders())->render();
        });

        // Couvre aussi ModelNotFoundException, que Laravel convertit en NotFoundHttpException.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! self::estApi($request)) {
                return null;
            }

            return ErreurApi::introuvable()->render();
        });
    }

    private static function estApi(Request $request): bool
    {
        return $request->is('api/*');
    }
}
