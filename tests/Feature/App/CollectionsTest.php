<?php

namespace Tests\Feature\App;

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Signalement;
use App\Models\TexteBanni;
use App\Models\Utilisateur;
use Babel\Moteur\Moteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Les collections du membre (M4, M5, M13).
 */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;
    use VerifieLeContrat;

    private Bibliotheque $ici;

    /** @var array<string, string> */
    private array $entetes;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->ici = Bibliotheque::factory()->locale()->create();
        Bibliotheque::factory()->create(['identifiant' => 'voisine', 'url_actuelle' => 'https://voisine.exemple.test']);
        $moi = Utilisateur::factory()->create();
        $this->entetes = ['Authorization' => 'Bearer '.$moi->createToken('test')->plainTextToken];
    }

    private function creer(array $donnees = ['nom' => 'Ma collection']): string
    {
        return $this->postJson('/api/v1/app/moi/collections', $donnees, $this->entetes)->assertCreated()->json('id');
    }

    private function ajouter(string $id, string $adresse, array $autres = []): TestResponse
    {
        return $this->postJson("/api/v1/app/moi/collections/{$id}/pages", ['adresse' => $adresse] + $autres, $this->entetes);
    }

    /**
     * @return list<string>
     */
    private function adresses(TestResponse $reponse): array
    {
        return array_column($reponse->json('pages'), 'adresse');
    }

    /**
     * Bannit un texte et renvoie l'adresse d'une page d'ici qui le contient.
     */
    private function pageBannie(): string
    {
        TexteBanni::query()->create([
            'texte' => 'texte interdit',
            'empreinte' => hash('sha256', 'texte interdit'),
            'origine_id' => $this->ici->id,
            'statut' => 'applique',
        ]);

        return $this->app->make(Moteur::class)->chercher('texte interdit', 'bruit');
    }

    // ---------------------------------------------------------------- CRUD

    public function test_creer_une_collection_privee_par_defaut(): void
    {
        $reponse = $this->postJson('/api/v1/app/moi/collections', ['nom' => 'Premières pages'], $this->entetes)->assertCreated();

        $this->assertConformeAuSchema($reponse, 'MaCollection');
        $reponse->assertJsonPath('nom', 'Premières pages')
            ->assertJsonPath('description', '')
            ->assertJsonPath('est_publique', false)
            ->assertJsonPath('masquee', false)
            ->assertJsonPath('nombre_pages', 0)
            ->assertJsonPath('pages', []);
    }

    public function test_les_collections_refusees(): void
    {
        foreach ([
            [[], 'nom'],
            [['nom' => ''], 'nom'],
            [['nom' => str_repeat('n', 101)], 'nom'],
            [['nom' => 'ok', 'description' => str_repeat('d', 1001)], 'description'],
            [['nom' => 'ok', 'est_publique' => 'oui'], 'est_publique'],
            [['nom' => 'ok', 'est_publique' => 1], 'est_publique'],
        ] as [$corps, $champ]) {
            $reponse = $this->postJson('/api/v1/app/moi/collections', $corps, $this->entetes)->assertUnprocessable();
            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertJsonPath('erreurs.0.champ', $champ);
        }

        $this->assertSame(0, Collection::query()->count());
    }

    public function test_lister_mes_collections_de_la_plus_recemment_modifiee(): void
    {
        $ancienne = $this->creer(['nom' => 'Ancienne', 'est_publique' => true]);
        $this->travel(1)->minutes();
        $recente = $this->creer(['nom' => 'Récente', 'description' => 'Privée']);
        $this->travel(1)->minutes();
        $this->ajouter($ancienne, '0-w1-s1-v1:1');

        $reponse = $this->getJson('/api/v1/app/moi/collections', $this->entetes)->assertOk();

        $this->assertConformeAuSchema($reponse, 'ListeMesCollections');
        $this->assertSame([$ancienne, $recente], array_column($reponse->json('elements'), 'id'));
        $reponse->assertJsonPath('total', 2)->assertJsonPath('elements.0.nombre_pages', 1);
    }

    public function test_renommer_publier_et_supprimer(): void
    {
        $id = $this->creer();

        $reponse = $this->patchJson("/api/v1/app/moi/collections/{$id}", ['nom' => 'Nouveau nom', 'est_publique' => true], $this->entetes)->assertOk();
        $this->assertConformeAuSchema($reponse, 'MaCollection');
        $reponse->assertJsonPath('nom', 'Nouveau nom')->assertJsonPath('est_publique', true)->assertJsonPath('description', '');

        $this->patchJson("/api/v1/app/moi/collections/{$id}", ['description' => ''], $this->entetes)->assertOk()->assertJsonPath('description', '');
        $this->patchJson("/api/v1/app/moi/collections/{$id}", [], $this->entetes)->assertUnprocessable();
        $this->patchJson("/api/v1/app/moi/collections/{$id}", ['nom' => ''], $this->entetes)->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'nom');

        $this->getJson('/api/v1/collections/'.$id)->assertOk();

        $this->deleteJson("/api/v1/app/moi/collections/{$id}", [], $this->entetes)->assertNoContent();
        $this->getJson("/api/v1/app/moi/collections/{$id}", $this->entetes)->assertNotFound();
    }

    public function test_supprimer_une_collection_emporte_ses_liens_courts_et_signalements(): void
    {
        $id = $this->creer();
        $this->ajouter($id, '0-w1-s1-v1:1');
        LienCourt::query()->create(['slug' => 'abcd1234', 'collection_id' => $id]);
        Signalement::query()->create(['collection_id' => $id, 'motif' => 'Test', 'statut' => 'en_attente']);

        $this->deleteJson("/api/v1/app/moi/collections/{$id}", [], $this->entetes)->assertNoContent();

        $this->assertSame(0, LienCourt::query()->count());
        $this->assertSame(0, Signalement::query()->count());
        $this->assertSame(1, Adresse::query()->count());
    }

    public function test_une_collection_masquee_reste_visible_par_son_auteur(): void
    {
        $id = $this->creer(['nom' => 'Masquée', 'est_publique' => true]);
        Collection::query()->whereKey($id)->update(['masque_le' => now()]);

        $this->getJson("/api/v1/app/moi/collections/{$id}", $this->entetes)->assertOk()->assertJsonPath('masquee', true);
        $this->getJson('/api/v1/collections/'.$id)->assertNotFound();
    }

    public function test_la_collection_d_un_autre_repond_404(): void
    {
        $autre = Utilisateur::factory()->create();
        $sienne = Collection::query()->create(['utilisateur_id' => $autre->id, 'nom' => 'À un autre', 'est_publique' => true]);

        foreach ([(string) $sienne->id, '9999', 'abc'] as $id) {
            $reponse = $this->getJson("/api/v1/app/moi/collections/{$id}", $this->entetes)->assertNotFound();
            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $this->patchJson("/api/v1/app/moi/collections/{$id}", ['nom' => 'Volée'], $this->entetes)->assertNotFound();
            $this->deleteJson("/api/v1/app/moi/collections/{$id}", [], $this->entetes)->assertNotFound();
            $this->ajouter($id, '0-w1-s1-v1:1')->assertNotFound();
        }

        $this->assertSame('À un autre', $sienne->fresh()->nom);
    }

    // ---------------------------------------------------------------- pages

    public function test_ajouter_des_pages_a_la_fin_ou_a_une_position(): void
    {
        $id = $this->creer();

        $this->ajouter($id, '0-w1-s1-v1:1')->assertOk();
        $this->ajouter($id, '0-w1-s1-v1:3')->assertOk();
        $reponse = $this->ajouter($id, '0-w1-s1-v1:2', ['position' => 2])->assertOk();
        $this->ajouter($id, '0-w1-s1-v1:1', ['bibliotheque' => 'voisine', 'position' => 1])->assertOk();
        $reponse = $this->ajouter($id, '0-w1-s1-v1:1')->assertOk();

        $this->assertConformeAuSchema($reponse, 'MaCollection');
        $this->assertSame(['0-w1-s1-v1:1', '0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3', '0-w1-s1-v1:1'], $this->adresses($reponse));
        $this->assertSame([1, 2, 3, 4, 5], array_column($reponse->json('pages'), 'position'));
        $this->assertSame(['voisine', 'ici', 'ici', 'ici', 'ici'], array_column($reponse->json('pages'), 'bibliotheque'));
        $reponse->assertJsonPath('nombre_pages', 5);
        $this->assertSame(4, Adresse::query()->count());
        Http::assertNothingSent();
    }

    public function test_les_pages_refusees(): void
    {
        $id = $this->creer();
        $this->ajouter($id, '0-w1-s1-v1:1');

        $this->ajouter($id, '0-w1-s1-v1:2', ['position' => 3])->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'position');
        $this->ajouter($id, '0-w1-s1-v1:2', ['position' => 0])->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'position');
        $this->ajouter($id, '0-w1-s1-v1:2', ['position' => '2'])->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'position');
        $this->ajouter($id, '0-w1-s1-v1')->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'page');
        $this->ajouter($id, '0-w1-s1-v33:1')->assertNotFound()->assertJsonPath('erreurs.0.code', 'ERR_OUT_OF_BOUNDS');
        $this->ajouter($id, '0-w1-s1-v1:2', ['bibliotheque' => 'inconnue'])->assertNotFound()->assertJsonPath('erreurs.0.code', 'ERR_UNKNOWN_LIBRARY');

        $this->getJson("/api/v1/app/moi/collections/{$id}", $this->entetes)->assertJsonPath('nombre_pages', 1);
    }

    public function test_retirer_une_page_fait_remonter_les_suivantes(): void
    {
        $id = $this->creer();
        foreach (['0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3'] as $adresse) {
            $this->ajouter($id, $adresse);
        }

        $reponse = $this->deleteJson("/api/v1/app/moi/collections/{$id}/pages/2", [], $this->entetes)->assertOk();

        $this->assertConformeAuSchema($reponse, 'MaCollection');
        $this->assertSame(['0-w1-s1-v1:1', '0-w1-s1-v1:3'], $this->adresses($reponse));
        $this->assertSame([1, 2], array_column($reponse->json('pages'), 'position'));

        foreach (['3', '0', 'abc'] as $position) {
            $this->deleteJson("/api/v1/app/moi/collections/{$id}/pages/{$position}", [], $this->entetes)
                ->assertNotFound()
                ->assertJsonPath('erreurs.0.champ', 'position');
        }
    }

    public function test_reordonner_les_pages(): void
    {
        $id = $this->creer();
        foreach (['0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3'] as $adresse) {
            $this->ajouter($id, $adresse);
        }

        $reponse = $this->putJson("/api/v1/app/moi/collections/{$id}/pages", ['pages' => [
            ['adresse' => '0-w1-s1-v1:3'],
            ['adresse' => '0-w1-s1-v1:1', 'bibliotheque' => 'ici'],
            ['adresse' => '0-w1-s1-v1:2'],
        ]], $this->entetes)->assertOk();

        $this->assertConformeAuSchema($reponse, 'MaCollection');
        $this->assertSame(['0-w1-s1-v1:3', '0-w1-s1-v1:1', '0-w1-s1-v1:2'], $this->adresses($reponse));

        $this->putJson("/api/v1/app/moi/collections/{$id}/pages", ['pages' => []], $this->entetes)->assertOk()->assertJsonPath('pages', []);
    }

    public function test_reordonner_renvoie_toutes_les_erreurs_d_adresse(): void
    {
        $id = $this->creer();
        $this->ajouter($id, '0-w1-s1-v1:1');

        $reponse = $this->putJson("/api/v1/app/moi/collections/{$id}/pages", ['pages' => [
            ['adresse' => '0-w1-s1-v1:1'],
            ['adresse' => '0-w9-s1-v1:1'],
            ['adresse' => 'ABC-w1-s1-v1:1'],
        ]], $this->entetes)->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $this->assertSame(['pages.1.mur', 'pages.2.hexagone'], array_column($reponse->json('erreurs'), 'champ'));

        $this->putJson("/api/v1/app/moi/collections/{$id}/pages", [], $this->entetes)->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'pages');
        $this->putJson("/api/v1/app/moi/collections/{$id}/pages", ['pages' => [['bibliotheque' => 'ici']]], $this->entetes)->assertUnprocessable();
        $this->getJson("/api/v1/app/moi/collections/{$id}", $this->entetes)->assertJsonPath('nombre_pages', 1);
    }

    // ---------------------------------------------------------------- textes bannis

    public function test_une_page_bannie_rangee_dans_une_collection_publique_est_masquee_au_public(): void
    {
        $bannie = $this->pageBannie();
        $id = $this->creer(['nom' => 'Publique', 'est_publique' => true]);
        $this->ajouter($id, '0-w1-s1-v1:1');

        $reponse = $this->ajouter($id, $bannie)->assertOk();

        $reponse->assertJsonPath('pages.1.masquee', true)->assertJsonPath('pages.0.masquee', false)->assertJsonPath('nombre_pages', 2);
        $this->getJson('/api/v1/collections/'.$id)->assertOk()->assertJsonPath('nombre_pages', 1)->assertJsonCount(1, 'pages');
    }

    public function test_une_page_bannie_dans_une_collection_privee_n_est_verifiee_qu_a_la_publication(): void
    {
        $bannie = $this->pageBannie();
        $id = $this->creer();

        $this->ajouter($id, $bannie)->assertOk()->assertJsonPath('pages.0.masquee', false);

        $this->patchJson("/api/v1/app/moi/collections/{$id}", ['est_publique' => true], $this->entetes)
            ->assertOk()
            ->assertJsonPath('pages.0.masquee', true);
    }

    public function test_une_page_d_ailleurs_est_lue_chez_elle_pour_la_verification(): void
    {
        $this->pageBannie();
        Http::fake(['voisine.exemple.test/*' => Http::response([
            'bibliotheque' => 'voisine',
            'adresse' => '0-w1-s1-v1:1',
            'texte' => str_pad('avant texte interdit apres', 3200),
        ])]);
        $id = $this->creer(['nom' => 'Publique', 'est_publique' => true]);

        $this->ajouter($id, '0-w1-s1-v1:1', ['bibliotheque' => 'voisine'])->assertOk()->assertJsonPath('pages.0.masquee', true);
    }

    public function test_un_pair_injoignable_n_empeche_pas_d_ajouter_la_page(): void
    {
        $this->pageBannie();
        Http::fake(['voisine.exemple.test/*' => fn () => throw new ConnectionException('Délai dépassé')]);
        $id = $this->creer(['nom' => 'Publique', 'est_publique' => true]);

        $this->ajouter($id, '0-w1-s1-v1:1', ['bibliotheque' => 'voisine'])->assertOk()->assertJsonPath('pages.0.masquee', false);
    }

    public function test_les_collections_exigent_d_etre_connecte(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/app/moi/collections')->assertUnauthorized();
        $this->postJson('/api/v1/app/moi/collections', ['nom' => 'x'])->assertUnauthorized();
    }
}
