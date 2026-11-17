<?php

namespace Tests\Feature\App;

use App\Bibliotheque\Adresse as AdresseValide;
use App\Bibliotheque\Reglages;
use App\Bibliotheque\Validation;
use App\Models\ActiviteJour;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\TexteBanni;
use App\Models\Utilisateur;
use App\Models\Vote;
use Babel\Moteur\Moteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Lecture par l'application web : /app/infos, /app/hasard, /app/pages/{adresse}.
 */
class LectureTest extends TestCase
{
    use RefreshDatabase;
    use VerifieLeContrat;

    private const PAGE = 'babel-w2-s3-v17:204';

    private Bibliotheque $ici;

    private Bibliotheque $voisine;

    private Moteur $moteur;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->ici = Bibliotheque::factory()->locale()->create(['description' => 'Une bibliothèque de test.']);
        $this->voisine = Bibliotheque::factory()->create(['identifiant' => 'voisine', 'url_actuelle' => 'https://voisine.exemple.test/']);
        $this->moteur = $this->app->make(Moteur::class);
    }

    private function adresse(string $ecriture, ?Bibliotheque $bibliotheque = null): Adresse
    {
        return Adresse::enregistrer($bibliotheque ?? $this->ici, Validation::valider($ecriture)->adresse);
    }

    /**
     * @return array<string, string>
     */
    private static function avec(Utilisateur $membre): array
    {
        return ['Authorization' => 'Bearer '.$membre->createToken('test')->plainTextToken];
    }

    private function annoter(Utilisateur $auteur, Adresse $adresse, string $contenu, bool $publique, bool $masquee = false, int $ilYA = 0): Annotation
    {
        $annotation = Annotation::query()->create([
            'utilisateur_id' => $auteur->id,
            'adresse_id' => $adresse->id,
            'contenu' => $contenu,
            'est_publique' => $publique,
            'masque_le' => $masquee ? now() : null,
        ]);
        $annotation->forceFill(['cree_le' => now()->subMinutes($ilYA)])->save();

        return $annotation;
    }

    // ---------------------------------------------------------------- GET /app/infos

    public function test_les_infos_de_la_bibliotheque(): void
    {
        $reponse = $this->getJson('/api/v1/app/infos')->assertOk();

        $this->assertConformeAuSchema($reponse, 'Infos');
        $reponse->assertJsonPath('identifiant', 'ici')
            ->assertJsonPath('description', 'Une bibliothèque de test.')
            ->assertJsonPath('empreinte_cle', $this->moteur->empreinte())
            ->assertJsonPath('inscriptions_ouvertes', true)
            ->assertJsonPath('constantes.dernier_hexagone', gmp_strval(AdresseValide::dernierHexagone(), 36))
            ->assertJsonPath('limites.annotation_max', 2000)
            ->assertJsonPath('reseau.delai_pair_ms', 2000)
            ->assertJsonPath('temps_reel', null);
    }

    public function test_les_infos_suivent_les_reglages(): void
    {
        Reglages::ouvrirLesInscriptions(false);
        config(['bibliotheque.temps_reel' => ['cle' => 'cle-publique', 'hote' => 'babel.exemple.test', 'port' => 443, 'tls' => true]]);

        $reponse = $this->getJson('/api/v1/app/infos')->assertOk();

        $this->assertConformeAuSchema($reponse, 'Infos');
        $reponse->assertJsonPath('inscriptions_ouvertes', false)
            ->assertJsonPath('temps_reel', ['protocole' => 'pusher', 'cle' => 'cle-publique', 'hote' => 'babel.exemple.test', 'port' => 443, 'tls' => true]);
    }

    // ---------------------------------------------------------------- GET /app/hasard

    public function test_une_page_au_hasard(): void
    {
        $reponse = $this->getJson('/api/v1/app/hasard')->assertOk();

        $this->assertConformeAuSchema($reponse, 'PageDesignee');
        $reponse->assertJsonPath('bibliotheque', 'ici');
        $this->assertSame('page', Validation::valider($reponse->json('adresse'))->niveau());
        $this->assertNotSame($reponse->json('adresse'), $this->getJson('/api/v1/app/hasard')->json('adresse'));
    }

    // ---------------------------------------------------------------- GET /app/pages/{adresse}

    public function test_un_visiteur_lit_une_page_jamais_enregistree(): void
    {
        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE)->assertOk();

        $this->assertConformeAuSchema($reponse, 'PageLue');
        $reponse->assertExactJson([
            'bibliotheque' => 'ici',
            'adresse' => self::PAGE,
            'adresse_livre' => 'babel-w2-s3-v17',
            'texte' => $this->moteur->lire(self::PAGE),
            'score' => 0,
            'mon_vote' => null,
            'marque_page' => null,
            'annotations' => [],
        ]);
    }

    public function test_lire_une_page_compte_une_page_lue(): void
    {
        $this->getJson('/api/v1/app/pages/'.self::PAGE)->assertOk();
        $this->getJson('/api/v1/app/pages/'.self::PAGE)->assertOk();

        $this->assertSame(2, ActiviteJour::query()->sole()->pages_lues);
    }

    public function test_un_membre_voit_son_vote_son_marque_page_et_ses_annotations(): void
    {
        [$moi, $autre] = Utilisateur::factory()->count(2)->create();
        $page = $this->adresse(self::PAGE);
        Vote::query()->create(['utilisateur_id' => $moi->id, 'adresse_id' => $page->id, 'valeur' => -1]);
        Vote::query()->create(['utilisateur_id' => $autre->id, 'adresse_id' => $page->id, 'valeur' => 1]);
        $marquePage = MarquePage::query()->create(['utilisateur_id' => $moi->id, 'adresse_id' => $page->id, 'titre' => 'À relire']);
        MarquePage::query()->create(['utilisateur_id' => $autre->id, 'adresse_id' => $page->id]);

        $publiqueDeLAutre = $this->annoter($autre, $page, 'Publique, d\'un autre', true, ilYA: 30);
        $this->annoter($autre, $page, 'Privée, d\'un autre', false, ilYA: 20);
        $this->annoter($autre, $page, 'Masquée, d\'un autre', true, masquee: true, ilYA: 10);
        $priveeAMoi = $this->annoter($moi, $page, 'Privée, à moi', false, ilYA: 5);
        $masqueeAMoi = $this->annoter($moi, $page, 'Masquée, à moi', true, masquee: true, ilYA: 1);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE, self::avec($moi))->assertOk();

        $this->assertConformeAuSchema($reponse, 'PageLue');
        $reponse->assertJsonPath('score', 2)
            ->assertJsonPath('mon_vote', -1)
            ->assertJsonPath('marque_page', ['id' => (string) $marquePage->id, 'titre' => 'À relire']);
        $this->assertSame(
            [(string) $masqueeAMoi->id, (string) $priveeAMoi->id, (string) $publiqueDeLAutre->id],
            array_column($reponse->json('annotations'), 'id'),
        );
        $reponse->assertJsonPath('annotations.0.masquee', true)
            ->assertJsonPath('annotations.0.est_a_moi', true)
            ->assertJsonPath('annotations.2.est_a_moi', false)
            ->assertJsonPath('annotations.2.auteur', ['pseudo' => $autre->pseudo]);
    }

    public function test_un_visiteur_ne_voit_que_les_annotations_publiques_non_masquees(): void
    {
        $auteur = Utilisateur::factory()->create();
        $page = $this->adresse(self::PAGE);
        $publique = $this->annoter($auteur, $page, 'Publique', true);
        $this->annoter($auteur, $page, 'Privée', false);
        $this->annoter($auteur, $page, 'Masquée', true, masquee: true);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE)->assertOk();

        $this->assertConformeAuSchema($reponse, 'PageLue');
        $this->assertSame([(string) $publique->id], array_column($reponse->json('annotations'), 'id'));
        $reponse->assertJsonPath('annotations.0.est_a_moi', false)->assertJsonPath('mon_vote', null);
    }

    public function test_sur_une_page_bannie_seul_l_auteur_voit_son_annotation(): void
    {
        [$moi, $autre] = Utilisateur::factory()->count(2)->create();
        $page = $this->adresse(self::PAGE);
        $banni = TexteBanni::query()->create(['texte' => 'texte interdit', 'empreinte' => hash('sha256', 'texte interdit'), 'origine_id' => $this->ici->id, 'statut' => 'applique']);
        $page->update(['texte_banni_id' => $banni->id]);
        $this->annoter($autre, $page, 'Publique, d\'un autre', true);
        $mienne = $this->annoter($moi, $page, 'Publique, à moi', true);

        $this->getJson('/api/v1/app/pages/'.self::PAGE)->assertOk()->assertJsonPath('annotations', []);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE, self::avec($moi))->assertOk();
        $this->assertSame([(string) $mienne->id], array_column($reponse->json('annotations'), 'id'));
        $reponse->assertJsonPath('annotations.0.masquee', true);
    }

    public function test_un_jeton_invalide_ou_suspendu_lit_comme_un_visiteur(): void
    {
        $suspendu = Utilisateur::factory()->suspendu()->create();
        Vote::query()->create(['utilisateur_id' => $suspendu->id, 'adresse_id' => $this->adresse(self::PAGE)->id, 'valeur' => 1]);

        $this->getJson('/api/v1/app/pages/'.self::PAGE, ['Authorization' => 'Bearer 1|faux'])->assertOk()->assertJsonPath('mon_vote', null);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/pages/'.self::PAGE, self::avec($suspendu))->assertOk()->assertJsonPath('mon_vote', null);
    }

    public function test_les_adresses_refusees(): void
    {
        $this->getJson('/api/v1/app/pages/babel-w2-s3-v17')->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'page');
        $this->getJson('/api/v1/app/pages/0-w1-s1-v33:1')->assertNotFound()->assertJsonPath('erreurs.0.code', 'ERR_OUT_OF_BOUNDS');

        // L'adresse est contrôlée avant la bibliothèque, donc avant tout appel réseau.
        $reponse = $this->getJson('/api/v1/app/pages/ABC-w1-s1-v1:1?bibliotheque=inconnue')->assertUnprocessable();
        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
    }

    public function test_une_bibliotheque_inconnue_donne_404(): void
    {
        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=inconnue')->assertNotFound();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_UNKNOWN_LIBRARY')->assertJsonPath('erreurs.0.champ', 'bibliotheque');
    }

    public function test_notre_identifiant_designe_la_bibliotheque_locale(): void
    {
        $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=ici')
            ->assertOk()
            ->assertJsonPath('texte', $this->moteur->lire(self::PAGE));
    }

    // ---------------------------------------------------------------- une page d'ailleurs

    private static function pageDuPair(array $remplacements = []): array
    {
        return $remplacements + ['bibliotheque' => 'voisine', 'adresse' => self::PAGE, 'texte' => str_repeat('voisin. ', 400)];
    }

    public function test_une_page_d_ailleurs_est_lue_chez_elle_avec_les_annotations_de_nos_membres(): void
    {
        Http::fake(['voisine.exemple.test/api/v1/pages/*' => Http::response(self::pageDuPair())]);
        $this->voisine->update(['echecs_consecutifs' => 2, 'etat' => 'inconnu']);
        $membre = Utilisateur::factory()->create();
        $publique = $this->annoter($membre, $this->adresse(self::PAGE, $this->voisine), 'Lue chez la voisine', true);
        $this->annoter($membre, $this->adresse(self::PAGE), 'Lue ici', true);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=voisine')->assertOk();

        $this->assertConformeAuSchema($reponse, 'PageLue');
        $reponse->assertJsonPath('bibliotheque', 'voisine')
            ->assertJsonPath('texte', str_repeat('voisin. ', 400))
            ->assertJsonPath('annotations.0.id', (string) $publique->id)
            ->assertJsonPath('annotations.0.bibliotheque', 'voisine')
            ->assertJsonCount(1, 'annotations');
        Http::assertSent(fn ($requete) => $requete->url() === 'https://voisine.exemple.test/api/v1/pages/'.self::PAGE);
        $this->assertSame(0, $this->voisine->fresh()->echecs_consecutifs);
        $this->assertSame('en_ligne', $this->voisine->fresh()->etat);
    }

    /**
     * @return array<string, array{\Closure}>
     */
    public static function pairsEnEchec(): array
    {
        return [
            'injoignable' => [fn () => throw new ConnectionException('Délai dépassé')],
            'erreur 500' => [fn () => Http::response('Oups', 500)],
            'page trop courte' => [fn () => Http::response(self::pageDuPair(['texte' => 'abc']))],
            'autre adresse' => [fn () => Http::response(self::pageDuPair(['adresse' => '0-w1-s1-v1:1']))],
            'champ en trop' => [fn () => Http::response(self::pageDuPair(['en_trop' => 1]))],
            'mauvaise bibliotheque' => [fn () => Http::response(self::pageDuPair(['bibliotheque' => 'ici']))],
            'pas du json' => [fn () => Http::response('<html></html>', 200)],
        ];
    }

    /**
     * @dataProvider pairsEnEchec
     */
    #[DataProvider('pairsEnEchec')]
    public function test_un_pair_injoignable_ou_hors_contrat_donne_502_et_compte_un_echec(\Closure $reponseDuPair): void
    {
        Http::fake(['voisine.exemple.test/*' => $reponseDuPair]);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=voisine')->assertStatus(502);

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_PEER_UNREACHABLE')->assertJsonPath('erreurs.0.champ', 'bibliotheque');
        $this->assertSame(1, $this->voisine->fresh()->echecs_consecutifs);
    }

    public function test_un_pair_passe_hors_ligne_au_troisieme_echec_de_suite(): void
    {
        Http::fake(['voisine.exemple.test/*' => Http::response('Oups', 500)]);

        foreach (['en_ligne', 'en_ligne', 'hors_ligne'] as $etat) {
            $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=voisine')->assertStatus(502);
            $this->assertSame($etat, $this->voisine->fresh()->etat);
        }
    }

    public function test_un_pair_occupe_donne_503_sans_compter_d_echec(): void
    {
        Http::fake(['voisine.exemple.test/*' => Http::response(['erreurs' => []], 429, ['Retry-After' => '12'])]);

        $reponse = $this->getJson('/api/v1/app/pages/'.self::PAGE.'?bibliotheque=voisine')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '12');

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_PEER_BUSY');
        $this->assertSame(0, $this->voisine->fresh()->echecs_consecutifs);
    }
}
