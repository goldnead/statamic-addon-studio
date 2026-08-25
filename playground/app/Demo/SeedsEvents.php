<?php

namespace App\Demo;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Events\Exceptions\InvalidOccurrenceWindow;
use Goldnead\Events\Exceptions\UnlocatableOccurrence;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Ramsey\Uuid\Uuid;

/**
 * Termine: eine Tour, ein Kurs, eine Sprechstunde, und alles, was daran schiefgeht.
 *
 * Ein Ereignis und seine Termine sind zwei Dinge, und dieses Addon ist das
 * einzige der Familie, das darauf besteht. Deshalb ist hier nicht die Menge
 * interessant, sondern die Spreizung: eine Tour, die in zwei Zeitzonen spielt,
 * ein Termin über zwei ganze Tage, einer der abgesagt ist statt gelöscht, einer
 * der nur online stattfindet, ein Entwurf, ein ungelisteter und ein privater.
 * Wer eine dieser Zeilen falsch behandelt, sieht es hier und nicht beim Kunden.
 *
 * ## Warum nichts hier `Model::create()` allein benutzt
 *
 * Das Addon feuert seine vier Domänen-Ereignisse aus den Modell-Haken heraus,
 * aber nur auf **Übergängen**: `EventPublished` beim Wechsel des Status,
 * `OccurrenceRescheduled` in `reschedule()`, `OccurrenceCancelled` in
 * `cancel()`. Ein Seeder, der eine Zeile fertig hinschreibt — veröffentlicht,
 * abgesagt, verschoben — legt genau die Daten an, die man sehen will, und keins
 * der Ereignisse feuert. Die Brücke zum Aktivitätslog bleibt leer, und das Demo
 * behauptet eine Verbindung, die es nie gefahren hat.
 *
 * Deshalb wird hier jedes Ereignis als Entwurf angelegt und dann über
 * `publish()` veröffentlicht, jede Absage über `cancel()` gefahren und jede
 * Verschiebung über `reschedule()`.
 *
 * ## Warum das zweimal laufen darf
 *
 * Jeder Termin trägt eine aus seinem Demo-Schlüssel abgeleitete UUID, nicht eine
 * zufällige. Damit findet der zweite Lauf dieselbe Zeile wieder statt eine neue
 * anzulegen — und weil `publish()`, `cancel()` und `reschedule()` allesamt
 * no-ops sind, wenn sich nichts ändert, wächst der Aktivitätslog beim zweiten
 * Lauf nicht. Das ist der eigentliche Test: dieselben Daten, keine zweite
 * Ansage an Leute, die den Termin längst im Kalender haben.
 */
class SeedsEvents
{
    /**
     * Was das Addon abgelehnt hat, im Wortlaut.
     *
     * Eine abgelehnte Zeile ist hier ein Ergebnis, kein Fehler: die Regel „jeder
     * Termin braucht einen Ort" steht in der Anleitung, und eine Regel, die nur
     * in der Anleitung steht, ist keine.
     *
     * @var list<string>
     */
    protected array $abgelehnt = [];

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, mixed>
     */
    public function run(array $marken): array
    {
        // Ohne gesetzte Marke stempelt HasBrand die Vorgabemarke auf jede Zeile
        // und der Lesescope findet später nichts. `runFor` setzt sie für den
        // Block und stellt danach den vorherigen Stand wieder her.
        BrandContext::runFor($marken['halbmond'], fn () => $this->halbmond());
        BrandContext::runFor($marken['chorwerkstatt'], fn () => $this->chorwerkstatt());
        BrandContext::runFor($marken['lindhorst'], fn () => $this->lindhorst());

        return [
            'ereignisse' => Event::acrossBrands()->count(),
            'termine' => Occurrence::acrossBrands()->count(),
            'termine_abgelehnt' => count($this->abgelehnt),
            '_ablehnungen' => $this->abgelehnt,
        ];
    }

