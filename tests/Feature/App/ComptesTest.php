<?php

namespace Tests\Feature\App;

use App\Bibliotheque\Reglages;
use App\Bibliotheque\Validation;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\MarquePage;
use App\Models\Signalement;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\VerifieLeContrat;
use Tests\TestCase;

/**
 * Comptes : inscription, connexion, déconnexion, profil, suppression (V19, M1, M9, M10).
 */
class ComptesTest extends TestCase
{
    use RefreshDatabase;
    use VerifieLeContrat;

    private const INSCRIPTION = ['pseudo' => 'lecteur', 'email' => 'lecteur@exemple.test', 'mot_de_passe' => 'un-long-secret'];

    private function inscrire(array $donnees = []): string
    {
        return $this->postJson('/api/v1/app/auth/inscription', $donnees + self::INSCRIPTION)->assertCreated()->json('jeton');
    }

    /**
     * @return array<string, string>
     */
    private static function avec(string $jeton): array
    {
        return ['Authorization' => "Bearer {$jeton}"];
    }

    // ---------------------------------------------------------------- inscription

    public function test_l_inscription_cree_un_membre_et_ouvre_une_session(): void
    {
        $reponse = $this->postJson('/api/v1/app/auth/inscription', self::INSCRIPTION + ['role' => 'admin'])->assertCreated();

        $this->assertConformeAuSchema($reponse, 'Session');
        $reponse->assertJsonPath('type', 'Bearer')
            ->assertJsonPath('utilisateur.pseudo', 'lecteur')
            ->assertJsonPath('utilisateur.email', 'lecteur@exemple.test')
            ->assertJsonPath('utilisateur.role', 'membre');

        $utilisateur = Utilisateur::query()->sole();
        $this->assertNotSame('un-long-secret', $utilisateur->mot_de_passe_hache);
        $this->assertTrue(Hash::check('un-long-secret', $utilisateur->mot_de_passe_hache));
        $this->assertNotNull($utilisateur->derniere_connexion_le);

        $this->getJson('/api/v1/app/moi', self::avec($reponse->json('jeton')))->assertOk();
    }

    public function test_l_inscription_refuse_les_champs_invalides_d_un_coup(): void
    {
        $reponse = $this->postJson('/api/v1/app/auth/inscription', [
            'pseudo' => str_repeat('p', 31),
            'email' => 'pas-un-email',
            'mot_de_passe' => 'court',
        ])->assertUnprocessable();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $this->assertSame(['pseudo', 'email', 'mot_de_passe'], array_column($reponse->json('erreurs'), 'champ'));
        $reponse->assertJsonPath('erreurs.2.message', 'Le mot de passe doit faire au moins 8 caractères.');
    }

    public function test_le_mot_de_passe_n_est_pas_retaille(): void
    {
        $this->inscrire(['mot_de_passe' => '  espaces  ']);

        $this->postJson('/api/v1/app/auth/connexion', ['email' => self::INSCRIPTION['email'], 'mot_de_passe' => '  espaces  '])->assertOk();
        $this->postJson('/api/v1/app/auth/connexion', ['email' => self::INSCRIPTION['email'], 'mot_de_passe' => 'espaces'])->assertUnauthorized();
    }

    public function test_un_email_ou_un_pseudo_deja_pris_donne_409(): void
    {
        $this->inscrire();

        $reponse = $this->postJson('/api/v1/app/auth/inscription', ['pseudo' => 'autre', 'email' => 'LECTEUR@exemple.test'] + self::INSCRIPTION)
            ->assertConflict();
        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_ALREADY_EXISTS')->assertJsonPath('erreurs.0.champ', 'email');

        $this->postJson('/api/v1/app/auth/inscription', ['email' => 'autre@exemple.test'] + self::INSCRIPTION)
            ->assertConflict()
            ->assertJsonPath('erreurs.0.champ', 'pseudo');
    }

    public function test_inscriptions_fermees(): void
    {
        Reglages::ouvrirLesInscriptions(false);

        $reponse = $this->postJson('/api/v1/app/auth/inscription', self::INSCRIPTION)->assertForbidden();

        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        $reponse->assertJsonPath('erreurs.0.code', 'ERR_REGISTRATION_CLOSED');
        $this->assertSame(0, Utilisateur::query()->count());
    }

    // ---------------------------------------------------------------- connexion et déconnexion

    public function test_la_connexion_ouvre_une_session(): void
    {
        $this->inscrire();

        $reponse = $this->postJson('/api/v1/app/auth/connexion', ['email' => 'Lecteur@Exemple.test', 'mot_de_passe' => 'un-long-secret'])
            ->assertOk();

        $this->assertConformeAuSchema($reponse, 'Session');
        $this->getJson('/api/v1/app/moi', self::avec($reponse->json('jeton')))->assertOk()->assertJsonPath('pseudo', 'lecteur');
    }

