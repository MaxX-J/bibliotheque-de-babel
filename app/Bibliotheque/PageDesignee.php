<?php

namespace App\Bibliotheque;

use App\Bibliotheque\Adresse as AdresseValide;
use App\Exceptions\ErreurApi;
use App\Models\Adresse;
use App\Models\Bibliotheque;

/**
 * Une page désignée par la porte application : une adresse de page et sa bibliothèque.
 * Une adresse ne veut rien dire sans sa bibliothèque, puisque chaque bibliothèque a sa clé.
 */
final readonly class PageDesignee
{
    private function __construct(
        public AdresseValide $adresse,
        public Bibliotheque $bibliotheque,
    ) {}

    /**
     * L'adresse est contrôlée d'abord (F5 : 422, ou 404 hors limites), puis la bibliothèque
     * (absente : la locale ; inconnue : 404 ERR_UNKNOWN_LIBRARY).
     *
     * @throws ErreurApi
     */
    public static function depuis(string $adresse, mixed $bibliotheque): self
    {
        $valide = Validation::exigerPage($adresse);

        return new self($valide, self::bibliotheque($bibliotheque));
    }

    /**
     * @throws ErreurApi
     */
    public static function bibliotheque(mixed $identifiant): Bibliotheque
    {
        if ($identifiant === null) {
            return Bibliotheque::locale();
        }

        $bibliotheque = is_string($identifiant)
            ? Bibliotheque::query()->where('identifiant', $identifiant)->first()
            : null;

        return $bibliotheque ?? throw ErreurApi::une(
            'ERR_UNKNOWN_LIBRARY',
            'bibliotheque',
            'Cette bibliothèque ne fait pas partie du réseau.',
            404,
        );
    }

    /**
     * L'adresse déjà enregistrée, sans en créer (null si personne ne l'a encore désignée).
     */
    public function trouver(): ?Adresse
    {
        return Adresse::query()
            ->where('bibliotheque_id', $this->bibliotheque->id)
            ->where('empreinte', Adresse::empreinteDe($this->adresse))
            ->first();
    }

    /**
     * L'adresse enregistrée, créée au besoin (règle 2 : une adresse n'est enregistrée qu'une fois).
     */
    public function enregistrer(): Adresse
    {
        return Adresse::enregistrer($this->bibliotheque, $this->adresse);
    }
}