    /**
     * Die Tour: fünf Abende, drei Städte, zwei Zeitzonen, eine Absage.
     *
     * Zwei Sachen sind hier absichtlich unbequem. Die beiden Abende in
     * Reykjavík überschreiben die Zeitzone des Ereignisses — 21:00 heißt dort
     * 21:00 und nicht 21:00 in Bochum, und genau dafür gibt es die Spalte. Und
     * Köln ist abgesagt statt gelöscht: wer den Termin schon im Telefon hat,
     * erfährt es nur, wenn dieselbe UID noch einmal kommt.
     */
    protected function halbmond(): void
    {
        $tour = $this->ereignis('tiefdruck-tour', 'Tiefdruck, die Tour', [
            'type' => 'concert',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => "Fünf Abende zur dritten Platte.\nEinlass eine Stunde vorher; Karten an der Abendkasse nur, wenn der Vorverkauf nicht reicht.",
        ]);

        // Der erste Abend wurde nach dem Ankündigen um eine Stunde geschoben.
        // `firstOrCreate` statt `updateOrCreate`, weil ein zweiter Lauf die
        // Zeile sonst auf 19:00 zurückschriebe und `reschedule()` jedes Mal
        // erneut feuern würde — eine zweite Ansage für eine Verschiebung, die
        // längst passiert ist.
        $bochum = Occurrence::firstOrCreate(
            ['uuid' => $this->uuid('hm-tour-1')],
            [
                'event_id' => $tour->id,
                'starts_at' => $this->zeit('2026-10-02 19:00', 'Europe/Berlin'),
                'ends_at' => $this->zeit('2026-10-02 22:00', 'Europe/Berlin'),
                'venue_name' => 'Rotunde',
                'venue_address' => 'Konrad-Adenauer-Platz 3',
                'venue_city' => 'Bochum',
                'venue_country' => 'DE',
            ],
        );

        $bochum->reschedule(
            $this->zeit('2026-10-02 20:00', 'Europe/Berlin'),
            $this->zeit('2026-10-02 23:00', 'Europe/Berlin'),
        );

        $this->termin($tour, 'hm-tour-2', [
            'starts_at' => $this->zeit('2026-10-03 20:00', 'Europe/Berlin'),
            'ends_at' => $this->zeit('2026-10-03 23:00', 'Europe/Berlin'),
            'venue_name' => 'Rotunde',
            'venue_address' => 'Konrad-Adenauer-Platz 3',
            'venue_city' => 'Bochum',
            'venue_country' => 'DE',
        ]);

        $koeln = $this->termin($tour, 'hm-tour-3', [
            'starts_at' => $this->zeit('2026-10-09 20:30', 'Europe/Berlin'),
            'ends_at' => $this->zeit('2026-10-09 23:30', 'Europe/Berlin'),
            'venue_name' => 'Sonic Ballroom',
            'venue_address' => 'Oskar-Jäger-Straße 190',
            'venue_city' => 'Köln',
            'venue_country' => 'DE',
        ]);

        // Absagen, nicht löschen. Beim zweiten Lauf ein no-op.
        $koeln->cancel('Wasserschaden im Saal. Ersatztermin am 12.02.2027, Karten behalten ihre Gültigkeit.');

        // Zwei Abende auf Island. Die Zeitzone steht am Termin, nicht am
        // Ereignis: eine Tour spielt an mehr als einem Ort, und 21:00 gilt dort,
        // wo die Leute im Saal sitzen.
        foreach ([['hm-tour-4', '2026-11-06'], ['hm-tour-5', '2026-11-07']] as [$schluessel, $tag]) {
            $this->termin($tour, $schluessel, [
                'starts_at' => $this->zeit($tag.' 21:00', 'Atlantic/Reykjavik'),
                'ends_at' => $this->zeit($tag.' 23:30', 'Atlantic/Reykjavik'),
                'timezone' => 'Atlantic/Reykjavik',
                'venue_name' => 'Gamla Bíó',
                'venue_address' => 'Ingólfsstræti 2a',
                'venue_city' => 'Reykjavík',
                'venue_country' => 'IS',
            ]);
        }

        // Ein Entwurf: steht im Control Panel, taucht auf keiner Seite und in
        // keinem Feed auf, auch nicht mit der UUID in der Hand. Der Zustand, in
        // dem ein Termin die meiste Zeit verbringt.
        $taufe = $this->ereignis('tiefdruck-plattentaufe', 'Plattentaufe Tiefdruck', [
            'type' => 'concert',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => 'Steht noch nicht. Der Saal ist angefragt, die Vorband nicht.',
        ], veroeffentlichen: false);

        $this->termin($taufe, 'hm-taufe-1', [
            'starts_at' => $this->zeit('2027-03-14 20:00', 'Europe/Berlin'),
            'venue_name' => 'Zeche Bochum',
            'venue_city' => 'Bochum',
            'venue_country' => 'DE',
        ]);
    }

