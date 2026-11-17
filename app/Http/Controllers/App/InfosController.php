<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\Adresse;
use App\Bibliotheque\Reglages;
use App\Http\Controllers\Controller;
use App\Http\Requetes\Pagination;
use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;

/**
 * GET /app/infos : tout ce dont l'application web a besoin au démarrage (V19, V20, A8).
 */
class InfosController extends Controller
{
    public function __invoke(Moteur $moteur): JsonResponse
    {
        $locale = Bibliotheque::locale();
        $tempsReel = config('bibliotheque.temps_reel');

        return response()->json([
            'identifiant' => $locale->identifiant,
            'nom' => $locale->nom,
            'description' => $locale->description ?? '',
            'empreinte_cle' => $moteur->empreinte(),
            'algorithme' => Moteur::ALGORITHME,
            'contrat' => config('bibliotheque.contrat'),
            'application_web' => config('bibliotheque.application_web'),
            'inscriptions_ouvertes' => Reglages::inscriptionsOuvertes(),
            'constantes' => [
                'alphabet' => Moteur::ALPHABET,
                'longueur_page' => Moteur::LONGUEUR_PAGE,
                'murs' => Moteur::MURS,
                'etageres' => Moteur::ETAGERES,
                'volumes' => Moteur::VOLUMES,
                'pages' => Moteur::PAGES,
                'longueur_max_hexagone' => Moteur::LONGUEUR_MAX_HEXAGONE,
                'dernier_hexagone' => gmp_strval(Adresse::dernierHexagone(), 36),
            ],
            'limites' => [
                'taille_lot_max' => Pagination::TAILLE_MAX,
                'annotation_max' => 2000,
                'motif_signalement_max' => 500,
                'nom_collection_max' => 100,
                'description_collection_max' => 1000,
                'pseudo_max' => 30,
                'mot_de_passe_min' => 8,
                'duree_lien_visiteur_jours' => config('bibliotheque.duree_lien_visiteur_jours'),
                'import_max_octets' => 10 * 1024 * 1024,
            ],
            'reseau' => [
                'delai_pair_ms' => config('bibliotheque.delai_pair_ms'),
            ],
            'temps_reel' => $tempsReel['cle'] === null ? null : [
                'protocole' => 'pusher',
                'cle' => $tempsReel['cle'],
                'hote' => $tempsReel['hote'],
                'port' => $tempsReel['port'],
                'tls' => $tempsReel['tls'],
            ],
        ]);
    }
}
