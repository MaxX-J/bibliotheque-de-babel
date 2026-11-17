<?php

namespace App\Http\Ressources;

use App\Models\Annotation;
use App\Models\Utilisateur;

/**
 * Une annotation telle que la décrit le contrat (schéma Annotation).
 * De l'auteur, on ne montre que le pseudo.
 */
final class AnnotationRessource
{
    /**
     * L'annotation doit avoir chargé adresse.bibliotheque et utilisateur.
     *
     * @return array<string, mixed>
     */
    public static function versTableau(Annotation $annotation, ?Utilisateur $lecteur): array
    {
        return [
            'id' => (string) $annotation->id,
            'bibliotheque' => $annotation->adresse->bibliotheque->identifiant,
            'adresse' => $annotation->adresse->ecriture(),
            'contenu' => $annotation->contenu,
            'est_publique' => $annotation->est_publique,
            'masquee' => $annotation->masque_le !== null || $annotation->adresse->texte_banni_id !== null,
            // Vrai pour une adresse importée qui désigne encore l'ancienne bibliothèque (M12) :
            // renseigné par l'import d'un compte, qui n'existe pas encore.
            'a_convertir' => false,
            'auteur' => ['pseudo' => $annotation->utilisateur->pseudo],
            'est_a_moi' => $lecteur !== null && $annotation->utilisateur_id === $lecteur->id,
            'cree_le' => $annotation->cree_le->toIso8601String(),
            'maj_le' => $annotation->maj_le->toIso8601String(),
        ];
    }
}
