<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\PageDesignee;
use App\Bibliotheque\Validation;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requetes\Pagination;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Les marque-pages d'un membre, toujours privés (M2, M3, M13, règle 7).
 */
class MarquePageController extends Controller
{
    private const MESSAGES = [
        'adresse.required' => 'L\'adresse est obligatoire.',
        'adresse.string' => 'L\'adresse doit être une chaîne de caractères.',
        'bibliotheque.string' => 'La bibliothèque doit être un identifiant.',
        'titre.present' => 'Le titre est obligatoire (null pour le retirer).',
        'titre.string' => 'Le titre doit être une chaîne de caractères ou null.',
        'titre.max' => 'Le titre fait au plus 100 caractères.',
    ];

    /**
     * GET /app/moi/marque-pages : du plus récent au plus ancien. Filtres : bibliotheque, et
     * livre (une adresse sans « :page », dans la bibliothèque filtrée, ou la locale).
     */
    public function index(Request $request): JsonResponse
    {
        $pagination = Pagination::depuis($request);
        $requete = $this->mesMarquePages($request);
        $filtreBibliotheque = $request->query('bibliotheque');
        $filtreLivre = $request->query('livre');

        if ($filtreBibliotheque !== null && (! is_string($filtreBibliotheque) || preg_match('/\A[a-z0-9][a-z0-9-]{1,31}\z/', $filtreBibliotheque) !== 1)) {
            throw ErreurApi::une('ERR_INVALID_PARAMETER', 'bibliotheque', 'La bibliothèque doit être un identifiant du réseau.', 422);
        }

        if ($filtreLivre !== null) {
            $livre = is_string($filtreLivre) ? Validation::valider($filtreLivre)->adresse : null;

            if ($livre === null || ! $livre->estUnLivre()) {
                throw ErreurApi::une('ERR_INVALID_PARAMETER', 'livre', 'Le livre doit être une adresse valide sans « :page ».', 422);
            }

            $requete->whereHas('adresse', fn (Builder $adresse) => $adresse->where('empreinte_livre', Adresse::empreinteDe($livre)));
            $filtreBibliotheque ??= Bibliotheque::locale()->identifiant;
        }

        if ($filtreBibliotheque !== null) {
            $requete->whereHas('adresse.bibliotheque', fn (Builder $bibliotheque) => $bibliotheque->where('identifiant', $filtreBibliotheque));
        }

        $marquePages = (clone $requete)
            ->with('adresse.bibliotheque')
            ->orderByDesc('cree_le')
            ->orderByDesc('id')
            ->offset($pagination->decalage())
            ->limit($pagination->taille)
            ->get();

        return response()->json([
            'lot' => $pagination->lot,
            'taille' => $pagination->taille,
            'total' => $requete->count(),
            'elements' => $marquePages->map(self::versTableau(...))->all(),
        ]);
    }

    /**
     * POST /app/moi/marque-pages : une page d'ici ou d'ailleurs, une seule fois par membre.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'adresse' => ['required', 'string'],
            'bibliotheque' => ['nullable', 'string'],
            'titre' => ['nullable', 'string', 'max:100'],
        ], self::MESSAGES);

        $adresse = PageDesignee::depuis($donnees['adresse'], $donnees['bibliotheque'] ?? null)->enregistrer();
        /** @var Utilisateur $membre */
        $membre = $request->user();

        if ($membre->marquePages()->where('adresse_id', $adresse->id)->exists()) {
            throw ErreurApi::doublon('adresse', 'Cette page est déjà dans vos marque-pages.');
        }

        $marquePage = $membre->marquePages()->create([
            'adresse_id' => $adresse->id,
            'titre' => $donnees['titre'] ?? null,
        ]);

        return response()->json(self::versTableau($marquePage->load('adresse.bibliotheque')), 201);
    }

    /**
     * PATCH /app/moi/marque-pages/{id} : change le titre (null le retire).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $marquePage = $this->trouver($request, $id);

        $donnees = $request->validate([
            'titre' => ['present', 'nullable', 'string', 'max:100'],
        ], self::MESSAGES);

        $marquePage->update(['titre' => $donnees['titre']]);

        return response()->json(self::versTableau($marquePage->load('adresse.bibliotheque')));
    }

    /**
     * DELETE /app/moi/marque-pages/{id}
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->trouver($request, $id)->delete();

        return response()->noContent();
    }

    /**
     * @return Builder<MarquePage>
     */
    private function mesMarquePages(Request $request): Builder
    {
        return MarquePage::query()->where('utilisateur_id', $request->user()->id);
    }

    /**
     * Le marque-page d'un autre membre répond 404, comme un marque-page inexistant.
     */
    private function trouver(Request $request, string $id): MarquePage
    {
        $marquePage = ctype_digit($id) ? $this->mesMarquePages($request)->find($id) : null;

        return $marquePage ?? throw ErreurApi::introuvable('id', 'Ce marque-page n\'existe pas.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function versTableau(MarquePage $marquePage): array
    {
        return [
            'id' => (string) $marquePage->id,
            'bibliotheque' => $marquePage->adresse->bibliotheque->identifiant,
            'adresse' => $marquePage->adresse->ecriture(),
            'titre' => $marquePage->titre,
            // Renseigné par l'import d'un compte (M12), qui n'existe pas encore.
            'a_convertir' => false,
            'cree_le' => $marquePage->cree_le->toIso8601String(),
        ];
    }
}
