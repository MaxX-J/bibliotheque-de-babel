<?php

namespace App\Bibliotheque;

use App\Exceptions\ErreurApi;
use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\Erreur;
use Babel\Moteur\Moteur;

/**
 * F5 valider(adresse) : dit si une adresse est bien écrite et existe.
 *
 * - Ne lève jamais d'exception (sauf exiger…, qui sert dans les contrôleurs).
 * - Renvoie toutes les erreurs en une fois, dans l'ordre hexagone, mur, etagere, volume, page.
 * - Refuse, ne corrige jamais : une page n'a qu'une seule écriture valide (pas de trim, pas
 *   de passage en minuscules, pas de zéro de tête).
 * - Accepte une adresse de livre (sans « :page ») : le niveau dit laquelle des deux on a.
 *
 * Les codes, les champs et les messages sont ceux du moteur : une adresse de page validée ici
 * est toujours lisible par Moteur::lire, et inversement.
 */
final class Validation
{
    private const DECOUPAGE = '/\A([^-]*)-w([^-]*)-s([^-]*)-v([^:]*)(?::(.*))?\z/s';

    public static function valider(string $adresse): ResultatValidation
    {
        if (preg_match(self::DECOUPAGE, $adresse, $morceaux, PREG_UNMATCHED_AS_NULL) !== 1) {
            return ResultatValidation::refusee([new Erreur(CodeErreur::AdresseMalEcrite, 'adresse')]);
        }

        $erreurs = [];
        $hexagone = self::hexagone((string) $morceaux[1], $erreurs);
        $mur = self::nombre('mur', $morceaux[2], Moteur::MURS, $erreurs);
        $etagere = self::nombre('etagere', $morceaux[3], Moteur::ETAGERES, $erreurs);
        $volume = self::nombre('volume', $morceaux[4], Moteur::VOLUMES, $erreurs);
        $estUnLivre = $morceaux[5] === null;
        $page = $estUnLivre ? null : self::nombre('page', $morceaux[5], Moteur::PAGES, $erreurs);

        if ($erreurs !== []) {
            return ResultatValidation::refusee($erreurs);
        }

        $resultat = new Adresse($hexagone, $mur, $etagere, $volume, $page);

        // Bien écrite, mais au-delà de la dernière page : le dernier hexagone n'est pas complet.
        if (gmp_cmp($resultat->numero(), Adresse::nombreDePages()) >= 0) {
            return ResultatValidation::refusee([new Erreur(CodeErreur::HorsLimites, 'adresse')]);
        }

        return ResultatValidation::valide($resultat);
    }

    /**
     * Pour un contrôleur : renvoie l'adresse de page, ou lève l'erreur du contrat
     * (422, ou 404 s'il ne reste que des ERR_OUT_OF_BOUNDS). Une adresse de livre est refusée.
     *
     * @throws ErreurApi
     */
    public static function exigerPage(string $adresse): Adresse
    {
        $resultat = self::valider($adresse);

        if ($resultat->adresse?->estUnLivre()) {
            throw ErreurApi::depuisListe([(new Erreur(CodeErreur::AdresseMalEcrite, 'page'))->versTableau()]);
        }

        return self::exiger($resultat);
    }

    /**
     * Pour un contrôleur : renvoie l'adresse de page ou de livre, ou lève l'erreur du contrat.
     *
     * @throws ErreurApi
     */
    public static function exigerCible(string $adresse): Adresse
    {
        return self::exiger(self::valider($adresse));
    }

    private static function exiger(ResultatValidation $resultat): Adresse
    {
        return $resultat->adresse ?? throw ErreurApi::depuisListe($resultat->versTableau());
    }

    /**
     * @param  list<Erreur>  $erreurs
     */
    private static function hexagone(string $brut, array &$erreurs): string
    {
        if ($brut === '') {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, 'hexagone');
        } elseif (preg_match('/\A[0-9a-z]+\z/', $brut) !== 1) {
            $erreurs[] = new Erreur(CodeErreur::HexagoneInvalide, 'hexagone');
        } elseif (strlen($brut) > 1 && $brut[0] === '0') {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, 'hexagone');
        } elseif (strlen($brut) > Moteur::LONGUEUR_MAX_HEXAGONE) {
            // Refusé avant toute conversion : une entrée énorme ne coûte aucun calcul.
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, 'hexagone');
        } elseif (gmp_cmp(gmp_init($brut, 36), Adresse::dernierHexagone()) > 0) {
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, 'hexagone');
        }

        return $brut;
    }

    /**
     * Un nombre décimal sans zéro de tête ni signe, entre 1 et $maximum. Au plus une erreur.
     *
     * @param  list<Erreur>  $erreurs
     */
    private static function nombre(string $champ, ?string $brut, int $maximum, array &$erreurs): int
    {
        if ($brut === null || preg_match('/\A(0|[1-9][0-9]*)\z/', $brut) !== 1) {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, $champ);

            return 0;
        }

        // La longueur est contrôlée avant la conversion : « 99999999999999999999 » ne déborde pas.
        if (strlen($brut) > 3 || (int) $brut < 1 || (int) $brut > $maximum) {
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, $champ);

            return 0;
        }

        return (int) $brut;
    }
}
