<?php

namespace App\Bibliotheque;

use App\Models\Adresse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Le score d'une page : somme des votes + nombre de marque-pages (règle 10).
 * Un total anonyme, calculé à la demande, jamais stocké. La même formule sert aux pages
 * populaires (B4, V13) et au score affiché avec une page (GET /app/pages/{adresse}).
 */
final class Popularite
{
    private const SCORE = 'COALESCE(v.total, 0) + COALESCE(m.total, 0)';

    /**
     * Les adresses, chacune avec sa colonne « score ».
     *
     * @return Builder<Adresse>
     */
    public static function adressesAvecScore(): Builder
    {
        $votes = DB::table('votes')->select('adresse_id', DB::raw('SUM(valeur) AS total'))->groupBy('adresse_id');
        $marquePages = DB::table('marque_pages')->select('adresse_id', DB::raw('COUNT(*) AS total'))->groupBy('adresse_id');

        return Adresse::query()
            ->select('adresses.*', DB::raw(self::SCORE.' AS score'))
            ->leftJoinSub($votes, 'v', 'v.adresse_id', '=', 'adresses.id')
            ->leftJoinSub($marquePages, 'm', 'm.adresse_id', '=', 'adresses.id');
    }

    /**
     * @param  Builder<Adresse>  $requete
     */
    public static function scorePositif(Builder $requete): void
    {
        $requete->whereRaw(self::SCORE.' > 0');
    }

    public static function scoreDe(?Adresse $adresse): int
    {
        if ($adresse === null) {
            return 0;
        }

        return (int) DB::table('votes')->where('adresse_id', $adresse->id)->sum('valeur')
            + DB::table('marque_pages')->where('adresse_id', $adresse->id)->count();
    }
}
