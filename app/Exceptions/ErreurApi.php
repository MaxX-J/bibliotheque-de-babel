<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Une erreur du contrat d'API : une liste d'erreurs { code, champ, message } et un statut HTTP.
 */
class ErreurApi extends RuntimeException
{
    /**
     * @param  list<array{code: string, champ: string|null, message: string}>  $erreurs
     * @param  array<string, string|int>  $entetes
     */
    public function __construct(
        public readonly array $erreurs,
        public readonly int $statut,
        public readonly array $entetes = [],
    ) {
        parent::__construct(implode(', ', array_column($erreurs, 'code')));
    }

    /**
     * @param  array<string, string|int>  $entetes
     */
    public static function une(string $code, ?string $champ, string $message, int $statut, array $entetes = []): self
    {
        return new self([['code' => $code, 'champ' => $champ, 'message' => $message]], $statut, $entetes);
    }

    /**
     * Des erreurs d'adresse ou de recherche : 404 s'il ne reste que des ERR_OUT_OF_BOUNDS
     * (l'adresse est bien écrite, la page n'existe pas), 422 dès qu'une erreur de forme y figure.
     *
     * @param  list<array{code: string, champ: string|null, message: string}>  $erreurs
     */
    public static function depuisListe(array $erreurs): self
    {
        $seulementHorsLimites = array_filter($erreurs, fn (array $erreur) => $erreur['code'] !== 'ERR_OUT_OF_BOUNDS') === [];

        return new self($erreurs, $seulementHorsLimites ? 404 : 422);
    }

    public static function identifiantsIncorrects(): self
    {
        return self::une('ERR_INVALID_CREDENTIALS', null, 'Email ou mot de passe incorrect.', 401);
    }

    public static function doublon(string $champ, string $message): self
    {
        return self::une('ERR_ALREADY_EXISTS', $champ, $message, 409);
    }

    public static function compteSuspendu(): self
    {
        return self::une('ERR_ACCOUNT_SUSPENDED', null, 'Ce compte est suspendu.', 403);
    }

    public static function introuvable(?string $champ = null, string $message = 'Cette ressource n\'existe pas.'): self
    {
        return self::une('ERR_NOT_FOUND', $champ, $message, 404);
    }

    public function render(): JsonResponse
    {
        return response()->json(['erreurs' => $this->erreurs], $this->statut, $this->entetes);
    }
}
