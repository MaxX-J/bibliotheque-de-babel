<?php

namespace App\Reseau;

use App\Exceptions\ErreurApi;
use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Appelle l'API publique d'un pair (une autre bibliothèque du réseau).
 *
 * - 2 secondes au plus par appel (contrat) ;
 * - chaque réponse est vérifiée contre le contrat avant d'être utilisée : hors contrat,
 *   c'est un échec (livrable 4, choix 7) ;
 * - un échec augmente le compteur du pair, qui passe hors ligne au 3e de suite ; un 429
 *   n'est pas un échec : le pair est occupé, son compteur ne bouge pas (choix 7 et 8).
 */
final class ClientPair
{
    /**
     * Lit une page chez un pair (B1). L'adresse doit déjà avoir été validée (F5).
     *
     * @throws ErreurApi 502 ERR_PEER_UNREACHABLE ou 503 ERR_PEER_BUSY
     */
    public function lirePage(Bibliotheque $pair, string $adresse): string
    {
        $reponse = $this->appeler($pair, '/pages/'.$adresse);
        $page = $reponse->json();

        $conforme = is_array($page)
            && array_keys($page) === ['bibliotheque', 'adresse', 'texte']
            && $page['bibliotheque'] === $pair->identifiant
            && $page['adresse'] === $adresse
            && is_string($page['texte'])
            && strlen($page['texte']) === Moteur::LONGUEUR_PAGE
            && preg_match('/\A[a-z ,.]+\z/', $page['texte']) === 1;

        if (! $reponse->ok() || ! $conforme) {
            throw $this->echec($pair);
        }

        $this->reussite($pair);

        return $page['texte'];
    }

    private function appeler(Bibliotheque $pair, string $chemin): Response
    {
        try {
            $reponse = Http::acceptJson()
                ->timeout(config('bibliotheque.delai_pair_ms') / 1000)
                ->connectTimeout(config('bibliotheque.delai_pair_ms') / 1000)
                ->get(rtrim($pair->url_actuelle, '/').'/api/v1'.$chemin);
        } catch (ConnectionException) {
            throw $this->echec($pair);
        }

        if ($reponse->status() === 429) {
            $attente = (int) $reponse->header('Retry-After');

            throw ErreurApi::une(
                'ERR_PEER_BUSY',
                'bibliotheque',
                'La bibliothèque voisine est occupée, réessayez plus tard.',
                503,
                $attente >= 1 ? ['Retry-After' => $attente] : [],
            );
        }

        return $reponse;
    }

    private function echec(Bibliotheque $pair): ErreurApi
    {
        $echecs = $pair->echecs_consecutifs + 1;

        $pair->forceFill([
            'echecs_consecutifs' => $echecs,
            'etat' => $echecs >= config('bibliotheque.echecs_avant_hors_ligne') ? 'hors_ligne' : $pair->etat,
        ])->save();

        return ErreurApi::une('ERR_PEER_UNREACHABLE', 'bibliotheque', 'La bibliothèque voisine ne répond pas.', 502);
    }

    private function reussite(Bibliotheque $pair): void
    {
        $pair->forceFill([
            'echecs_consecutifs' => 0,
            'etat' => 'en_ligne',
            'dernier_contact_le' => now(),
        ])->save();
    }
}
