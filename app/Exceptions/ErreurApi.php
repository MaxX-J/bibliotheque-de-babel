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

    public static function introuvable(?string $champ = null, string $message = 'Cette ressource n\'existe pas.'): self
    {
        return self::une('ERR_NOT_FOUND', $champ, $message, 404);
    }

    public function render(): JsonResponse
    {
        return response()->json(['erreurs' => $this->erreurs], $this->statut, $this->entetes);
    }
}
