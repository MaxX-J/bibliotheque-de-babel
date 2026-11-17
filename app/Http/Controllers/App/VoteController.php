<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\PageDesignee;
use App\Bibliotheque\Popularite;
use App\Http\Controllers\Controller;
use App\Http\Requetes\Pagination;
use App\Models\Utilisateur;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les votes d'un membre : un par page, +1 ou −1 (M7, règle 10).
 */
class VoteController extends Controller
{
    /**
     * PUT /app/pages/{adresse}/vote : enregistre ou remplace le vote.
     */
    public function voter(Request $request, string $adresse): JsonResponse
    {
        $page = PageDesignee::depuis($adresse, $request->query('bibliotheque'));

        $donnees = $request->validate(
            ['valeur' => ['required', 'integer:strict', 'in:1,-1']],
            ['valeur' => 'La valeur du vote doit être 1 (pour) ou -1 (contre).'],
        );

        $enregistree = $page->enregistrer();

        Vote::query()->upsert(
            [[
                'utilisateur_id' => $request->user()->id,
                'adresse_id' => $enregistree->id,
                'valeur' => (int) $donnees['valeur'],
                'cree_le' => now(),
            ]],
            ['utilisateur_id', 'adresse_id'],
            ['valeur', 'cree_le'],
        );

        return response()->json([
            'bibliotheque' => $page->bibliotheque->identifiant,
            'adresse' => $adresse,
            'mon_vote' => (int) $donnees['valeur'],
            'score' => Popularite::scoreDe($enregistree),
        ]);
    }

    /**
     * DELETE /app/pages/{adresse}/vote : retire le vote ; sans vote, la réponse est la même.
     */
    public function retirer(Request $request, string $adresse): JsonResponse
    {
        $page = PageDesignee::depuis($adresse, $request->query('bibliotheque'));
        $enregistree = $page->trouver();

        if ($enregistree !== null) {
            Vote::query()
                ->where('utilisateur_id', $request->user()->id)
                ->where('adresse_id', $enregistree->id)
                ->delete();
        }

        return response()->json([
            'bibliotheque' => $page->bibliotheque->identifiant,
            'adresse' => $adresse,
            'mon_vote' => null,
            'score' => Popularite::scoreDe($enregistree),
        ]);
    }

    /**
     * GET /app/moi/votes : du plus récent au plus ancien.
     */
    public function mesVotes(Request $request): JsonResponse
    {
        $pagination = Pagination::depuis($request);
        /** @var Utilisateur $membre */
        $membre = $request->user();
        $requete = Vote::query()->where('utilisateur_id', $membre->id);

        $votes = (clone $requete)
            ->with('adresse.bibliotheque')
            ->orderByDesc('cree_le')
            ->orderByDesc('adresse_id')
            ->offset($pagination->decalage())
            ->limit($pagination->taille)
            ->get();

        return response()->json([
            'lot' => $pagination->lot,
            'taille' => $pagination->taille,
            'total' => $requete->count(),
            'elements' => $votes->map(fn (Vote $vote) => [
                'bibliotheque' => $vote->adresse->bibliotheque->identifiant,
                'adresse' => $vote->adresse->ecriture(),
                'valeur' => $vote->valeur,
                'cree_le' => $vote->cree_le->toIso8601String(),
            ])->all(),
        ]);
    }
}
