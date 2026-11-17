<?php

namespace App\Http\Controllers\Reseau;

use App\Bibliotheque\Validation;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lire des pages pour les autres bibliothèques (F1, B1). Une page est toujours lisible,
 * même si elle contient un texte banni : on ne masque jamais une page.
 */
class PageController extends Controller
{
    public function __construct(private readonly Moteur $moteur) {}

    /**
     * GET /pages/{adresse}
     */
    public function show(string $adresse): JsonResponse
    {
        Validation::exigerPage($adresse);

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()->identifiant,
            'adresse' => $adresse,
            'texte' => $this->moteur->lire($adresse),
        ]);
    }

    /**
     * POST /lectures : jusqu'à 50 pages en un appel, dans l'ordre de la demande. Une adresse
     * refusée reçoit ses erreurs à sa place, sans empêcher les autres (statut 200).
     */
    public function lectures(Request $request): JsonResponse
    {
        $request->validate([
            'adresses' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'adresses.*' => ['string'],
        ], [
            'adresses.*' => 'Il faut une liste de 1 à 50 adresses.',
            'adresses.*.string' => 'Chaque adresse doit être une chaîne de caractères.',
        ]);

        $elements = [];
        foreach ($request->input('adresses') as $adresse) {
            try {
                Validation::exigerPage($adresse);
                $elements[] = ['adresse' => $adresse, 'texte' => $this->moteur->lire($adresse), 'erreurs' => null];
            } catch (ErreurApi $e) {
                $elements[] = ['adresse' => $adresse, 'texte' => null, 'erreurs' => $e->erreurs];
            }
        }

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()->identifiant,
            'elements' => $elements,
        ]);
    }
}
