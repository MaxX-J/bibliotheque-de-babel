<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\PageDesignee;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requetes\Pagination;
use App\Models\Adresse;
use App\Models\Collection;
use App\Models\CollectionPage;
use App\Moderation\VerificationTextesBannis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Les collections d'un membre : des pages rangées dans l'ordre, publiques ou privées
 * (M4, M5, M13, règle 8). L'auteur voit tout, y compris ce que le public ne voit pas.
 */
class CollectionController extends Controller
{
    private const MESSAGES = [
        'nom.required' => 'Le nom est obligatoire.',
        'nom.filled' => 'Le nom ne peut pas être vide.',
        'nom.string' => 'Le nom doit être une chaîne de caractères.',
        'nom.max' => 'Le nom fait au plus 100 caractères.',
        'description.string' => 'La description doit être une chaîne de caractères.',
        'description.max' => 'La description fait au plus 1 000 caractères.',
        'est_publique.boolean' => 'est_publique doit valoir true ou false.',
        'adresse.required' => 'L\'adresse est obligatoire.',
        'adresse.string' => 'L\'adresse doit être une chaîne de caractères.',
        'bibliotheque.string' => 'La bibliothèque doit être un identifiant.',
        'position.integer' => 'La position doit être un entier.',
        'position.min' => 'La position commence à 1.',
        'pages.present' => 'La liste des pages est obligatoire.',
        'pages.array' => 'Les pages doivent être une liste.',
        'pages.list' => 'Les pages doivent être une liste.',
        'pages.*.array' => 'Chaque page doit être un objet { bibliotheque, adresse }.',
        'pages.*.adresse.required' => 'Chaque page doit avoir une adresse.',
        'pages.*.adresse.string' => 'L\'adresse doit être une chaîne de caractères.',
        'pages.*.bibliotheque.string' => 'La bibliothèque doit être un identifiant.',
    ];

    public function __construct(private readonly VerificationTextesBannis $verification) {}

    /**
     * GET /app/moi/collections : publiques et privées, de la plus récemment modifiée à la plus ancienne.
     */
    public function index(Request $request): JsonResponse
    {
        $pagination = Pagination::depuis($request);
        $requete = $this->mesCollections($request);

        $collections = (clone $requete)
            ->withCount('pages')
            ->orderByDesc('maj_le')
            ->orderByDesc('id')
            ->offset($pagination->decalage())
            ->limit($pagination->taille)
            ->get();

        return response()->json([
            'lot' => $pagination->lot,
            'taille' => $pagination->taille,
            'total' => $requete->count(),
            'elements' => $collections->map(fn (Collection $collection) => self::resume($collection, $collection->pages_count))->all(),
        ]);
    }

    /**
     * POST /app/moi/collections : une collection vide, privée par défaut.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'est_publique' => ['sometimes', 'boolean:strict'],
        ], self::MESSAGES);

        $collection = $request->user()->collections()->create([
            'nom' => $donnees['nom'],
            'description' => $donnees['description'] ?? null,
            'est_publique' => $donnees['est_publique'] ?? false,
        ]);

        return response()->json($this->detail($collection), 201);
    }

    /**
     * GET /app/moi/collections/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->detail($this->trouver($request, $id)));
    }

    /**
     * PATCH /app/moi/collections/{id} : seuls les champs envoyés changent. Rendre une
     * collection publique publie ses pages : elles sont vérifiées contre les textes bannis.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $collection = $this->trouver($request, $id);

        if (! $request->hasAny(['nom', 'description', 'est_publique'])) {
            throw ErreurApi::une('ERR_INVALID_PARAMETER', null, 'Aucun champ à modifier : nom, description ou est_publique.', 422);
        }

        $donnees = $request->validate([
            'nom' => ['sometimes', 'filled', 'string', 'max:100'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'est_publique' => ['sometimes', 'boolean:strict'],
        ], self::MESSAGES);

        $devientPublique = ($donnees['est_publique'] ?? false) && ! $collection->est_publique;
        $collection->update($donnees);

        if ($devientPublique) {
            $collection->pages()->with('adresse.bibliotheque')->get()
                ->each(fn (CollectionPage $page) => $this->verification->verifier($page->adresse));
        }

        return response()->json($this->detail($collection));
    }

    /**
     * DELETE /app/moi/collections/{id} : ses pages, liens courts et signalements partent avec elle.
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->trouver($request, $id)->delete();

        return response()->noContent();
    }

    /**
     * POST /app/moi/collections/{id}/pages : à la fin, ou insérée à une position (les
     * suivantes sont décalées). Une même page peut revenir plusieurs fois.
     */
    public function ajouterPage(Request $request, string $id): JsonResponse
    {
        $collection = $this->trouver($request, $id);

        $donnees = $request->validate([
            'adresse' => ['required', 'string'],
            'bibliotheque' => ['nullable', 'string'],
            'position' => ['sometimes', 'integer:strict', 'min:1'],
        ], self::MESSAGES);

        $adresses = $collection->pages()->pluck('adresse_id')->all();
        $position = $donnees['position'] ?? count($adresses) + 1;

        if ($position > count($adresses) + 1) {
            throw ErreurApi::une('ERR_INVALID_PARAMETER', 'position', 'La position dépasse la fin de la collection.', 422);
        }

        $adresse = PageDesignee::depuis($donnees['adresse'], $donnees['bibliotheque'] ?? null)->enregistrer();
        array_splice($adresses, $position - 1, 0, [$adresse->id]);
        $this->remplacerPages($collection, $adresses);

        if ($collection->est_publique) {
            $this->verification->verifier($adresse);
        }

        return response()->json($this->detail($collection));
    }

