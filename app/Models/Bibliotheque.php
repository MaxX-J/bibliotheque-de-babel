<?php

namespace App\Models;

use Database\Factories\BibliothequeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Une bibliothèque du réseau, la nôtre comprise (règles 5 et 6).
 */
#[Fillable([
    'identifiant', 'nom', 'description', 'url_actuelle', 'est_locale', 'etat',
    'echecs_consecutifs', 'dernier_contact_le', 'vue_annuaire_le', 'empreinte_cle', 'cle_changee_le',
])]
class Bibliotheque extends Model
{
    /** @use HasFactory<BibliothequeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'est_locale' => 'boolean',
            'echecs_consecutifs' => 'integer',
            'dernier_contact_le' => 'datetime',
            'vue_annuaire_le' => 'datetime',
            'cle_changee_le' => 'datetime',
        ];
    }

    /**
     * Notre bibliothèque : la seule ligne avec est_locale à vrai.
     */
    public static function locale(): self
    {
        return self::query()->where('est_locale', true)->first()
            ?? throw new RuntimeException('La bibliothèque locale n\'est pas configurée : lancez php artisan bibliotheque:installer.');
    }
}
