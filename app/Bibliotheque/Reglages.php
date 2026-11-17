<?php

namespace App\Bibliotheque;

use App\Models\Reglage;

/**
 * Les réglages de la bibliothèque, en paires clé/valeur (règle 22).
 * Jamais la clé du moteur ni le jeton réseau : ils restent dans la configuration du serveur.
 */
final class Reglages
{
    public const INSCRIPTIONS_OUVERTES = 'inscriptions_ouvertes';

    public static function inscriptionsOuvertes(): bool
    {
        return self::lire(self::INSCRIPTIONS_OUVERTES, '1') === '1';
    }

    public static function ouvrirLesInscriptions(bool $ouvertes): void
    {
        self::ecrire(self::INSCRIPTIONS_OUVERTES, $ouvertes ? '1' : '0');
    }

    public static function lire(string $cle, string $defaut): string
    {
        return Reglage::query()->find($cle)?->valeur ?? $defaut;
    }

    public static function ecrire(string $cle, string $valeur): void
    {
        Reglage::query()->updateOrCreate(['cle' => $cle], ['valeur' => $valeur]);
    }
}
