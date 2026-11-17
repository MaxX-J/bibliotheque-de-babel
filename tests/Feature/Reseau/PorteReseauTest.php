<?php

namespace Tests\Feature\Reseau;

use App\Bibliotheque\Validation;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\CollectionPage;
use App\Models\MarquePage;
use App\Models\TexteBanni;
use App\Models\Utilisateur;
use App\Models\Vote;
use Babel\Moteur\Moteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Les 8 routes de la porte réseau (/api/v1), vérifiées contre les schémas du contrat.
 */
class PorteReseauTest extends TestCase
{
    use RefreshDatabase;
    use VerifieLeContrat;

    private Bibliotheque $ici;

    private Moteur $moteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ici = Bibliotheque::factory()->locale()->create(['description' => null]);
        $this->moteur = $this->app->make(Moteur::class);
        config(['bibliotheque.jeton_reseau' => 'jeton-du-reseau-pour-les-tests']);
    }

    private function adresse(string $ecriture, ?Bibliotheque $bibliotheque = null): Adresse
    {
        return Adresse::enregistrer($bibliotheque ?? $this->ici, Validation::valider($ecriture)->adresse);
    }

    private function texteBanni(string $texte, Bibliotheque $origine, string $statut = 'applique'): TexteBanni
    {
        return TexteBanni::query()->create([
            'texte' => $texte,
            'empreinte' => hash('sha256', $texte),
            'motif' => 'Pour les tests.',
            'origine_id' => $origine->id,
            'statut' => $statut,
            'decide_le' => now(),
        ]);
    }

    // ---------------------------------------------------------------- GET /identite

    public function test_identite(): void
    {
        $reponse = $this->getJson('/api/v1/identite')->assertOk();

        $this->assertConformeAuSchema($reponse, 'Identite');
        $reponse->assertExactJson([
            'identifiant' => 'ici',
            'nom' => 'La bibliothèque d\'ici',
            'description' => '',
            'empreinte_cle' => $this->moteur->empreinte(),
            'algorithme' => 'babel-moteur/v1',
            'contrat' => '1.1.0',
            'application_web' => null,
        ]);
    }

    // ---------------------------------------------------------------- GET /pages/{adresse}

    public function test_lire_une_page(): void
    {
        $reponse = $this->getJson('/api/v1/pages/babel-w2-s3-v17:204')->assertOk();

        $this->assertConformeAuSchema($reponse, 'Page');
        $reponse->assertExactJson([
            'bibliotheque' => 'ici',
            'adresse' => 'babel-w2-s3-v17:204',
            'texte' => $this->moteur->lire('babel-w2-s3-v17:204'),
        ]);
    }

    public function test_une_page_contenant_un_texte_banni_reste_lisible(): void
    {
        $adresse = $this->adresse('0-w1-s1-v1:1');
        $adresse->update(['texte_banni_id' => $this->texteBanni('quelque chose', $this->ici)->id]);

        $this->getJson('/api/v1/pages/0-w1-s1-v1:1')->assertOk();
    }

    public function test_lire_une_adresse_de_livre_est_refuse(): void
    {
        $reponse = $this->getJson('/api/v1/pages/0-w1-s1-v1')->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_INVALID_ADDRESS_SYNTAX')->assertJsonPath('erreurs.0.champ', 'page');
    }

    public function test_lire_une_adresse_mal_ecrite_renvoie_toutes_les_erreurs(): void
    {
        $reponse = $this->getJson('/api/v1/pages/ABC-w9-s1-v1:1')->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $this->assertSame(['hexagone', 'mur'], array_column($reponse->json('erreurs'), 'champ'));
    }

    public function test_lire_une_page_qui_n_existe_pas(): void
    {
        $this->getJson('/api/v1/pages/0-w1-s1-v33:1')->assertNotFound()->assertJsonPath('erreurs.0.code', 'ERR_OUT_OF_BOUNDS');
    }

    // ---------------------------------------------------------------- POST /lectures

    public function test_lire_plusieurs_pages_garde_l_ordre_et_isole_les_erreurs(): void
    {
        $reponse = $this->postJson('/api/v1/lectures', ['adresses' => ['0-w1-s1-v1:1', '0-w1-s1-v33:1', '0-w1-s1-v1']])
            ->assertOk();

        $this->assertConformeAuSchema($reponse, 'ResultatLectures');
        $reponse->assertJsonPath('bibliotheque', 'ici')
            ->assertJsonPath('elements.0.adresse', '0-w1-s1-v1:1')
            ->assertJsonPath('elements.0.texte', $this->moteur->lire('0-w1-s1-v1:1'))
            ->assertJsonPath('elements.0.erreurs', null)
            ->assertJsonPath('elements.1.texte', null)
            ->assertJsonPath('elements.1.erreurs.0.code', 'ERR_OUT_OF_BOUNDS')
            ->assertJsonPath('elements.2.erreurs.0.champ', 'page');
    }

    public function test_lire_plus_de_50_pages_est_refuse(): void
    {
        $reponse = $this->postJson('/api/v1/lectures', ['adresses' => array_fill(0, 51, '0-w1-s1-v1:1')])
            ->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_INVALID_PARAMETER')->assertJsonPath('erreurs.0.champ', 'adresses');
    }

    public function test_lectures_mal_formees(): void
    {
        $this->postJson('/api/v1/lectures', [])->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'adresses');
        $this->postJson('/api/v1/lectures', ['adresses' => []])->assertUnprocessable();
        $this->postJson('/api/v1/lectures', ['adresses' => [12]])->assertUnprocessable()->assertJsonPath('erreurs.0.code', 'ERR_INVALID_PARAMETER');
    }

    // ---------------------------------------------------------------- POST /recherches

    public function test_chercher_normalise_puis_trouve_le_texte(): void
    {
        $reponse = $this->postJson('/api/v1/recherches', ['texte' => 'Où est passé l\'été ?', 'mode' => 'exact'])->assertOk();

        $this->assertConformeAuSchema($reponse, 'ResultatRecherche');
        $reponse->assertJsonPath('bibliotheque', 'ici')
            ->assertJsonPath('texte_normalise', 'ou est passe l ete')
            ->assertJsonPath('mode', 'exact');
        $this->assertStringStartsWith('ou est passe l ete', $this->moteur->lire($reponse->json('adresse')));
    }

    public function test_la_meme_recherche_donne_toujours_la_meme_adresse(): void
    {
        foreach (['exact', 'bruit'] as $mode) {
            $premiere = $this->postJson('/api/v1/recherches', ['texte' => 'la bibliotheque', 'mode' => $mode])->json('adresse');
            $seconde = $this->postJson('/api/v1/recherches', ['texte' => 'La Bibliothèque', 'mode' => $mode])->json('adresse');

            $this->assertSame($premiere, $seconde);
            $this->assertStringContainsString('la bibliotheque', $this->moteur->lire($premiere));
        }
    }

    public function test_le_mode_page_redonne_l_adresse_d_une_page_espaces_comprises(): void
    {
        // Une page trouvée en mode exact finit par des espaces : TrimStrings ne doit pas les retirer.
        $adresse = $this->postJson('/api/v1/recherches', ['texte' => 'fin de page', 'mode' => 'exact'])->json('adresse');
        $texte = $this->moteur->lire($adresse);
        $this->assertStringEndsWith('  ', $texte);

        $reponse = $this->postJson('/api/v1/recherches', ['texte' => $texte, 'mode' => 'page'])->assertOk();

        $this->assertConformeAuSchema($reponse, 'ResultatRecherche');
        $reponse->assertJsonPath('adresse', $adresse)->assertJsonPath('texte_normalise', $texte);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function recherchesRefusees(): array
    {
        return [
            'mode absent' => [['texte' => 'abc'], 'ERR_UNKNOWN_MODE'],
            'mode en majuscule' => [['texte' => 'abc', 'mode' => 'Exact'], 'ERR_UNKNOWN_MODE'],
            'mode avant le texte' => [['texte' => '', 'mode' => 'autre'], 'ERR_UNKNOWN_MODE'],
            'texte absent' => [['mode' => 'exact'], 'ERR_EMPTY_QUERY'],
            'texte null' => [['texte' => null, 'mode' => 'exact'], 'ERR_EMPTY_QUERY'],
            'texte vide' => [['texte' => '', 'mode' => 'exact'], 'ERR_EMPTY_QUERY'],
            'vide après normalisation' => [['texte' => '?!', 'mode' => 'bruit'], 'ERR_EMPTY_QUERY'],
            'trop long' => [['texte' => str_repeat('a', 3201), 'mode' => 'exact'], 'ERR_TEXT_TOO_LONG'],
            'page trop courte' => [['texte' => 'abc', 'mode' => 'page'], 'ERR_INVALID_PAGE_LENGTH'],
        ];
    }

    /**
     * @param  array<string, mixed>  $corps
     */
    #[DataProvider('recherchesRefusees')]
    public function test_une_recherche_refusee(array $corps, string $code): void
    {
        $reponse = $this->postJson('/api/v1/recherches', $corps)->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $this->assertCount(1, $reponse->json('erreurs'));
        $reponse->assertJsonPath('erreurs.0.code', $code);
    }

    // ---------------------------------------------------------------- GET /collections

    /**
     * Trois collections publiques (dont une avec une page bannie), une privée, une masquée.
     *
     * @return array{Collection, Collection, Collection}
     */
    private function desCollections(): array
    {
        $membre = Utilisateur::factory()->create();
        $voisine = Bibliotheque::factory()->create(['identifiant' => 'voisine']);
        $banni = $this->texteBanni('texte interdit', $this->ici);

        $ancienne = Collection::query()->create(['utilisateur_id' => $membre->id, 'nom' => 'Ancienne', 'est_publique' => true]);
        $ancienne->forceFill(['maj_le' => now()->subDays(2)])->save();

        $avecBannie = Collection::query()->create(['utilisateur_id' => $membre->id, 'nom' => 'Avec une page bannie', 'description' => 'Trois pages.', 'est_publique' => true]);
        $avecBannie->forceFill(['maj_le' => now()->subDay()])->save();
        $pageBannie = $this->adresse('0-w1-s1-v1:2');
        $pageBannie->update(['texte_banni_id' => $banni->id]);
        CollectionPage::query()->insert([
            ['collection_id' => $avecBannie->id, 'position' => 1, 'adresse_id' => $this->adresse('0-w1-s1-v1:1')->id],
            ['collection_id' => $avecBannie->id, 'position' => 2, 'adresse_id' => $pageBannie->id],
            ['collection_id' => $avecBannie->id, 'position' => 3, 'adresse_id' => $this->adresse('babel-w2-s3-v17:204', $voisine)->id],
        ]);

        $recente = Collection::query()->create(['utilisateur_id' => $membre->id, 'nom' => 'Récente', 'est_publique' => true]);

        Collection::query()->create(['utilisateur_id' => $membre->id, 'nom' => 'Privée', 'est_publique' => false]);
        Collection::query()->create(['utilisateur_id' => $membre->id, 'nom' => 'Masquée', 'est_publique' => true, 'masque_le' => now()]);

        return [$recente, $avecBannie, $ancienne];
    }

    public function test_lister_les_collections_publiques(): void
    {
        [$recente, $avecBannie, $ancienne] = $this->desCollections();

        $reponse = $this->getJson('/api/v1/collections')->assertOk();

        $this->assertConformeAuSchema($reponse, 'ListeCollections');
        $reponse->assertJsonPath('lot', 1)->assertJsonPath('taille', 20)->assertJsonPath('total', 3);
        $this->assertSame(
            [(string) $recente->id, (string) $avecBannie->id, (string) $ancienne->id],
            array_column($reponse->json('elements'), 'id'),
        );
        $reponse->assertJsonPath('elements.1.nombre_pages', 2)
            ->assertJsonPath('elements.1.description', 'Trois pages.')
            ->assertJsonPath('elements.0.description', '');
    }

    public function test_lister_les_collections_par_lots(): void
    {
        $this->desCollections();

        $reponse = $this->getJson('/api/v1/collections?lot=2&taille=2')->assertOk();

        $this->assertConformeAuSchema($reponse, 'ListeCollections');
        $reponse->assertJsonPath('total', 3)->assertJsonCount(1, 'elements');
        $this->getJson('/api/v1/collections?lot=9')->assertOk()->assertJsonCount(0, 'elements');
    }

    public function test_un_lot_ou_une_taille_hors_limites_est_refuse(): void
    {
        $reponse = $this->getJson('/api/v1/collections?lot=0&taille=51')->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $this->assertSame(['lot', 'taille'], array_column($reponse->json('erreurs'), 'champ'));
        $this->getJson('/api/v1/collections?taille=abc')->assertUnprocessable();
        $this->getJson('/api/v1/collections?taille=05')->assertUnprocessable();
    }

    public function test_lire_une_collection_sans_ses_pages_bannies(): void
    {
        [, $avecBannie] = $this->desCollections();

        $reponse = $this->getJson("/api/v1/collections/{$avecBannie->id}")->assertOk();

        $this->assertConformeAuSchema($reponse, 'Collection');
        $reponse->assertJsonPath('nombre_pages', 2)->assertJsonPath('pages', [
            ['position' => 1, 'bibliotheque' => 'ici', 'adresse' => '0-w1-s1-v1:1'],
            ['position' => 3, 'bibliotheque' => 'voisine', 'adresse' => 'babel-w2-s3-v17:204'],
        ]);
    }

    public function test_une_collection_privee_masquee_ou_inconnue_donne_la_meme_404(): void
    {
        $this->desCollections();
        $privee = Collection::query()->where('nom', 'Privée')->value('id');
        $masquee = Collection::query()->where('nom', 'Masquée')->value('id');

        foreach ([$privee, $masquee, 9999, 'abc'] as $id) {
            $reponse = $this->getJson("/api/v1/collections/{$id}")->assertNotFound();

            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.code', 'ERR_NOT_FOUND')->assertJsonPath('erreurs.0.champ', 'id');
        }
    }

    // ---------------------------------------------------------------- GET /pages-populaires

    public function test_les_pages_populaires(): void
    {
        [$alice, $bob, $chloe] = Utilisateur::factory()->count(3)->create();
        $voisine = Bibliotheque::factory()->create(['identifiant' => 'voisine']);
        $tresAimee = $this->adresse('babel-w2-s3-v17:204');
        $aimee = $this->adresse('0-w1-s1-v1:1', $voisine);
        $detestee = $this->adresse('0-w1-s1-v1:3');
        $bannie = $this->adresse('0-w1-s1-v1:4');
        $bannie->update(['texte_banni_id' => $this->texteBanni('texte interdit', $this->ici)->id]);
        $livre = $this->adresse('0-w1-s1-v2');

        foreach ([$alice, $bob, $chloe] as $membre) {
            Vote::query()->create(['utilisateur_id' => $membre->id, 'adresse_id' => $tresAimee->id, 'valeur' => 1]);
            Vote::query()->create(['utilisateur_id' => $membre->id, 'adresse_id' => $bannie->id, 'valeur' => 1]);
            Vote::query()->create(['utilisateur_id' => $membre->id, 'adresse_id' => $detestee->id, 'valeur' => -1]);
        }
        MarquePage::query()->create(['utilisateur_id' => $alice->id, 'adresse_id' => $tresAimee->id]);
        MarquePage::query()->create(['utilisateur_id' => $alice->id, 'adresse_id' => $aimee->id]);
        MarquePage::query()->create(['utilisateur_id' => $bob->id, 'adresse_id' => $aimee->id]);
        MarquePage::query()->create(['utilisateur_id' => $bob->id, 'adresse_id' => $detestee->id]);
        MarquePage::query()->create(['utilisateur_id' => $chloe->id, 'adresse_id' => $livre->id]);

        $reponse = $this->getJson('/api/v1/pages-populaires')->assertOk();

        $this->assertConformeAuSchema($reponse, 'ListePagesPopulaires');
        $reponse->assertExactJson(['elements' => [
            ['bibliotheque' => 'ici', 'adresse' => 'babel-w2-s3-v17:204', 'score' => 4],
            ['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:1', 'score' => 2],
        ]]);

        $this->getJson('/api/v1/pages-populaires?taille=1')->assertJsonCount(1, 'elements');
        $this->getJson('/api/v1/pages-populaires?taille=51')->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'taille');
    }

    // ---------------------------------------------------------------- GET /textes-bannis

    public function test_les_textes_bannis_exigent_le_jeton_reseau(): void
    {
        foreach ([[], ['Authorization' => 'Bearer mauvais'], ['Authorization' => 'jeton-du-reseau-pour-les-tests']] as $entetes) {
            $reponse = $this->getJson('/api/v1/textes-bannis', $entetes)
                ->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Bearer')
                ->assertHeader('Cache-Control', 'no-store');

            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.code', 'ERR_INVALID_NETWORK_TOKEN');
        }
    }

    public function test_sans_jeton_configure_les_textes_bannis_restent_fermes(): void
    {
        config(['bibliotheque.jeton_reseau' => null]);

        $this->getJson('/api/v1/textes-bannis', ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_seuls_nos_textes_bannis_appliques_sont_publies(): void
    {
        $voisine = Bibliotheque::factory()->create();
        $this->texteBanni('ou est passe l ete', $this->ici);
        $this->texteBanni('propose ici', $this->ici, 'propose');
        $this->texteBanni('banni puis leve', $this->ici, 'rejete');
        $this->texteBanni('venu d ailleurs', $voisine);

        $reponse = $this->getJson('/api/v1/textes-bannis', ['Authorization' => 'Bearer jeton-du-reseau-pour-les-tests'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store');

        $this->assertConformeAuSchema($reponse, 'ListeTextesBannis');
        $reponse->assertJsonCount(1, 'elements')
            ->assertJsonPath('elements.0.texte', 'ou est passe l ete')
            ->assertJsonPath('elements.0.empreinte', '91faae8c40f78510e024c03c008bb0761800f1e3fbf168ea48e300d4ba299164')
            ->assertJsonPath('elements.0.motif', 'Pour les tests.');
    }
}
