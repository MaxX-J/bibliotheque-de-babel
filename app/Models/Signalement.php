<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un signalement vise exactement un contenu publié (V17, V18, A4, règle 13).
 */
#[Fillable(['annotation_id', 'collection_id', 'lien_court_id', 'auteur_id', 'motif', 'statut', 'traite_par_id', 'traite_le'])]
class Signalement extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'signalements';

    protected function casts(): array
    {
        return ['traite_le' => 'datetime'];
    }
}
