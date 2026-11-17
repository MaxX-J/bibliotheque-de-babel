<?php

namespace App\Http\Controllers\Reseau;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requetes\Pagination;
use App\Models\Adresse;
use App\Models\Collection;
use App\Models\CollectionPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ce que nos membres ont publié, pour les autres bibliothèques (B3, B4).
 * Rien ne dit qui a publié, voté ou gardé une page.
 */
class DecouverteController extends Controller
{
    /**
     * GET /collections : les collections publiques et non masquées, de la plus récemment
     * modifiée à la plus ancienne.
     */
    public function collections(Request $request): JsonResponse
    {
        $pagination = Pagination::depuis($request);
        $requete = Collection::query()->visiblesDuPublic();

        $collections = (clone $requete)
            ->withCount('pagesMontrees')
            ->orderByDesc('maj_le')
            ->orderByDesc('id')
            ->offset($pagination->decalage())
            ->limit($pagination->taille)
            ->get();

        return response()->json([
            'lot' => $pagination->lot,
            'taille' => $pagination->taille,
            'total' => $requete->count(),
            'elements' => $collections->map(fn (Collection $collection) => self::resume($collection))->all(),
        ]);
    }

    /**
     * GET /collections/{id} : une collection publique, avec ses pages dans l'ordre. Privée,
     * masquée ou inexistante : la même 404, pour ne rien laisser deviner.
     */
    public function collection(string $id): JsonResponse
    {
        $collection = ctype_digit($id)
            ? Collection::query()->visiblesDuPublic()->with('pagesMontrees.adresse.bibliotheque')->find($id)
            : null;

        if ($collection === null) {
            throw ErreurApi::introuvable('id', 'Cette ressource n\'existe pas ou n\'est pas visible.');
        }

        $collection->pages_montrees_count = $collection->pagesMontrees->count();

        return response()->json(self::resume($collection) + [
            'pages' => $collection->pagesMontrees->map(fn (CollectionPage $page) => [
                'position' => $page->position,
                'bibliotheque' => $page->adresse->bibliotheque->identifiant,
                'adresse' => $page->adresse->ecriture(),
            ])->all(),
        ]);
    }

    /**
     * GET /pages-populaires : score = somme des votes + nombre de marque-pages, total anonyme.
     * Une page marquée par un texte banni appliqué n'apparaît pas.
     */
    public function pagesPopulaires(Request $request): JsonResponse
    {
        $taille = Pagination::taille($request);

        $votes = DB::table('votes')->select('adresse_id', DB::raw('SUM(valeur) AS total'))->groupBy('adresse_id');
        $marquePages = DB::table('marque_pages')->select('adresse_id', DB::raw('COUNT(*) AS total'))->groupBy('adresse_id');
        $score = 'COALESCE(v.total, 0) + COALESCE(m.total, 0)';

        $pages = Adresse::query()
            ->with('bibliotheque')
            ->select('adresses.*', DB::raw("{$score} AS score"))
            ->leftJoinSub($votes, 'v', 'v.adresse_id', '=', 'adresses.id')
            ->leftJoinSub($marquePages, 'm', 'm.adresse_id', '=', 'adresses.id')
            ->whereNotNull('adresses.page')
            ->whereNull('adresses.texte_banni_id')
            ->whereRaw("{$score} > 0")
            ->orderByDesc('score')
            ->orderBy('adresses.id')
            ->limit($taille)
            ->get();

        return response()->json([
            'elements' => $pages->map(fn (Adresse $adresse) => [
                'bibliotheque' => $adresse->bibliotheque->identifiant,
                'adresse' => $adresse->ecriture(),
                'score' => (int) $adresse->getAttribute('score'),
            ])->all(),
        ]);
    }

    /**
     * @return array{id: string, nom: string, description: string, nombre_pages: int, maj_le: string}
     */
    private static function resume(Collection $collection): array
    {
        return [
            'id' => (string) $collection->id,
            'nom' => $collection->nom,
            'description' => $collection->description ?? '',
            'nombre_pages' => (int) $collection->pages_montrees_count,
            'maj_le' => $collection->maj_le->toIso8601String(),
        ];
    }
}
