<?php

namespace App\Models;

use App\Bibliotheque\Adresse as AdresseValide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une adresse enregistrée : une page ou un livre (page nulle), dans une bibliothèque.
 * Toutes les tables qui désignent une page pointent ici (règle 2). Aucun texte de page (règle 1).
 */
#[Fillable(['bibliotheque_id', 'hexagone', 'mur', 'etagere', 'volume', 'page', 'empreinte', 'empreinte_livre', 'texte_banni_id'])]
class Adresse extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mur' => 'integer',
            'etagere' => 'integer',
            'volume' => 'integer',
            'page' => 'integer',
        ];
    }

    /**
     * Retrouve ou enregistre une adresse déjà validée (F5), dans une bibliothèque.
     */
    public static function enregistrer(Bibliotheque $bibliotheque, AdresseValide $adresse): self
    {
        return self::query()->firstOrCreate(
            ['bibliotheque_id' => $bibliotheque->id, 'empreinte' => self::empreinteDe($adresse)],
            [
                'hexagone' => $adresse->hexagone,
                'mur' => $adresse->mur,
                'etagere' => $adresse->etagere,
                'volume' => $adresse->volume,
                'page' => $adresse->page,
                'empreinte_livre' => self::empreinteDe($adresse->livre()),
            ],
        );
    }

    /**
     * SHA-256 de l'adresse écrite sous sa forme valide (règles 2 et 3).
     */
    public static function empreinteDe(AdresseValide $adresse): string
    {
        return hash('sha256', (string) $adresse);
    }

    public function valeur(): AdresseValide
    {
        return new AdresseValide($this->hexagone, $this->mur, $this->etagere, $this->volume, $this->page);
    }

    public function ecriture(): string
    {
        return (string) $this->valeur();
    }

    /** @return BelongsTo<Bibliotheque, $this> */
    public function bibliotheque(): BelongsTo
    {
        return $this->belongsTo(Bibliotheque::class);
    }

    /** @return BelongsTo<TexteBanni, $this> */
    public function texteBanni(): BelongsTo
    {
        return $this->belongsTo(TexteBanni::class);
    }
}
