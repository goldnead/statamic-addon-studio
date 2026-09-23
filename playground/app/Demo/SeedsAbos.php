<?php

namespace App\Demo;

use Goldnead\BrandContext\Models\Brand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abo-Verlauf fuer statamic-insights (I1: MRR, ARR, Churn, Retention, Prognose,
 * faellige Abbuchungen).
 *
 * Die 315 Zahlungen aus {@see SeedsInsights} sind alle Einmalkaeufe, und die
 * acht Abos aus {@see SeedsCommerce} sind Zustaende, kein Verlauf: keines hat
 * Zyklen in `payments`. Abo-Kennzahlen haetten damit nichts zu zeigen. Hier
 * stehen deshalb 58 Abos der Chorwerkstatt ueber achtzehn Monate, mit rund
 * 289 bezahlten Zyklen: Wachstum, Kuendigungen, eine Preiserhoehung
 * (Expansion), eine Senkung als Wechsel im Kundenkonto (Kontraktion,
 * payments P2), Jahrespaesse, Franken, Pausen mit und ohne Rueckkehr (P1),
 * ein ausgesetztes Abo, zwei Testphasen, drei Ratenplaene und ein Abo mit
 * Gutschein auf den ersten drei Zyklen (offers O6).
 *
 * **Per `DB::table()`, nicht ueber die Modelle.** Ueber das Modell feuerten fuer
 * 286 Zyklen die Listener der Nachbarn: Rechnungen, Zugaenge, Mails. Aus
 * demselben Grund klammern {@see SeedsInvoices} und {@see SeedsAutomations}
 * `demo_abo_%` aus, wie schon die Menge (`demo_ins_%`).
 *
 * Deterministisch (`mt_srand`), idempotent ueber `provider_id like 'demo_abo_%'`:
 * ein zweiter Lauf loescht den ersten und schreibt dieselbe Geschichte neu,
 * relativ zu heute. Vorlage: `demo-insights-seed-abos.php` des I1-Baus
 * (GoldnerOS, TASKS/thrivecart-suite-bau-2026-09-23/).
 */
class SeedsAbos
{
    public const SAAT = 20260923;

    protected const NAMEN = ['Ana Nuñez', 'Bärbel Öztürk', 'Jens Taube', 'Mira Holm', 'Lukas Brandt', 'Sophie Keller', 'Tarek Aziz',
        'Greta Lind', 'Paul Weiss', 'Ida Sommer', 'Noah Berg', 'Lea Fink', 'Emil Roth', 'Hanna Voss', 'Jonas Kraft',
        'Clara Wolf', 'Felix Stern', 'Marie Lange', 'Ole Hansen', 'Zoe Richter'];

    protected int $nummer = 0;

    protected int $marke = 0;

    protected Carbon $jetzt;

    protected bool $mitPause = false;

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        mt_srand(self::SAAT);

        $this->marke = (int) (($marken['chorwerkstatt'] ?? null)?->id
            ?? Brand::query()->where('handle', 'chorwerkstatt')->value('id'));
        $this->jetzt = Carbon::now();
        $this->nummer = 0;
        // statamic-payments P1 (Pause und Fortsetzen) bringt `paused_at` und
        // `resumes_at`. Auf einem Stand ohne die Migration bleibt es beim
        // Status allein, und insights zaehlt das Abo trotzdem als pausiert.
        $this->mitPause = Schema::hasColumn('subscriptions', 'paused_at');

        $this->aufraeumen();

        $this->mitgliedschaften();
        $this->preiswechsel();
        $this->jahrespaesse();
        $this->franken();
        $this->pausiert();
        $this->ausgesetzt();
        $this->testphase();
        $this->raten();
        $this->zurueckAusDerPause();
        $this->mitGutschein();

