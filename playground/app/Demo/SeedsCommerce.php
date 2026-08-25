<?php

namespace App\Demo;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * What the three clients sell, and every state their money can be in.
 *
 * The catalogue lives in config (a product is not content), so the seeder
 * writes it there and only creates the rows that hang off it: offers, coupons,
 * and a spread of payments and agreements deliberately chosen to include the
 * states a screen usually only meets in production.
 */
class SeedsCommerce
{
    /**
     * The catalogue, ready to be written into `statamic-payments.products`.
     *
     * Every product here is a shape the payment addon claims to handle, and
     * three of them are shapes it should refuse. Keeping the refusals in the
     * demo is deliberate: a catalogue with a typo in it is the ordinary case,
     * and what matters is that the typo cannot be bought.
     *
     * @return array<string, array<string, mixed>>
     */
    public function katalog(): array
    {
        return [
            // ---- Chorwerkstatt Nord -------------------------------------
            'cw-kurs' => ['name' => 'Frühlingskurs für Chorleitende', 'amount_cent' => 24900, 'grants' => 'kurs-fruehling'],
            'cw-begleit-cd' => ['name' => 'Begleit-CD zum Mitsingen', 'amount_cent' => 900, 'grants' => 'begleit-cd'],
            'cw-noten' => ['name' => 'Notenpaket als PDF', 'amount_cent' => 1500, 'grants' => 'noten'],
            'cw-stimmcheck' => ['name' => 'Stimm-Check (kostenlos)', 'amount_cent' => 0, 'grants' => 'stimmcheck'],
            'cw-mitgliedschaft' => [
                'name' => 'Mitgliedschaft Chorwerkstatt',
                'amount_cent' => 1900,
                'interval' => '1 month',
                // Granted per cycle: the entitlement is extended every month by
                // the ordinary payment path, without the bridge knowing that
                // subscriptions exist.
                'grants' => 'mitgliedschaft',
            ],
            'cw-ausbildung' => [
                'name' => 'Chorleiter-Ausbildung, drei Raten',
                'amount_cent' => 39900,
                'interval' => '1 month',
                'times' => 3,
            ],
            'cw-workshop' => ['name' => 'Workshop-Tag vor Ort', 'amount_cent' => 45000],

            // ---- Kollektiv Halbmond -------------------------------------
            'hm-vinyl' => ['name' => 'Halbmond, das Album auf Vinyl', 'amount_cent' => 2900],
            'hm-ticket' => ['name' => 'Konzertticket', 'amount_cent' => 2200],
            'hm-fanclub' => [
                'name' => 'Fanclub Halbmond',
                'amount_cent' => 500,
                'interval' => '1 month',
                'grants' => 'fanclub',
            ],
            'hm-shirt' => ['name' => 'Shirt „Ännchen & Söhne"', 'amount_cent' => 3200],

            // ---- Praxis Lindhorst ---------------------------------------
            'lh-erstgespraech' => ['name' => 'Erstgespräch', 'amount_cent' => 0],
            'lh-fuenferkarte' => ['name' => 'Fünferkarte', 'amount_cent' => 45000],
            'lh-begleitung' => [
                'name' => 'Begleitung, monatlich',
                'amount_cent' => 14900,
                'interval' => '1 month',
                'trial_days' => 14,
                'trial_amount_cent' => 100,
            ],
            'lh-quartal' => [
                'name' => 'Begleitung im Quartalsrhythmus',
                'amount_cent' => 39900,
                'interval' => '3 months',
            ],

            // ---- Was der Katalog ablehnen muss --------------------------
            // Each of these is a mistake somebody makes in a config file, and
            // each must be unsellable rather than cheap.
            'kaputt-negativ' => ['name' => 'Negativ', 'amount_cent' => -500],
            'kaputt-string' => ['name' => 'Als Text getippt', 'amount_cent' => '19,00'],
            'kaputt-ohne-preis' => ['name' => 'Ohne Preis'],
            // Legal, and a shape nobody plans for: a handle with a dot in it.
            // `Arr::get()` would walk into it as a nested key; plain array
            // access is what stops that, and this is what proves it.
            'punkt.im.handle' => ['name' => 'Punkt im Handle', 'amount_cent' => 1234],
        ];
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $angebote = $this->angebote();
        $this->gutscheine();
        $zahlungen = $this->zahlungen();
        $abos = $this->abos();

        return [
            'angebote' => count($angebote),
            'gutscheine' => Coupon::count(),
            'zahlungen' => $zahlungen,
            'abos' => $abos,
        ];
    }

