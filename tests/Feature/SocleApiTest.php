<?php

namespace Tests\Feature;

use App\Exceptions\ErreurApi;
use Babel\Moteur\Moteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le socle commun à toutes les routes : forme des erreurs, statuts, middlewares, CORS.
 */
class SocleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/test')->middleware('api')->group(function () {
            Route::post('/echo', fn (Request $request) => [
                'texte' => $request->input('texte'),
                'description' => $request->input('description'),
            ]);
            Route::post('/validation', function (Request $request) {
                $request->validate([
                    'email' => ['required', 'email'],
                    'mot_de_passe' => ['required', 'min:8'],
                ]);

                return ['ok' => true];
            });
            Route::get('/lire/{adresse}', fn (string $adresse, Moteur $moteur) => ['texte' => $moteur->lire($adresse)]);
            Route::get('/introuvable', fn () => throw ErreurApi::introuvable('id'));
            Route::get('/membre', fn () => ['ok' => true])->middleware('auth:sanctum');
            Route::get('/quota', fn () => ['ok' => true])->middleware('throttle:reseau');
        });
    }

    private function assertFormeErreur(array $json): void
    {
        $this->assertSame(['erreurs'], array_keys($json));
        $this->assertNotEmpty($json['erreurs']);
        foreach ($json['erreurs'] as $erreur) {
            $this->assertSame(['code', 'champ', 'message'], array_keys($erreur));
            $this->assertStringStartsWith('ERR_', $erreur['code']);
            $this->assertNotSame('', $erreur['message']);
        }
    }

    public function test_une_route_inconnue_repond_en_json_au_format_du_contrat(): void
    {
        $reponse = $this->get('/api/v1/nexiste-pas')->assertNotFound();

        $this->assertFormeErreur($reponse->json());
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_NOT_FOUND');
    }

    public function test_une_mauvaise_methode_repond_en_json(): void
    {
        $reponse = $this->get('/api/v1/test/echo')->assertStatus(405);

        $this->assertFormeErreur($reponse->json());
    }

    public function test_une_erreur_api_garde_son_code_et_son_champ(): void
    {
        $this->getJson('/api/v1/test/introuvable')
            ->assertNotFound()
            ->assertExactJson(['erreurs' => [['code' => 'ERR_NOT_FOUND', 'champ' => 'id', 'message' => 'Cette ressource n\'existe pas.']]]);
    }

    public function test_la_validation_renvoie_une_erreur_par_champ(): void
    {
        $reponse = $this->postJson('/api/v1/test/validation', ['email' => 'pas-un-email', 'mot_de_passe' => 'court'])
            ->assertUnprocessable();

        $this->assertFormeErreur($reponse->json());
        $this->assertSame(['email', 'mot_de_passe'], array_column($reponse->json('erreurs'), 'champ'));
        $this->assertSame(['ERR_INVALID_PARAMETER'], array_unique(array_column($reponse->json('erreurs'), 'code')));
    }

    public function test_une_adresse_mal_ecrite_donne_422_avec_toutes_ses_erreurs(): void
    {
        $reponse = $this->getJson('/api/v1/test/lire/ABC-w9-s1-v1:1')->assertUnprocessable();

        $this->assertFormeErreur($reponse->json());
        $this->assertSame(['ERR_INVALID_HEX_CHARS', 'ERR_OUT_OF_BOUNDS'], array_column($reponse->json('erreurs'), 'code'));
    }

    public function test_une_adresse_seulement_hors_limites_donne_404(): void
    {
        $this->getJson('/api/v1/test/lire/0-w1-s1-v33:1')
            ->assertNotFound()
            ->assertJsonPath('erreurs.0.code', 'ERR_OUT_OF_BOUNDS')
            ->assertJsonPath('erreurs.0.champ', 'volume');
    }

    public function test_une_adresse_valide_se_lit(): void
    {
        $texte = $this->getJson('/api/v1/test/lire/0-w1-s1-v1:1')->assertOk()->json('texte');

        $this->assertSame(3200, strlen($texte));
    }

    public function test_les_espaces_de_bord_du_texte_ne_sont_pas_retirees(): void
    {
        $this->postJson('/api/v1/test/echo', ['texte' => '  deux espaces  '])
            ->assertJsonPath('texte', '  deux espaces  ');
    }

    public function test_une_chaine_vide_reste_vide_sur_l_api(): void
    {
        $this->postJson('/api/v1/test/echo', ['texte' => '', 'description' => ''])
            ->assertExactJson(['texte' => '', 'description' => '']);
    }

    public function test_une_route_de_membre_sans_jeton_donne_401(): void
    {
        $this->getJson('/api/v1/test/membre')
            ->assertUnauthorized()
            ->assertJsonPath('erreurs.0.code', 'ERR_UNAUTHENTICATED');
    }

    public function test_une_route_de_membre_sans_accept_json_donne_aussi_401_en_json(): void
    {
        $this->get('/api/v1/test/membre')
            ->assertUnauthorized()
            ->assertJsonPath('erreurs.0.code', 'ERR_UNAUTHENTICATED');
    }

    public function test_le_quota_accepte_60_requetes_puis_repond_429_au_format_du_contrat(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/test/quota')->assertOk();
        }

        $reponse = $this->getJson('/api/v1/test/quota')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertFormeErreur($reponse->json());
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_TOO_MANY_REQUESTS');
        $this->assertGreaterThanOrEqual(1, (int) $reponse->headers->get('Retry-After'));
    }

    public function test_cors_autorise_l_application_angular(): void
    {
        $this->options('/api/v1/test/echo', [], [
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_cors_n_autorise_pas_une_autre_origine(): void
    {
        $origine = $this->options('/api/v1/test/echo', [], [
            'Origin' => 'http://exemple.test',
            'Access-Control-Request-Method' => 'POST',
        ])->headers->get('Access-Control-Allow-Origin');

        $this->assertNotContains($origine, ['http://exemple.test', '*']);
    }
}