        return [
            'abo_verlauf' => DB::table('subscriptions')->where('provider_id', 'like', 'demo_abo_%')->count(),
            'abo_zyklen' => DB::table('payments')->where('provider_id', 'like', 'demo_abo_%')->count(),
        ];
    }

    protected function aufraeumen(): void
    {
        $alt = DB::table('subscriptions')->where('provider_id', 'like', 'demo_abo_%')->pluck('id');

        DB::table('payments')->whereIn('subscription_id', $alt)->delete();
        DB::table('payments')->where('provider_id', 'like', 'demo_abo_%')->delete();
        DB::table('subscriptions')->whereIn('id', $alt)->delete();
    }

    /** 1. Mitgliedschaft 19 EUR im Monat: 34 Abos ueber 18 Monate, mehr in juengeren Monaten. */
    protected function mitgliedschaften(): void
    {
        for ($i = 0; $i < 34; $i++) {
            $monateZurueck = (int) floor(17 * (1 - sqrt(mt_rand(0, 1000) / 1000)));
            $start = $this->jetzt->copy()->subMonthsNoOverflow($monateZurueck)->startOfMonth()
                ->addDays(mt_rand(0, 26))->setTime(mt_rand(8, 21), mt_rand(0, 59));

            if ($start->gt($this->jetzt)) {
                $start = $this->jetzt->copy()->subDays(2);
            }

            $wurf = mt_rand(1, 100);
            $stop = null;
            $status = 'active';

            if ($wurf <= 22 && $monateZurueck >= 2) {
                $status = 'cancelled';
                $stop = $start->copy()->addMonthsNoOverflow(mt_rand(1, max(1, $monateZurueck - 1)))->addDays(mt_rand(0, 20));

                if ($stop->gt($this->jetzt)) {
                    $stop = $this->jetzt->copy()->subDays(3);
                }
            }

            $this->abo(['product' => 'cw-mitgliedschaft', 'amount' => 1900, 'start' => $start, 'status' => $status, 'stop' => $stop]);
        }
    }

    /** 2. Preis erhoeht (Expansion) und einmal gesenkt (Kontraktion). */
    protected function preiswechsel(): void
    {
        foreach ([14, 11, 9] as $m) {
            $this->abo(['product' => 'cw-mitgliedschaft-plus', 'prices' => [[0, 1900], [4, 2400]], 'start' => $this->jetzt->copy()->subMonthsNoOverflow($m)->addDays(3)]);
        }

        // Die Kontraktion ist ein Wechsel im Kundenkonto (payments P2): von
        // Plus auf die normale Mitgliedschaft, ohne Anrechnung, zum nächsten
        // Termin. Dazu eine Karte, die in sechs Wochen abläuft (P3).
        $beginn = $this->jetzt->copy()->subMonthsNoOverflow(8)->addDays(5);
        $id = $this->abo(['product' => 'cw-mitgliedschaft', 'prices' => [[0, 2400], [3, 1900]], 'start' => $beginn]);

        DB::table('subscriptions')->where('id', $id)->update(['meta' => json_encode(['switches' => [[
            'from' => 'cw-mitgliedschaft-plus',
            'to' => 'cw-mitgliedschaft',
            'from_amount_cent' => 2400,
            'to_amount_cent' => 1900,
            'proration_cent' => 0,
            'proration_payment_id' => null,
            'immediate' => false,
            'by' => 'portal',
            'at' => $beginn->copy()->addMonthsNoOverflow(2)->addDays(20)->toIso8601String(),
        ]]])]);

        if ($this->mitPause) {
            DB::table('subscriptions')->where('id', $id)->update([
                'card_expires_at' => $this->jetzt->copy()->addWeeks(6)->endOfMonth()->toDateString(),
                'card_checked_at' => $this->jetzt->copy()->subDay(),
            ]);
        }
    }

    /**
     * 10. Gutschein auf Folgezahlungen (offers O6, payments): `CHOR20` gilt
     * drei Zyklen, zwei sind bezahlt, der dritte kommt noch rabattiert.
     */
    protected function mitGutschein(): void
    {
        $id = $this->abo([
            'product' => 'cw-mitgliedschaft', 'amount' => 1900,
            'start' => $this->jetzt->copy()->subMonthsNoOverflow(1)->subDays(3),
            'rabatt' => ['CHOR20', 380, 3],
        ]);

        DB::table('subscriptions')->where('id', $id)->update(['meta' => json_encode(['coupon' => [
            'code' => 'CHOR20', 'percent' => 20, 'amount_cent' => null, 'currency' => null,
            'duration' => 'repeating', 'cycles' => 3, 'current_discount_cent' => 380,
        ]])]);
    }

    /** 3. Jahrespass, 180 EUR im Jahr. */
    protected function jahrespaesse(): void
    {
        foreach ([16, 10, 4, 1] as $m) {
            $this->abo(['product' => 'cw-jahrespass', 'amount' => 18000, 'interval' => '1 year', 'start' => $this->jetzt->copy()->subMonthsNoOverflow($m)->addDays(2)]);
        }
    }

    /** 4. Franken: fuenf Mitgliedschaften zu 20 CHF, eine gekuendigt. */
    protected function franken(): void
    {
        foreach ([12, 9, 6, 3, 1] as $j => $m) {
            $start = $this->jetzt->copy()->subMonthsNoOverflow($m)->addDays(6);

            $this->abo([
                'product' => 'cw-mitgliedschaft', 'amount' => 2000, 'currency' => 'CHF', 'start' => $start,
                'status' => $j === 1 ? 'cancelled' : 'active',
                'stop' => $j === 1 ? $start->copy()->addMonthsNoOverflow(4)->addDays(2) : null,
            ]);
        }
    }

    /** 5. Pausiert, mit dem Status, den statamic-payments P1 schreibt. */
    protected function pausiert(): void
    {
        // Das erste pausiert im Kundenkonto, mit Rückkehrtermin und einer
        // früheren Pause im Verlauf; das zweite im CP, ohne Termin („bis
        // jemand fortsetzt"). Die Form von `meta.pause` ist die, die
        // `SubscriptionPauses::completePause()` schreibt.
        foreach ([[10, 'portal', 5], [7, 'cp', null]] as [$m, $wer, $wochen]) {
            $start = $this->jetzt->copy()->subMonthsNoOverflow($m)->addDays(1);
            $seit = $this->jetzt->copy()->subDays(12 + $m);

            $id = $this->abo(['product' => 'cw-mitgliedschaft', 'amount' => 1900, 'start' => $start, 'status' => 'paused', 'stop' => $seit]);

            $meta = ['pause' => [
                'mode' => 'recreate',
                'provider_id' => 'demo_abo_'.$this->nummer,
                'next_payment_at' => $seit->copy()->addDays(9)->toIso8601String(),
                'by' => $wer,
                'at' => $seit->toIso8601String(),
            ]];

            if ($wer === 'portal') {
                $meta['pauses'] = [[
                    'paused_at' => $start->copy()->addMonthsNoOverflow(2)->toIso8601String(),
                    'resumed_at' => $start->copy()->addMonthsNoOverflow(3)->toIso8601String(),
                    'mode' => 'recreate',
                    'by' => 'cp',
                ]];
            }

            DB::table('subscriptions')->where('id', $id)->update(['meta' => json_encode($meta)]);

            if ($this->mitPause) {
                DB::table('subscriptions')->where('id', $id)->update([
                    'paused_at' => $seit,
                    'resumes_at' => $wochen === null ? null : $this->jetzt->copy()->addWeeks($wochen)->startOfDay(),
                ]);
            }
        }
    }

    /** 6. Ausgesetzt nach Fehlversuch. */
    protected function ausgesetzt(): void
    {
        $this->abo(['product' => 'cw-mitgliedschaft', 'amount' => 1900, 'start' => $this->jetzt->copy()->subMonthsNoOverflow(6)->addDays(4), 'status' => 'suspended', 'stop' => $this->jetzt->copy()->subDays(9)]);
    }

    /** 7. Testphase: beginnt in fuenf bzw. neun Tagen, nichts bezahlt. */
    protected function testphase(): void
    {
        foreach ([5, 9] as $tage) {
            $beginn = $this->jetzt->copy()->addDays($tage);
            $this->abo(['product' => 'cw-mitgliedschaft', 'amount' => 1900, 'start' => $beginn, 'status' => 'pending', 'starts_at' => $beginn, 'next' => $beginn]);
        }
    }

    /** 8. Ratenzahlungen Ausbildung, 3 x 399 EUR: eine fertig, zwei laufen. */
    protected function raten(): void
    {
        $this->abo(['product' => 'cw-ausbildung', 'amount' => 39900, 'times' => 3, 'start' => $this->jetzt->copy()->subMonthsNoOverflow(7), 'status' => 'completed', 'stop' => $this->jetzt->copy()->subMonthsNoOverflow(5)]);
        $this->abo(['product' => 'cw-ausbildung', 'amount' => 39900, 'times' => 3, 'start' => $this->jetzt->copy()->subMonthsNoOverflow(1)->addDays(3)]);
        $this->abo(['product' => 'cw-ausbildung', 'amount' => 39900, 'times' => 3, 'start' => $this->jetzt->copy()->subDays(10)]);
    }

    /**
     * 9. Pausiert und zurueckgekehrt: Historie in `meta.pauses`, wie payments P1
     * sie beim Fortsetzen schreibt. In der Pause wurde nichts abgebucht.
     */
    protected function zurueckAusDerPause(): void
    {
        foreach ([[9, 4, 2], [12, 6, 3]] as [$m, $pauseVor, $dauer]) {
            $id = $this->abo(['product' => 'cw-mitgliedschaft', 'amount' => 1900, 'start' => $this->jetzt->copy()->subMonthsNoOverflow($m)->addDays(2)]);
            $von = $this->jetzt->copy()->subMonthsNoOverflow($pauseVor)->setTime(10, 0);
            $bis = $von->copy()->addMonthsNoOverflow($dauer);

            DB::table('subscriptions')->where('id', $id)->update(['meta' => json_encode(['pauses' => [[
                'paused_at' => $von->toIso8601String(), 'resumed_at' => $bis->toIso8601String(), 'mode' => 'recreate', 'by' => 'portal',
            ]]])]);

            DB::table('payments')->where('subscription_id', $id)->whereBetween('paid_at', [$von, $bis])->delete();
            DB::table('subscriptions')->where('id', $id)->update([
                'times_charged' => DB::table('payments')->where('subscription_id', $id)->count(),
            ]);
        }
    }

    /**
     * Ein Abo samt seinen bezahlten Zyklen. Der erste Zyklus ist der Checkout,
     * `starts_at` liegt einen Rhythmus danach (so schreibt es
     * `Subscriptions::startFromPayment()`).
     *
     * @param  array<string, mixed>  $a
     */
    protected function abo(array $a): int
    {
        $this->nummer++;
        $nummer = $this->nummer;
        $name = $a['name'] ?? self::NAMEN[$nummer % count(self::NAMEN)].' '.$nummer;
        $email = $a['email'] ?? 'abo'.$nummer.'@beispiel.de';
        /** @var Carbon $start */
        $start = $a['start'];
        $rhythmus = $a['interval'] ?? '1 month';
        $stop = $a['stop'] ?? null;
        $status = $a['status'] ?? 'active';
        $preise = $a['prices'] ?? [[0, $a['amount']]];
        $mal = $a['times'] ?? null;

        $zyklen = [];
        $wann = $start->copy();
        $k = 0;

        while ($wann->lte($this->jetzt) && ($stop === null || $wann->lt($stop)) && ($mal === null || $k < $mal)) {
            $betrag = $preise[0][1];

            foreach ($preise as [$ab, $b]) {
                if ($k >= $ab) {
                    $betrag = $b;
                }
            }

            $zyklen[] = [$wann->copy(), $betrag];
            $k++;
            $wann = str_contains($rhythmus, 'year')
                ? $wann->copy()->addYearNoOverflow()
                : $wann->copy()->addMonthsNoOverflow((int) $rhythmus);
        }

        $laeuft = in_array($status, ['active', 'pending'], true);
        $letzterPreis = $zyklen === [] ? $preise[0][1] : end($zyklen)[1];

        if (isset($a['rabatt']) && count($zyklen) < $a['rabatt'][2]) {
            // Der naechste Zyklus ist noch rabattiert: das Abo sagt, was es
            // als naechstes abbucht.
            $letzterPreis -= $a['rabatt'][1];
        }
        $waehrung = $a['currency'] ?? 'EUR';

        $id = DB::table('subscriptions')->insertGetId([
            'brand_id' => $this->marke,
            'provider' => 'mollie',
            'provider_id' => 'demo_abo_'.$nummer,
            'customer_reference' => 'demo_abo_cst_'.$nummer,
            'product' => $a['product'],
            'amount_cent' => $letzterPreis,
            'currency' => $waehrung,
            'interval' => $rhythmus,
            'times' => $mal,
            'times_charged' => count($zyklen),
            'status' => $status,
            'starts_at' => $a['starts_at'] ?? ($zyklen === [] ? $start : ($zyklen[1][0] ?? $wann)),
            'next_payment_at' => $laeuft && ($mal === null || count($zyklen) < $mal) ? ($a['next'] ?? $wann) : null,
            'cancelled_at' => $status === 'cancelled' ? $stop : null,
            'ended_at' => in_array($status, ['cancelled', 'completed', 'suspended'], true) ? $stop : null,
            'dunning_started_at' => $status === 'suspended' ? $stop : null,
            'email' => $email,
            'name' => $name,
            'created_at' => $start,
            'updated_at' => $stop ?? $start,
        ]);

        [$code, $nachlass, $rabattZyklen] = $a['rabatt'] ?? [null, 0, 0];

        foreach ($zyklen as $i => [$bezahlt, $betrag]) {
            $rabattiert = $code !== null && $i < $rabattZyklen;

            DB::table('payments')->insert([
                'discount_code' => $rabattiert ? $code : null,
                'discount_cent' => $rabattiert ? $nachlass : null,
                'brand_id' => $this->marke,
                'provider' => 'mollie',
                'provider_id' => 'demo_abo_'.$nummer.'_'.$i,
                'product' => $a['product'],
                'amount_cent' => $rabattiert ? $betrag - $nachlass : $betrag,
                'currency' => $waehrung,
                'status' => 'paid',
                'email' => $email,
                'name' => $name,
                'subscription_id' => $id,
                'paid_at' => $bezahlt,
                'fulfilled_at' => $bezahlt,
                'created_at' => $bezahlt,
                'updated_at' => $bezahlt,
            ]);
        }

        return $id;
    }
}
