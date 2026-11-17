<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lien court vers une page, un livre ou une collection : exactement une cible (V11, V12, règle 11).
 */
#[Fillable(['slug', 'utilisateur_id', 'collection_id', 'adresse_id', 'passage', 'visites', 'expire_le', 'masque_le'])]
class LienCourt extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'liens_courts';

    protected function casts(): array
    {
        return ['visites' => 'integer', 'expire_le' => 'datetime', 'masque_le' => 'datetime'];
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
