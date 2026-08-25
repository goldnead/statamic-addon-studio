<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The demo talks to Mollie's real test account when a test key is
        // configured, and falls back to the local stand-in when it is not.
        //
        // Deliberately keyed on the key rather than on an env flag: a demo that
        // claims to have run a real checkout while quietly using a fake is worse
        // than one that admits it has no key. `test_` is Mollie's own prefix, so
        // a live key here would be a mistake and is refused.
        $schluessel = (string) config('statamic-payments.key');

        if (str_starts_with($schluessel, 'test_')) {
            return;
        }

        if ($schluessel !== '') {
            throw new \RuntimeException(
                'Der Playground nimmt nur einen Mollie-TEST-Schlüssel. Ein Live-Schlüssel würde echtes Geld bewegen.'
            );
        }

        $this->app->singleton(\Goldnead\StatamicPayments\Contracts\PaymentGateway::class, \App\Support\PlaygroundGateway::class);

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
