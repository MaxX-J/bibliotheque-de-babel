<?php

namespace App\Bibliotheque;

/**
 * F7 auHasard() : tire une adresse de page au hasard, sans graine.
 *
 * Chaque page de la bibliothèque a la même chance de sortir. Un entier aléatoire ordinaire
 * (64 bits) ne tomberait que dans les tout premiers hexagones : on tire donc directement un
 * numéro de page entre 0 et N − 1, où N = 29^3200 a 15 546 bits.
 *
 * Méthode du rejet : on tire autant de bits que N en a, avec le générateur cryptographique
 * de PHP (random_bytes), et on recommence si le nombre dépasse N − 1. Comme N > 2^15545,
 * un tirage sur deux au moins est accepté, et le résultat est exactement uniforme.
 */
final class Hasard
{
    public static function auHasard(): Adresse
    {
        $n = Adresse::nombreDePages();
        $bits = strlen(gmp_strval($n, 2));
        $octets = intdiv($bits + 7, 8);
        $bitsEnTrop = $octets * 8 - $bits;

        do {
            $numero = gmp_import(random_bytes($octets));
            $numero = gmp_div_q($numero, gmp_pow(2, $bitsEnTrop));
        } while (gmp_cmp($numero, $n) >= 0);

        return Adresse::depuisNumero($numero);
    }
}
