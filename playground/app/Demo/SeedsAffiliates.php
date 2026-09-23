<?php

namespace App\Demo;

use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Models\Click;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Das Partnerprogramm der Chorwerkstatt (statamic-affiliates X1 bis X3).
 *
 * Fuenf Partner in vier Lagen (aktiv, aktiv mit eigenem Satz, beantragt,
 * gesperrt), Saetze je Produkt, ein JV-Vertrag mit Laufzeit, Klicks ueber
 * zwanzig Tage und sieben Verkaeufe, aus denen das Hauptbuch echte Provisionen
 * bucht: per Link, per Gutschein (`CLARA10`), mit Bump, als JV-Anteil, mit
 * Festbetrag auf ein Abo, und einer ohne Partner. Die drei aeltesten sind aus
 * der Haltefrist heraus, daraus entsteht eine Auszahlungsliste, die als
 * bezahlt markiert ist; die juengeren warten noch.
 *
 * **Kein `PaymentPaid`.** Die sieben Zahlungen sind per `DB::table()`
 * geschrieben und tragen `meta.affiliates_demo`. Das Ereignis wuerde neben der
 * Provision auch Rechnung, Zugaenge und Automationen ausloesen. Gebucht wird
 * stattdessen genau das, was der Listener des Addons tut:
 * `Ledger::book(PaymentsBridge::sale($zahlung))`. Die Zuordnung per Link
 * schreibt der Seeder als `Referral`, so wie `PaymentsBridge::created()` sie im
 * Checkout des Kaeufers schreiben wuerde; die per Gutschein findet das Addon
 * selbst.
 *
 * Mails an Partner sind waehrend des Laufs aus
 * (`affiliates.mail.commission`), danach wieder wie konfiguriert.
 *
 * Wiederholbar: Partner ueber (Marke, Adresse), Saetze ueber (Marke, Produkt),
 * Zahlungen ueber `provider_id = tr_affdemo_<n>`, Buchungen ueber den
 * `dedupe_key` des Hauptbuchs. Klicks nur, wenn der Partner noch keine hat.
 */
class SeedsAffiliates
{
    public const SAAT = 20260924;

    public const PASSWORT = 'demo-local-password';

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        $marke = $marken['chorwerkstatt'] ?? Brand::query()->where('handle', 'chorwerkstatt')->firstOrFail();

        // Seit affiliates `1f9936c` setzt die Installation den Container des
        // Bildfelds selbst (und repariert ein vorhandenes Blueprint). Ohne
        // Vorgabe naehme sie den ersten Container der Site; das Bild der
        // Werbemittel liegt aber in `assets`.
        config()->set('affiliates.materials.container', 'assets');
        Artisan::call('affiliates:install');

        $vorher = config('affiliates.mail.commission');
        config()->set('affiliates.mail.commission', false);

        try {
            $ergebnis = BrandContext::runFor($marke, fn () => $this->imMarkenkontext((int) $marke->id));
        } finally {
            config()->set('affiliates.mail.commission', $vorher);
        }

        $this->werbemittel();

