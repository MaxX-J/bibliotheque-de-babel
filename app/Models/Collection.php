<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une collection ordonnée de pages (M4, M5, règle 8).
 */
#[Fillable(['utilisateur_id', 'nom', 'description', 'est_publique', 'masque_le'])]
class Collection extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = 'maj_le';

    protected function casts(): array
    {
        return [
            'est_publique' => 'boolean',
            'masque_le' => 'datetime',
        ];
    }

    /**
     * Publique et non masquée : ce que voit le public et le réseau (B3, V13).
     *
     * @param  Builder<self>  $requete
     */
    public function scopeVisiblesDuPublic(Builder $requete): void
    {
        $requete->where('est_publique', true)->whereNull('masque_le');
    }

    /** @return BelongsTo<Utilisateur, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    /** @return HasMany<CollectionPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(CollectionPage::class)->orderBy('position');
    }

    /**
     * Les pages montrées au public : celles marquées par un texte banni appliqué sont
     * retirées, les autres gardent leur position (règle 18).
     *
     * @return HasMany<CollectionPage, $this>
     */
    public function pagesMontrees(): HasMany
    {
        return $this->pages()->whereHas('adresse', fn (Builder $adresse) => $adresse->whereNull('texte_banni_id'));
    }
}
