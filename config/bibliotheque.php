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

];
