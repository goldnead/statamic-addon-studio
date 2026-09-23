<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Was statamic-offers seit dem ThriveCart-Rundgang kann (O1 bis O7), je einmal
 * echt, unter Chorwerkstatt Nord.
 *
 * - `cw-workshop-spende`: Zahl, was du willst, mit Mindestpreis 10 EUR,
 *   Vorschlag 25 EUR, Dankestext ab 50 EUR (O1), nur in DE/AT/CH (O3) und mit
 *   einer Link-Weiche `/go/workshop-herbst`, die in zehn Tagen von `/workshop`
 *   auf `/warteliste` umschaltet (O5).
 * - `cw-mitglied-monatlich`: Abo mit Aufnahmegebuehr als erster Zahlung (O2).
 * - `CHOR20`: Gutschein fuer die ersten drei Zahlungen, nur aufs Hauptprodukt,
 *   funnelweit, mit vorbelegtem Link und QR-Code (O4, O6).
 * - `cw-stimmgruppe`: zehn Plaetze fuer eine Stimmgruppe (O7), mit einem
 *   gekauften Kontingent, einer angenommenen und einer offenen Einladung.
 *
 * Die Plaetze entstehen ueber den echten Weg: eine bezahlte Zahlung mit dem
 * Angebot als Posten, dann `SeatPools::openFor()`, `invite()` und `accept()`.
 * Damit sind Verwaltungs- und Annahme-Token zufaellig (`Str::random(48)` im
 * Addon) — sie sind die einzige Berechtigung, und die Demo ist oeffentlich.
 * Ein Token aus dem Seeder waere fuer jeden lesbar, der dieses Repo kennt.
 *
 * Die Mails dazu (Kontingent, Einladung) landen im Mail-Log; auf der Demo steht
 * `MAIL_MAILER=log`. Dort steht auch der Annahme-Link zum Vorfuehren.
 *
 * Kein `PaymentPaid` fuer die Kontingent-Zahlung: das Ereignis wuerde Rechnung,
 * Zugaenge und Automationen ausloesen. Die Zahlung ist per `DB::table()`
 * geschrieben und von {@see SeedsInvoices} und {@see SeedsAutomations}
 * ausgeklammert (`demo_seats_%`).
 */
