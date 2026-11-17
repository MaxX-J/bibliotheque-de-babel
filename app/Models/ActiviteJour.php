<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Les compteurs d'un jour : pages lues, recherches (A11, règle 21).
 */
#[Fillable(['jour', 'pages_lues', 'recherches'])]
class ActiviteJour extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'jour';

    protected $table = 'activites_jour';

    protected function casts(): array
    {
        return ['jour' => 'date', 'pages_lues' => 'integer', 'recherches' => 'integer'];
    }
}
