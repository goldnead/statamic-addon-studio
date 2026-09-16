<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menge. Achtzehn Monate Handel, damit `statamic-insights` etwas zu zeigen hat.
 *
 * Jeder andere Seeder hier schreibt Zustaende: je eine Zeile pro Fall, den ein
 * Bildschirm rendern koennen muss. Fuer ein Reporting-Addon reicht das nicht.
 * Ein Umsatzdiagramm aus drei Monaten mit je vier Zahlungen sieht nicht nach
 * wenig Umsatz aus, es sieht nach einem kaputten Addon aus, und die Frage, die
 * Insights stellt ("was sagen die Daten, die du ohnehin hast"), hat ohne
 * Verlauf keine Antwort.
 *
 * Also: rund dreihundert Zahlungen ueber achtzehn Monate, mit Wachstum, mit
 * Sommerloch, mit Dezemberspitze, mit Herkuenften, Laendern, zwei Waehrungen,
 * Erstattungen, wiedergeholten Warenkoerben und Bumps. Absichtlich unauffaellig
 * — die haesslichen Einzelfaelle stehen in {@see SeedsCommerce} und bleiben
 * dort; hier soll nichts brechen, hier soll etwas zu sehen sein.
 *
 * Deterministisch (`mt_srand`), damit zwei Laeufe dieselbe Geschichte erzaehlen
 * und ein Screenshot von gestern noch zur Zahl von heute passt. Wiederholbar
 * ueber `provider_id = demo_ins_<n>`.
 *
 * Laeuft **nach** {@see SeedsInvoices}, und das ist bindend: der Rechnungs-
 * Seeder schreibt jeder bezahlten Zahlung eine Rechnung, und dreihundert
 * Demo-Rechnungen unter einer Marke waeren eine Nummernreihe, die niemand
 * bestellt hat.
 */
class SeedsInsights
{
    /** Ein fester Wurf: gleiche Saat, gleiche Geschichte. */
    protected const SAAT = 20260916;

    protected const MONATE = 18;

    /** Was verkauft wird: Handle, Gewicht, Cent. */
    protected const SORTIMENT = [
        ['cw-kurs', 8, 24900],
        ['cw-noten', 18, 1500],
        ['cw-begleit-cd', 12, 900],
        ['cw-workshop', 4, 45000],
        ['cw-mitgliedschaft', 9, 1900],
        ['cw-ausbildung', 2, 39900],
        // Niedrig gehalten: eine kostenlose Zeile zaehlt als Bestellung und
        // bringt null Umsatz mit. Zu viele davon, und ein ruhiger Monat steht
        // im Bericht als „4 Zahlungen, 0,00 EUR" — was wie ein Defekt aussieht
        // und keiner ist.
        ['cw-stimmcheck', 3, 0],
        ['hm-vinyl', 14, 2900],
        ['hm-ticket', 20, 2200],
        ['hm-shirt', 8, 3200],
        ['lh-fuenferkarte', 4, 45000],
        ['lh-begleitung', 6, 14900],
        ['lh-quartal', 2, 39900],
        // Die Agentur selbst. Wenig Stueck, hoher Betrag — und die Marke, auf
        // der ein Besucher des Schauraums als erstes landet.
        ['studio-website', 5, 149000],
        ['studio-betreuung', 14, 19900],
    ];

    /** Woher der Kaeufer kommt: Code, Gewicht, Waehrung. */
    protected const LAENDER = [
        ['DE', 58, 'EUR'],
        ['AT', 11, 'EUR'],
        ['CH', 8, 'CHF'],
        ['NL', 6, 'EUR'],
        ['FR', 5, 'EUR'],
        ['BE', 4, 'EUR'],
        ['LU', 2, 'EUR'],
        ['US', 3, 'EUR'],
        // Ohne Land. Die Zeile, die im Laenderbericht "unbekannt" heisst.
        [null, 3, 'EUR'],
    ];

