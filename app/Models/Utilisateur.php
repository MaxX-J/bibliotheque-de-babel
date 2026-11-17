<?php

namespace App\Models;

use Database\Factories\UtilisateurFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un compte, local à cette bibliothèque (V19, M1, M9, M10).
 */
#[Fillable(['pseudo', 'email', 'mot_de_passe_hache', 'role'])]
#[Hidden(['mot_de_passe_hache', 'remember_token'])]
class Utilisateur extends Authenticatable
{
    /** @use HasFactory<UtilisateurFactory> */
    use HasApiTokens, HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'utilisateurs';

    protected $authPasswordName = 'mot_de_passe_hache';

    protected function casts(): array
    {
        return [
            'mot_de_passe_hache' => 'hashed',
            'suspendu_le' => 'datetime',
            'derniere_connexion_le' => 'datetime',
        ];
    }

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function estSuspendu(): bool
    {
        return $this->suspendu_le !== null;
    }

    /** @return HasMany<MarquePage, $this> */
    public function marquePages(): HasMany
    {
        return $this->hasMany(MarquePage::class);
    }

    /** @return HasMany<Collection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    /** @return HasMany<Annotation, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }
}
