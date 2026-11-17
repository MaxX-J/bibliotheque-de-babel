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

    /**
     * Le membre tel que le décrit le contrat (schéma Utilisateur). Jamais le mot de passe.
     *
     * @return array{id: string, pseudo: string, email: string, role: string, cree_le: string}
     */
    public function versProfil(): array
    {
        return [
            'id' => (string) $this->id,
            'pseudo' => $this->pseudo,
            'email' => $this->email,
            'role' => $this->role,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }

    /**
     * Ouvre une session : un jeton d'API Sanctum, à envoyer dans Authorization: Bearer.
     *
     * @return array{jeton: string, type: string, utilisateur: array<string, string>}
     */
    public function ouvrirUneSession(): array
    {
        $this->forceFill(['derniere_connexion_le' => now()])->save();

        return [
            'jeton' => $this->createToken('application-web')->plainTextToken,
            'type' => 'Bearer',
            'utilisateur' => $this->versProfil(),
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
