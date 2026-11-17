<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Une recherche d'un membre ; l'adresse trouvée n'est pas stockée (M8, règle 12).
 */
#[Fillable(['utilisateur_id', 'texte', 'mode', 'portee'])]
class Recherche extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'recherches';
}