    /** @return array<string, Offer> */
    protected function angebote(): array
    {
        $definitionen = [
            // The ordinary case, with two bumps hanging off it.
            'cw-kurs-angebot' => ['Frühlingskurs', 'cw-kurs', null, Offer::SLOT_STANDALONE, ['cw-cd-bump', 'cw-noten-bump']],
            'cw-cd-bump' => ['Begleit-CD zum Mitsingen', 'cw-begleit-cd', 500, Offer::SLOT_BUMP, []],
            'cw-noten-bump' => ['Das Notenpaket dazu', 'cw-noten', 900, Offer::SLOT_BUMP, []],
            // A post-purchase upsell at a price of its own.
            'cw-upsell' => ['Die Aufnahmen aller vier Abende', 'cw-noten', 1200, Offer::SLOT_POST_PURCHASE, []],
            'hm-vinyl-angebot' => ['Die Platte, signiert', 'hm-vinyl', 3400, Offer::SLOT_STANDALONE, ['hm-shirt-bump']],
            'hm-shirt-bump' => ['Shirt dazu', 'hm-shirt', 2500, Offer::SLOT_BUMP, []],
            'lh-karte-angebot' => ['Fünferkarte', 'lh-fuenferkarte', null, Offer::SLOT_STANDALONE, []],

            // ---- Awkward on purpose ------------------------------------
            // Points at a product that is not in the catalogue. Must be
            // unsellable and must not take the funnel down with it.
            'zeigt-ins-leere' => ['Zeigt ins Leere', 'gibt-es-nicht', 1000, Offer::SLOT_STANDALONE, []],
            // Points at a product the catalogue refuses.
            'zeigt-auf-kaputt' => ['Zeigt auf einen Tippfehler', 'kaputt-negativ', null, Offer::SLOT_STANDALONE, []],
            // Switched off, and listed as a bump elsewhere: the checkbox must
            // quietly stop appearing rather than refusing the whole checkout.
            'stiller-bump' => ['Abgeschalteter Bump', 'cw-noten', 100, Offer::SLOT_BUMP, []],
            // A name that has to survive a page, a mail and a CSV.
            'sonderzeichen' => ['Müller & Söhne <Chor> „Ännchen"', 'cw-begleit-cd', 1, Offer::SLOT_STANDALONE, []],
            // Free. Sellable, and the provider is never asked.
            'gratis-angebot' => ['Der Stimm-Check, kostenlos', 'cw-stimmcheck', null, Offer::SLOT_STANDALONE, []],
            // The handle with a dot in it, reached through an offer.
            'punkt-angebot' => ['Punkt im Handle', 'punkt.im.handle', null, Offer::SLOT_STANDALONE, []],
        ];

        $angebote = [];

        foreach ($definitionen as $handle => [$name, $produkt, $cent, $slot, $bumps]) {
            $angebote[$handle] = Offer::updateOrCreate(['handle' => $handle], [
                'name' => $name,
                'headline' => $name,
                'product' => $produkt,
                'amount_cent' => $cent,
                'slot' => $slot,
                'bumps' => $bumps,
                'active' => $handle !== 'stiller-bump',
                'body' => 'Ein Satz, der erklärt, was dabei ist. Mit Umlauten: Übung, Größe, Maß.',
            ]);
        }

        // A bump list that names a bump which is switched off, plus one that
        // does not exist at all. Both must simply not appear.
        $angebote['cw-kurs-angebot']->update([
            'bumps' => ['cw-cd-bump', 'cw-noten-bump', 'stiller-bump', 'gibt-es-gar-nicht'],
        ]);

        return $angebote;
    }