    /**
     * Die Werkstatt: ein Kurs an vier Abenden, ein Wochenende am Stück, eine
     * geschlossene Gruppe und ein Titel, den keine Spaltenbreite erwartet hat.
     */
    protected function chorwerkstatt(): void
    {
        $kurs = $this->ereignis('stimmwerkstatt-vier-abende', 'Stimmwerkstatt an vier Abenden', [
            'type' => 'workshop',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => "Vier Dienstage, jeweils 19:00 bis 21:30.\nRegisterarbeit, Einsingen als Diagnose, Korrekturen zum Mitnehmen; Noten stellen wir.",
        ]);

        foreach (['2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06'] as $i => $tag) {
            $this->termin($kurs, 'cw-abend-'.($i + 1), [
                'starts_at' => $this->zeit($tag.' 19:00', 'Europe/Berlin'),
                'ends_at' => $this->zeit($tag.' 21:30', 'Europe/Berlin'),
                'venue_name' => 'Alte Schmiede',
                'venue_address' => 'Schmiedestraße 8',
                'venue_city' => 'Kiel',
                'venue_country' => 'DE',
            ]);
        }

        // Ganztägig, über zwei Tage. Das Ende ist im ICS ausschließend (RFC 5545
        // §3.8.2.2): ein Wochenende Sa+So endet dort am Montag. Wer das falsch
        // rechnet, verliert im Kalender jedes Mal den letzten Tag, und zwar erst
        // beim Kunden.
        $wochenende = $this->ereignis('probenwochenende-nordchoere', 'Probenwochenende Nordchöre', [
            'type' => 'workshop',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => 'Anreise Freitagabend, Abschlusssingen Sonntag um 16:00.',
        ]);

        $this->termin($wochenende, 'cw-wochenende-1', [
            'starts_at' => $this->zeit('2026-11-07 00:00', 'Europe/Berlin'),
            'ends_at' => $this->zeit('2026-11-08 00:00', 'Europe/Berlin'),
            'all_day' => true,
            'venue_name' => 'Bildungsstätte Salzau',
            'venue_city' => 'Fargau-Pratjau',
            'venue_country' => 'DE',
        ]);

        // Ungelistet: in keinem Feed, aber mit der UUID herunterladbar. Genau
        // das, was eine geschlossene Gruppe braucht, die ihren Termin per Mail
        // bekommt.
        $kantorei = $this->ereignis('werkstatt-kantorei-st-nikolai', 'Werkstatt für die Kantorei St. Nikolai', [
            'type' => 'workshop',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'unlisted',
            'description' => 'Geschlossen. Der Link geht an die Kantorei, nicht an die Liste.',
        ]);

        $this->termin($kantorei, 'cw-kantorei-1', [
            'starts_at' => $this->zeit('2026-10-24 10:00', 'Europe/Berlin'),
            'ends_at' => $this->zeit('2026-10-24 17:00', 'Europe/Berlin'),
            'venue_name' => 'St. Nikolai',
            'venue_city' => 'Eckernförde',
            'venue_country' => 'DE',
        ]);

        // Ein Titel von 260 Zeichen, dazu zwei Daten aus der Liste der
        // unangenehmen: die Stunde, die es in Mitteleuropa nicht gibt, und ein
        // Monatsletzter um 23:59.
        $lang = $this->ereignis('titel-viel-zu-lang', DemoData::tooLong(), [
            'type' => 'masterclass',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => "Eine Beschreibung mit allem, was im ICS maskiert werden muss: Kommas, Semikolons; Backslashes \\ und ein Zeilenumbruch.\nHier geht es weiter.",
        ]);

        // 02:30 am 28.03.2027 gibt es in Europe/Berlin nicht — die Uhr springt
        // von 02:00 auf 03:00. Carbon schiebt still auf 03:30 CEST. Das Demo
        // zeigt, was danach in der Liste steht, statt es zu vermeiden.
        $this->termin($lang, 'cw-lang-1', [
            'starts_at' => $this->zeit(DemoData::awkwardDates()['sommerzeit'], 'Europe/Berlin'),
            'venue_name' => 'Alte Schmiede',
            'venue_city' => 'Kiel',
            'venue_country' => 'DE',
        ]);

        $this->termin($lang, 'cw-lang-2', [
            'starts_at' => $this->zeit(DemoData::awkwardDates()['monatsende'], 'Europe/Berlin'),
            'venue_name' => 'Alte Schmiede',
            'venue_city' => 'Kiel',
            'venue_country' => 'DE',
        ]);

        // Und hier die beiden Regeln, die das Addon durchsetzt statt vorschlägt.
        $this->regelnBelegen($kurs);
    }

