<?php

namespace App\Console\Commands;

use App\Demo\DemoData;
use App\Demo\SeedsAbos;
use App\Demo\SeedsAffiliates;
use App\Demo\SeedsAssessments;
use App\Demo\SeedsAutomations;
use App\Demo\SeedsBrands;
use App\Demo\SeedsCampaign;
use App\Demo\SeedsClientRooms;
use App\Demo\SeedsCommerce;
use App\Demo\SeedsCourses;
use App\Demo\SeedsCrm;
use App\Demo\SeedsEmailTemplates;
use App\Demo\SeedsEvents;
use App\Demo\SeedsFunnels;
use App\Demo\SeedsIdentity;
use App\Demo\SeedsInsights;
use App\Demo\SeedsInvoiceExports;
use App\Demo\SeedsInvoices;
use App\Demo\SeedsOffers;
use App\Demo\SeedsProducts;
use App\Demo\SeedsProof;
use App\Demo\SeedsTeam;
use App\Demo\SeedsWebhooks;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Build the demo.
 *
 * Idempotent on purpose: every step is an `updateOrCreate`, so running it twice
 * changes nothing and running it after a code change brings the data up to the
 * new shape. A seeder that only works on an empty database is a seeder nobody
 * runs a second time, and this one is meant to be run every time something in
 * the family changes.
 *
 * The data is deliberately awkward. A demo built from tidy rows proves that
 * tidy rows work, which nobody doubted. See {@see DemoData}.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
                            {--fresh : Wipe the demo rows first}';

    protected $description = 'Seed the agency demo: three brands, their catalogue, and every awkward state.';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Removing the demo rows.');
            $this->wipe();
        }

        $marken = [];

        $this->components->task('Marken', function () use (&$marken) {
            $marken = (new SeedsBrands)->run();
            $this->zahl = count($marken);

            return true;
        });

        $this->components->task('Katalog in die Konfiguration', fn () => $this->katalogSchreiben());

        $this->components->task('Handel: Angebote, Gutscheine, Zahlungen, Abos', function () use (&$marken) {
            $this->ergebnis = (new SeedsCommerce)->run($marken);

            return true;
        });

        // Vor den Leuten: SeedsCrm weist Kontakte an CP-Konten zu, und in einem
        // frischen Klon gibt es noch keins. Genau darauf ist der erste Aufbau
        // aus einem Klon gelaufen.
        $this->components->task('Team: fünf Konten, drei Rollen, vier Markenzuordnungen', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsTeam)->run());

            return true;
        });

        $this->components->task('Leute: Kontakte, Listen, Sperren, Freebies, Zugänge', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, array_filter(
                (new SeedsCrm)->run($marken),
                fn ($k) => ! str_starts_with($k, '_'),
                ARRAY_FILTER_USE_KEY,
            ));

            return true;
        });

        $this->components->task('Wege: Funnels, einer davon absichtlich krumm', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsFunnels)->run());

            return true;
        });

        $this->components->task('Termine: Tour, Kurs, Sprechstunde', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, array_filter(
                (new SeedsEvents)->run($marken),
                fn ($k) => ! str_starts_with($k, '_'),
                ARRAY_FILTER_USE_KEY,
            ));

            return true;
        });

        // Nach den Terminen: die Hälfte der Zeiger geht auf Event-uuids, und
        // ein Zeiger auf einen Termin, den es nicht gibt, soll als fehlend
        // erkannt werden — vorher wäre er nur nicht prüfbar.
        $this->components->task('Produkte: elf Zeilen, zwei absichtlich krumm', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsProducts)->run());

            return true;
        });

        $this->components->task('Mailvorlagen: vier, eine mit unbekanntem Platzhalter', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsEmailTemplates)->run());

            return true;
        });

        // Muss nach SeedsCrm laufen: die Akteure sind die dortigen Kontakte.
        $this->components->task('Identität: wer was getan hat', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsIdentity)->run());

            return true;
        });

        // Reihenfolge ist bindend: die aus der Vorlage installierte Automation
        // hängt am Auslöser `webhook_manager.outbound_failed` und muss stehen,
        // bevor der tote Empfänger feuert.
        $this->components->task('Automationen: sechs Rezepte, davon eines absichtlich rot', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsAutomations)->run());

            return true;
        });

        $this->components->task('Webhooks: sechs Eingänge, ein toter Empfänger', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsWebhooks)->run());

            return true;
        });

        // Buchungen und Einwilligungs-Nachweise. Beide gab es im gepflegten
        // Stand, aber nur weil jemand geklickt hatte — ein Klon-Aufbau fand
        // beide Tabellen leer.
        $this->components->task('Belege: Termine und Einwilligungen', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsProof)->run());

            return true;
        });

        // Nach den Belegen, weil die Erstattung eine Stornorechnung ausloest.
        $this->components->task('Rechnungen: und die, die keine bekommen', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsInvoices)->run());

            return true;
        });

        // Nach den Rechnungen, und das ist bindend: SeedsInvoices schreibt
        // jeder bezahlten Zahlung eine Rechnung, und dreihundert Demo-
        // Rechnungen unter einer Marke waeren eine Nummernreihe, die niemand
        // bestellt hat.
        $this->components->task('Menge: achtzehn Monate Handel fuer die Berichte', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsInsights)->run($marken));

            return true;
        });

        // Nach der Menge, damit der LeadHub-Listener die Antwort einem
        // vorhandenen Kontakt zuordnen kann. `submit()` setzt `contact_id`
        // selbst nicht.
        $this->components->task('Fragebögen: zwei veröffentlicht, einer im Entwurf', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsAssessments)->run($marken));

            return true;
        });

        // Nach SeedsTeam und SeedsCrm: die Eigentuemer sind CP-Konten, und der
        // Klient wird ueber seine Adresse im CRM nachgeschlagen.
        $this->components->task('Klientenräume: sechs, mit offener Arbeit darin', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsClientRooms)->run($marken));

            return true;
        });

        // Nach SeedsCrm: die Zugaenge laufen ueber entitlements, das dann steht.
        // Braucht `php artisan courses:install`, sonst fehlen die Sammlungen.
        // Deshalb ruft der Schritt es selbst: der Befehl laesst bestehende
        // Sammlungen und Blueprints stehen, auf der Demo (kein composer, nur
        // rollout.sh) ist das der eine Ort, an dem er sicher laeuft.
        //
        // `--merge` seit 23.09.2026 (courses K1 bis K6): ohne ergänzt der Befehl
        // bestehende Blueprints nicht, und auf der Demo stehen sie seit dem
        // ersten Aufbau. Bausteine, Drip-Varianten, Zielgruppen, Quiz- und
        // Teamfelder fehlten dann im Formular, obwohl der Seeder sie füllt.
        // `--merge` fügt nur Fehlendes hinzu und ändert nichts anderes.
        $this->components->task('Kurse: zwei, fünf Lernende, Baukasten, Quiz, Team', function () {
            $this->callSilently('courses:install', ['--merge' => true]);
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsCourses)->run());

            return true;
        });

        // ---- Suite-Runde ThriveCart-Rundgang, 23.09.2026 ---------------
        //
        // Je Addon eine Klasse, alle nach der Menge und nach den Rechnungen:
        // sie schreiben Zahlungen per `DB::table()` ohne `PaymentPaid`, und
        // SeedsInvoices wie SeedsAutomations klammern sie über
        // `DemoData::MENGEN_PRAEFIXE` aus.
        //
        // Zweite Runde, noch nicht gebaut: payments (P1 bis P9, Pausen,
        // Wechsel, Kundenportal), funnels (F1 bis F7) und automations (A1, A2).
        // Ihre Seeds kommen als eigene Klassen hier dazu, payments vor
        // SeedsAffiliates, weil dessen Zahlungen die neuen Abo-Felder dann
        // mittragen sollen.

        $this->components->task('Abos: 57 mit Verlauf für die Kennzahlen', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsAbos)->run($marken));

            return true;
        });

        $this->components->task('Steuermonat: fünf Belege für den Rechnungsexport', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsInvoiceExports)->run($marken));

            return true;
        });

        $this->components->task('Angebote: Spendenpreis, Aufnahmegebühr, Kurzlink, Plätze', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsOffers)->run($marken));

            return true;
        });

        // Nach SeedsOffers: die Partner-Verkäufe gehen auf `cw-stimmgruppe`.
        $this->components->task('Partner: fünf, sieben Verkäufe, eine Auszahlung', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsAffiliates)->run($marken));

            return true;
        });

        $this->components->task('Kampagne: einmal wirklich senden', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsCampaign)->run($marken));

            return true;
        });

        $this->newLine();
        $this->components->twoColumnDetail('Marken', (string) $this->zahl);

        foreach ($this->ergebnis ?? [] as $was => $wie_viele) {
            $this->components->twoColumnDetail(ucfirst($was), (string) $wie_viele);
        }

        $this->newLine();
        $this->components->info('Fertig. Der Katalog liegt in config/statamic-payments.php, die Produktdaten in der Tabelle.');

        return self::SUCCESS;
    }

    protected int $zahl = 0;

    /** @var array<string, mixed> */
    protected array $ergebnis = [];

    /**
     * The catalogue is configuration, not content.
     *
     * Written into the published config file rather than a database table,
     * because that is where this family says prices live: a payment addon that
     * shipped a price would be wrong, and one that took it from a request would
     * be worse.
     */
    protected function katalogSchreiben(): bool
    {
        $pfad = config_path('statamic-payments.php');

        if (! File::exists($pfad)) {
            $this->error('config/statamic-payments.php fehlt. Erst `php artisan vendor:publish` laufen lassen.');

            return false;
        }

        $inhalt = File::get($pfad);
        $katalog = $this->alsPhp((new SeedsCommerce)->katalog());

        // Replaces whatever `products` currently holds, between its opening
        // bracket and the matching closing one. Deliberately narrow: the rest of
        // the file is the site's, and a seeder that rewrote the whole config
        // would throw away the Mollie key with it.
        $neu = preg_replace(
            "/'products' => \[.*?\n    \],/s",
            "'products' => [\n".$katalog."\n    ],",
            $inhalt,
            1,
        );

        if ($neu === null || $neu === $inhalt) {
            $this->error('Der products-Block in der Konfiguration war nicht zu finden.');

            return false;
        }

        File::put($pfad, $neu);

        // Auch in den geladenen Stand dieses Laufs. Die Datei liest Laravel
        // erst beim naechsten Start; bis dahin saehen die Seeder dahinter den
        // alten Katalog. Aufgefallen am `grants` von `cw-workshop`: die Plaetze
        // aus SeedsOffers kamen im ersten Lauf ohne Zugang an. Zeile fuer
        // Zeile gemischt statt ersetzt, weil statamic-offers seine eigenen
        // `offer:*`-Eintraege beim Start in denselben Schluessel legt.
        $geladen = (array) config('statamic-payments.products', []);

        foreach ((new SeedsCommerce)->katalog() as $handle => $daten) {
            $geladen[$handle] = $daten;
        }

        config()->set('statamic-payments.products', $geladen);

        return true;
    }

    /** @param array<string, array<string, mixed>> $katalog */
    protected function alsPhp(array $katalog): string
    {
        $zeilen = [];

        foreach ($katalog as $handle => $daten) {
            $teile = [];

            foreach ($daten as $k => $v) {
                $teile[] = "'{$k}' => ".(is_string($v) ? "'".str_replace("'", "\\'", $v)."'" : var_export($v, true));
            }

            $zeilen[] = "        '{$handle}' => [".implode(', ', $teile).'],';
        }

        return implode("\n", $zeilen);
    }

    protected function wipe(): void
    {
        // Rechnungen zuerst und ueber den Query Builder, nicht ueber das
        // Modell: statamic-invoices verbietet das Loeschen einer Rechnung mit
        // Absicht, weil eine verschwundene Nummer eine Luecke in der Reihe ist.
        // Das gilt fuer den Betrieb. Ein Demo, das sich neu aufbaut, ist der
        // eine Fall, in dem der Riegel im Weg steht -- und der Zaehler muss
        // mit, sonst kollidiert die naechste Nummer mit einer, die es nicht
        // mehr gibt.
        // Alles, was an einer Zahlung haengt, muss mit ihr gehen.
        //
        // Bis 16.09.2026 standen hier nur die Rechnungen, und `PaymentItem`
        // wurde unten eigens geloescht. Jede Kindtabelle, die seitdem
        // dazugekommen ist, blieb liegen: nach drei `--fresh`-Laeufen trugen
        // zehn Zahlungen eine Erstattung, waehrend `payment_refunds`
        // einundzwanzig Zeilen hatte, elf davon Waisen ohne Zahlung. Das sieht
        // im Bericht aus wie ein Zaehlfehler des Addons und ist keiner.
        //
        // `leadhub_contact_revenue` gehoert dazu, weil jede Zeile die Referenz
        // einer Zahlung traegt, die es nach dem Wischen nicht mehr gibt. Die
        // Kontakte selbst bleiben stehen: die legt `SeedsCrm` an, sie werden
        // ueber die Adresse wiedergefunden, und ihre Summen rechnet
        // `recordRevenue()` beim naechsten Lauf neu.
        //
        // Seit 23.09.2026 dazu: was an den Zahlungen der Suite-Runde haengt.
        // Die Partner-Buchungen und Zuordnungen verweisen auf Zahlungs-IDs, die
        // Platzkontingente ebenso; blieben sie stehen, zeigte das CP Provisionen
        // auf Verkaeufe, die es nicht mehr gibt. Partner, Saetze und
        // JV-Vertraege sind Stammdaten und bleiben, der Seeder findet sie wieder.
        foreach ([
            'affiliate_commissions', 'affiliate_payouts', 'affiliate_referrals',
            'offer_seats', 'offer_seat_pools',
            'invoice_items', 'invoices', 'invoice_counters',
            'payment_refunds', 'payment_communications', 'payment_chargebacks',
            'payment_webhook_events', 'payment_withdrawals', 'payment_cancellations',
            'leadhub_contact_revenue',
        ] as $tabelle) {
            if (Schema::hasTable($tabelle)) {
                DB::table($tabelle)->delete();
            }
        }

        foreach ([
            PaymentItem::class,
            Payment::class,
            Subscription::class,
            Coupon::class,
            Offer::class,
            Product::class,
        ] as $modell) {
            $modell::query()->delete();
        }
    }
}
