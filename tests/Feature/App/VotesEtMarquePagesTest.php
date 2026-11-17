<?php

namespace Tests\Feature\App;

use App\Bibliotheque\Validation;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\Utilisateur;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Votes (M7) et marque-pages (M2, M3, M13).
 */
class VotesEtMarquePagesTest extends TestCase
{
    use RefreshDatabase;
    use VerifieLeContrat;

    private const PAGE = 'babel-w2-s3-v17:204';

    private Utilisateur $moi;

    /** @var array<string, string> */
    private array $entetes;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bibliotheque::factory()->locale()->create();
        Bibliotheque::factory()->create(['identifiant' => 'voisine']);
        $this->moi = Utilisateur::factory()->create();
        $this->entetes = ['Authorization' => 'Bearer '.$this->moi->createToken('test')->plainTextToken];
    }

    // ---------------------------------------------------------------- votes

    public function test_voter_puis_changer_d_avis(): void
    {
        $autre = Utilisateur::factory()->create();
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', ['valeur' => 1], ['Authorization' => 'Bearer '.$autre->createToken('t')->plainTextToken])->assertOk();
        $this->app['auth']->forgetGuards();

        $reponse = $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', ['valeur' => 1], $this->entetes)->assertOk();
        $this->assertConformeAuSchema($reponse, 'EtatVote');
        $reponse->assertExactJson(['bibliotheque' => 'ici', 'adresse' => self::PAGE, 'mon_vote' => 1, 'score' => 2]);

        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', ['valeur' => -1], $this->entetes)
            ->assertOk()
            ->assertJsonPath('mon_vote', -1)
            ->assertJsonPath('score', 0);

        $this->assertSame(2, Vote::query()->count());
        $this->assertSame(1, Adresse::query()->count());
        $this->getJson('/api/v1/app/pages/'.self::PAGE, $this->entetes)->assertJsonPath('mon_vote', -1);
    }

    public function test_retirer_mon_vote_meme_sans_vote(): void
    {
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', ['valeur' => 1], $this->entetes);

        foreach ([1, 2] as $fois) {
            $reponse = $this->deleteJson('/api/v1/app/pages/'.self::PAGE.'/vote', [], $this->entetes)->assertOk();

            $this->assertConformeAuSchema($reponse, 'EtatVote');
            $reponse->assertJsonPath('mon_vote', null)->assertJsonPath('score', 0);
        }
        $this->assertSame(0, Vote::query()->count());
    }

    public function test_retirer_un_vote_sur_une_page_jamais_designee_ne_cree_rien(): void
    {
        $this->deleteJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', [], $this->entetes)->assertOk();

        $this->assertSame(0, Adresse::query()->count());
    }

    public function test_voter_pour_une_page_d_ailleurs_sans_appel_reseau(): void
    {
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote?bibliotheque=voisine', ['valeur' => 1], $this->entetes)
            ->assertOk()
            ->assertJsonPath('bibliotheque', 'voisine');

        Http::assertNothingSent();
    }

    public function test_les_votes_refuses(): void
    {
        foreach ([[], ['valeur' => 2], ['valeur' => 0], ['valeur' => 'pour'], ['valeur' => '1'], ['valeur' => true]] as $corps) {
            $reponse = $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', $corps, $this->entetes)->assertUnprocessable();
            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.code', 'ERR_INVALID_PARAMETER')->assertJsonPath('erreurs.0.champ', 'valeur');
        }

        $this->putJson('/api/v1/app/pages/babel-w2-s3-v17/vote', ['valeur' => 1], $this->entetes)->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'page');
        $this->putJson('/api/v1/app/pages/0-w1-s1-v33:1/vote', ['valeur' => 1], $this->entetes)->assertNotFound();
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote?bibliotheque=inconnue', ['valeur' => 1], $this->entetes)
            ->assertNotFound()
            ->assertJsonPath('erreurs.0.code', 'ERR_UNKNOWN_LIBRARY');
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote', ['valeur' => 1])->assertUnauthorized();
        $this->assertSame(0, Vote::query()->count());
    }

    public function test_mes_votes_du_plus_recent_au_plus_ancien(): void
    {
        $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1], $this->entetes);
        $this->travel(1)->minutes();
        $this->putJson('/api/v1/app/pages/'.self::PAGE.'/vote?bibliotheque=voisine', ['valeur' => -1], $this->entetes);

        $reponse = $this->getJson('/api/v1/app/moi/votes', $this->entetes)->assertOk();

        $this->assertConformeAuSchema($reponse, 'ListeVotes');
        $reponse->assertJsonPath('total', 2)
            ->assertJsonPath('elements.0.bibliotheque', 'voisine')
            ->assertJsonPath('elements.0.valeur', -1)
            ->assertJsonPath('elements.1.adresse', '0-w1-s1-v1:1');
        $this->getJson('/api/v1/app/moi/votes?taille=1&lot=2', $this->entetes)->assertJsonPath('elements.0.valeur', 1);
    }

    // ---------------------------------------------------------------- marque-pages

    public function test_garder_une_page_en_marque_page(): void
    {
        $reponse = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'titre' => 'À relire'], $this->entetes)
            ->assertCreated();

        $this->assertConformeAuSchema($reponse, 'MarquePage');
        $reponse->assertJsonPath('bibliotheque', 'ici')
            ->assertJsonPath('adresse', self::PAGE)
            ->assertJsonPath('titre', 'À relire')
            ->assertJsonPath('a_convertir', false);

        $this->getJson('/api/v1/app/pages/'.self::PAGE, $this->entetes)
            ->assertJsonPath('marque_page', ['id' => $reponse->json('id'), 'titre' => 'À relire'])
            ->assertJsonPath('score', 1);
    }

    public function test_garder_une_page_d_ailleurs_sans_titre(): void
    {
        $reponse = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'bibliotheque' => 'voisine'], $this->entetes)
            ->assertCreated();

        $this->assertConformeAuSchema($reponse, 'MarquePage');
        $reponse->assertJsonPath('bibliotheque', 'voisine')->assertJsonPath('titre', null);
        Http::assertNothingSent();
    }

    public function test_une_page_ne_se_garde_qu_une_fois(): void
    {
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE], $this->entetes)->assertCreated();

        $reponse = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'titre' => 'Encore'], $this->entetes)
            ->assertConflict();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_ALREADY_EXISTS')->assertJsonPath('erreurs.0.champ', 'adresse');

        // La même adresse dans une autre bibliothèque est une autre page.
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'bibliotheque' => 'voisine'], $this->entetes)->assertCreated();
    }

    public function test_les_marque_pages_refuses(): void
    {
        $this->postJson('/api/v1/app/moi/marque-pages', [], $this->entetes)->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'adresse');
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'titre' => str_repeat('t', 101)], $this->entetes)
            ->assertUnprocessable()
            ->assertJsonPath('erreurs.0.champ', 'titre');
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => 'babel-w2-s3-v17'], $this->entetes)
            ->assertUnprocessable()
            ->assertJsonPath('erreurs.0.code', 'ERR_INVALID_ADDRESS_SYNTAX');
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v33:1'], $this->entetes)->assertNotFound();
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'bibliotheque' => 'inconnue'], $this->entetes)
            ->assertNotFound()
            ->assertJsonPath('erreurs.0.code', 'ERR_UNKNOWN_LIBRARY');
        $this->assertSame(0, MarquePage::query()->count());
    }

    public function test_lister_mes_marque_pages_avec_filtres(): void
    {
        $autre = Utilisateur::factory()->create();
        $garder = fn (string $adresse, ?string $bibliotheque = null) => $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => $adresse, 'bibliotheque' => $bibliotheque], $this->entetes)->json('id');

        $premier = $garder('babel-w2-s3-v17:1');
        $this->travel(1)->minutes();
        $deuxieme = $garder('babel-w2-s3-v17:2');
        $this->travel(1)->minutes();
        $ailleurs = $garder('babel-w2-s3-v17:3', 'voisine');
        $this->travel(1)->minutes();
        $autreLivre = $garder('babel-w2-s3-v18:1');
        MarquePage::query()->create(['utilisateur_id' => $autre->id, 'adresse_id' => Adresse::query()->first()->id]);

        $reponse = $this->getJson('/api/v1/app/moi/marque-pages', $this->entetes)->assertOk();
        $this->assertConformeAuSchema($reponse, 'ListeMarquePages');
        $this->assertSame([$autreLivre, $ailleurs, $deuxieme, $premier], array_column($reponse->json('elements'), 'id'));
        $reponse->assertJsonPath('total', 4);

        $this->assertSame([$ailleurs], array_column($this->getJson('/api/v1/app/moi/marque-pages?bibliotheque=voisine', $this->entetes)->json('elements'), 'id'));
        $this->assertSame([$deuxieme, $premier], array_column($this->getJson('/api/v1/app/moi/marque-pages?livre=babel-w2-s3-v17', $this->entetes)->json('elements'), 'id'));
        $this->assertSame([$ailleurs], array_column($this->getJson('/api/v1/app/moi/marque-pages?livre=babel-w2-s3-v17&bibliotheque=voisine', $this->entetes)->json('elements'), 'id'));
        $this->getJson('/api/v1/app/moi/marque-pages?bibliotheque=inconnue', $this->entetes)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/app/moi/marque-pages?taille=2&lot=2', $this->entetes)->assertJsonPath('elements.0.id', $deuxieme);
    }

    public function test_des_filtres_mal_ecrits_sont_refuses(): void
    {
        foreach (['livre=babel-w2-s3-v17:1' => 'livre', 'livre=ABC-w1-s1-v1' => 'livre', 'bibliotheque=PAS_VALIDE' => 'bibliotheque', 'taille=0' => 'taille'] as $filtre => $champ) {
            $reponse = $this->getJson("/api/v1/app/moi/marque-pages?{$filtre}", $this->entetes)->assertUnprocessable();

            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.champ', $champ);
        }
    }

    public function test_renommer_puis_retirer_un_marque_page(): void
    {
        $id = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE, 'titre' => 'Avant'], $this->entetes)->json('id');

        $reponse = $this->patchJson("/api/v1/app/moi/marque-pages/{$id}", ['titre' => 'Après'], $this->entetes)->assertOk();
        $this->assertConformeAuSchema($reponse, 'MarquePage');
        $reponse->assertJsonPath('titre', 'Après');

        $this->patchJson("/api/v1/app/moi/marque-pages/{$id}", ['titre' => null], $this->entetes)->assertOk()->assertJsonPath('titre', null);
        $this->patchJson("/api/v1/app/moi/marque-pages/{$id}", [], $this->entetes)->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'titre');

        $this->deleteJson("/api/v1/app/moi/marque-pages/{$id}", [], $this->entetes)->assertNoContent();
        $this->deleteJson("/api/v1/app/moi/marque-pages/{$id}", [], $this->entetes)->assertNotFound();
    }

    public function test_le_marque_page_d_un_autre_repond_404(): void
    {
        $autre = Utilisateur::factory()->create();
        $chezLAutre = MarquePage::query()->create([
            'utilisateur_id' => $autre->id,
            'adresse_id' => Adresse::enregistrer(Bibliotheque::locale(), Validation::valider(self::PAGE)->adresse)->id,
        ]);

        foreach ([(string) $chezLAutre->id, '9999', 'abc'] as $id) {
            $reponse = $this->patchJson("/api/v1/app/moi/marque-pages/{$id}", ['titre' => 'Volé'], $this->entetes)->assertNotFound();
            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.code', 'ERR_NOT_FOUND');
            $this->deleteJson("/api/v1/app/moi/marque-pages/{$id}", [], $this->entetes)->assertNotFound();
        }

        $this->assertModelExists($chezLAutre);
    }

    public function test_les_marque_pages_exigent_d_etre_connecte(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/app/moi/marque-pages')->assertUnauthorized();
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => self::PAGE])->assertUnauthorized();
        $this->getJson('/api/v1/app/moi/votes')->assertUnauthorized();
    }
}
