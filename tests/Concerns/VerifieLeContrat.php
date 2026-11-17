<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Vérifie une réponse contre un schéma de docs/openapi.yaml (OpenAPI 3.1 = JSON Schema 2020-12).
 * Le contrat fait foi : une réponse qui s'en écarte, même d'un nom de champ, est un échec.
 */
trait VerifieLeContrat
{
    private const ID_CONTRAT = 'https://babel.contrat/openapi.json';

    private static ?Validator $validateurDuContrat = null;

    protected function assertConformeAuSchema(TestResponse $reponse, string $schema): void
    {
        $donnees = json_decode($reponse->getContent(), false, flags: JSON_THROW_ON_ERROR);
        $resultat = self::validateurDuContrat()->validate(
            $donnees,
            (object) ['$ref' => self::ID_CONTRAT.'#/components/schemas/'.$schema],
        );

        $this->assertTrue(
            $resultat->isValid(),
            "La réponse ne respecte pas le schéma {$schema} du contrat :\n"
            .json_encode(
                $resultat->isValid() ? [] : (new ErrorFormatter)->format($resultat->error()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    private static function validateurDuContrat(): Validator
    {
        if (self::$validateurDuContrat === null) {
            $contrat = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml');
            $document = json_decode(json_encode($contrat, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);

            $validateur = new Validator;
            $validateur->parser()->setDefaultDraftVersion('2020-12');
            $validateur->setMaxErrors(10);
            $validateur->resolver()->registerRaw($document, self::ID_CONTRAT);

            self::$validateurDuContrat = $validateur;
        }

        return self::$validateurDuContrat;
    }
}
