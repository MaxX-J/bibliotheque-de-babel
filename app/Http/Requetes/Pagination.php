<?php

namespace App\Http\Requetes;

use App\Exceptions\ErreurApi;
use Illuminate\Http\Request;

/**
 * Les paramètres lot (à partir de 1) et taille (1 à 50, 20 par défaut) des listes.
 * Mal écrits ou hors limites, ils sont refusés (ERR_INVALID_PARAMETER), toutes les erreurs d'un coup.
 */
final readonly class Pagination
{
    public const TAILLE_MAX = 50;

    public const TAILLE_PAR_DEFAUT = 20;

    private function __construct(
        public int $lot,
        public int $taille,
    ) {}

    /**
     * @throws ErreurApi
     */
    public static function depuis(Request $request): self
    {
        $erreurs = [];
        $lot = self::entier($request->query('lot'), 1, PHP_INT_MAX, 1);
        $taille = self::entier($request->query('taille'), 1, self::TAILLE_MAX, self::TAILLE_PAR_DEFAUT);

        if ($lot === null) {
            $erreurs[] = ['code' => 'ERR_INVALID_PARAMETER', 'champ' => 'lot', 'message' => 'Le lot doit être un entier supérieur ou égal à 1.'];
        }
        if ($taille === null) {
            $erreurs[] = ['code' => 'ERR_INVALID_PARAMETER', 'champ' => 'taille', 'message' => 'La taille doit être comprise entre 1 et 50.'];
        }
        if ($erreurs !== []) {
            throw new ErreurApi($erreurs, 422);
        }

        return new self($lot, $taille);
    }

    /**
     * Seulement la taille (GET /pages-populaires).
     *
     * @throws ErreurApi
     */
    public static function taille(Request $request): int
    {
        return self::entier($request->query('taille'), 1, self::TAILLE_MAX, self::TAILLE_PAR_DEFAUT)
            ?? throw ErreurApi::une('ERR_INVALID_PARAMETER', 'taille', 'La taille doit être comprise entre 1 et 50.', 422);
    }

    public function decalage(): int
    {
        return ($this->lot - 1) * $this->taille;
    }

    private static function entier(mixed $brut, int $min, int $max, int $defaut): ?int
    {
        if ($brut === null) {
            return $defaut;
        }
        if (! is_string($brut) || preg_match('/\A[1-9][0-9]{0,17}\z/', $brut) !== 1) {
            return null;
        }

        $valeur = (int) $brut;

        return $valeur >= $min && $valeur <= $max ? $valeur : null;
    }
}
