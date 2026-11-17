<?php

namespace App\Http\Controllers\Reseau;

use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Illuminate\Http\JsonResponse;

/**
 * GET /textes-bannis : les textes que nous avons bannis nous-mêmes et qui sont appliqués
 * (A6, B5). Réservé au réseau par le jeton réseau (middleware JetonReseau).
 *
 * Un texte relevé chez un pair n'est jamais republié, même appliqué ici ; un bannissement
 * levé (rejete) disparaît de la liste.
 */
class TexteBanniController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $textes = TexteBanni::query()
            ->where('origine_id', Bibliotheque::locale()->id)
            ->where('statut', 'applique')
            ->orderBy('id')
            ->get();

        return response()->json([
            'elements' => $textes->map(fn (TexteBanni $texte) => [
                'texte' => $texte->texte,
                'empreinte' => $texte->empreinte,
                'motif' => $texte->motif,
                'banni_le' => ($texte->decide_le ?? $texte->cree_le)->toIso8601String(),
            ])->all(),
        ]);
    }
}