    /** Herkunft: Quelle, Medium, Gewicht, Kampagnen. */
    protected const HERKUNFT = [
        ['newsletter', 'email', 30, ['herbstkurs', 'warteliste', 'jahresrueckblick', 'fruehjahrsstimme']],
        ['instagram', 'social', 18, ['reel-atemstuetze', 'story-workshoptag']],
        ['google', 'cpc', 14, ['marke', 'chorleitung-weiterbildung']],
        ['youtube', 'video', 9, ['tutorial-register']],
        ['empfehlung', 'referral', 8, [null]],
        // Direkt: kein utm, und das ist der haeufigste Fall.
        [null, null, 21, [null]],
    ];

    /** Was oben draufgelegt wird, je Marken-Vorsilbe: Handle, Cent, Wahrscheinlichkeit in Prozent. */
    protected const BUMPS = [
        'cw-' => [['cw-begleit-cd', 500, 22], ['cw-noten', 900, 14]],
        'hm-' => [['hm-shirt', 1900, 16]],
    ];

    /** Was nach dem Kauf angeboten wird: Handle, Cent, Wahrscheinlichkeit. */
    protected const UPSELLS = [
        'cw-' => ['cw-noten', 1200, 9],
    ];

    /** Welches Angebot zu welchem Zusatz gehoert, mit seiner Anzeigequote. */
    protected const ANGEBOTS_QUOTEN = [
        'cw-cd-bump' => ['cw-begleit-cd', PaymentItem::KIND_BUMP, 0.22],
        'cw-noten-bump' => ['cw-noten', PaymentItem::KIND_BUMP, 0.14],
        'hm-shirt-bump' => ['hm-shirt', PaymentItem::KIND_BUMP, 0.16],
        'cw-upsell' => ['cw-noten', PaymentItem::KIND_UPSELL, 0.09],
    ];

    /**
     * Zugaenge entstehen nur fuer die letzten acht Monate.
     *
     * Jeder Zugang laeuft ueber die Fassade und feuert Ereignisse. Fuer den
     * Zugriffsbericht braucht es einen Bestand, keine Vollstaendigkeit — acht
     * Monate liefern aktive, abgelaufene und solche in der Gnadenfrist.
     */
    protected const ZUGANG_MONATE = 8;

    /** Produkt => Laufzeit des Zugangs (null = laeuft nie ab). */
    protected const ZUGAENGE = [
        'cw-kurs' => null,
        'cw-noten' => null,
        'cw-begleit-cd' => null,
        'cw-stimmcheck' => '14 days',
        'cw-mitgliedschaft' => '1 month',
        // Der Download-Code zur Platte. Ohne ihn haette Halbmond genau eine
        // Zeile im Zugriffsbericht, und eine Marke, die nur Vinyl und Tickets
        // verkauft, sieht dort aus wie eine Marke ohne Daten.
        'hm-vinyl' => null,
        'lh-fuenferkarte' => '6 months',
        'lh-begleitung' => '1 month',
        'studio-betreuung' => '1 month',
    ];

    protected const VORNAMEN = [
        'Annika', 'Bastian', 'Clara', 'Damaris', 'Elias', 'Frauke', 'Gero', 'Hanna',
        'Imke', 'Jost', 'Katrin', 'Lennart', 'Mareike', 'Nils', 'Ole', 'Pia',
        'Quirin', 'Rike', 'Silke', 'Torben', 'Ulrike', 'Vincent', 'Wiebke', 'Yannick',
    ];

    protected const NACHNAMEN = [
        'Ahrens', 'Bruhn', 'Clasen', 'Dierks', 'Ehlers', 'Freese', 'Gerdes', 'Harms',
        'Ihnen', 'Jansen', 'Kroeger', 'Lembke', 'Mohr', 'Nissen', 'Ohlsen', 'Petersen',
        'Rohde', 'Sievers', 'Tietjen', 'Voigt',
    ];

