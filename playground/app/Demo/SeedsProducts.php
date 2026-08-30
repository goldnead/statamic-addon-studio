<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Events\Models\Event;
use Goldnead\StatamicProducts\Models\Product;
use Ramsey\Uuid\Uuid;

/**
 * What the agency and its clients sell, as rows instead of a config block.
 *
 * The catalogue has always lived in `config/statamic-payments.php`, and the
 * demo keeps writing it there (`DemoSeed::katalogSchreiben()`): that is the
 * "before" of this addon, and a site that never moves off it is supported on
 * purpose. These rows are the "after": the thing that is sold, with a kind and
 * a pointer at the thing of that kind, one catalogue per brand.
 *
 * Two rows are deliberately wrong, because a catalogue of tidy rows proves
 * nothing a config file could not have proved:
 *
 * - `cw-kurs` exists here *and* in the config, at a different price. The
 *   config wins and the screen says so — two truths about one price is the
 *   illness this addon exists to make visible.
 * - `sz-phantom` points at an event that was never seeded. `statamic-events`
 *   is installed and says there is no such thing, so the row is flagged and
 *   counted above the table: sold, paid, nothing behind it.
 *
 * Every kind appears once, so the three answers a pointer can give (resolved,
 * gone, cannot-be-checked) are all on screen in one walk through the brands.
 */
