<?php

namespace App\Console\Commands;

use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou met à jour la ligne de notre bibliothèque (est_locale à vrai, règle 5).
 */
#[Signature('bibliotheque:installer
    {identifiant : L\'identifiant remis par la plateforme du réseau}
    {nom : Le nom de la bibliothèque}
    {--description= : Sa description}')]
#[Description('Crée ou met à jour la bibliothèque locale (identité publiée sur GET /api/v1/identite)')]
class InstallerBibliotheque extends Command
{
    public function handle(Moteur $moteur): int
    {
        $donnees = [
            'identifiant' => $this->argument('identifiant'),
            'nom' => $this->argument('nom'),
            'description' => $this->option('description'),
        ];

        $validation = Validator::make($donnees, [
            'identifiant' => ['required', 'regex:/\A[a-z0-9][a-z0-9-]{1,31}\z/'],
            'nom' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $locale = Bibliotheque::query()->where('est_locale', true)->first();

        if ($locale !== null && $locale->identifiant !== $donnees['identifiant']) {
            $this->components->error("La bibliothèque locale existe déjà avec l'identifiant « {$locale->identifiant} » : un identifiant ne change jamais.");

            return self::FAILURE;
        }

        Bibliotheque::query()->updateOrCreate(
            ['identifiant' => $donnees['identifiant']],
            [
                'nom' => $donnees['nom'],
                'description' => $donnees['description'],
                'url_actuelle' => config('app.url'),
                'est_locale' => true,
                'etat' => 'en_ligne',
                'empreinte_cle' => $moteur->empreinte(),
            ],
        );

        $this->components->info("Bibliothèque locale « {$donnees['identifiant']} » prête. Empreinte de la clé : {$moteur->empreinte()}");

        return self::SUCCESS;
    }
}
