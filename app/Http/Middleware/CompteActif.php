<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use App\Models\Utilisateur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte suspendu (A2) ne peut plus rien faire, même avec un jeton ouvert avant la suspension.
 */
class CompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur instanceof Utilisateur && $utilisateur->estSuspendu()) {
            throw ErreurApi::compteSuspendu();
        }

        return $next($request);
    }
}