    protected const DOMAINS = ['beispiel.de', 'chor-beispiel.de', 'post.beispiel.de', 'beispiel.at'];

    /** @var list<array{email: string, name: string, land: ?string, waehrung: string}> */
    protected array $kaeufer = [];

    /** @var array<string, int> Produkt|Art => wie oft angenommen */
    protected array $angenommen = [];

    /**
     * Was jede bezahlte Bestellung fuer das CRM bedeutet.
     *
     * Gesammelt statt sofort gebucht, weil die Reihenfolge bindend ist:
     * `LeadHub::recordRevenue()` legt **nie** einen Kontakt an, sondern gibt
     * still `null` zurueck, wenn es keinen gibt. Erst alle Kontakte, dann aller
     * Umsatz.
     *
     * @var list<array{email: string, name: ?string, marke: ?Brand, referenz: string, cent: int, waehrung: string, zeit: Carbon, produkt: string, erstattet: int}>
     */
    protected array $umsatz = [];

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        mt_srand(self::SAAT);

        $handel = new SeedsCommerce;
        $katalog = $handel->katalog();
        $nummer = 0;
        $zugaenge = [];

        foreach ($this->monate() as $monat => $menge) {
            $beginn = Carbon::now()->startOfMonth()->subMonths(self::MONATE - 1 - $monat);

            for ($i = 0; $i < $menge; $i++) {
                $nummer++;
                $zahlung = $this->eineZahlung($nummer, $beginn, $katalog, $handel, $marken);

                if ($zahlung !== null) {
                    $zugaenge[] = $zahlung;
                }
            }
        }

        $this->angeboteNachziehen();

