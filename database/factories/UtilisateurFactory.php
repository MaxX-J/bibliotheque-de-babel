<?php

namespace Database\Factories;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Utilisateur>
 */
class UtilisateurFactory extends Factory
{
    protected static ?string $motDePasse = null;

    public function definition(): array
    {
        return [
            'pseudo' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'mot_de_passe_hache' => static::$motDePasse ??= Hash::make('mot-de-passe'),
            'role' => 'membre',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function suspendu(): static
    {
        return $this->state(fn () => ['suspendu_le' => now()]);
    }
}