    /**
     * Die Praxis: ein Termin, der nur online stattfindet, und einer, der
     * niemanden etwas angeht.
     */
    protected function lindhorst(): void
    {
        $sprechstunde = $this->ereignis('offene-sprechstunde', 'Offene Sprechstunde, online', [
            'type' => 'other',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'public',
            'description' => 'Eine Stunde, offener Raum, keine Anmeldung. Wer da ist, ist da.',
        ]);

        // Kein Ort, nur ein Link. Der Fall, an dem `location` und die
        // ICS-Zeile LOCATION auf die URL zurückfallen müssen, statt leer zu
        // bleiben.
        foreach ([['lh-online-1', '2026-09-10'], ['lh-online-2', '2026-10-08']] as [$schluessel, $tag]) {
            $this->termin($sprechstunde, $schluessel, [
                'starts_at' => $this->zeit($tag.' 18:00', 'Europe/Berlin'),
                'ends_at' => $this->zeit($tag.' 19:00', 'Europe/Berlin'),
                'online_url' => 'https://meet.lindhorst.beispiel/sprechstunde',
            ]);
        }

        // Privat: bleibt im Control Panel. Nicht im Feed, nicht auf einer Seite,
        // und auch mit der UUID in der Hand nicht herunterladbar.
        $supervision = $this->ereignis('supervision-intern', 'Supervision, intern', [
            'type' => 'other',
            'timezone' => 'Europe/Berlin',
            'visibility' => 'private',
            'description' => 'Geht niemanden etwas an außer den vier Leuten im Raum.',
        ]);

        $this->termin($supervision, 'lh-supervision-1', [
            'starts_at' => $this->zeit('2026-09-24 09:00', 'Europe/Berlin'),
            'ends_at' => $this->zeit('2026-09-24 12:00', 'Europe/Berlin'),
            'venue_name' => 'Praxis Lindhorst',
            'venue_address' => 'Gartenstraße 12',
            'venue_city' => 'Hamburg',
            'venue_country' => 'DE',
        ]);
    }

