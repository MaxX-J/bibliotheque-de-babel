<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une annotation d'un membre sur une page, publique ou privée, 2 000 caractères au plus (M6, V14, règle 9).
 */
#[Fillable(['utilisateur_id', 'adresse_id', 'contenu', 'est_publique', 'masque_le'])]
class Annotation extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = 'maj_le';

    protected $table = 'annotations';

    protected function casts(): array
    {
        return ['est_publique' => 'boolean', 'masque_le' => 'datetime'];
    }

    /** @return BelongsTo<Utilisateur, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    /** @return BelongsTo<Adresse, $this> */
    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class);
    }
}
