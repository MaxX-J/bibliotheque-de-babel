<?php

return [

    /*
    | La version du contrat d'API commun que cette bibliothèque respecte.
    */
    'contrat' => '1.1.0',

    /*
    | L'adresse publique de l'application web Angular, si elle est en ligne (null sinon).
    | Publiée dans l'identité : les pairs peuvent proposer d'y ouvrir une page (V16, V21).
    */
    'application_web' => env('BIBLIOTHEQUE_APPLICATION_WEB') ?: null,

    /*
    | Le jeton réseau, remis par la plateforme, le même pour toutes les bibliothèques.
    | Il ouvre la seule route GET /textes-bannis. Jamais en base, jamais dans un journal.
    */
    'jeton_reseau' => env('BIBLIOTHEQUE_JETON_RESEAU') ?: null,

    /*
    | Nombre de requêtes acceptées par minute et par adresse IP sur la porte réseau (A10).
    | Le contrat impose au moins 60. La même limite protège les actions sans compte de la
    | porte application : liens courts, signalements, recherches sur le réseau.
    */
    'quota_par_minute' => max(60, (int) env('BIBLIOTHEQUE_QUOTA_PAR_MINUTE', 60)),

    /*
    | Quota de la porte application pour la lecture (pages, hasard, infos), plus large :
    | un lecteur tourne les pages vite.
    */
    'quota_application_par_minute' => max(60, (int) env('BIBLIOTHEQUE_QUOTA_APPLICATION_PAR_MINUTE', 300)),

    /*
    | Durée maximale d'un lien court créé sans compte, en jours (GET /app/infos).
    */
    'duree_lien_visiteur_jours' => max(1, (int) env('BIBLIOTHEQUE_DUREE_LIEN_VISITEUR_JOURS', 30)),

    /*
    | Un pair a 2 secondes pour répondre (contrat, x-babel-delai-max-ms). Au 3e échec de
    | suite, il passe hors ligne (livrable 4, choix 8).
    */
    'delai_pair_ms' => 2000,
    'echecs_avant_hors_ligne' => 3,

    /*
    | Le serveur temps réel (protocole Pusher : Laravel Reverb). Sans clé, il n'y en a pas :
    | GET /app/infos renvoie temps_reel à null.
    */
    'temps_reel' => [
        'cle' => env('REVERB_APP_KEY') ?: null,
        'hote' => env('REVERB_HOST', 'localhost'),
        'port' => (int) env('REVERB_PORT', 8080),
        'tls' => env('REVERB_SCHEME', 'http') === 'https',
    ],

];