class SeedsProducts
{
    public function run(): array
    {
        foreach ([
            'nordlicht' => $this->nordlicht(),
            'chorwerkstatt' => $this->chorwerkstatt(),
            'halbmond' => $this->halbmond(),
            'lindhorst' => $this->lindhorst(),
            'sonderzeichen' => $this->sonderzeichen(),
        ] as $marke => $zeilen) {
            foreach ($zeilen as $handle => $daten) {
                BrandContext::runFor($marke, fn () => Product::updateOrCreate(
                    ['handle' => $handle],
                    $daten,
                ));
            }
        }

        return ['produkte' => Product::count()];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function nordlicht(): array
    {
        return [
            // Der Kurs, den die Agentur selbst verkauft. Der Zeiger geht auf
            // die Kursseite — was der Kurs *zeigt*, ist Sache der Website,
            // hier steht nur, dass es ihn gibt und was er kostet.
            'nl-fruehlingskurs' => [
                'name' => 'Frühlingskurs für Chorleitende',
                'type' => Product::TYPE_ZUGANG,
                'ref' => '8f3a41d7-2c95-4e18-b6a3-51d09c7fe210',
                'amount_cent' => 24900,
                'digital' => true,
                'grants' => ['kurs-fruehling'],
                'active' => true,
            ],
            'nl-arbeitsbuch' => [
                'name' => 'Arbeitsbuch zum Frühlingskurs',
                'type' => Product::TYPE_DOWNLOAD,
                'ref' => null,
                'amount_cent' => 3900,
                'digital' => true,
                'grants' => null,
                'active' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function chorwerkstatt(): array
    {
        $wochenende = $this->eventUuid('chorwerkstatt', 'probenwochenende-nordchoere');

        return [
            // Absichtlich doppelt: derselbe Handle wie in der Konfiguration,
            // aber 259,00 statt 249,00. Die Konfiguration gewinnt, die Zeile
            // bekommt den Schatten-Badge — genau der Fall, den das Addon
            // sichtbar machen will, statt ihn still aufzulösen.
            'cw-kurs' => [
                'name' => 'Frühlingskurs für Chorleitende',
                'type' => Product::TYPE_ZUGANG,
                'ref' => 'c7a4b134-3c48-5107-a0c2-8be2bc6cc9e7',
                'amount_cent' => 25900,
                'digital' => true,
                'grants' => ['kurs-fruehling'],
                'active' => true,
            ],
            'cw-wochenende' => [
                'name' => 'Wochenende in der Alten Schmiede',
                'type' => Product::TYPE_TERMIN,
                'ref' => $wochenende,
                'amount_cent' => 45000,
                // Vor Ort, mit Mittagessen: keine digitale Leistung, also ein
                // anderer Ort der Lieferung und ein anderer Pflichthinweis.
                'digital' => false,
                'grants' => null,
                'active' => true,
            ],
            'cw-einblick' => [
                'name' => 'Beiträge im Volltext',
                'type' => Product::TYPE_FEED,
                'ref' => 'posts',
                'amount_cent' => 900,
                'digital' => true,
                'grants' => ['beitraege'],
                'active' => true,
            ],
            // Ausgesteuert statt gelöscht: der Handle bleibt, weil der
            // Jahrgang verkauft wurde und ein gelöschter Handle die Geschichte
            // dieser Verkäufe verstummen ließe.
            'cw-jahrgang-2024' => [
                'name' => 'Chorleiter-Ausbildung, Jahrgang 2024',
                'type' => Product::TYPE_KOHORTE,
                'ref' => 'c7a4b134-3c48-5107-a0c2-8be2bc6cc9e7',
                'amount_cent' => 39900,
                'digital' => true,
                'grants' => ['ausbildung'],
                'active' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function halbmond(): array
    {
        $tour = $this->eventUuid('halbmond', 'tiefdruck-tour');

        return [
            'hm-tour-ticket' => [
                'name' => 'Ticket, Tour-Auftakt',
                'type' => Product::TYPE_TERMIN,
                'ref' => $tour,
                'amount_cent' => 1800,
                'digital' => true,
                'grants' => null,
                'active' => true,
            ],
            'hm-fanclub' => [
                'name' => 'Fanclub-Mitgliedschaft',
                'type' => Product::TYPE_ZUGANG,
                'ref' => '0f6593db-7557-5a3b-ac31-6952de6a74fc',
                'amount_cent' => 5900,
                'digital' => true,
                'grants' => ['fanclub'],
                'active' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function lindhorst(): array
    {
        return [
            'lh-erstgespraech' => [
                'name' => 'Erstgespräch, 30 Minuten',
                'type' => Product::TYPE_SITZUNGEN,
                'ref' => 'beratung',
                'amount_cent' => 0,
                'digital' => false,
                'grants' => null,
                'active' => true,
            ],
            'lh-block-von-fuenf' => [
                'name' => 'Fünf Sprechstunden im Block',
                'type' => Product::TYPE_SITZUNGEN,
                'ref' => 'beratung',
                'amount_cent' => 35000,
                'digital' => false,
                'grants' => null,
                'active' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function sonderzeichen(): array
    {
        return [
            // Der Zeiger auf ein Konzert, das nie angelegt wurde. Weil
            // statamic-events installiert ist und sagt, dass es das nicht
            // gibt, ist das ein echter Defekt: markiert und über der Tabelle
            // gezählt. (Wäre events nicht installiert, stünde hier
            // "nicht prüfbar" — auch das ist ein Zustand, nur kein Fehler.)
            'sz-phantom' => [
                'name' => 'Konzert, das keiner angelegt hat',
                'type' => Product::TYPE_TERMIN,
                'ref' => $this->uuid('sz-phantom-1'),
                'amount_cent' => 1500,
                'digital' => false,
                'grants' => null,
                'active' => true,
            ],
        ];
    }

    /**
     * Die uuid eines Termins, nachgeschlagen am Slug.
     *
     * Events würfeln ihre uuid (nur die Occurrences unter ihnen haben eine
     * aus dem Demo-Schlüssel abgeleitete), deshalb darf hier nichts hart
     * stehen: ein harter uuid-Text würde im nächsten Aufbau auf ein Konzert
     * zeigen, das es nicht mehr gibt. Der Slug ist der stabile Schlüssel, unter
     * dem SeedsEvents die Zeile wiederfindet.
     */
    protected function eventUuid(string $marke, string $slug): string
    {
        $event = BrandContext::runFor(
            $marke,
            fn () => Event::firstWhere('slug', $slug),
        );

        if ($event === null) {
            throw new \RuntimeException("SeedsProducts: kein Event mit Slug '{$slug}'. Läuft SeedsProducts vor SeedsEvents?");
        }

        return (string) $event->uuid;
    }

    /**
     * Aus dem Demo-Schlüssel abgeleitet, damit ein zweiter Lauf dieselbe Zeile
     * trifft — derselbe Namensraum wie in SeedsEvents, weil ein Zeiger auf ein
     * nicht vorhandenes Event deterministisch dieselbe nicht vorhandene uuid
     * treffen muss.
     */
    protected function uuid(string $schluessel): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://playground.local/demo/events/'.$schluessel);
    }
}
