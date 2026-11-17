<?php

return [

    /*
    | Nombre de requêtes acceptées par minute et par adresse IP sur la porte réseau (A10).
    | Le contrat impose au moins 60. La même limite protège les actions sans compte de la
    | porte application : liens courts, signalements, recherches sur le réseau.
    */
    'quota_par_minute' => max(60, (int) env('BIBLIOTHEQUE_QUOTA_PAR_MINUTE', 60)),

];
