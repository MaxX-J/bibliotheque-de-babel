<?php

namespace App\Bibliotheque;

use Babel\Moteur\Moteur;
use GMP;
use InvalidArgumentException;

/**
 * Une adresse valide, déjà découpée : une page, ou un livre entier quand la page est nulle.
 *
 * On n'en construit une qu'à partir d'une adresse passée par Validation::valider (F5)
 * ou d'un numéro de page (F7) : son écriture est donc toujours l'écriture valide.
 */
final readonly class Adresse
{
    public const PAGES_PAR_HEXAGONE = Moteur::MURS * Moteur::ETAGERES * Moteur::VOLUMES * Moteur::PAGES;

    public function __construct(
        public string $hexagone,
        public int $mur,
        public int $etagere,
        public int $volume,
        public ?int $page,
    ) {}

    public function estUnLivre(): bool
    {
        return $this->page === null;
    }

    /**
     * @return 'page'|'livre'
     */
    public function niveau(): string
    {
        return $this->estUnLivre() ? 'livre' : 'page';
    }

    /**
     * Le livre qui contient cette page (ou ce livre lui-même).
     */
    public function livre(): self
    {
        return new self($this->hexagone, $this->mur, $this->etagere, $this->volume, null);
    }

    /**
     * Le numéro de la page dans la bibliothèque, de 0 à N − 1 (page 1 pour un livre).
     */
    public function numero(): GMP
    {
        $numero = gmp_add(gmp_mul(gmp_init($this->hexagone, 36), Moteur::MURS), $this->mur - 1);
        $numero = gmp_add(gmp_mul($numero, Moteur::ETAGERES), $this->etagere - 1);
        $numero = gmp_add(gmp_mul($numero, Moteur::VOLUMES), $this->volume - 1);

        return gmp_add(gmp_mul($numero, Moteur::PAGES), ($this->page ?? 1) - 1);
    }

    /**
     * L'adresse de la page qui porte ce numéro.
     */
    public static function depuisNumero(GMP $numero): self
    {
        if (gmp_sign($numero) < 0 || gmp_cmp($numero, self::nombreDePages()) >= 0) {
            throw new InvalidArgumentException('Le numéro de page est hors de la bibliothèque.');
        }

        [$numero, $page] = gmp_div_qr($numero, Moteur::PAGES);
        [$numero, $volume] = gmp_div_qr($numero, Moteur::VOLUMES);
        [$numero, $etagere] = gmp_div_qr($numero, Moteur::ETAGERES);
        [$hexagone, $mur] = gmp_div_qr($numero, Moteur::MURS);

        return new self(
            gmp_strval($hexagone, 36),
            gmp_intval($mur) + 1,
            gmp_intval($etagere) + 1,
            gmp_intval($volume) + 1,
            gmp_intval($page) + 1,
        );
    }

    /**
     * N = 29^3200 : le nombre de pages de la bibliothèque.
     */
    public static function nombreDePages(): GMP
    {
        static $n = null;

        return $n ??= gmp_pow(strlen(Moteur::ALPHABET), Moteur::LONGUEUR_PAGE);
    }

    /**
     * Le numéro du dernier hexagone. Il ne contient qu'une seule page : w1-s1-v1:1.
     */
    public static function dernierHexagone(): GMP
    {
        static $dernier = null;

        return $dernier ??= gmp_div_q(gmp_sub(self::nombreDePages(), 1), self::PAGES_PAR_HEXAGONE);
    }

    public function __toString(): string
    {
        $livre = "{$this->hexagone}-w{$this->mur}-s{$this->etagere}-v{$this->volume}";

        return $this->page === null ? $livre : "{$livre}:{$this->page}";
    }
}
