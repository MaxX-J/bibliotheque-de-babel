<?php

namespace App\Http\Controllers\Reseau;

use App\Bibliotheque\Recherche;
use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /recherches : l'adresse d'une page qui contient le texte (F2, B2). Rien n'est enregistré.
 */
class RechercheController extends Controller
{
    public function __invoke(Request $request, Recherche $recherche): JsonResponse
    {
        $resultat = $recherche->chercher($request->input('texte'), $request->input('mode'));

        return response()->json(['bibliotheque' => Bibliotheque::locale()->identifiant] + $resultat);
    }
}
