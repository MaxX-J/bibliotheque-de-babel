<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un marque-page, toujours privé (M2, M3, M13, règle 7).
 */
#[Fillable(['utilisateur_id', 'adresse_id', 'titre'])]
class MarquePage extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'marque_pages';

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
