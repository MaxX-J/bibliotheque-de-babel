<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Le vérificateur de contrat doit vraiment refuser ce qui s'en écarte.
 */
class VerifieLeContratTest extends TestCase
{
    use VerifieLeContrat;

    private static function reponse(array $donnees): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse($donnees));
    }

    private function assertRefuse(array $donnees, string $schema): void
    {
        try {
            $this->assertConformeAuSchema(self::reponse($donnees), $schema);
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Le vérificateur a accepté une réponse hors contrat ({$schema}).");
    }

    public function test_accepte_une_erreur_conforme(): void
    {
        $this->assertConformeAuSchema(
            self::reponse(['erreurs' => [['code' => 'ERR_NOT_FOUND', 'champ' => null, 'message' => 'Introuvable.']]]),
            'ReponseErreur',
        );
    }

    public function test_refuse_un_champ_en_trop_un_champ_manquant_ou_un_mauvais_type(): void
    {
        $erreur = ['code' => 'ERR_NOT_FOUND', 'champ' => null, 'message' => 'Introuvable.'];

        $this->assertRefuse(['erreurs' => [$erreur], 'message' => 'en trop'], 'ReponseErreur');
        $this->assertRefuse(['erreurs' => []], 'ReponseErreur');
        $this->assertRefuse(['erreurs' => [['code' => 'ERR_INCONNU'] + $erreur]], 'ReponseErreur');
        $this->assertRefuse(['erreurs' => [['champ' => 12] + $erreur]], 'ReponseErreur');
        $this->assertRefuse(['identifiant' => 'ici'], 'Identite');
    }

    public function test_refuse_un_identifiant_numerique_ou_une_adresse_mal_ecrite(): void
    {
        $resume = ['id' => '12', 'nom' => 'Ma collection', 'description' => '', 'nombre_pages' => 0, 'maj_le' => '2026-11-20T14:05:00+01:00'];

        $this->assertConformeAuSchema(self::reponse(['lot' => 1, 'taille' => 20, 'total' => 1, 'elements' => [$resume]]), 'ListeCollections');
        $this->assertRefuse(['lot' => 1, 'taille' => 20, 'total' => 1, 'elements' => [['id' => 12] + $resume]], 'ListeCollections');
        $this->assertRefuse(['bibliotheque' => 'ici', 'adresse' => '0-w04-s1-v1:1', 'texte' => str_repeat('a', 3200)], 'Page');
    }
}