    public function test_email_inconnu_ou_mot_de_passe_faux_donnent_la_meme_401(): void
    {
        $this->inscrire();

        foreach ([['email' => 'inconnu@exemple.test', 'mot_de_passe' => 'un-long-secret'], ['email' => 'lecteur@exemple.test', 'mot_de_passe' => 'mauvais-secret']] as $essai) {
            $reponse = $this->postJson('/api/v1/app/auth/connexion', $essai)->assertUnauthorized();

            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
            $reponse->assertExactJson(['erreurs' => [['code' => 'ERR_INVALID_CREDENTIALS', 'champ' => null, 'message' => 'Email ou mot de passe incorrect.']]]);
        }
    }

    public function test_la_connexion_sans_champs_donne_422(): void
    {
        $this->postJson('/api/v1/app/auth/connexion', [])
            ->assertUnprocessable()
            ->assertJsonCount(2, 'erreurs');
    }

    public function test_un_compte_suspendu_ne_peut_ni_se_connecter_ni_utiliser_son_jeton(): void
    {
        $jeton = $this->inscrire();
        Utilisateur::query()->sole()->forceFill(['suspendu_le' => now()])->save();

        $this->postJson('/api/v1/app/auth/connexion', ['email' => 'lecteur@exemple.test', 'mot_de_passe' => 'un-long-secret'])
            ->assertForbidden()
            ->assertJsonPath('erreurs.0.code', 'ERR_ACCOUNT_SUSPENDED');
        $this->getJson('/api/v1/app/moi', self::avec($jeton))->assertForbidden()->assertJsonPath('erreurs.0.code', 'ERR_ACCOUNT_SUSPENDED');
    }

