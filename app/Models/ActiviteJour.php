<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Ajoute 1 au compteur du jour : 'pages_lues' ou 'recherches'. Aucune donnée personnelle.
     */
    public static function compter(string $compteur): void
    {
        $ligne = ['jour' => now()->toDateString(), 'pages_lues' => 0, 'recherches' => 0];
        $ligne[$compteur] = 1;

        self::query()->upsert([$ligne], ['jour'], [$compteur => DB::raw("{$compteur} + 1")]);
    }

    protected function casts(): array
    {
        return ['jour' => 'date', 'pages_lues' => 'integer', 'recherches' => 'integer'];
    }
}
