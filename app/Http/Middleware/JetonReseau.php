<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige le jeton réseau dans Authorization: Bearer <jeton>.
 *
 * Comparaison en temps constant (hash_equals). Jeton absent, mal formé ou faux : la même
 * réponse 401, sans dire lequel. Sans jeton configuré, la route reste fermée.
 */
class JetonReseau
{
    public function handle(Request $request, Closure $next): Response
    {
        $attendu = config('bibliotheque.jeton_reseau');
        $recu = $request->bearerToken();

        if (! is_string($attendu) || $attendu === '' || ! is_string($recu) || ! hash_equals($attendu, $recu)) {
            throw ErreurApi::une(
                'ERR_INVALID_NETWORK_TOKEN',
                null,
                'Cette route est réservée aux bibliothèques du réseau.',
                401,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        return $next($request);
    }
}
