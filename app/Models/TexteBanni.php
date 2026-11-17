<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un texte banni, stocké en clair et normalisé (A5, A6, A7, B5, règles 16 à 18).
 */
#[Fillable(['texte', 'empreinte', 'motif', 'origine_id', 'statut', 'decide_le'])]
class TexteBanni extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'textes_bannis';

    protected function casts(): array
    {
        return ['decide_le' => 'datetime'];
    }

    /** @return BelongsTo<Bibliotheque, $this> */
    public function origine(): BelongsTo
    {
        return $this->belongsTo(Bibliotheque::class, 'origine_id');
    }
}
