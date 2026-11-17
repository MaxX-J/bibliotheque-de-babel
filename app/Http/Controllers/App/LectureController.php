<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\Hasard;
use App\Bibliotheque\PageDesignee;
use App\Bibliotheque\Popularite;
use App\Http\Controllers\Controller;
use App\Http\Ressources\AnnotationRessource;
use App\Models\ActiviteJour;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\Utilisateur;
use App\Models\Vote;
use App\Reseau\ClientPair;
use Babel\Moteur\Moteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lire une page d'ici ou d'ailleurs, avec ce que nos membres y ont laissé ; page au hasard
 * (V1, V9, V14, V16, M13). Ouvert aux visiteurs ; un jeton de membre est pris en compte.
 */
class LectureController extends Controller
{
    /**
     * GET /app/hasard : une page de la bibliothèque locale, tirée uniformément (F7, V9).
     */
    public function hasard(): JsonResponse
    {
        return response()->json([
            'bibliotheque' => Bibliotheque::locale()->identifiant,
            'adresse' => (string) Hasard::auHasard(),
        ]);
    }

    /**
     * GET /app/pages/{adresse}?bibliotheque=… : le texte de la page et son contexte.
     *
     * L'adresse est contrôlée avant tout appel réseau. Une page d'ailleurs est lue chez sa
     * bibliothèque par notre serveur, jamais par l'application web.
     */
    public function page(Request $request, string $adresse, Moteur $moteur, ClientPair $pairs): JsonResponse
    {
        $page = PageDesignee::depuis($adresse, $request->query('bibliotheque'));
        $bibliotheque = $page->bibliotheque;
        $lecteur = self::membre($request);

        $texte = $bibliotheque->est_locale ? $moteur->lire($adresse) : $pairs->lirePage($bibliotheque, $adresse);
        ActiviteJour::compter('pages_lues');

        $enregistree = $page->trouver();

        return response()->json([
            'bibliotheque' => $bibliotheque->identifiant,
            'adresse' => $adresse,
            'adresse_livre' => (string) $page->adresse->livre(),
            'texte' => $texte,
            'score' => Popularite::scoreDe($enregistree),
            'mon_vote' => self::monVote($enregistree, $lecteur),
            'marque_page' => self::monMarquePage($enregistree, $lecteur),
            'annotations' => self::annotations($enregistree, $lecteur),
        ]);
    }

    /**
     * Le membre connecté, s'il a envoyé un jeton valide et que son compte n'est pas suspendu ;
     * sinon, on le lit comme un visiteur.
     */
    private static function membre(Request $request): ?Utilisateur
    {
        $utilisateur = $request->user('sanctum');

        return $utilisateur instanceof Utilisateur && ! $utilisateur->estSuspendu() ? $utilisateur : null;
    }

    private static function monVote(?Adresse $adresse, ?Utilisateur $lecteur): ?int
    {
        if ($adresse === null || $lecteur === null) {
            return null;
        }

        return Vote::query()->where('adresse_id', $adresse->id)->where('utilisateur_id', $lecteur->id)->value('valeur');
    }

    /**
     * @return array{id: string, titre: string|null}|null
     */
    private static function monMarquePage(?Adresse $adresse, ?Utilisateur $lecteur): ?array
    {
        if ($adresse === null || $lecteur === null) {
            return null;
        }

        $marquePage = MarquePage::query()->where('adresse_id', $adresse->id)->where('utilisateur_id', $lecteur->id)->first();

        return $marquePage === null ? null : ['id' => (string) $marquePage->id, 'titre' => $marquePage->titre];
    }

    /**
     * Les annotations publiques non masquées, et toutes celles du lecteur, de la plus
     * récente à la plus ancienne. Sur une page qui contient un texte banni appliqué, les
     * annotations publiques ne sont plus montrées aux autres (règle 18).
     *
     * @return list<array<string, mixed>>
     */
    private static function annotations(?Adresse $adresse, ?Utilisateur $lecteur): array
    {
        if ($adresse === null) {
            return [];
        }

        return Annotation::query()
            ->with(['adresse.bibliotheque', 'utilisateur'])
            ->where('adresse_id', $adresse->id)
            ->where(function (Builder $visibles) use ($adresse, $lecteur) {
                if ($adresse->texte_banni_id === null) {
                    $visibles->orWhere(fn (Builder $publiques) => $publiques->where('est_publique', true)->whereNull('masque_le'));
                }
                if ($lecteur !== null) {
                    $visibles->orWhere('utilisateur_id', $lecteur->id);
                }
                $visibles->orWhereRaw('1 = 0');
            })
            ->orderByDesc('cree_le')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Annotation $annotation) => AnnotationRessource::versTableau($annotation, $lecteur))
            ->all();
    }
}
