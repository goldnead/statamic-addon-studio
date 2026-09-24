<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicFunnels\Events\UpsellDeclined;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Die neuen Ausloeser aus statamic-automations A1 und A2, je einmal verdrahtet.
 *
 * Zehn Ablaeufe unter Chorwerkstatt, jeder mit genau einem Log-Knoten statt
 * einer Mail: die Demo ist offen, und ein Ablauf, der auf ein Ereignis hin
 * jemandem schreibt, schriebe an Adressen aus den Seeds. Der Log-Knoten zeigt
 * dieselben Variablen und macht im Durchlauf sichtbar, dass sie aufloesen.
 * Alle Ausloeser `sync`, damit ein Durchlauf ohne Queue-Worker entsteht.
 *
 * Drei Ereignisse werden danach echt geworfen, **mit gesetzter Marke**:
 * Automationen haben einen Markenfilter, und ohne Marke findet ein Ereignis
 * aus der Konsole keinen Ablauf (fail-closed). `SubscriptionPaused` am
 * pausierten Abo aus {@see SeedsAbos}, `LearnerEnrolled` fuer Mo aus
 * {@see SeedsCourses}, `UpsellDeclined` im Funnel aus {@see SeedsSuiteKasse}.
 *
 * Der Partner-Ablauf steht nur, weil statamic-affiliates installiert ist.
 * Wiederholbar: Ablaeufe ueber den Handle, eigene Durchlaeufe werden vor dem
 * Werfen geloescht.
 */
class SeedsSuiteAutomations extends SeedsAutomations
{
    /** @return array<string, int> */
    public function run(): array
    {
        $this->ablaeufe();
        $this->laeufeAufraeumen();
        $this->durchlaeufe();

        return [
            'suite_ablaeufe' => BrandContext::withoutBrandScope(fn () => Automation::query()->whereIn('handle', $this->eigene)->count()),
            'suite_durchlaeufe' => BrandContext::withoutBrandScope(fn () => AutomationRun::query()
                ->whereIn('automation_id', Automation::query()->whereIn('handle', $this->eigene)->pluck('id'))->count()),
        ];
    }

