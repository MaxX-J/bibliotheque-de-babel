<?php

namespace App\Bibliotheque;

use Normalizer;

/**
 * F3 normaliser(texte) : convertit une phrase quelconque en texte cherchable.
 *
 * La règle fait partie du contrat commun, elle est identique dans toutes les bibliothèques :
 *  1. passer en minuscules ;
 *  2. retirer les accents et la cédille, développer les lettres liées (œ → oe, æ → ae) ;
 *  3. remplacer tout caractère hors des 29 symboles par une espace, saut de ligne compris ;
 *  4. réduire les espaces qui se suivent à une seule ;
 *  5. retirer les espaces du début et de la fin.
 *
 * Elle accepte tout et ne lève jamais d'erreur : un résultat vide ou trop long est refusé
 * ensuite par le moteur.
 */
final class Normalisation
{
    /**
     * Les 5 étapes, pour les modes exact et bruit. Ex. : « Où est passé l'été ? » → « ou est passe l ete ».
     */
    public static function normaliser(string $texte): string
    {
        $texte = self::etapesUnATrois($texte);
        $texte = preg_replace('/ {2,}/', ' ', $texte);

        return trim($texte, ' ');
    }

    /**
     * Les étapes 1 à 3 seulement, pour le mode page : une page contient des espaces doubles
     * et des espaces en bord, les retirer en ferait un autre texte.
     */
    public static function normaliserPage(string $texte): string
    {
        return self::etapesUnATrois($texte);
    }

    private static function etapesUnATrois(string $texte): string
    {
        // Une suite d'octets qui n'est pas de l'UTF-8 valide devient « ? », puis une espace.
        $texte = mb_scrub($texte, 'UTF-8');

        // 1. Minuscules.
        $texte = mb_strtolower($texte, 'UTF-8');

        // 2. Lettres liées, puis accents et cédille : la décomposition NFD sépare la lettre
        //    de ses signes diacritiques (é → e + ́), qu'on retire ensuite.
        $texte = strtr($texte, ['œ' => 'oe', 'æ' => 'ae']);
        $texte = Normalizer::normalize($texte, Normalizer::FORM_D);
        $texte = preg_replace('/\p{Mn}+/u', '', $texte);

        // 3. Tout caractère hors des 29 symboles devient une espace (un caractère = une espace).
        return preg_replace('/[^a-z ,.]/u', ' ', $texte);
    }
}
