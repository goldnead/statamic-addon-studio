<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicConsent\Records\Recorder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Die zwei Spuren, die bisher nur durch Anklicken entstanden sind.
 *
 * Ein Klon-Aufbau hat das sichtbar gemacht: `bookings` und `consent_records`
 * hatten im gepflegten Stand Zeilen, im frisch gebauten null. Sie stammten aus
 * Handarbeit, nicht aus dem Seeder — also aus etwas, das `demo:seed --fresh`
 * ersatzlos wegräumt und niemand vermisst, bis er das Demo neu aufbaut.
 *
 * Beide gehen hier durch den echten Weg des jeweiligen Addons: Buchungen über
 * denselben Recorder, den auch der Cal.com-Webhook ruft, Einwilligungen über
 * denselben Recorder wie der Nachweis-Endpunkt. Ein Seeder, der stattdessen die
 * Zeile schreibt, belegt nur, dass die Tabelle Spalten hat.
 */
class SeedsProof
{
    /** @return array<string, int> */
    public function run(): array
    {
        return [
            'buchungen' => $this->buchungen(),
            'einwilligungen' => $this->einwilligungen(),
        ];
    }

    /**
     * Termine über den Weg, den Cal.com nimmt.
     *
     * Die Zustände sind absichtlich vollständig: gebucht, angefragt, verlegt,
     * abgesagt, abgelehnt. Abgesagt und abgelehnt fehlten dem Demo ganz, dabei
     * ist „die Zeile bleibt stehen und wird durchgestrichen" die eigentliche
     * Aussage des Addons — wer absagt, soll nicht vor einer verschlossenen Tür
     * stehen.
     *
     * Und zwei Termine liegen in der Vergangenheit, sonst findet der Filter
     * „vergangen" nichts und `booking:prune` hätte nie etwas zu tun.
     */
    protected function buchungen(): int
    {
        $recorder = app(BookingRecorder::class);
        $gezaehlt = 0;

        $termine = [
            // [uid, Auslöser, Name, Adresse, wann, Dauer, Ort]
            ['demo-cal-01', 'BOOKING_CREATED', 'Jonas Weber', 'jonas@beispiel.de', '+16 days', 60, 'https://meet.beispiel/lindhorst-1'],
            ['demo-cal-02', 'BOOKING_REQUESTED', 'Bärbel Öztürk-Weiß', 'BÄRBEL.Öztürk@Beispiel.DE', '+18 days', 30, null],
            ['demo-cal-03', 'BOOKING_CREATED', "Sängerin's Ännchen", 'plus+tag@beispiel.de', '+21 days', 45, 'https://meet.beispiel/lindhorst-3'],
            // Die Vergangenheit, damit der Filter beide Hälften hat.
            ['demo-cal-04', 'BOOKING_CREATED', 'Müller & Söhne <Chor>', 'a@b.de', '-9 days', 60, null],
            ['demo-cal-05', 'BOOKING_CREATED', '李 明', 'kein-name@beispiel.de', '-30 days', 90, null],
        ];

        foreach ($termine as [$uid, $ausloeser, $name, $adresse, $wann, $dauer, $ort]) {
            $start = Carbon::parse($wann);

            $ergebnis = $recorder->record('beratung', [
                'triggerEvent' => $ausloeser,
                'payload' => [
                    'uid' => $uid,
                    'title' => 'Erstgespräch',
                    'startTime' => $start->toIso8601String(),
                    'endTime' => $start->copy()->addMinutes($dauer)->toIso8601String(),
                    'attendees' => [['name' => $name, 'email' => $adresse]],
                    'metadata' => $ort ? ['videoCallUrl' => $ort] : [],
                ],
            ]);

            $gezaehlt += $ergebnis ? 1 : 0;
        }

        // Zwei Zustände, die es im Demo gar nicht gab. Sie kommen als eigene
        // Zustellung, genau wie im Betrieb.
        foreach ([['demo-cal-03', 'BOOKING_CANCELLED'], ['demo-cal-02', 'BOOKING_REJECTED']] as [$uid, $ausloeser]) {
            $recorder->record('beratung', ['triggerEvent' => $ausloeser, 'payload' => ['uid' => $uid]]);
        }

        // Und einer, der verlegt wird: derselbe Termin, neue Zeit.
        $recorder->record('beratung', [
            'triggerEvent' => 'BOOKING_RESCHEDULED',
            'payload' => [
                'uid' => 'demo-cal-01',
                'title' => 'Erstgespräch',
                'startTime' => Carbon::parse('+17 days')->setTime(10, 0)->toIso8601String(),
                'endTime' => Carbon::parse('+17 days')->setTime(11, 0)->toIso8601String(),
                'attendees' => [['name' => 'Jonas Weber', 'email' => 'jonas@beispiel.de']],
            ],
        ]);

        return $gezaehlt;
    }

    /**
     * Einwilligungs-Nachweise in allen Formen, die es gibt.
     *
     * Vorher standen dort fünfmal `accept_all` — dieselbe Zeile, fünfmal von
     * Hand geklickt. Artikel 7 Absatz 1 DSGVO verlangt den Nachweis für *jede*
     * Entscheidung, und die interessanten sind die anderen vier.
     */
    protected function einwilligungen(): int
    {
        $recorder = app(Recorder::class);
        $marken = Brand::query()->get()->keyBy('handle');
        $gezaehlt = 0;

        $entscheidungen = [
            ['chorwerkstatt', ['youtube', 'vimeo', 'google_maps'], 'accept_all', '-40 days'],
            ['chorwerkstatt', [], 'necessary_only', '-33 days'],
            ['halbmond', ['youtube'], 'custom', '-21 days'],
            ['halbmond', [], 'reject_all', '-14 days'],
            ['lindhorst', ['google_maps'], 'custom', '-9 days'],
            // Ein Dienst, der erst am Zwei-Klick-Platzhalter freigegeben
            // wurde: der Besucher hat nicht das Banner beantwortet, sondern ein
            // einzelnes Video geladen. Ein eigener Nachweiswert, weil es eine
            // andere Einwilligung ist als „alles erlauben".
            ['lindhorst', ['youtube'], 'gate', '-5 days'],
            // Global Privacy Control: der Browser hat widersprochen, bevor
            // jemand gefragt wurde.
            ['sonderzeichen', [], 'gpc', '-2 days'],
        ];

        foreach ($entscheidungen as $i => [$marke, $erlaubt, $wie, $wann]) {
            if (! $eine = $marken->get($marke)) {
                continue;
            }

            $entschieden = Carbon::parse($wann);

            $keks = json_encode([
                'id' => 'demo-consent-'.$i,
                'v' => 1,
                'granted' => $erlaubt,
                'how' => $wie,
                't' => $entschieden->getTimestamp(),
            ]);

            // Eine echte Anfrage mit echtem Keks: der Recorder liest die
            // Entscheidung von dort und nirgendwo sonst, und genau das ist die
            // CSRF-Abwehr des Endpunkts.
            $anfrage = Request::create('/!/statamic-consent/record', 'POST');
            $anfrage->cookies->set(
                (string) config('statamic-consent.cookie.name', 'statamic_consent'),
                $keks,
            );

            $ergebnis = BrandContext::runFor($eine, fn () => $recorder->record($anfrage));
            $gezaehlt += $ergebnis ? 1 : 0;
        }

        return $gezaehlt;
    }
}
