<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un réglage clé/valeur : inscriptions, quota (A8, A10, règle 22).
 */
#[Fillable(['cle', 'valeur'])]
class Reglage extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'cle';

    protected $keyType = 'string';

    protected $table = 'reglages';
}