    protected function gutscheine(): void
    {
        $codes = [
            // Ordinary.
            ['FRUEHLING25', 'Frühlingsaktion', 25, null, null, null, 50, 7],
            ['ZEHNEURO', 'Zehn Euro Nachlass', null, 1000, null, null, null, 3],
            // Every way a code can be dead.
            ['ABGELAUFEN', 'Abgelaufen', 20, null, '2026-01-01', '2026-06-30', null, 12],
            ['NOCHNICHT', 'Startet später', 20, null, '2099-01-01', null, null, 0],
            ['AUFGEBRAUCHT', 'Aufgebraucht', 50, null, null, null, 5, 5],
            // Typos somebody makes in the Control Panel.
            ['UEBERHUNDERT', 'Prozentsatz über 100', 500, null, null, null, null, 0],
            ['ZUVIEL', 'Mehr als der Warenkorb', null, 500000, null, null, null, 0],
            // Only for one offer, so it must do nothing on the others.
            ['NURVINYL', 'Nur für die Platte', 15, null, null, null, null, 1],
            // Characters and case.
            ['früh-ling_25', 'Mit Umlaut und Bindestrich', 10, null, null, null, null, 0],
        ];

        foreach ($codes as [$code, $name, $prozent, $cent, $von, $bis, $max, $benutzt]) {
            Coupon::updateOrCreate(['code' => $code], [
                'name' => $name,
                'percent' => $prozent,
                'amount_cent' => $cent,
                'currency' => $cent ? 'EUR' : null,
                'offers' => $code === 'NURVINYL' ? ['hm-vinyl-angebot'] : [],
                'starts_at' => $von ? Carbon::parse($von) : null,
                'ends_at' => $bis ? Carbon::parse($bis) : null,
                'max_uses' => $max,
                'used_count' => $benutzt,
                'active' => $code !== 'NOCHNICHT' ? true : true,
            ]);
        }
    }

    /**
     * A spread of payments, one per state a screen has to render.
     *
     * Written directly rather than driven through a checkout: these are the
     * *past*, and a demo needs a history that did not all happen in the last
     * five minutes. The live path is exercised separately, against Mollie's
     * test account.
     */
    protected function zahlungen(): int
    {
        $daten = DemoData::awkwardDates();

        $reihen = [
            ['cw-kurs', 24900, Payment::STATUS_PAID, 'bärbel.öztürk@beispiel.de', 'Bärbel Öztürk-Weiß', '-40 days', true],
            ['cw-kurs', 24900, Payment::STATUS_PAID, 'plus+tag@beispiel.de', 'Jean-Luc «Loup» Fabre', '-33 days', true],
            ['hm-vinyl', 2900, Payment::STATUS_PAID, 'a@b.de', 'A', '-21 days', true],
            ['hm-ticket', 2200, Payment::STATUS_OPEN, 'doppelt@beispiel.de', 'Doppelt Eins', '-2 hours', false],
            ['hm-ticket', 2200, Payment::STATUS_FAILED, 'DOPPELT@beispiel.de', 'Doppelt Zwei', '-1 hour', false],
            ['lh-fuenferkarte', 45000, Payment::STATUS_PAID, 'ana.maria@beispiel.de', 'Ana María Ñuñez', '-9 days', true],
            ['cw-workshop', 45000, Payment::STATUS_EXPIRED, 'kein-name@beispiel.de', null, '-15 days', false],
            ['hm-shirt', 3200, Payment::STATUS_CANCELED, 'aegir@beispiel.de', 'Ægir Þórsson', '-6 days', false],
            // Free: paid on the spot, no provider, and it must still be
            // fulfilled like any other.
            ['cw-stimmcheck', 0, Payment::STATUS_PAID, 'gratis@beispiel.de', '🎵 Der Taktstock', '-3 days', true],
            // The oldest one, on a date that breaks arithmetic.
            ['cw-kurs', 24900, Payment::STATUS_PAID, 'alt@beispiel.de', 'Ein Beleg von 1999', $daten['jahrhundert'], true],
            // An amount nobody rounds nicely, with a discount on it.
            ['cw-noten', 1500, Payment::STATUS_PAID, 'krumm@beispiel.de', 'Krummer Betrag', '-12 days', true],
        ];

        $nummer = 0;

        foreach ($reihen as [$produkt, $cent, $status, $email, $name, $wann, $erfuellt]) {
            $nummer++;
            $zeit = str_starts_with($wann, '-') ? Carbon::now()->sub(ltrim($wann, '-')) : Carbon::parse($wann);

            $zahlung = Payment::updateOrCreate(
                ['provider' => $cent === 0 ? 'free' : 'mollie', 'provider_id' => 'demo_tr_'.$nummer],
                [
                    'product' => $produkt,
                    'amount_cent' => $cent,
                    'currency' => 'EUR',
                    'status' => $status,
                    'email' => $email,
                    'name' => $name,
                    'discount_code' => $produkt === 'cw-noten' ? 'ZEHNEURO' : null,
                    'discount_cent' => $produkt === 'cw-noten' ? 1000 : null,
                    'paid_at' => $status === Payment::STATUS_PAID ? $zeit : null,
                    'fulfilled_at' => $erfuellt ? $zeit : null,
                    'created_at' => $zeit,
                    'updated_at' => $zeit,
                ],
            );

            $zahlung->items()->delete();

            PaymentItem::create([
                'payment_id' => $zahlung->getKey(),
                'product' => $produkt,
                'name' => $this->katalog()[$produkt]['name'] ?? $produkt,
                'amount_cent' => $cent,
                'quantity' => 1,
                'kind' => PaymentItem::KIND_PRIMARY,
            ]);

            // One order with two bumps on it, so a line-item report has
            // something with more than one line in it.
            if ($nummer === 1) {
                foreach ([['cw-begleit-cd', 500], ['cw-noten', 900]] as [$h, $c]) {
                    PaymentItem::create([
                        'payment_id' => $zahlung->getKey(),
                        'product' => $h,
                        'name' => $this->katalog()[$h]['name'] ?? $h,
                        'amount_cent' => $c,
                        'quantity' => 1,
                        'kind' => PaymentItem::KIND_BUMP,
                    ]);
                }
            }
        }

        return count($reihen);
    }

