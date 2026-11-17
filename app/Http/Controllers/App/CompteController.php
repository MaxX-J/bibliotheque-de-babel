<?php

namespace App\Http\Controllers\App;

use App\Bibliotheque\Reglages;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Les comptes : inscription, connexion, profil, suppression (V19, M1, M9, M10).
 *
 * Un compte est local à cette bibliothèque. La session est un jeton d'API Sanctum.
 */
class CompteController extends Controller
{
    private const MESSAGES = [
        'pseudo.required' => 'Le pseudo est obligatoire.',
        'pseudo.string' => 'Le pseudo doit être une chaîne de caractères.',
        'pseudo.min' => 'Le pseudo ne peut pas être vide.',
        'pseudo.filled' => 'Le pseudo ne peut pas être vide.',
        'pseudo.max' => 'Le pseudo fait au plus 30 caractères.',
        'email.required' => 'L\'email est obligatoire.',
        'email.string' => 'L\'email doit être une chaîne de caractères.',
        'email.email' => 'L\'email n\'est pas valide.',
        'email.filled' => 'L\'email ne peut pas être vide.',
        'email.max' => 'L\'email fait au plus 255 caractères.',
        'mot_de_passe.required' => 'Le mot de passe est obligatoire.',
        'mot_de_passe.string' => 'Le mot de passe doit être une chaîne de caractères.',
        'mot_de_passe.filled' => 'Le mot de passe ne peut pas être vide.',
        'mot_de_passe.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        'mot_de_passe_actuel.required_with' => 'Le mot de passe actuel est obligatoire pour changer l\'email ou le mot de passe.',
        'mot_de_passe_actuel.string' => 'Le mot de passe actuel doit être une chaîne de caractères.',
    ];

    /**
     * POST /app/auth/inscription : crée un compte de membre et ouvre une session (V19).
     */
    public function inscription(Request $request): JsonResponse
    {
        if (! Reglages::inscriptionsOuvertes()) {
            throw ErreurApi::une('ERR_REGISTRATION_CLOSED', null, 'Les inscriptions sont fermées.', 403);
        }

        $donnees = $request->validate([
            'pseudo' => ['required', 'string', 'min:1', 'max:30'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mot_de_passe' => ['required', 'string', 'min:8'],
        ], self::MESSAGES);

        $email = Str::lower($donnees['email']);
        self::exigerPseudoLibre($donnees['pseudo']);
        self::exigerEmailLibre($email);

        $utilisateur = Utilisateur::query()->create([
            'pseudo' => $donnees['pseudo'],
            'email' => $email,
            'mot_de_passe_hache' => $donnees['mot_de_passe'],
            'role' => 'membre',
        ]);

        return response()->json($utilisateur->ouvrirUneSession(), 201);
    }

    /**
     * POST /app/auth/connexion : email inconnu ou mot de passe faux donnent la même 401,
     * sans dire lequel. Un compte suspendu reçoit 403 (M1, A2).
     */
    public function connexion(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string'],
        ], self::MESSAGES);

        $utilisateur = Utilisateur::query()->where('email', Str::lower($donnees['email']))->first();

        if ($utilisateur === null || ! Hash::check($donnees['mot_de_passe'], $utilisateur->mot_de_passe_hache)) {
            throw ErreurApi::identifiantsIncorrects();
        }

        if ($utilisateur->estSuspendu()) {
            throw ErreurApi::compteSuspendu();
        }

        return response()->json($utilisateur->ouvrirUneSession());
    }

    /**
     * POST /app/auth/deconnexion : révoque le jeton utilisé pour cet appel.
     */
    public function deconnexion(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * GET /app/moi
     */
    public function moi(Request $request): JsonResponse
    {
        return response()->json($request->user()->versProfil());
    }

    /**
     * PATCH /app/moi : seuls les champs envoyés changent. L'email et le mot de passe
     * demandent le mot de passe actuel (M9).
     */
    public function modifier(Request $request): JsonResponse
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $request->user();

        if (! $request->hasAny(['pseudo', 'email', 'mot_de_passe'])) {
            throw ErreurApi::une('ERR_INVALID_PARAMETER', null, 'Aucun champ à modifier : pseudo, email ou mot_de_passe.', 422);
        }

        $donnees = $request->validate([
            'pseudo' => ['sometimes', 'filled', 'string', 'min:1', 'max:30'],
            'email' => ['sometimes', 'filled', 'string', 'email', 'max:255'],
            'mot_de_passe' => ['sometimes', 'filled', 'string', 'min:8'],
            'mot_de_passe_actuel' => ['required_with:email,mot_de_passe', 'string'],
        ], self::MESSAGES);

        if (isset($donnees['email']) || isset($donnees['mot_de_passe'])) {
            if (! Hash::check($donnees['mot_de_passe_actuel'], $utilisateur->mot_de_passe_hache)) {
                throw ErreurApi::identifiantsIncorrects();
            }
        }

        if (isset($donnees['pseudo']) && $donnees['pseudo'] !== $utilisateur->pseudo) {
            self::exigerPseudoLibre($donnees['pseudo']);
            $utilisateur->pseudo = $donnees['pseudo'];
        }

        if (isset($donnees['email']) && Str::lower($donnees['email']) !== $utilisateur->email) {
            self::exigerEmailLibre(Str::lower($donnees['email']));
            $utilisateur->email = Str::lower($donnees['email']);
        }

        if (isset($donnees['mot_de_passe'])) {
            $utilisateur->mot_de_passe_hache = $donnees['mot_de_passe'];
            // Un nouveau mot de passe ferme les autres sessions, pas celle en cours.
            $utilisateur->tokens()->whereKeyNot($utilisateur->currentAccessToken()->getKey())->delete();
        }

        $utilisateur->save();

        return response()->json($utilisateur->versProfil());
    }

    /**
     * DELETE /app/moi : supprime le compte et ses données ; ses signalements restent,
     * sans auteur (M10, règle 19). Toutes ses sessions sont fermées.
     */
    public function supprimer(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $request->user();

        $donnees = $request->validate(['mot_de_passe' => ['required', 'string']], self::MESSAGES);

        if (! Hash::check($donnees['mot_de_passe'], $utilisateur->mot_de_passe_hache)) {
            throw ErreurApi::identifiantsIncorrects();
        }

        DB::transaction(function () use ($utilisateur) {
            $utilisateur->tokens()->delete();
            $utilisateur->delete();
        });

        return response()->noContent();
    }

    private static function exigerPseudoLibre(string $pseudo): void
    {
        if (Utilisateur::query()->where('pseudo', $pseudo)->exists()) {
            throw ErreurApi::doublon('pseudo', 'Ce pseudo est déjà pris.');
        }
    }

    private static function exigerEmailLibre(string $email): void
    {
        if (Utilisateur::query()->where('email', $email)->exists()) {
            throw ErreurApi::doublon('email', 'Un compte utilise déjà cet email.');
        }
    }
}