        return array_merge([
            'insights_zahlungen' => $nummer,
            'insights_zugaenge' => $this->zugaenge($zugaenge, $marken),
        ], $this->kontakteUndUmsatz());
    }

    /**
     * Wie viele Zahlungen je Monat.
     *
     * Wachstum, Sommerloch, Dezemberspitze. Nicht weil ein Demo huebsch sein
     * muss, sondern weil eine flache Linie keine einzige Frage beantwortet, die
     * jemand an ein Umsatzdiagramm stellt.
     *
     * @return list<int>
     */
    protected function monate(): array
    {
        $mengen = [];

        for ($m = 0; $m < self::MONATE; $m++) {
            $monat = (int) Carbon::now()->startOfMonth()->subMonths(self::MONATE - 1 - $m)->format('n');

            $basis = 13 + $m * 0.6;
            $saison = match (true) {
                in_array($monat, [7, 8], true) => 0.62,   // Sommerferien, die Choere pausieren
                $monat === 12 => 1.45,                    // Geschenke
                in_array($monat, [1, 9], true) => 1.2,    // Semesterstart
                default => 1.0,
            };

            $mengen[] = max(4, (int) round($basis * $saison) + mt_rand(-2, 2));
        }

        return $mengen;
    }

    /**
     * Eine Zahlung samt Posten.
     *
     * @param  array<string, array<string, mixed>>  $katalog
     * @param  array<string, Brand>  $marken
     * @return array{produkt: string, email: string, zeit: Carbon, marke: ?Brand}|null Bezahlt und jung genug fuer einen Zugang
     */
    protected function eineZahlung(int $nummer, Carbon $beginn, array $katalog, SeedsCommerce $handel, array $marken): ?array
    {
        [$produkt, , $cent] = $this->gewichtet(self::SORTIMENT);
        $kaeufer = $this->kaeufer();
        $zeit = $this->zeitpunktIn($beginn);

        $status = $this->wahl([
            Payment::STATUS_PAID => 70,
            Payment::STATUS_OPEN => 13,
            Payment::STATUS_EXPIRED => 8,
            Payment::STATUS_FAILED => 6,
            Payment::STATUS_CANCELED => 3,
        ]);

        // Ein liegengebliebener Warenkorb, der doch noch bezahlt wurde. Der
        // Bericht zur Kaufabbruchquote misst genau diese Differenz.
        $wiedergeholt = $status === Payment::STATUS_OPEN && mt_rand(1, 100) <= 14;

        if ($wiedergeholt) {
            $status = Payment::STATUS_PAID;
        }

        $bezahlt = $status === Payment::STATUS_PAID;
        $posten = $this->posten($produkt, $cent, $katalog, $bezahlt);

        // Franken sind nicht Euro. Ein Bericht, der beides addiert, luegt —
        // deshalb fuehrt das Demo zwei Waehrungen, damit die Trennung zu sehen
        // ist statt nur behauptet. Umgerechnet wird der Posten, nicht die
        // Summe: sonst weichen beide um die Rundung voneinander ab, und die
        // Umsatzansicht meldet zu Recht, dass die Posten nicht zum Betrag
        // passen.
        if ($kaeufer['waehrung'] === 'CHF') {
            $posten = array_map(function ($p) {
                $p['amount_cent'] = (int) (round($p['amount_cent'] * 0.95 / 10) * 10);

                return $p;
            }, $posten);
        }

        $summe = array_sum(array_column($posten, 'amount_cent'));

        [$quelle, $medium, , $kampagnen] = $this->gewichtet(self::HERKUNFT, 2);
        $kampagne = $kampagnen[array_rand($kampagnen)];

        $rabatt = $bezahlt && mt_rand(1, 100) <= 9;
        $erstattet = $bezahlt && $summe > 0 && mt_rand(1, 100) <= 4;

        $zahlung = Payment::updateOrCreate(
            ['provider' => $summe === 0 ? 'free' : 'mollie', 'provider_id' => 'demo_ins_'.$nummer],
            [
                'product' => $produkt,
                'amount_cent' => $summe,
                'currency' => $kaeufer['waehrung'],
                'status' => $status,
                'email' => $kaeufer['email'],
                'name' => $kaeufer['name'],
                'brand_id' => $handel->markeFuer($produkt, $marken) ?? 0,
                'country' => $kaeufer['land'],
                'country_source' => $kaeufer['land'] ? 'checkout' : null,
                'customer_reference' => 'demo_ins_cst_'.md5($kaeufer['email']),
                'discount_code' => $rabatt ? 'ZEHNEURO' : null,
                'discount_cent' => $rabatt ? min(1000, $summe) : null,
                'utm_source' => $quelle,
                'utm_medium' => $medium,
                'utm_campaign' => $kampagne,
                'referrer' => $quelle ? 'https://'.$quelle.'.beispiel/' : null,
                'landing_page' => '/angebot/'.$produkt,
                'card_last4' => $bezahlt && $summe > 0 ? str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT) : null,
                'card_label' => $bezahlt && $summe > 0 ? $this->wahl(['Visa' => 5, 'Mastercard' => 4, 'PayPal' => 3, 'SEPA' => 2]) : null,
                // `refunded_cent` und `refunded_at` stehen hier bewusst NICHT:
                // die Erstattung wird weiter unten ueber `Refunds::record()`
                // gebucht. Bis 16.09.2026 stempelte dieser Seeder beides
                // direkt, und dann trugen zehn Zahlungen eine Erstattung,
                // waehrend `payment_refunds` vier Zeilen hatte. Der
                // Umsatzbildschirm las den Stempel und sah richtig aus, die
                // Belegtabelle widersprach ihm.
                'recovered_at' => $wiedergeholt ? $zeit->copy()->addHours(mt_rand(3, 60)) : null,
                'paid_at' => $bezahlt ? $zeit : null,
                'fulfilled_at' => $bezahlt ? $zeit : null,
                'created_at' => $zeit,
                'updated_at' => $zeit,
            ],
        );

        $zahlung->items()->delete();

        foreach ($posten as $posten_zeile) {
            PaymentItem::create($posten_zeile + ['payment_id' => $zahlung->getKey()]);

            if ($posten_zeile['kind'] !== PaymentItem::KIND_PRIMARY) {
                $schluessel = $posten_zeile['product'].'|'.$posten_zeile['kind'];
                $this->angenommen[$schluessel] = ($this->angenommen[$schluessel] ?? 0) + 1;
            }
        }

        $marke = $this->markeVon($produkt, $marken);

        if ($bezahlt) {
            $erstattetCent = $erstattet ? $this->erstatten($zahlung, $marke, $nummer, $zeit) : 0;

            $this->umsatz[] = [
                'email' => $kaeufer['email'],
                'name' => $kaeufer['name'],
                'marke' => $marke,
                // Namensraum voran, so wie der Vertrag es vorsieht: die
                // Referenz ist der einzige Schutz gegen doppelte Buchung.
                'referenz' => 'payments:demo_ins:'.$nummer,
                'cent' => $summe,
                'waehrung' => $kaeufer['waehrung'],
                'zeit' => $zeit,
                'produkt' => $produkt,
                'erstattet' => $erstattetCent,
            ];
        }

        $jungGenug = $zeit->greaterThan(Carbon::now()->subMonths(self::ZUGANG_MONATE));

        // `array_key_exists`, nicht `isset`: die Produkte, deren Zugang nie
        // ablaeuft, stehen mit `null` in der Liste, und `isset` haelt einen
        // Schluessel mit dem Wert null fuer nicht vorhanden. Genau daran sind
        // im ersten Lauf drei von sieben Zugangsarten stillschweigend
        // ausgefallen.
        return $bezahlt && $jungGenug && array_key_exists($produkt, self::ZUGAENGE)
            ? ['produkt' => $produkt, 'email' => $kaeufer['email'], 'zeit' => $zeit, 'marke' => $marke]
            : null;
    }

    /**
     * Eine Erstattung buchen, nicht stempeln.
     *
     * `Refunds::record()` schreibt die Zeile in `payment_refunds`, rechnet
     * `refunded_cent` auf der Zahlung nach und feuert `PaymentRefunded`. Die
     * Referenz ist dabei nicht schmueckend, sondern die einzige Duplikatsperre:
     * ohne sie bucht ein zweiter Seed-Lauf ein zweites Mal, solange noch Betrag
     * offen ist.
     *
     * Dass an `PaymentRefunded` eine Stornorechnung haengt, ist hier harmlos:
     * `InvoiceWriter::creditNoteFor()` gibt `null` zurueck, wenn es zur Zahlung
     * keine Rechnung gibt, und die Menge bekommt keine (siehe SeedsInvoices).
     *
     * Rueckdatiert wird danach, und zwar absichtlich am Beleg entlang statt an
     * ihm vorbei: der Dienst kennt nur „jetzt", ein Schauraum braucht eine
     * Vergangenheit. Verstellt wird nur der Zeitpunkt, nie der Betrag.
     */
    protected function erstatten(Payment $zahlung, ?Brand $marke, int $nummer, Carbon $zeit): int
    {
        if ($marke === null) {
            return 0;
        }

        $betrag = (int) $zahlung->amount_cent;

        BrandContext::runFor($marke, fn () => app(Refunds::class)->record(
            $zahlung,
            $betrag,
            'demo_ins_erstattung_'.$nummer,
        ));

        $wann = $zeit->copy()->addDays(mt_rand(2, 21))->min(Carbon::now());

        $zahlung->forceFill(['refunded_at' => $wann])->saveQuietly();

        // Nur `created_at`: die Tabelle fuehrt kein `updated_at`. Ein Beleg
        // wird nicht geaendert, er entsteht einmal.
        DB::table('payment_refunds')
            ->where('reference', 'demo_ins_erstattung_'.$nummer)
            ->update(['created_at' => $wann]);

        return $betrag;
    }

    /**
     * Die Kaeufer als Kontakte, und was sie ausgegeben haben.
     *
     * Ohne das ist die Demo in sich widerspruechlich: der Umsatzbildschirm
     * zaehlt hunderte Kaeufer, das CRM kennt achtzehn Leute, und die Spalte
     * „Lebensumsatz" ist auf jeder Kontaktkarte leer. Wer beides nebeneinander
     * sieht, haelt eines von beidem fuer kaputt.
     *
     * Reihenfolge ist bindend: `recordRevenue()` legt keinen Kontakt an. Und
     * beides laeuft je Marke, weil `Contact` `HasBrand` fuehrt und die Suche
     * nach der Adresse im Bereich der aktiven Marke stattfindet.
     *
     * @return array<string, int>
     */
    protected function kontakteUndUmsatz(): array
    {
        $kontakte = 0;
        $gebucht = 0;

        // Je Marke ein Durchgang, statt je Buchung einmal umzuschalten.
        $nachMarke = [];

        foreach ($this->umsatz as $zeile) {
            if ($zeile['marke'] !== null) {
                $nachMarke[$zeile['marke']->id][] = $zeile;
            }
        }

        foreach ($nachMarke as $zeilen) {
            $marke = $zeilen[0]['marke'];

            BrandContext::runFor($marke, function () use ($zeilen, &$kontakte, &$gebucht) {
                $gesehen = [];

                foreach ($zeilen as $zeile) {
                    $schluessel = mb_strtolower($zeile['email']);

                    if (! isset($gesehen[$schluessel])) {
                        $gesehen[$schluessel] = true;
                        $kontakte++;

                        LeadHub::create([
                            'email' => $zeile['email'],
                            'full_name' => $zeile['name'],
                            'status' => 'customer',
                            'source' => 'checkout',
                            'tags' => ['kunde'],
                        ]);
                    }

                    $ergebnis = LeadHub::recordRevenue(
                        $zeile['email'],
                        $zeile['referenz'],
                        $zeile['cent'],
                        $zeile['waehrung'],
                        $zeile['zeit'],
                        'statamic-payments',
                        ['product' => $zeile['produkt']],
                    );

                    if ($ergebnis !== null) {
                        $gebucht++;
                    }

                    // Erstattet heisst nicht „nie eingenommen": der Eintrag
                    // bleibt stehen und traegt den zurueckgegangenen Teil, so
                    // wie es die Belegtabelle auch tut.
                    if ($zeile['erstattet'] > 0) {
                        LeadHub::refundRevenue($zeile['referenz'], $zeile['erstattet']);
                    }
                }
            });
        }

        return ['insights_kontakte' => $kontakte, 'insights_umsatzbuchungen' => $gebucht];
    }

    /**
     * Hauptposten, Bump, Upsell.
     *
     * Die Summe der Posten ist der Betrag der Zahlung. In `SeedsCommerce` steht
     * bewusst eine Zahlung, deren Posten nicht zum Betrag passen — der Fall,
     * den die Umsatzansicht meldet. Ein Fall reicht; dreihundert davon waeren
     * kein Hinweis mehr, sondern Rauschen.
     *
     * @param  array<string, array<string, mixed>>  $katalog
     * @return list<array<string, mixed>>
     */
    protected function posten(string $produkt, int $cent, array $katalog, bool $bezahlt): array
    {
        $posten = [[
            'product' => $produkt,
            'name' => $katalog[$produkt]['name'] ?? $produkt,
            'amount_cent' => $cent,
            'quantity' => 1,
            'kind' => PaymentItem::KIND_PRIMARY,
        ]];

        // Zusaetze nur auf bezahlten Bestellungen: ein Bump auf einem
        // abgebrochenen Warenkorb wuerde im Angebotsbericht als angenommen
        // zaehlen, obwohl nie Geld geflossen ist.
        if (! $bezahlt) {
            return $posten;
        }

        // Ein Produkt darf auf einer Bestellung nur einmal stehen:
        // `payment_items` ist eindeutig auf (payment_id, product). Dieselben
        // Noten als Bump **und** als Upsell sind deshalb keine zwei Zeilen,
        // sondern ein Fehlschlag mitten im Seeder — gefunden beim Bauen.
        $vergeben = [$produkt => true];
        $vorsilbe = substr($produkt, 0, 3);

        foreach (self::BUMPS[$vorsilbe] ?? [] as [$handle, $preis, $chance]) {
            if (! isset($vergeben[$handle]) && mt_rand(1, 100) <= $chance) {
                $vergeben[$handle] = true;
                $posten[] = [
                    'product' => $handle,
                    'name' => $katalog[$handle]['name'] ?? $handle,
                    'amount_cent' => $preis,
                    'quantity' => 1,
                    'kind' => PaymentItem::KIND_BUMP,
                ];
            }
        }

        if (isset(self::UPSELLS[$vorsilbe])) {
            [$handle, $preis, $chance] = self::UPSELLS[$vorsilbe];

            if (! isset($vergeben[$handle]) && mt_rand(1, 100) <= $chance) {
                $posten[] = [
                    'product' => $handle,
                    'name' => $katalog[$handle]['name'] ?? $handle,
                    'amount_cent' => $preis,
                    'quantity' => 1,
                    'kind' => PaymentItem::KIND_UPSELL,
                ];
            }
        }

        return $posten;
    }

    /**
     * Die Zaehler der Angebote an das anpassen, was wirklich gekauft wurde.
     *
     * Der Angebotsbericht rechnet die Annahmequote aus `shown_count` und
     * `accepted_count`. Beide standen auf Null, waehrend Bumps in den Posten
     * lagen — eine Quote von 0 % neben einem Umsatz ist keine Zahl, die jemand
     * glaubt, und sie ist auch nicht wahr.
     */
    protected function angeboteNachziehen(): void
    {
        foreach (self::ANGEBOTS_QUOTEN as $handle => [$produkt, $art, $quote]) {
            $angenommen = $this->angenommen[$produkt.'|'.$art] ?? 0;

            if ($angenommen === 0) {
                continue;
            }

            Offer::withoutGlobalScopes()
                ->where('handle', $handle)
                ->update([
                    'accepted_count' => $angenommen,
                    'shown_count' => (int) round($angenommen / $quote),
                ]);
        }
    }

    /**
     * Zugaenge zu den bezahlten Bestellungen der letzten Monate.
     *
     * Ueber `Entitlements::grant()`, nicht per Einfuegen: die Spalten, die
     * ueber Zugriff entscheiden, sind gegen Massenzuweisung geschuetzt, und der
     * Weg an ihnen vorbei ist die Fassade. Wiederholbar ueber die Referenz.
     *
     * @param  list<array{produkt: string, email: string, zeit: Carbon, marke: ?Brand}>  $zeilen
     * @param  array<string, Brand>  $marken
     */
    protected function zugaenge(array $zeilen, array $marken): int
    {
        $gezaehlt = 0;

        foreach ($zeilen as $i => $zeile) {
            $marke = $zeile['marke'] ?? ($marken['nordlicht'] ?? null);

            if ($marke === null) {
                continue;
            }

            $laufzeit = self::ZUGAENGE[$zeile['produkt']] ?? null;
            $laeuft_ab = $laufzeit ? $zeile['zeit']->copy()->add($laufzeit) : null;

            // Jeder fuenfte abgelaufene Zugang steht in der Gnadenfrist. Der
            // Zustand, den der Zugriffsbericht als eigene Spalte fuehrt.
            $gnade = $laeuft_ab && $laeuft_ab->isPast() && $i % 5 === 0
                ? Carbon::now()->addDays(mt_rand(1, 10))
                : null;

            BrandContext::runFor($marke, function () use ($zeile, $laeuft_ab, $gnade, $i) {
                Entitlements::grant(
                    subject: new SubjectReference('email', $zeile['email']),
                    productSlug: $zeile['produkt'],
                    source: 'payment',
                    sourceRef: 'demo_ins_'.$i,
                    startsAt: $zeile['zeit'],
                    expiresAt: $laeuft_ab,
                    graceUntil: $gnade,
                    meta: ['demo' => true],
                );
            });

            $gezaehlt++;
        }

        return $gezaehlt;
    }

    /**
     * Wer kauft.
     *
     * Ein Drittel der Bestellungen geht an jemanden, der schon einmal gekauft
     * hat. Ohne Wiederkaeufer waere die Zahl der Kaeufer immer gleich der Zahl
     * der Bestellungen, der Lebensumsatz je Kontakt immer ein einziger Kauf,
     * und zwei Kacheln der Umsatzansicht waeren stumm.
     *
     * @return array{email: string, name: string, land: ?string, waehrung: string}
     */
    protected function kaeufer(): array
    {
        if ($this->kaeufer !== [] && mt_rand(1, 100) <= 33) {
            return $this->kaeufer[array_rand($this->kaeufer)];
        }

        $vorname = self::VORNAMEN[array_rand(self::VORNAMEN)];
        $nachname = self::NACHNAMEN[array_rand(self::NACHNAMEN)];
        [$land, , $waehrung] = $this->gewichtet(self::LAENDER);

        $neu = [
            'email' => strtolower($vorname.'.'.$nachname.(count($this->kaeufer) + 1).'@'.self::DOMAINS[array_rand(self::DOMAINS)]),
            'name' => $vorname.' '.$nachname,
            'land' => $land,
            'waehrung' => $waehrung,
        ];

        $this->kaeufer[] = $neu;

        return $neu;
    }

    /** Irgendwann in diesem Monat, aber nicht in der Zukunft. */
    protected function zeitpunktIn(Carbon $beginn): Carbon
    {
        $ende = $beginn->copy()->endOfMonth()->min(Carbon::now()->subMinutes(5));
        $spanne = max(1, $beginn->diffInMinutes($ende));

        return $beginn->copy()->addMinutes(mt_rand(0, (int) $spanne));
    }

    /**
     * @param  array<string, Brand>  $marken
     */
    protected function markeVon(string $produkt, array $marken): ?Brand
    {
        return match (substr($produkt, 0, 3)) {
            'cw-' => $marken['chorwerkstatt'] ?? null,
            'hm-' => $marken['halbmond'] ?? null,
            'lh-' => $marken['lindhorst'] ?? null,
            default => $marken['nordlicht'] ?? null,
        };
    }

    /**
     * Eine Zeile nach ihrem Gewicht ziehen.
     *
     * @param  list<array<int, mixed>>  $tabelle
     * @param  int  $spalte  Wo das Gewicht steht
     * @return array<int, mixed>
     */
    protected function gewichtet(array $tabelle, int $spalte = 1): array
    {
        $wurf = mt_rand(1, (int) array_sum(array_column($tabelle, $spalte)));

        foreach ($tabelle as $zeile) {
            $wurf -= (int) $zeile[$spalte];

            if ($wurf <= 0) {
                return $zeile;
            }
        }

        return $tabelle[array_key_last($tabelle)];
    }

    /**
     * Einen Schluessel nach seinem Gewicht ziehen.
     *
     * @param  array<string, int>  $gewichte
     */
    protected function wahl(array $gewichte): string
    {
        $wurf = mt_rand(1, array_sum($gewichte));

        foreach ($gewichte as $schluessel => $gewicht) {
            $wurf -= $gewicht;

            if ($wurf <= 0) {
                return $schluessel;
            }
        }

        return (string) array_key_last($gewichte);
    }
}