        return $ergebnis;
    }

    /** @return array<string, int> */
    protected function imMarkenkontext(int $marke): array
    {
        $partner = $this->partner();
        $this->saetze();
        $this->jv($partner['jonasweber']);
        $this->klicks($partner);
        $this->partnerGutschein();

        $ledger = app(Ledger::class);

        foreach ($this->verkaeufe($marke) as [$zahlungId, $vermittler]) {
            if ($vermittler !== null) {
                Referral::query()->acrossBrands()->firstOrCreate(['payment_id' => $zahlungId], [
                    'brand_id' => $marke,
                    'partner_id' => $partner[$vermittler]->getKey(),
                    'source' => Referral::SOURCE_LINK,
                    'clicked_at' => DB::table('payments')->where('id', $zahlungId)->value('created_at'),
                ]);
            }

            $ledger->book(PaymentsBridge::sale(Payment::withoutGlobalScopes()->findOrFail($zahlungId)));
        }

        $ledger->release();

        // Eine Auszahlung, schon bezahlt: die Liste zeigt dann beide Zustaende,
        // Bezahltes und noch Offenes in der Haltefrist.
        if (! Payout::query()->exists()) {
            $listen = app(Payouts::class)->build();

            if ($listen->isNotEmpty()) {
                app(Payouts::class)->markPaid($listen->first());
            }
        }

        return [
            'partner' => Partner::query()->count(),
            'provisionen' => Commission::query()->count(),
            'auszahlungen' => Payout::query()->count(),
        ];
    }

    /** @return array<string, Partner> nach Code */
    protected function partner(): array
    {
        $clara = $this->nutzer('clara.brandt@partner.beispiel', 'Clara Brandt');

        $zeilen = [
            'clarabrandt' => [
                'name' => 'Clara Brandt', 'email' => 'clara.brandt@partner.beispiel', 'status' => Partner::STATUS_ACTIVE,
                'user_id' => (string) $clara->id(), 'coupon_codes' => ['CLARA10'],
                'payout_method' => 'bank', 'payout_details' => "Clara Brandt\nDE89 3704 0044 0532 0130 00",
                'approved_at' => Carbon::now()->subDays(60),
            ],
            'jonasweber' => [
                'name' => 'Jonas Weber', 'email' => 'jonas.weber@partner.beispiel', 'status' => Partner::STATUS_ACTIVE,
                'commission_percent' => 40, 'payout_method' => 'paypal', 'payout_details' => 'jonas.weber@partner.beispiel',
                'notes' => 'Leitet die Stimmgruppen-Workshops mit, Umsatzteilung im JV-Vertrag.',
                'approved_at' => Carbon::now()->subDays(45),
            ],
            'mirasommer' => [
                'name' => 'Mira Sommer', 'email' => 'mira.sommer@partner.beispiel', 'status' => Partner::STATUS_PENDING,
                'website' => 'https://chorblog.example.org',
                'message' => 'Ich schreibe über Chorproben und würde den Workshop gern empfehlen.',
            ],
            'chorverband' => [
                'name' => 'Chorverband Nord', 'email' => 'geschaeftsstelle@chorverband.beispiel', 'status' => Partner::STATUS_ACTIVE,
                'payout_method' => 'bank', 'payout_details' => "Chorverband Nord e. V.\nDE02 1203 0000 0000 2020 51",
                'approved_at' => Carbon::now()->subDays(30),
            ],
            'alteagentur' => [
                'name' => 'Alte Agentur', 'email' => 'kontakt@alte-agentur.beispiel', 'status' => Partner::STATUS_SUSPENDED,
                'notes' => 'Gesperrt: Links in Gutscheinportalen gestreut.',
                'approved_at' => Carbon::now()->subDays(120),
            ],
        ];

        $partner = [];

        foreach ($zeilen as $code => $werte) {
            $partner[$code] = Partner::query()->updateOrCreate(
                ['email' => $werte['email']],
                ['code' => $code, 'notify' => true, ...$werte],
            );
        }

        return $partner;
    }

    /** Satz je Produkt: Prozent mit Upsell, ein Bump-Satz, und ein Festbetrag auf ein Abo. */
    protected function saetze(): void
    {
        Rate::query()->updateOrCreate(['product' => 'offer:cw-stimmgruppe'], [
            'type' => Rate::TYPE_PERCENT, 'percent' => 25, 'recurring' => Rate::RECURRING_NONE,
            'bumps' => true, 'bump_percent' => 10, 'upsells' => true, 'active' => true,
        ]);

        // Beim Bump zaehlt der Satz des Zusatzprodukts, nicht der des
        // Hauptprodukts (Entscheidung X1 vom 23.09.2026). Ohne eigene Zeile
        // fiele das Notenpaket auf die Vorgabe zurueck, und die schaltet Bumps ab.
        Rate::query()->updateOrCreate(['product' => 'cw-notenpaket'], [
            'type' => Rate::TYPE_PERCENT, 'percent' => 10, 'recurring' => Rate::RECURRING_NONE,
            'bumps' => true, 'bump_percent' => 10, 'upsells' => false, 'active' => true,
        ]);

        Rate::query()->updateOrCreate(['product' => 'cw-mitgliedschaft'], [
            'type' => Rate::TYPE_FIXED, 'amount_cent' => 500, 'percent' => null,
            'recurring' => Rate::RECURRING_LIMITED, 'recurring_times' => 6,
            'bumps' => false, 'upsells' => false, 'active' => true,
        ]);
    }

    /** X2: Umsatzteilung mit Jonas, seit einem Monat, noch fuenf Monate. */
    protected function jv(Partner $jonas): void
    {
        JvContract::query()->updateOrCreate(
            ['partner_id' => $jonas->getKey(), 'name' => 'Co-Workshop Stimmgruppen'],
            [
                'products' => ['offer:cw-stimmgruppe'],
                'percent' => 30,
                'bump_percent' => 10,
                'recurring' => false,
                'starts_on' => Carbon::now()->subMonthNoOverflow()->toDateString(),
                'ends_on' => Carbon::now()->addMonthsNoOverflow(5)->toDateString(),
                'active' => true,
            ],
        );
    }

    /** @param  array<string, Partner>  $partner */
    protected function klicks(array $partner): void
    {
        mt_srand(self::SAAT);

        foreach (['clarabrandt' => [37, '/chorwerkstatt/kurse'], 'jonasweber' => [12, '/workshop']] as $code => [$anzahl, $seite]) {
            $p = $partner[$code];

            if (Click::query()->where('partner_id', $p->getKey())->exists()) {
                continue;
            }

            for ($i = 0; $i < $anzahl; $i++) {
                Click::query()->create([
                    'partner_id' => $p->getKey(),
                    'landing' => $seite.'?ref='.$code,
                    'created_at' => Carbon::now()->subDays(mt_rand(0, 19))->setTime(mt_rand(7, 22), mt_rand(0, 59)),
                ]);
            }
        }
    }

    /**
     * Der Gutschein, der Clara gehoert, auch in statamic-offers, damit er in der
     * Kasse einloesbar ist und nicht nur im Partnerprogramm steht.
     */
    protected function partnerGutschein(): void
    {
        Coupon::updateOrCreate(['code' => 'CLARA10'], [
            'name' => 'Empfehlung Clara Brandt',
            'percent' => 10,
            'active' => true,
            'offers' => ['cw-stimmgruppe'],
        ]);
    }

    /**
     * Sieben bezahlte Zahlungen der Chorwerkstatt, direkt geschrieben.
     *
     * @return list<array{0: int, 1: string|null}> Zahlung und Partner, der per Link vermittelt hat
     */
    protected function verkaeufe(int $marke): array
    {
        $stimmgruppe = ['offer:cw-stimmgruppe', 'cw-stimmgruppe', 'Workshop für die Stimmgruppe (10 Plätze)', 39000];
        $notenpaket = ['cw-notenpaket', null, 'Notenpaket zum Workshop', 4900];
        $mitglied = ['cw-mitgliedschaft', null, 'Mitgliedschaft Chorwerkstatt', 1900];
        $workshop = ['cw-workshop', null, 'Workshop-Tag vor Ort', 45000];

        // [Tage zurueck, Posten, Gutschein, Kaeufer, Partner per Link]
        $reihen = [
            [48, [$stimmgruppe], null, ['Hanne Kramer', 'hanne.kramer@chor.beispiel'], 'clarabrandt'],
            [41, [$stimmgruppe, [...$notenpaket, 'bump']], null, ['Ole Paulsen', 'ole.paulsen@chor.beispiel'], 'clarabrandt'],
            [36, [$stimmgruppe], 'CLARA10', ['Fenja Lorenz', 'fenja.lorenz@chor.beispiel'], null],
            [6, [$stimmgruppe], null, ['Malte Brix', 'malte.brix@chor.beispiel'], 'jonasweber'],
            [4, [$mitglied], null, ['Svea Thode', 'svea.thode@chor.beispiel'], 'chorverband'],
            [2, [$mitglied], null, ['Imke Rahn', 'imke.rahn@chor.beispiel'], 'clarabrandt'],
            [3, [$workshop], null, ['Bent Iversen', 'bent.iversen@chor.beispiel'], null],
        ];

        $ergebnis = [];

        foreach ($reihen as $n => [$tage, $posten, $gutschein, [$name, $email], $vermittler]) {
            $kennung = 'tr_affdemo_'.($n + 1);
            $id = DB::table('payments')->where('provider_id', $kennung)->value('id');

            if ($id === null) {
                $zeit = Carbon::now()->subDays($tage)->setTime(10 + $n, 15);
                $rabatt = $gutschein !== null ? (int) round($posten[0][3] * 0.10) : 0;
                $summe = array_sum(array_map(fn ($p) => $p[3], $posten)) - $rabatt;

                $id = DB::table('payments')->insertGetId([
                    'brand_id' => $marke,
                    'provider' => 'mollie',
                    'provider_id' => $kennung,
                    'product' => $posten[0][0],
                    'amount_cent' => $summe,
                    'currency' => 'EUR',
                    'status' => 'paid',
                    'email' => $email,
                    'name' => $name,
                    'country' => 'DE',
                    'discount_code' => $gutschein,
                    'discount_cent' => $rabatt ?: null,
                    'meta' => json_encode(['affiliates_demo' => true]),
                    'paid_at' => $zeit,
                    'fulfilled_at' => $zeit,
                    'created_at' => $zeit,
                    'updated_at' => $zeit,
                ]);

                foreach ($posten as $i => $p) {
                    DB::table('payment_items')->insert([
                        'payment_id' => $id,
                        'product' => $p[0],
                        'offer' => $p[1],
                        'name' => $p[2],
                        'amount_cent' => $p[3],
                        'quantity' => 1,
                        'kind' => $p[4] ?? 'primary',
                        'discount_cent' => $i === 0 ? $rabatt : 0,
                        'created_at' => $zeit,
                        'updated_at' => $zeit,
                    ]);
                }
            }

            $ergebnis[] = [(int) $id, $vermittler];
        }

        return $ergebnis;
    }

    /**
     * Ein Frontend-Konto, das der Partnerbereich wiedererkennt. Dasselbe
     * Passwort wie die Kurs-Lernenden aus {@see SeedsCourses}.
     */
    protected function nutzer(string $adresse, string $name): \Statamic\Contracts\Auth\User
    {
        $nutzer = User::findByEmail($adresse);

        if (! $nutzer) {
            $nutzer = User::make()->email($adresse);
            $nutzer->password(self::PASSWORT);
        }

        $nutzer->set('name', $name);
        $nutzer->save();

        return $nutzer;
    }

    /** Zwei Werbemittel und die Seite mit dem Partnerbereich. */
    protected function werbemittel(): void
    {
        $sammlung = (string) config('affiliates.materials.collection', 'affiliate_materials');

        foreach ([
            'mail-workshop' => [
                'title' => 'Mailvorlage: Workshop für die Stimmgruppe',
                'kind' => 'mail',
                'target_url' => '/workshop',
                'copy' => "Hallo,\n\ndie Chorwerkstatt Nord bietet einen Workshop-Tag für ganze Stimmgruppen an, zehn Plätze in einem Kauf.\n\nHier geht es zur Anmeldung: {link}",
            ],
            'post-probenwoche' => [
                'title' => 'Social Post mit Bild',
                'kind' => 'social',
                'target_url' => '/chorwerkstatt/kurse',
                'copy' => 'Probenwoche in der Chorwerkstatt Nord. Kurse und Termine: {link}',
                'image' => 'marken/chorwerkstatt/probenwoche-01.jpg',
            ],
        ] as $slug => $daten) {
            $eintrag = Entry::query()->where('collection', $sammlung)->where('slug', $slug)->first()
                ?? Entry::make()->collection($sammlung)->slug($slug);

            $eintrag->data($daten)->published(true)->save();
        }

        DemoSeiten::seite('partner', [
            'title' => 'Partnerbereich',
            'brand' => 'chorwerkstatt',
            'template' => 'affiliates_demo',
        ], unter: 'chorwerkstatt');
    }
}