class SeedsOffers
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        $marke = $marken['chorwerkstatt'] ?? Brand::query()->where('handle', 'chorwerkstatt')->firstOrFail();

        $this->seiten();
        $this->angebote((int) $marke->id);
        $this->gutschein();

        $plaetze = BrandContext::runFor($marke, fn () => $this->plaetze((int) $marke->id));

        return [
            'angebote_neu' => Offer::query()->withoutGlobalScopes()->whereIn('handle', ['cw-workshop-spende', 'cw-mitglied-monatlich', 'cw-stimmgruppe'])->count(),
            'plaetze_vergeben' => $plaetze,
        ];
    }

    /** Ziel und Ausweichziel der Link-Weiche. Ohne sie landet `/go/…` auf 404. */
    protected function seiten(): void
    {
        DemoSeiten::seite('workshop', [
            'title' => 'Workshop-Tag vor Ort',
            'brand' => 'chorwerkstatt',
            'content' => "Hierher führt der Kurzlink `/go/workshop-herbst` bis zum Stichtag.\n\n"
                ."Ein Gutschein aus dem Link bleibt in der Adresse stehen (`?coupon=CHOR20`) und wird in der Kasse vorbelegt.\n\n"
                .'Angebot im Control Panel: **Workshop auf Spendenbasis**. Zahl, was du willst, ab 10 EUR, nur aus Deutschland, Österreich und der Schweiz.',
        ]);

        DemoSeiten::seite('warteliste', [
            'title' => 'Warteliste Workshop-Tag',
            'brand' => 'chorwerkstatt',
            'content' => "Hierher führt derselbe Kurzlink nach dem Stichtag oder wenn der Workshop ausverkauft ist.\n\n"
                .'Der Link auf dem Flyer bleibt gleich, nur sein Ziel wechselt.',
        ]);
    }

    protected function angebote(int $marke): void
    {
        // O1 + O3 + O5
        Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => 'cw-workshop-spende'], [
            'brand_id' => $marke,
            'name' => 'Workshop auf Spendenbasis',
            'product' => 'cw-workshop',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'price_mode' => 'pwyw',
            'pwyw_min_cent' => 1000,
            'pwyw_suggested_cent' => 2500,
            'pwyw_max_cent' => 20000,
            'pwyw_thanks' => [
                ['from_cent' => 0, 'text' => 'Danke, dass du dabei bist.'],
                ['from_cent' => 5000, 'text' => 'Danke, du trägst den Workshop für andere mit.'],
            ],
            'country_mode' => 'only',
            'countries' => ['DE', 'AT', 'CH'],
            'link_slug' => 'workshop-herbst',
            'link_target' => '/workshop',
            'link_fallback' => '/warteliste',
            'link_switch_at' => Carbon::now()->addDays(10)->startOfHour(),
        ]);

        // O2
        Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => 'cw-mitglied-monatlich'], [
            'brand_id' => $marke,
            'name' => 'Mitgliedschaft monatlich',
            'product' => 'cw-mitgliedschaft',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'amount_cent' => 2900,
            'interval' => '1 month',
            'setup_fee_cent' => 4900,
            'setup_fee_label' => 'Aufnahmegespräch',
        ]);

        // O7
        Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => 'cw-stimmgruppe'], [
            'brand_id' => $marke,
            'name' => 'Workshop für die Stimmgruppe (10 Plätze)',
            'product' => 'cw-workshop',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'amount_cent' => 39000,
            'seats' => 10,
        ]);
    }

    /** O4 + O6 */
    protected function gutschein(): void
    {
        Coupon::updateOrCreate(['code' => 'CHOR20'], [
            'name' => 'Chorrabatt Herbst',
            'percent' => 20,
            'active' => true,
            'duration' => 'repeating',
            'duration_cycles' => 3,
            'applies_to' => 'main',
            'funnel_wide' => true,
            'link_url' => '/workshop',
            'offers' => ['cw-workshop-spende', 'cw-mitglied-monatlich'],
        ]);
    }

    /**
     * O7: ein gekauftes Kontingent, Sofie hat angenommen, Alex ist eingeladen.
     *
     * @return int angenommene Plaetze
     */
    protected function plaetze(int $marke): int
    {
        $zahlung = $this->kontingentZahlung($marke);
        $dienst = app(SeatPools::class);

        $dienst->openFor($zahlung);

        $pool = SeatPool::query()->where('payment_id', $zahlung->getKey())->where('offer', 'cw-stimmgruppe')->first();

        if ($pool === null) {
            return 0;
        }

        foreach ([
            ['sofie.sopran@example.com', 'Sofie Sopran', true],
            ['alex.alt@example.com', null, false],
        ] as [$adresse, $name, $annehmen]) {
            $platz = Seat::query()->where('pool_id', $pool->getKey())->where('email', $adresse)->first()
                ?? $dienst->invite($pool, $adresse, $name);

            if ($annehmen && $platz->status === Seat::STATUS_INVITED) {
                $dienst->accept($platz);
            }
        }

        return Seat::query()->where('pool_id', $pool->getKey())->where('status', Seat::STATUS_CLAIMED)->count();
    }

    /**
     * Die bezahlte Zahlung, an der das Kontingent haengt. Direkt geschrieben,
     * damit kein Listener feuert; das Kontingent oeffnet `openFor()` selbst.
     */
    protected function kontingentZahlung(int $marke): Payment
    {
        $id = DB::table('payments')->where('provider_id', 'demo_seats_1')->value('id');

        if ($id === null) {
            $zeit = Carbon::now()->subDays(5)->setTime(11, 20);

            $id = DB::table('payments')->insertGetId([
                'brand_id' => $marke,
                'provider' => 'mollie',
                'provider_id' => 'demo_seats_1',
                'product' => 'offer:cw-stimmgruppe',
                'amount_cent' => 39000,
                'currency' => 'EUR',
                'status' => 'paid',
                'email' => 'anna.leitung@example.com',
                'name' => 'Anna Leitung',
                'country' => 'DE',
                'paid_at' => $zeit,
                'fulfilled_at' => $zeit,
                'created_at' => $zeit,
                'updated_at' => $zeit,
            ]);

            DB::table('payment_items')->insert([
                'payment_id' => $id,
                'product' => 'offer:cw-stimmgruppe',
                'offer' => 'cw-stimmgruppe',
                'name' => 'Workshop für die Stimmgruppe (10 Plätze)',
                'amount_cent' => 39000,
                'quantity' => 1,
                'kind' => 'primary',
                'created_at' => $zeit,
                'updated_at' => $zeit,
            ]);
        }

        return Payment::withoutGlobalScopes()->with('items')->findOrFail($id);
    }
}
