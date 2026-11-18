<?php

namespace App\Moderation;

use App\Exceptions\ErreurApi;
use App\Models\Adresse;
use App\Models\TexteBanni;
use App\Reseau\ClientPair;
use Babel\Moteur\Moteur;

/**
 * Vérifie une page désignée contre les textes bannis appliqués (livrable 3, « Comment un
 * texte banni masque ce qui est publié », étape 3 ; règle 18).
 *
 * On lit la page (ici avec le moteur, ailleurs chez son pair) et on y cherche chaque texte
 * banni appliqué : le premier trouvé marque l'adresse. Rien n'est supprimé, la page reste
 * lisible ; ce qui est publié vers elle n'est plus montré au public.
 *
 * Une page d'une autre bibliothèque qui ne répond pas reste non vérifiée : elle sera revue
 * au passage suivant (étape 2, tâche de fond).
 */
final class VerificationTextesBannis
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly ClientPair $pairs,
    ) {}

    public function verifier(Adresse $adresse): void
    {
        if ($adresse->texte_banni_id !== null || $adresse->page === null) {
            return;
        }

        $textes = TexteBanni::query()->where('statut', 'applique')->orderBy('id')->get(['id', 'texte']);

        if ($textes->isEmpty()) {
            return;
        }

        $page = $this->lire($adresse);

        if ($page === null) {
            return;
        }

        $trouve = $textes->first(fn (TexteBanni $texte) => str_contains($page, $texte->texte));

        if ($trouve !== null) {
            $adresse->update(['texte_banni_id' => $trouve->id]);
        }
    }

    private function lire(Adresse $adresse): ?string
    {
        $bibliotheque = $adresse->bibliotheque;

        if ($bibliotheque->est_locale) {
            return $this->moteur->lire($adresse->ecriture());
        }

        try {
            return $this->pairs->lirePage($bibliotheque, $adresse->ecriture());
        } catch (ErreurApi) {
            return null;
        }
    }
}
