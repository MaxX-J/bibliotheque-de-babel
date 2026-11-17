<?php

namespace Tests\Feature;

use App\Bibliotheque\Adresse;
use App\Bibliotheque\Validation;
use App\Exceptions\ErreurApi;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F5 valider(adresse). Comparée au moteur : une adresse de page est valide ici si et
 * seulement si Moteur::lire l'accepte, avec exactement les mêmes erreurs sinon.
 */
class ValidationAdresseTest extends TestCase
{
    private static function dernier(): string
    {
        return gmp_strval(Adresse::dernierHexagone(), 36);
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $champs
     */
    private function assertRefusee(string $adresse, array $codes, array $champs): void
    {
        $resultat = Validation::valider($adresse);

        $this->assertFalse($resultat->estValide());
        $this->assertNull($resultat->niveau());
        $this->assertSame($codes, array_column($resultat->versTableau(), 'code'));
        $this->assertSame($champs, array_column($resultat->versTableau(), 'champ'));
    }

    public function test_une_adresse_de_page_valide(): void
    {
        $resultat = Validation::valider('babel-w2-s3-v17:204');

        $this->assertTrue($resultat->estValide());
        $this->assertSame('page', $resultat->niveau());
        $this->assertSame([], $resultat->erreurs);
        $this->assertSame('babel-w2-s3-v17:204', (string) $resultat->adresse);
        $this->assertSame('babel-w2-s3-v17', (string) $resultat->adresse->livre());
    }

    public function test_une_adresse_de_livre_valide(): void
    {
        $resultat = Validation::valider('babel-w2-s3-v17');

        $this->assertTrue($resultat->estValide());
        $this->assertSame('livre', $resultat->niveau());
        $this->assertNull($resultat->adresse->page);
    }

    public function test_les_bornes_sont_valides(): void
    {
        $this->assertTrue(Validation::valider('0-w1-s1-v1:1')->estValide());
        $this->assertTrue(Validation::valider('1x-w4-s5-v32:410')->estValide());
        $this->assertTrue(Validation::valider(self::dernier().'-w1-s1-v1:1')->estValide());
        $this->assertTrue(Validation::valider(self::dernier().'-w1-s1-v1')->estValide());
    }

    public function test_le_dernier_hexagone_fait_3004_caracteres(): void
    {
        $this->assertSame(Moteur::LONGUEUR_MAX_HEXAGONE, strlen(self::dernier()));
    }

    /**
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function refus(): array
    {
        return [
            'forme inconnue' => ['n importe quoi', ['ERR_INVALID_ADDRESS_SYNTAX'], ['adresse']],
            'chaîne vide' => ['', ['ERR_INVALID_ADDRESS_SYNTAX'], ['adresse']],
            'W majuscule' => ['0-W1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['adresse']],
            'zéro de tête au mur' => ['0-w04-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['mur']],
            'hexagone en majuscules' => ['ABC-w1-s1-v1:1', ['ERR_INVALID_HEX_CHARS'], ['hexagone']],
            'hexagone avec zéro de tête' => ['01-w1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['hexagone']],
            'hexagone vide' => ['-w1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['hexagone']],
            'espace finale' => ['0-w1-s1-v1:1 ', ['ERR_INVALID_ADDRESS_SYNTAX'], ['page']],
            'espace initiale' => [' 0-w1-s1-v1:1', ['ERR_INVALID_HEX_CHARS'], ['hexagone']],
            'page vide' => ['0-w1-s1-v1:', ['ERR_INVALID_ADDRESS_SYNTAX'], ['page']],
            'signe' => ['0-w+1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['mur']],
            'mur zéro' => ['0-w0-s1-v1:1', ['ERR_OUT_OF_BOUNDS'], ['mur']],
            'étagère 6' => ['0-w1-s6-v1:1', ['ERR_OUT_OF_BOUNDS'], ['etagere']],
            'volume 33' => ['0-w1-s1-v33:1', ['ERR_OUT_OF_BOUNDS'], ['volume']],
            'page 411' => ['0-w1-s1-v1:411', ['ERR_OUT_OF_BOUNDS'], ['page']],
            'nombre énorme' => ['0-w99999999999999999999-s1-v1:1', ['ERR_OUT_OF_BOUNDS'], ['mur']],
            'chiffre non ascii' => ['0-w١-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX'], ['mur']],
            'toutes les erreurs dans l ordre' => [
                'ABC-w9-s0-v01:0',
                ['ERR_INVALID_HEX_CHARS', 'ERR_OUT_OF_BOUNDS', 'ERR_OUT_OF_BOUNDS', 'ERR_INVALID_ADDRESS_SYNTAX', 'ERR_OUT_OF_BOUNDS'],
                ['hexagone', 'mur', 'etagere', 'volume', 'page'],
            ],
        ];
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $champs
     */
    #[DataProvider('refus')]
    public function test_une_adresse_mal_ecrite_est_refusee_jamais_corrigee(string $adresse, array $codes, array $champs): void
    {
        $this->assertRefusee($adresse, $codes, $champs);
    }

    public function test_un_hexagone_trop_long_est_hors_limites(): void
    {
        $this->assertRefusee(str_repeat('z', 3005).'-w1-s1-v1:1', ['ERR_OUT_OF_BOUNDS'], ['hexagone']);
    }

    public function test_un_hexagone_apres_le_dernier_est_hors_limites(): void
    {
        $suivant = gmp_strval(gmp_add(Adresse::dernierHexagone(), 1), 36);

        $this->assertRefusee($suivant.'-w1-s1-v1:1', ['ERR_OUT_OF_BOUNDS'], ['hexagone']);
    }

    public function test_le_dernier_hexagone_ne_contient_qu_une_page(): void
    {
        $this->assertRefusee(self::dernier().'-w1-s1-v1:2', ['ERR_OUT_OF_BOUNDS'], ['adresse']);
        $this->assertRefusee(self::dernier().'-w4-s5-v32:410', ['ERR_OUT_OF_BOUNDS'], ['adresse']);
        $this->assertRefusee(self::dernier().'-w2-s1-v1', ['ERR_OUT_OF_BOUNDS'], ['adresse']);
    }

    public function test_valider_ne_leve_jamais_d_exception(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $entree = random_bytes(random_int(1, 40));
            if ($i % 2 === 0) {
                $entree = random_int(0, 99).'-w'.$entree.'-s1-v1:'.random_int(0, 500);
            }

            $this->assertIsBool(Validation::valider($entree)->estValide());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adressesDePage(): array
    {
        $cas = array_map(fn (array $refus) => [$refus[0]], self::refus());

        return $cas + [
            'valide' => ['babel-w2-s3-v17:204'],
            'première' => ['0-w1-s1-v1:1'],
            'livre' => ['babel-w2-s3-v17'],
            'trop long' => [str_repeat('z', 3005).'-w1-s1-v1:1'],
        ];
    }

    #[DataProvider('adressesDePage')]
    public function test_valider_est_d_accord_avec_le_moteur(string $adresse): void
    {
        $moteur = $this->app->make(Moteur::class);

        try {
            $moteur->lire($adresse);
            $erreursDuMoteur = [];
        } catch (ErreurMoteur $e) {
            $erreursDuMoteur = $e->versTableau();
        }

        try {
            Validation::exigerPage($adresse);
            $erreursIci = [];
        } catch (ErreurApi $e) {
            $erreursIci = $e->erreurs;
        }

        $this->assertSame($erreursDuMoteur, $erreursIci);
    }

    public function test_le_dernier_hexagone_est_d_accord_avec_le_moteur(): void
    {
        $moteur = $this->app->make(Moteur::class);

        $this->assertSame(3200, strlen($moteur->lire(self::dernier().'-w1-s1-v1:1')));

        $this->expectException(ErreurMoteur::class);
        $moteur->lire(self::dernier().'-w1-s1-v1:2');
    }

    public function test_exiger_page_donne_422_ou_404_selon_les_erreurs(): void
    {
        try {
            Validation::exigerPage('ABC-w9-s1-v1:1');
            $this->fail('Adresse acceptée');
        } catch (ErreurApi $e) {
            $this->assertSame(422, $e->statut);
        }

        try {
            Validation::exigerPage('0-w1-s1-v33:1');
            $this->fail('Adresse acceptée');
        } catch (ErreurApi $e) {
            $this->assertSame(404, $e->statut);
        }
    }

    public function test_exiger_cible_accepte_un_livre(): void
    {
        $this->assertSame('livre', Validation::exigerCible('0-w1-s1-v1')->niveau());
    }

    public function test_depuis_numero_et_numero_font_l_aller_retour(): void
    {
        foreach (['0-w1-s1-v1:1', 'babel-w2-s3-v17:204', '1x-w4-s5-v32:410', self::dernier().'-w1-s1-v1:1'] as $adresse) {
            $page = Validation::valider($adresse)->adresse;

            $this->assertSame($adresse, (string) Adresse::depuisNumero($page->numero()));
        }
    }
}
