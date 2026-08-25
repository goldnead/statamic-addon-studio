<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Write an invoice by itself
    |--------------------------------------------------------------------------
    |
    | On every paid payment, and a credit note on every full refund. Off means
    | the host decides when — through the `Invoices` facade — which is what an
    | installation wants when not every payment is a sale.
    |
    */

    'auto_issue' => env('INVOICES_AUTO_ISSUE', true),

    /*
    |--------------------------------------------------------------------------
    | The number series
    |--------------------------------------------------------------------------
    |
    | German law wants a series that is unique and continuous. This addon gives
    | out numbers from a locked counter row rather than from `MAX() + 1`,
    | because both of those properties are about concurrency: two checkouts
    | finishing together would otherwise read the same maximum.
    |
    | `period` is a date format, and it decides how often the series restarts —
    | `Y-m` monthly, `Y` yearly, an empty string never. Changing it later does
    | not renumber anything: the resolved series is stored on the counter.
    |
    | `prefix_per_brand` maps a brand id to its own prefix. One series per brand
    | is the point: two brands sharing a counter give each of them a series with
    | holes in it, and it is each brand that has to answer for its own numbering.
    |
    */

    'number' => [
        'prefix' => env('INVOICES_PREFIX', 'RE'),
        'period' => env('INVOICES_PERIOD', 'Y-m'),
        'separator' => '-',
        'pad' => 3,
        // Je Marke eine eigene Vorsilbe. Ohne die verweigert das Addon, und
        // zu Recht: der Zaehler ist je Marke, die Nummer global eindeutig --
        // zwei Marken mit derselben Vorsilbe geben dieselbe Nummer aus.
        // Die Ids kommen aus `brands`; nordlicht ist 2.
        'prefix_per_brand' => [
            2 => 'NL',   // Nordlicht Studio, die Agentur
            3 => 'CW',   // Chorwerkstatt Nord
            4 => 'HM',   // Kollektiv Halbmond
            5 => 'LH',   // Praxis Lindhorst
            6 => 'SZ',   // Sonderzeichen
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who is sending the invoice
    |--------------------------------------------------------------------------
    |
    | Frozen onto every invoice at the moment it is written, because an invoice
    | that changes when somebody edits a setting is not an invoice. Fill this in
    | before the first one goes out — a document missing the sender's details is
    | not a valid invoice in Germany, and it cannot be corrected afterwards.
    |
    */

    'seller' => [
        'name' => env('INVOICES_SELLER_NAME', 'Nordlicht Studio'),
        'address' => env('INVOICES_SELLER_ADDRESS', "Rheinsberger Str. 12\n10115 Berlin"),
        'vat_id' => env('INVOICES_SELLER_VAT_ID', 'DE312345678'),
        'tax_number' => env('INVOICES_SELLER_TAX_NUMBER'),
        'email' => env('INVOICES_SELLER_EMAIL', 'rechnung@nordlicht.beispiel'),
        'iban' => env('INVOICES_SELLER_IBAN', 'DE02120300000000202051'),
    ],

    'seller_per_brand' => [],

    // Das Demo verkauft aus Deutschland: Kurse und Workshops zum vollen Satz,
    // Noten ermaessigt, Unterricht nach § 4 Nr. 20a befreit. Die Saetze stehen
    // hier und nicht im Addon -- ohne Pflege antwortet der Rechner
    // "unbestimmt" statt "19 %".
    'tax' => [
        'merchant_country' => 'DE',
        'merchant_vat_id' => 'DE123456789',
        'prices_include_tax' => true,
        'default_product_class' => 'standard',
        'product_classes' => [
            'cw-noten' => 'reduced',
            'hm-vinyl' => 'reduced',
            'cw-stimmcheck' => 'exempt_teaching',
            'lh-erstgespraech' => 'exempt_teaching',
        ],
        'exemptions' => [
            'exempt_teaching' => [
                'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
                'legal_basis' => '§ 4 Nr. 20 Buchst. a UStG',
            ],
        ],
        'zones' => [
            ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
            ['countries' => ['AT'], 'rates' => ['standard' => 2000, 'reduced' => 1000]],
            ['countries' => ['*'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
        ],
    ],

];
