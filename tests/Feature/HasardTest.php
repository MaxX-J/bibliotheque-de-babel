<?php

namespace Tests\Feature;

use App\Bibliotheque\Adresse;
use App\Bibliotheque\Hasard;
use App\Bibliotheque\Validation;
use Babel\Moteur\Moteur;
use Tests\TestCase;

/**
 * F7 auHasard(). Sans graine, on ne teste pas une valeur : on teste une distribution.
 */
class HasardTest extends TestCase
{
    private const TIRAGES = 10_000;

    /** Quantile de la loi normale pour un risque de 1 sur 10 000 : le test ne doit pas clignoter. */
    private const Z = 3.719;

    /** @var list<Adresse>|null */
    private static ?array $tirages = null;

    /**
     * @return list<Adresse>
     */
    private static function tirages(): array
    {
        if (self::$tirages === null) {
            self::$tirages = [];
            for ($i = 0; $i < self::TIRAGES; $i++) {
                self::$tirages[] = Hasard::auHasard();
            }
        }

        return self::$tirages;
    }

    /**
     * Test du khi-deux : les valeurs 1 à $categories sortent-elles à parts égales ?
     *
     * @param  list<int>  $valeurs
     */
    private function assertUniforme(array $valeurs, int $categories, string $quoi): void
    {
        $attendu = count($valeurs) / $categories;
        $comptes = array_count_values($valeurs);
        $khiDeux = 0.0;

        for ($valeur = 1; $valeur <= $categories; $valeur++) {
            $khiDeux += (($comptes[$valeur] ?? 0) - $attendu) ** 2 / $attendu;
        }

        // Valeur critique approchée (Wilson-Hilferty) pour $categories − 1 degrés de liberté.
        $ddl = $categories - 1;
        $critique = $ddl * (1 - 2 / (9 * $ddl) + self::Z * sqrt(2 / (9 * $ddl))) ** 3;

        $this->assertLessThan($critique, $khiDeux, "Les {$quoi} ne sortent pas à parts égales.");
    }

    /**
     * Part des pages de la bibliothèque dont l'hexagone a un nom de cette longueur.
     */
    private static function partAttendue(int $longueur): float
    {
        $premier = gmp_pow(36, $longueur - 1);
        $dernierDeCetteLongueur = gmp_sub(gmp_pow(36, $longueur), 1);
        $dernier = Adresse::dernierHexagone();

        if (gmp_cmp($dernierDeCetteLongueur, $dernier) >= 0) {
            // Le dernier hexagone ne contient qu'une page.
            $pages = gmp_add(gmp_mul(gmp_sub($dernier, $premier), Adresse::PAGES_PAR_HEXAGONE), 1);
        } else {
            $pages = gmp_mul(gmp_add(gmp_sub($dernierDeCetteLongueur, $premier), 1), Adresse::PAGES_PAR_HEXAGONE);
        }

        return gmp_intval(gmp_div_q(gmp_mul($pages, 1_000_000), Adresse::nombreDePages())) / 1_000_000;
    }

    public function test_chaque_tirage_est_une_adresse_de_page_valide(): void
    {
        foreach (self::tirages() as $adresse) {
            $resultat = Validation::valider((string) $adresse);

            $this->assertTrue($resultat->estValide(), "Adresse invalide : {$adresse}");
            $this->assertSame('page', $resultat->niveau());
        }
    }

    public function test_les_tirages_se_lisent_avec_le_moteur(): void
    {
        $moteur = $this->app->make(Moteur::class);

        foreach (array_slice(self::tirages(), 0, 50) as $adresse) {
            $this->assertSame(Moteur::LONGUEUR_PAGE, strlen($moteur->lire((string) $adresse)));
        }
    }

    public function test_le_tirage_n_est_pas_reproductible(): void
    {
        $adresses = array_map('strval', self::tirages());

        $this->assertCount(self::TIRAGES, array_unique($adresses));
    }

    public function test_murs_etageres_volumes_et_pages_sortent_a_parts_egales_sur_10_000_tirages(): void
    {
        $tirages = self::tirages();

        $this->assertUniforme(array_column($tirages, 'mur'), Moteur::MURS, 'murs');
        $this->assertUniforme(array_column($tirages, 'etagere'), Moteur::ETAGERES, 'étagères');
        $this->assertUniforme(array_column($tirages, 'volume'), Moteur::VOLUMES, 'volumes');
        $this->assertUniforme(array_column($tirages, 'page'), Moteur::PAGES, 'pages');
    }

    public function test_la_longueur_de_l_hexagone_suit_la_taille_de_la_bibliotheque(): void
    {
        $longueurs = array_count_values(array_map(fn (Adresse $a) => strlen($a->hexagone), self::tirages()));
        $parts = [
            3004 => self::partAttendue(3004),
            3003 => self::partAttendue(3003),
        ];

        // Ce qu'annonce le cahier des charges : environ 79 %, 20 %, et moins de 1 % pour le reste.
        $this->assertEqualsWithDelta(0.79, $parts[3004], 0.01);
        $this->assertEqualsWithDelta(0.20, $parts[3003], 0.01);

        foreach ($parts as $longueur => $part) {
            $ecartType = sqrt($part * (1 - $part) / self::TIRAGES);
            $observee = ($longueurs[$longueur] ?? 0) / self::TIRAGES;

            $this->assertEqualsWithDelta($part, $observee, self::Z * $ecartType, "Hexagones de {$longueur} caractères.");
        }

        $reste = self::TIRAGES - ($longueurs[3004] ?? 0) - ($longueurs[3003] ?? 0);
        $this->assertLessThan(0.02, $reste / self::TIRAGES);
    }
}