    /**
     * Zwei Zeilen, die nicht angelegt werden dürfen.
     *
     * Der Rest des Demos hält absichtlich kaputte Daten fest, damit man sieht,
     * wie eine Oberfläche damit umgeht. Hier geht es andersherum: das Addon
     * behauptet, zwei Regeln durchzusetzen statt sie zu empfehlen, und eine
     * Behauptung ohne Beleg ist eine Zeile in einer README. Also wird sie
     * provoziert, und die Ablehnung ist das Ergebnis.
     */
    protected function regelnBelegen(Event $event): void
    {
        // 1. Jeder Termin braucht einen Ort: Saal oder Link, mindestens eines.
        try {
            Occurrence::create([
                'event_id' => $event->id,
                'uuid' => $this->uuid('cw-ohne-ort'),
                'starts_at' => $this->zeit('2026-12-01 19:00', 'Europe/Berlin'),
            ]);

            $this->abgelehnt[] = 'FEHLER: Ein Termin ohne Saal und ohne Link wurde angelegt. '
                .'Die Regel „jeder Termin braucht einen Ort" greift nicht.';
        } catch (UnlocatableOccurrence $e) {
            $this->abgelehnt[] = 'Termin ohne Ort abgelehnt: '.$e->getMessage();
        }

        // 2. Ein Ende vor seinem Anfang ist ein Tippfehler, keine Dauer.
        try {
            Occurrence::create([
                'event_id' => $event->id,
                'uuid' => $this->uuid('cw-rueckwaerts'),
                'starts_at' => $this->zeit('2026-12-01 21:00', 'Europe/Berlin'),
                'ends_at' => $this->zeit('2026-12-01 19:00', 'Europe/Berlin'),
                'venue_name' => 'Alte Schmiede',
                'venue_city' => 'Kiel',
            ]);

            $this->abgelehnt[] = 'FEHLER: Ein Termin, der vor seinem Anfang endet, wurde angelegt.';
        } catch (InvalidOccurrenceWindow $e) {
            $this->abgelehnt[] = 'Rückwärts laufender Termin abgelehnt: '.$e->getMessage();
        }
    }

    /**
     * Ein Ereignis, angelegt als Entwurf und dann veröffentlicht.
     *
     * Der Umweg ist der Punkt: `EventPublished` feuert auf dem Wechsel des
     * Status, nicht auf dem Wert. Wer die Zeile fertig veröffentlicht
     * hinschreibt, bekommt die Daten und kein Ereignis — und die Brücke zum
     * Aktivitätslog bleibt still.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function ereignis(string $slug, string $titel, array $daten, bool $veroeffentlichen = true): Event
    {
        // `status` steht bewusst nicht in den Werten: beim Anlegen setzt der
        // Modell-Haken `draft`, beim zweiten Lauf bleibt der Stand, wie er ist.
        $event = Event::updateOrCreate(
            ['slug' => $slug],
            array_merge(['title' => $titel], $daten),
        );

        if ($veroeffentlichen) {
            $event->publish();
        }

        return $event;
    }

    /**
     * Ein Termin unter stabiler UUID.
     *
     * Die UUID wird aus dem Demo-Schlüssel abgeleitet statt gewürfelt, damit der
     * zweite Lauf dieselbe Zeile wiederfindet. Das ist hier mehr als Bequemlich-
     * keit: die UUID ist die UID im Kalender des Abonnenten, und eine, die sich
     * bei jedem Seed-Lauf ändert, legt jedes Mal einen zweiten Termin in
     * dasselbe Telefon.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function termin(Event $event, string $schluessel, array $daten): Occurrence
    {
        return Occurrence::updateOrCreate(
            ['uuid' => $this->uuid($schluessel)],
            array_merge(['event_id' => $event->id], $daten),
        );
    }

    /**
     * Eine Ortszeit in ihrer eigenen Zone.
     *
     * Bewusst nicht als Zeichenkette an das Modell gereicht: eine Zeichenkette
     * ohne Zone liest der Cast als UTC, was für eine Datenbankzeile richtig und
     * für „19:00 in Bochum" falsch ist.
     */
    protected function zeit(string $wann, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($wann, $zone);
    }

    /** Aus dem Demo-Schlüssel abgeleitet, damit ein zweiter Lauf dieselbe Zeile trifft. */
    protected function uuid(string $schluessel): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://playground.local/demo/events/'.$schluessel);
    }
}
