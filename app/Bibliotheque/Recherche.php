<?php

namespace App\Bibliotheque;

use App\Exceptions\ErreurApi;
use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\Erreur;
use Babel\Moteur\Moteur;

/**
 * Une recherche dans notre bibliothèque (F2 après F3), pour la porte réseau comme pour
 * l'application web.
 *
 * Les contrôles se font dans cet ordre et s'arrêtent à la première erreur : mode, puis texte
 * vide, puis longueur. ERR_INVALID_ALPHABET ne peut pas sortir : on normalise avant le moteur.
 */
final class Recherche
{
    public function __construct(private readonly Moteur $moteur) {}

    /**
     * @return array{texte_normalise: string, mode: string, adresse: string}
     *
     * @throws ErreurApi
     */
    public function chercher(mixed $texte, mixed $mode): array
    {
        if (! is_string($mode) || ! in_array($mode, Moteur::MODES, true)) {
            throw self::erreur(CodeErreur::ModeInconnu, 'mode');
        }

        if ($texte !== null && ! is_string($texte)) {
            throw ErreurApi::une('ERR_INVALID_PARAMETER', 'texte', 'Le texte doit être une chaîne de caractères.', 422);
        }

        $normalise = $mode === 'page'
            ? Normalisation::normaliserPage($texte ?? '')
            : Normalisation::normaliser($texte ?? '');

        if ($normalise === '') {
            throw self::erreur(CodeErreur::RechercheVide, 'texte');
        }

        if ($mode === 'page' && strlen($normalise) !== Moteur::LONGUEUR_PAGE) {
            throw self::erreur(CodeErreur::LongueurDePage, 'texte');
        }

        if (strlen($normalise) > Moteur::LONGUEUR_PAGE) {
            throw self::erreur(CodeErreur::TexteTropLong, 'texte');
        }

        return [
            'texte_normalise' => $normalise,
            'mode' => $mode,
            'adresse' => $this->moteur->chercher($normalise, $mode),
        ];
    }

    private static function erreur(CodeErreur $code, string $champ): ErreurApi
    {
        return new ErreurApi([(new Erreur($code, $champ))->versTableau()], 422);
    }
}
