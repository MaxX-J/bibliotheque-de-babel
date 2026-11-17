<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Envoie exactement « Cache-Control: no-store », comme l'exige le contrat pour les textes bannis.
 *
 * Symfony ajoute « private » à tout Cache-Control qui n'est ni public ni private : on remplace
 * donc le sac d'en-têtes par une version qui n'ajoute rien.
 */
class PasDeCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers = new class($response->headers->allPreserveCaseWithoutCookies()) extends ResponseHeaderBag
        {
            protected function computeCacheControlValue(): string
            {
                return 'no-store';
            }
        };

        return $response;
    }
}
