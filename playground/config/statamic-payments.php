<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mollie
    |--------------------------------------------------------------------------
    |
    | The live or test key from your Mollie dashboard. A test key starts with
    | `test_` and moves no money, which is what you want until the whole path
    | has run once end to end.
    |
    */

    'key' => env('MOLLIE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    |
    | What may be bought, and for how much. **The amount is read from here and
    | never from the request** — a checkout that accepted a posted price would
    | let anyone buy anything for a cent, which is the oldest mistake in online
    | payments and still the most common.
    |
    | `amount_cent` is an integer in minor units. Not a float: a float here is
    | how a cent goes missing every thousand orders.
    |
    | Empty as shipped. An addon that carried prices would be wrong about every
    | site that installed it.
    |
    |     'noten-paket' => [
    |         'name' => 'Notenpaket „Frühling"',
    |         'amount_cent' => 1900,
    |     ],
    |
    */

    // Lokal aus: Mollie prueft, ob die Adresse von aussen erreichbar ist, und
    // lehnt localhost ab. Der Zustand wird stattdessen geholt, siehe demo:poll.
    'webhook_url' => false,

    'products' => [
        'cw-kurs' => ['name' => 'Frühlingskurs für Chorleitende', 'amount_cent' => 24900, 'grants' => 'kurs-fruehling'],
        'cw-begleit-cd' => ['name' => 'Begleit-CD zum Mitsingen', 'amount_cent' => 900, 'grants' => 'begleit-cd'],
        'cw-noten' => ['name' => 'Notenpaket als PDF', 'amount_cent' => 1500, 'grants' => 'noten'],
        'cw-stimmcheck' => ['name' => 'Stimm-Check (kostenlos)', 'amount_cent' => 0, 'grants' => 'stimmcheck'],
        'cw-mitgliedschaft' => ['name' => 'Mitgliedschaft Chorwerkstatt', 'amount_cent' => 1900, 'interval' => '1 month', 'grants' => 'mitgliedschaft'],
        'cw-ausbildung' => ['name' => 'Chorleiter-Ausbildung, drei Raten', 'amount_cent' => 39900, 'interval' => '1 month', 'times' => 3],
        'cw-workshop' => ['name' => 'Workshop-Tag vor Ort', 'amount_cent' => 45000],
        'hm-vinyl' => ['name' => 'Halbmond, das Album auf Vinyl', 'amount_cent' => 2900],
        'hm-ticket' => ['name' => 'Konzertticket', 'amount_cent' => 2200],
        'hm-fanclub' => ['name' => 'Fanclub Halbmond', 'amount_cent' => 500, 'interval' => '1 month', 'grants' => 'fanclub'],
        'hm-shirt' => ['name' => 'Shirt „Ännchen & Söhne"', 'amount_cent' => 3200],
        'lh-erstgespraech' => ['name' => 'Erstgespräch', 'amount_cent' => 0],
        'lh-fuenferkarte' => ['name' => 'Fünferkarte', 'amount_cent' => 45000],
        'lh-begleitung' => ['name' => 'Begleitung, monatlich', 'amount_cent' => 14900, 'interval' => '1 month', 'trial_days' => 14, 'trial_amount_cent' => 100],
        'lh-quartal' => ['name' => 'Begleitung im Quartalsrhythmus', 'amount_cent' => 39900, 'interval' => '3 months'],
        'kaputt-negativ' => ['name' => 'Negativ', 'amount_cent' => -500],
        'kaputt-string' => ['name' => 'Als Text getippt', 'amount_cent' => '19,00'],
        'kaputt-ohne-preis' => ['name' => 'Ohne Preis'],
        'punkt.im.handle' => ['name' => 'Punkt im Handle', 'amount_cent' => 1234],
    ],

    'entitlements' => [
        'enabled' => env('STATAMIC_PAYMENTS_ENTITLEMENTS', false),
    ],
];
