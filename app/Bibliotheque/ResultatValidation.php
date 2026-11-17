<?php

namespace App\Bibliotheque;

use Babel\Moteur\Erreur\Erreur;

/**
 * La réponse de valider (F5) : « valide » avec l'adresse et son niveau, sinon la liste des erreurs.
 */
final readonly class ResultatValidation
{
    /**
     * @param  list<Erreur>  $erreurs
     */
    private function __construct(
        public ?Adresse $adresse,
        public array $erreurs,
    ) {}

    public static function valide(Adresse $adresse): self
    {
        return new self($adresse, []);
    }

    /**
     * @param  non-empty-list<Erreur>  $erreurs
     */
    public static function refusee(array $erreurs): self
    {
        return new self(null, $erreurs);
    }

    public function estValide(): bool
    {
        return $this->adresse !== null;
    }

    /**
     * @return 'page'|'livre'|null
     */
    public function niveau(): ?string
    {
        return $this->adresse?->niveau();
    }

    /**
     * @return list<array{code: string, champ: string, message: string}>
     */
    public function versTableau(): array
    {
        return array_map(fn (Erreur $erreur) => $erreur->versTableau(), $this->erreurs);
    }
}
