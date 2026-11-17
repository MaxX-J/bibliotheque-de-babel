<?php

namespace App\Http\Controllers\Reseau;

use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;

/**
 * GET /identite : qui sommes-nous, et notre clé a-t-elle changé ? (B6, A9)
 */
class IdentiteController extends Controller
{
    public function __invoke(Moteur $moteur): JsonResponse
    {
        $locale = Bibliotheque::locale();

        return response()->json([
            'identifiant' => $locale->identifiant,
            'nom' => $locale->nom,
            'description' => $locale->description ?? '',
            'empreinte_cle' => $moteur->empreinte(),
            'algorithme' => Moteur::ALGORITHME,
            'contrat' => config('bibliotheque.contrat'),
            'application_web' => config('bibliotheque.application_web'),
        ]);
    }
}
