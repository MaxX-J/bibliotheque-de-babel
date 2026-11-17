<?php

namespace Tests\Unit;

use App\Bibliotheque\Normalisation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalisationTest extends TestCase
{
    private const MOTIF_NORMALISE = '/\A([a-z,.]+( [a-z,.]+)*)?\z/';

    /**
     * @return array<string, array{string, string}>
     */
    public static function exemples(): array
    {
        return [
            'exemple du cahier des charges' => ['Où est passé l\'été ?', 'ou est passe l ete'],
            'majuscules' => ['BONJOUR Le Monde', 'bonjour le monde'],
            'cédille' => ['Ça, c\'est français.', 'ca, c est francais.'],
            'œ et æ' => ['Cœur, ŒUVRE, Æther, nævus', 'coeur, oeuvre, aether, naevus'],
            'accents variés' => ['àâä éèêë îï ôö ùûü ÿ ñ', 'aaa eeee ii oo uuu y n'],
            'saut de ligne et tabulation' => ["un\ndeux\r\ntrois\tquatre", 'un deux trois quatre'],
            'espaces multiples et bords' => ['   trop    d   espaces  ', 'trop d espaces'],
            'chiffres et symboles' => ['Page 42 : 100 % !', 'page'],
            'virgule et point gardés' => ['a,b.c', 'a,b.c'],
            'emoji' => ['salut 👋 toi', 'salut toi'],
            'lettres sans décomposition' => ['ø ł ß', ''],
            'vide après normalisation' => ['?!', ''],
            'chaîne vide' => ['', ''],
        ];
    }

    #[DataProvider('exemples')]
    public function test_normalise_selon_les_5_etapes(string $entree, string $attendu): void
    {
        $this->assertSame($attendu, Normalisation::normaliser($entree));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entreesQuelconques(): array
    {
        return array_map(fn (array $exemple) => [$exemple[0]], self::exemples()) + [
            'utf-8 invalide' => ["caf\xC3 \xFF\xFE ok"],
            'apostrophes typographiques' => ['l’été « chaud »'],
            'alphabets non latins' => ['Привет мир, γεια σου.'],
            'combinaisons isolées' => ["e\u{0301}\u{0301} a\u{0327}"],
        ];
    }

    #[DataProvider('entreesQuelconques')]
    public function test_la_sortie_n_utilise_que_les_29_symboles(string $entree): void
    {
        $this->assertMatchesRegularExpression(self::MOTIF_NORMALISE, Normalisation::normaliser($entree));
    }

    #[DataProvider('entreesQuelconques')]
    public function test_normaliser_deux_fois_revient_a_normaliser_une_fois(string $entree): void
    {
        $une = Normalisation::normaliser($entree);

        $this->assertSame($une, Normalisation::normaliser($une));
    }

    public function test_un_caractere_hors_alphabet_donne_une_seule_espace(): void
    {
        $this->assertSame('l ete', Normalisation::normaliser('l\'ete'));
        $this->assertSame('a b', Normalisation::normaliser('a€b'));
    }

    public function test_le_mode_page_garde_les_espaces_doubles_et_de_bord(): void
    {
        $this->assertSame('  ab  c ', Normalisation::normaliserPage('  Ab  c '));
        $this->assertSame(' l ete  ', Normalisation::normaliserPage(' l\'été  '));
    }

    public function test_le_mode_page_ne_change_pas_une_page_valide(): void
    {
        $page = str_repeat('ab, c.  ', 400);

        $this->assertSame(3200, strlen($page));
        $this->assertSame($page, Normalisation::normaliserPage($page));
    }

    public function test_le_mode_page_garde_un_caractere_par_caractere(): void
    {
        $this->assertSame(mb_strlen('Où ça ?!'), strlen(Normalisation::normaliserPage('Où ça ?!')));
    }
}