    protected function ablaeufe(): void
    {
        $kurs = Entry::query()->where('collection', 'courses')->where('slug', 'einsingen-leiten')->first()?->id();

        $ablaeufe = [
            // Filter aufs Produkt, nicht aufs Angebot `sk-workshop` wie in der
            // Vorlage: die pausierbaren Demo-Abos sind Mitgliedschaften, ein
            // Angebotsfilter liesse den Durchlauf unten nie entstehen. Den
            // Angebots-/Zahlweisen-Filter (A2) zeigt `nur-zahlweise-team`.
            ['abo-pausiert', 'Abo pausiert: Rückkehr ankündigen', 'payments.subscription_paused', ['product' => 'cw-mitgliedschaft'],
                'Pausiert: {{ subscription.name }}, zurück am {{ resumes_at }}, von {{ by }}.'],
            ['abbuchung-naht', 'Abbuchung naht: Erinnerung', 'payments.subscription_payment_upcoming', [],
                'Abbuchung am {{ due_at }} über {{ subscription.amount_cent }} Cent.'],
            ['dritter-fehlversuch', 'Dritter Fehlversuch: persönlich melden', 'payments.subscription_attempt_failed', ['attempt' => 3],
                'Fehlversuch {{ attempt }} bei {{ subscription.email }}: anrufen.'],
            ['raten-komplett', 'Raten komplett: Danke', 'payments.subscription_plan_completed', [],
                'Alle Raten bezahlt für {{ subscription.product }}.'],
            ['nur-zahlweise-team', 'Workshop für die Stimmgruppe gekauft', 'payments.paid', ['pricing_option' => 'offer:sk-workshop:team'],
                'Stimmgruppe gebucht: {{ payment.product }}.'],
            ['kurs-willkommen', 'Eingeschrieben: Willkommen im Kurs', 'courses.learner_enrolled', array_filter(['course' => $kurs]),
                'Willkommen {{ user.name }} ({{ user.email }}) im Kurs {{ course.title }}.'],
            ['quiz-hilfe', 'Quiz nicht bestanden: Hilfe anbieten', 'courses.quiz_failed', [],
                'Quiz {{ lesson.slug }} mit {{ quiz.score }} Punkten nicht bestanden.'],
            ['team-einladung', 'Teamplatz vergeben: Einladung', 'courses.team_member_added', [],
                'Einladung an {{ member.email }} von {{ owner.name }}.'],
            ['upsell-nach-kauf', 'Upsell nach Kauf abgelehnt: Aufnahmen anbieten', 'funnels.upsell_declined', ['offer' => 'sk-spende'],
                'Abgelehnt: {{ step.offer }} nach {{ payment.product }}, an {{ email }}.'],
        ];

        // Suite-Nachtrag 24.09.2026 (automations 2.21): Momente aus offers.
        $ablaeufe[] = ['platz-angenommen', 'Platz angenommen: Willkommen im Workshop', 'offers.seat_accepted', [],
            'Platz angenommen von {{ seat.email }} ({{ seat.name }}), Kontingent {{ pool.taken }} von {{ pool.seats }}.'];

        if (class_exists(\Goldnead\Affiliates\ServiceProvider::class)) {
            $ablaeufe[] = ['partner-freigegeben', 'Partner freigegeben: Code schicken', 'affiliates.partner_approved', [],
                'Freigegeben: {{ partner.name }}, Code {{ partner.code }}.'];
        }

        foreach ($ablaeufe as [$handle, $name, $ausloeser, $filter, $nachricht]) {
            $this->automation('chorwerkstatt', $handle, $name, true,
                'Suite-Runde 23.09.2026: Auslöser '.$ausloeser.', Log statt Mail.',
                [
                    $this->knoten('ausloeser', $ausloeser, $name, 0, 0, [...$filter, '_dispatch_mode' => 'sync']),
                    $this->knoten('log', 'add_log_entry', 'Vermerken', 300, 0, ['level' => 'info', 'message' => $nachricht]),
                ],
                [['ausloeser', 'log']],
            );
        }
    }

    protected function durchlaeufe(): void
    {
        $marke = Brand::query()->where('handle', 'chorwerkstatt')->first();

        if ($marke === null) {
            return;
        }

        BrandContext::runFor($marke, function () use ($marke) {
            $abo = Subscription::withoutGlobalScopes()
                ->where('provider_id', 'like', 'demo_abo_%')
                ->where('status', 'paused')
                ->orderBy('id')
                ->first();

            if ($abo !== null) {
                event(new SubscriptionPaused($abo, $abo->resumes_at ?? Carbon::now()->addWeeks(5), 'portal'));
            }

            $mo = User::findByEmail(SeedsCourses::PAUSIERT[0]);
            $kurs = Entry::query()->where('collection', 'courses')->where('slug', 'einsingen-leiten')->first();

            if ($mo && $kurs) {
                event(new LearnerEnrolled((string) $mo->id(), (string) $kurs->id(), 'einsingen-leiten', (int) $marke->id));
            }

            $funnel = Funnel::query()->where('handle', 'suite-kasse')->first();
            $schritt = $funnel?->steps()->where('node_key', 'spende_1')->first();
            $besuch = $funnel?->visits()->orderBy('id')->first();

            if ($schritt && $besuch) {
                event(new UpsellDeclined($besuch, $schritt, 'sk-spende', null));
            }

            // Ein dritter Platz im Kontingent aus SeedsOffers, eingeladen und
            // angenommen, nachdem der Ablauf steht: das echte SeatAccepted
            // startet ihn (und den Ausgang aus SeedsSuiteWebhooks).
            $pool = \Goldnead\StatamicOffers\Models\SeatPool::query()->where('offer', 'cw-stimmgruppe')->orderBy('id')->first();

            if ($pool !== null && ! $pool->seatRows()->where('email', 'tom.tenor@example.com')->exists()) {
                $dienst = app(\Goldnead\StatamicOffers\Support\SeatPools::class);
                $dienst->accept($dienst->invite($pool, 'tom.tenor@example.com', 'Tom Tenor'));
            }
        });
    }
}
