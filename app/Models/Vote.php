<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un vote par membre et par page, +1 ou −1 (M7, règle 10).
 */
#[Fillable(['utilisateur_id', 'adresse_id', 'valeur'])]
class Vote extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['valeur' => 'integer'];
    }

    /** @return BelongsTo<Adresse, $this> */
    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class);
    }
}