    public function test_la_deconnexion_revoque_seulement_le_jeton_utilise(): void
    {
        $premier = $this->inscrire();
        $second = $this->postJson('/api/v1/app/auth/connexion', ['email' => 'lecteur@exemple.test', 'mot_de_passe' => 'un-long-secret'])->json('jeton');

        $this->postJson('/api/v1/app/auth/deconnexion', [], self::avec($premier))->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/moi', self::avec($premier))->assertUnauthorized()->assertJsonPath('erreurs.0.code', 'ERR_UNAUTHENTICATED');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/moi', self::avec($second))->assertOk();
    }

    public function test_les_routes_de_membre_exigent_un_jeton_valide(): void
    {
        foreach ([[], self::avec('1|faux')] as $entetes) {
            $this->getJson('/api/v1/app/moi', $entetes)->assertUnauthorized();
            $this->patchJson('/api/v1/app/moi', ['pseudo' => 'x'], $entetes)->assertUnauthorized();
            $this->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'x'], $entetes)->assertUnauthorized();
            $reponse = $this->postJson('/api/v1/app/auth/deconnexion', [], $entetes)->assertUnauthorized();
            $this->assertConformeAuSchema($reponse, 'ReponseErreur');
        }
    }

    public function test_les_tentatives_de_connexion_sont_limitees(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/app/auth/connexion', ['email' => 'x@exemple.test', 'mot_de_passe' => 'mauvais'])->assertUnauthorized();
        }

        $reponse = $this->postJson('/api/v1/app/auth/connexion', ['email' => 'x@exemple.test', 'mot_de_passe' => 'mauvais'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
        $this->assertConformeAuSchema($reponse, 'ReponseErreur');
    }

    // ---------------------------------------------------------------- profil

    public function test_mon_profil(): void
    {
        $jeton = $this->inscrire();

        $reponse = $this->getJson('/api/v1/app/moi', self::avec($jeton))->assertOk();

        $this->assertConformeAuSchema($reponse, 'Utilisateur');
        $this->assertIsString($reponse->json('id'));
        $reponse->assertJsonMissingPath('mot_de_passe_hache');
    }

    public function test_changer_de_pseudo_sans_mot_de_passe(): void
    {
        $jeton = $this->inscrire();

        $reponse = $this->patchJson('/api/v1/app/moi', ['pseudo' => 'nouveau'], self::avec($jeton))->assertOk();

        $this->assertConformeAuSchema($reponse, 'Utilisateur');
        $reponse->assertJsonPath('pseudo', 'nouveau')->assertJsonPath('email', 'lecteur@exemple.test');
    }

    public function test_changer_d_email_demande_le_mot_de_passe_actuel(): void
    {
        $jeton = $this->inscrire();

        $this->patchJson('/api/v1/app/moi', ['email' => 'neuf@exemple.test'], self::avec($jeton))
            ->assertUnprocessable()
            ->assertJsonPath('erreurs.0.champ', 'mot_de_passe_actuel');
        $this->patchJson('/api/v1/app/moi', ['email' => 'neuf@exemple.test', 'mot_de_passe_actuel' => 'faux-secret'], self::avec($jeton))
            ->assertUnauthorized()
            ->assertJsonPath('erreurs.0.code', 'ERR_INVALID_CREDENTIALS');
        $this->patchJson('/api/v1/app/moi', ['email' => 'neuf@exemple.test', 'mot_de_passe_actuel' => 'un-long-secret'], self::avec($jeton))
            ->assertOk()
            ->assertJsonPath('email', 'neuf@exemple.test');
    }

    public function test_changer_pour_un_email_ou_un_pseudo_deja_pris_donne_409(): void
    {
        Utilisateur::factory()->create(['pseudo' => 'occupe', 'email' => 'pris@exemple.test']);
        $jeton = $this->inscrire();

        $this->patchJson('/api/v1/app/moi', ['email' => 'pris@exemple.test', 'mot_de_passe_actuel' => 'un-long-secret'], self::avec($jeton))
            ->assertConflict()
            ->assertJsonPath('erreurs.0.champ', 'email');
        $this->patchJson('/api/v1/app/moi', ['pseudo' => 'occupe'], self::avec($jeton))
            ->assertConflict()
            ->assertJsonPath('erreurs.0.champ', 'pseudo');
    }

    public function test_changer_de_mot_de_passe_ferme_les_autres_sessions(): void
    {
        $courant = $this->inscrire();
        $autre = $this->postJson('/api/v1/app/auth/connexion', ['email' => 'lecteur@exemple.test', 'mot_de_passe' => 'un-long-secret'])->json('jeton');

        $this->patchJson('/api/v1/app/moi', ['mot_de_passe' => 'nouveau-secret', 'mot_de_passe_actuel' => 'un-long-secret'], self::avec($courant))
            ->assertOk();

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/moi', self::avec($autre))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/moi', self::avec($courant))->assertOk();
        $this->postJson('/api/v1/app/auth/connexion', ['email' => 'lecteur@exemple.test', 'mot_de_passe' => 'nouveau-secret'])->assertOk();
    }

    public function test_une_modification_vide_ou_invalide_est_refusee(): void
    {
        $jeton = $this->inscrire();

        $this->patchJson('/api/v1/app/moi', [], self::avec($jeton))->assertUnprocessable()->assertJsonPath('erreurs.0.code', 'ERR_INVALID_PARAMETER');
        $this->patchJson('/api/v1/app/moi', ['pseudo' => ''], self::avec($jeton))->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'pseudo');
        $this->patchJson('/api/v1/app/moi', ['mot_de_passe' => 'court', 'mot_de_passe_actuel' => 'un-long-secret'], self::avec($jeton))
            ->assertUnprocessable()
            ->assertJsonPath('erreurs.0.champ', 'mot_de_passe');
    }

    // ---------------------------------------------------------------- suppression

    public function test_supprimer_mon_compte_efface_mes_donnees_mais_garde_mes_signalements(): void
    {
        $jeton = $this->inscrire();
        $moi = Utilisateur::query()->sole();
        $autre = Utilisateur::factory()->create();
        $ici = Bibliotheque::factory()->locale()->create();
        $adresse = Adresse::enregistrer($ici, Validation::valider('0-w1-s1-v1:1')->adresse);

        MarquePage::query()->create(['utilisateur_id' => $moi->id, 'adresse_id' => $adresse->id]);
        Collection::query()->create(['utilisateur_id' => $moi->id, 'nom' => 'À moi']);
        $annotationDeLAutre = Annotation::query()->create(['utilisateur_id' => $autre->id, 'adresse_id' => $adresse->id, 'contenu' => 'Bof', 'est_publique' => true]);
        $monSignalement = Signalement::query()->create(['annotation_id' => $annotationDeLAutre->id, 'auteur_id' => $moi->id, 'motif' => 'Hors sujet', 'statut' => 'en_attente']);

        $this->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'un-long-secret'], self::avec($jeton))->assertNoContent();

        $this->assertModelMissing($moi);
        $this->assertSame(0, MarquePage::query()->count());
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertNull($monSignalement->fresh()->auteur_id);
        $this->assertModelExists($autre);
    }

    public function test_supprimer_mon_compte_demande_le_bon_mot_de_passe(): void
    {
        $jeton = $this->inscrire();

        $this->deleteJson('/api/v1/app/moi', [], self::avec($jeton))->assertUnprocessable()->assertJsonPath('erreurs.0.champ', 'mot_de_passe');
        $this->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'faux-secret'], self::avec($jeton))
            ->assertUnauthorized()
            ->assertJsonPath('erreurs.0.code', 'ERR_INVALID_CREDENTIALS');
        $this->assertSame(1, Utilisateur::query()->count());
    }
}
