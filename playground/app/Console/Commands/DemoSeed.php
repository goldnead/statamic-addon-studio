<?php

namespace App\Console\Commands;

use App\Demo\SeedsBrands;
use App\Demo\SeedsCommerce;
use App\Demo\SeedsCrm;
use App\Demo\SeedsFunnels;
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

        $this->components->task('Leute: Kontakte, Listen, Sperren, Freebies, Zugänge', function () use (&$marken) {
            $this->ergebnis = array_merge($this->ergebnis, array_filter(
                (new SeedsCrm)->run($marken),
                fn ($k) => ! str_starts_with($k, '_'),
                ARRAY_FILTER_USE_KEY,
            ));

            return true;
        });

        $this->components->task('Wege: vier Funnels, einer davon absichtlich krumm', function () {
            $this->ergebnis = array_merge($this->ergebnis, (new SeedsFunnels)->run());

            return true;
        });

        $this->newLine();
        $this->components->twoColumnDetail('Marken', (string) $this->zahl);

        foreach ($this->ergebnis ?? [] as $was => $wie_viele) {
            $this->components->twoColumnDetail(ucfirst($was), (string) $wie_viele);
        }

        $this->newLine();
        $this->components->info('Fertig. Der Katalog liegt in config/statamic-payments.php.');

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
        foreach ([
            \Goldnead\StatamicPayments\Models\PaymentItem::class,
            \Goldnead\StatamicPayments\Models\Payment::class,
            \Goldnead\StatamicPayments\Models\Subscription::class,
            \Goldnead\StatamicOffers\Models\Coupon::class,
            \Goldnead\StatamicOffers\Models\Offer::class,
        ] as $modell) {
            $modell::query()->delete();
        }
    }
}
