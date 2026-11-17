<?php

namespace Database\Factories;

use App\Models\Bibliotheque;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bibliotheque>
 */
class BibliothequeFactory extends Factory
{
    public function definition(): array
    {
        $identifiant = fake()->unique()->regexify('[a-z]{6,10}');

        return [
            'identifiant' => $identifiant,
            'nom' => 'Bibliothèque '.$identifiant,
            'description' => null,
            'url_actuelle' => "https://{$identifiant}.exemple.test",
            'est_locale' => false,
            'etat' => 'en_ligne',
            'empreinte_cle' => hash('sha256', $identifiant),
        ];
    }

    public function locale(): static
    {
        return $this->state(fn () => [
            'identifiant' => 'ici',
            'nom' => 'La bibliothèque d\'ici',
            'url_actuelle' => 'http://localhost:8000',
            'est_locale' => true,
        ]);
    }
}
