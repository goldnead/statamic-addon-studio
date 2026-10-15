<?php

/*
|--------------------------------------------------------------------------
| statamic-teams, nur die Schluessel, die das Demo aendert
|--------------------------------------------------------------------------
|
| Alles andere kommt aus der Paket-Konfiguration (die Paketdatei wird
| schluesselweise daruntergelegt).
*/

return [

    /*
    | Ansprueche (seit statamic-teams 0.4.0): ein Team beansprucht eine
    | Ressource der Site, hier ein Chorprofil aus statamic-directory. Ohne diese
    | beiden Eintraege ist nichts beanspruchbar, die Vorgabe verweigert alles.
    |
    | Die Policy ist die des Demos (App\Claims\DemoClaimPolicy): jedes bestaetigte
    | Konto darf beantragen, entscheiden darf die Chorfinder-Moderation. Die
    | Grenzen sind enger als die Vorgabe, damit ein Besucher die Sperre auch
    | wirklich sieht: zwei Antraege je Konto und Tag.
    */
    'claims' => [
        'resources' => [
            'directory' => \Goldnead\Directory\Claims\DirectoryClaimResource::class,
        ],
        'policy' => \App\Claims\DemoClaimPolicy::class,
        'limits' => [
            'max_per_account' => 2,
            'window_minutes' => 1440,
            'rejected_cooldown_minutes' => 1440,
        ],
    ],

];
