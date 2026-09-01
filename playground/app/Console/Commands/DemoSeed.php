<?php

namespace App\Console\Commands;

use App\Demo\SeedsAutomations;
use App\Demo\SeedsBrands;
use App\Demo\SeedsCampaign;
use App\Demo\SeedsCommerce;
use App\Demo\SeedsCrm;
use App\Demo\SeedsEmailTemplates;
use App\Demo\SeedsEvents;
use App\Demo\SeedsFunnels;
use App\Demo\SeedsIdentity;
use App\Demo\SeedsInvoices;
use App\Demo\SeedsProducts;
use App\Demo\SeedsProof;
use App\Demo\SeedsTeam;
use App\Demo\SeedsWebhooks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
 * tidy rows work, which nobody doubted. See {@see \App\Demo\DemoData}.
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

        $this->components->task('Handel: Angebote, Gutscheine, Zahlungen, Abos', function () {
            $this->ergebnis = (new SeedsCommerce)->run();

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
        foreach (['invoice_items', 'invoices', 'invoice_counters'] as $tabelle) {
            if (\Illuminate\Support\Facades\Schema::hasTable($tabelle)) {
                \Illuminate\Support\Facades\DB::table($tabelle)->delete();
            }
        }

        foreach ([
            \Goldnead\StatamicPayments\Models\PaymentItem::class,
            \Goldnead\StatamicPayments\Models\Payment::class,
            \Goldnead\StatamicPayments\Models\Subscription::class,
            \Goldnead\StatamicOffers\Models\Coupon::class,
            \Goldnead\StatamicOffers\Models\Offer::class,
            \Goldnead\StatamicProducts\Models\Product::class,
        ] as $modell) {
            $modell::query()->delete();
        }
    }
}