    /**
     * PUT /app/moi/collections/{id}/pages : remplace la liste par celle envoyée, dans cet
     * ordre (glisser-déposer). Toutes les erreurs d'adresse sont renvoyées d'un coup.
     */
    public function ordonnerPages(Request $request, string $id): JsonResponse
    {
        $collection = $this->trouver($request, $id);

        $donnees = $request->validate([
            'pages' => ['present', 'array', 'list'],
            'pages.*' => ['array'],
            'pages.*.adresse' => ['required', 'string'],
            'pages.*.bibliotheque' => ['nullable', 'string'],
        ], self::MESSAGES);

        $pages = [];
        $erreurs = [];
        foreach ($donnees['pages'] as $i => $page) {
            try {
                $pages[] = PageDesignee::depuis($page['adresse'], $page['bibliotheque'] ?? null);
            } catch (ErreurApi $e) {
                foreach ($e->erreurs as $erreur) {
                    $erreurs[] = ['champ' => "pages.{$i}.{$erreur['champ']}"] + $erreur;
                }
            }
        }

        if ($erreurs !== []) {
            throw ErreurApi::depuisListe($erreurs);
        }

        $avant = $collection->pages()->pluck('adresse_id')->all();
        $adresses = array_map(fn (PageDesignee $page) => $page->enregistrer(), $pages);
        $this->remplacerPages($collection, array_map(fn (Adresse $adresse) => $adresse->id, $adresses));

        if ($collection->est_publique) {
            foreach ($adresses as $adresse) {
                if (! in_array($adresse->id, $avant, true)) {
                    $this->verification->verifier($adresse);
                }
            }
        }

        return response()->json($this->detail($collection));
    }

    /**
     * DELETE /app/moi/collections/{id}/pages/{position} : les suivantes remontent d'un rang.
     */
    public function retirerPage(Request $request, string $id, string $position): JsonResponse
    {
        $collection = $this->trouver($request, $id);
        $adresses = $collection->pages()->pluck('adresse_id')->all();

        if (! ctype_digit($position) || (int) $position < 1 || (int) $position > count($adresses)) {
            throw ErreurApi::introuvable('position', 'Il n\'y a pas de page à cette position.');
        }

        array_splice($adresses, (int) $position - 1, 1);
        $this->remplacerPages($collection, $adresses);

        return response()->json($this->detail($collection));
    }

    /**
     * @return Builder<Collection>
     */
    private function mesCollections(Request $request): Builder
    {
        return Collection::query()->where('utilisateur_id', $request->user()->id);
    }

    /**
     * La collection d'un autre membre répond 404, comme une collection inexistante.
     */
    private function trouver(Request $request, string $id): Collection
    {
        $collection = ctype_digit($id) ? $this->mesCollections($request)->find($id) : null;

        return $collection ?? throw ErreurApi::introuvable('id', 'Cette collection n\'existe pas.');
    }

    /**
     * Réécrit les pages de la collection, positions renumérotées à partir de 1, sans trou.
     * Réécrire évite de violer la clé (collection_id, position) en décalant les lignes.
     *
     * @param  list<int>  $adresses
     */
    private function remplacerPages(Collection $collection, array $adresses): void
    {
        DB::transaction(function () use ($collection, $adresses) {
            CollectionPage::query()->where('collection_id', $collection->id)->delete();
            CollectionPage::query()->insert(array_map(
                fn (int $adresse, int $i) => ['collection_id' => $collection->id, 'position' => $i + 1, 'adresse_id' => $adresse],
                $adresses,
                array_keys($adresses),
            ));
            $collection->touch();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function resume(Collection $collection, int $nombrePages): array
    {
        return [
            'id' => (string) $collection->id,
            'nom' => $collection->nom,
            'description' => $collection->description ?? '',
            'est_publique' => $collection->est_publique,
            'masquee' => $collection->masque_le !== null,
            'nombre_pages' => $nombrePages,
            'cree_le' => $collection->cree_le->toIso8601String(),
            'maj_le' => $collection->maj_le->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Collection $collection): array
    {
        $pages = $collection->pages()->with('adresse.bibliotheque')->get();

        return self::resume($collection->refresh(), $pages->count()) + [
            'pages' => $pages->map(fn (CollectionPage $page) => [
                'position' => $page->position,
                'bibliotheque' => $page->adresse->bibliotheque->identifiant,
                'adresse' => $page->adresse->ecriture(),
                'masquee' => $page->adresse->texte_banni_id !== null,
                // Renseigné par l'import d'un compte (M12), qui n'existe pas encore.
                'a_convertir' => false,
            ])->all(),
        ];
    }
}