    /** Agreements in every state, including the ones that have gone wrong. */
    protected function abos(): int
    {
        $reihen = [
            // Running subscription, three cycles behind it.
            ['cw-mitgliedschaft', 1900, '1 month', null, 3, Subscription::STATUS_ACTIVE, '-3 months', '+1 month'],
            // Payment plan, one of three paid.
            ['cw-ausbildung', 39900, '1 month', 2, 1, Subscription::STATUS_ACTIVE, '-1 month', '+2 days'],
            // Plan that ran to its end.
            ['cw-ausbildung', 39900, '1 month', 2, 2, Subscription::STATUS_COMPLETED, '-5 months', null],
            // Somebody left.
            ['hm-fanclub', 500, '1 month', null, 7, Subscription::STATUS_CANCELLED, '-8 months', null],
            // The provider stopped charging after a card failed. The state a
            // screen usually only meets in production.
            ['hm-fanclub', 500, '1 month', null, 2, Subscription::STATUS_SUSPENDED, '-2 months', null],
            // A trial that has not started charging yet.
            ['lh-begleitung', 14900, '1 month', null, 0, Subscription::STATUS_PENDING, '-3 days', '+11 days'],
            // A quarterly rhythm, so the wording is not always "monthly".
            ['lh-quartal', 39900, '3 months', null, 1, Subscription::STATUS_ACTIVE, '-3 months', '+3 days'],
            // Never confirmed by the provider: the row that should never be
            // there and sometimes is.
            ['cw-mitgliedschaft', 1900, '1 month', null, 0, Subscription::STATUS_INITIATED, '-1 day', null],
        ];

        $nummer = 0;

        foreach ($reihen as [$produkt, $cent, $rhythmus, $mal, $gezahlt, $status, $seit, $naechste]) {
            $nummer++;
            $start = Carbon::now()->sub(ltrim($seit, '-'));

            Subscription::updateOrCreate(
                ['provider' => 'mollie', 'provider_id' => 'demo_sub_'.$nummer],
                [
                    'customer_reference' => 'demo_cst_'.$nummer,
                    'product' => $produkt,
                    'amount_cent' => $cent,
                    'currency' => 'EUR',
                    'interval' => $rhythmus,
                    'times' => $mal,
                    'times_charged' => $gezahlt,
                    'status' => $status,
                    'starts_at' => $start,
                    'next_payment_at' => $naechste ? Carbon::now()->add(ltrim($naechste, '+')) : null,
                    'cancelled_at' => $status === Subscription::STATUS_CANCELLED ? Carbon::now()->subMonth() : null,
                    'ended_at' => in_array($status, [Subscription::STATUS_CANCELLED, Subscription::STATUS_COMPLETED], true)
                        ? Carbon::now()->subMonth()
                        : null,
                    'email' => DemoData::AWKWARD_EMAILS[$nummer % count(DemoData::AWKWARD_EMAILS)],
                    'name' => DemoData::AWKWARD_NAMES[$nummer % count(DemoData::AWKWARD_NAMES)],
                    'created_at' => $start,
                ],
            );
        }

        return count($reihen);
    }
}
