<?php

namespace App\Providers;

use Goldnead\IdentityContracts\Contracts\ContactLocator;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Notifications\Facades\Notifications;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Die einzige Stelle, an der der Join per E-Mail auf einen CRM-Kontakt
        // lebt (so sagt es das README von statamic-identity-contracts). Ohne
        // diese Bindung ist der NullContactLocator aktiv: jede Auflösung geht
        // ins Leere, jede Aktivität und jede Meldung bleibt ohne contact_uuid,
        // und der Empfänger-Filter der Meldungen ist tot.
        //
        // Bewusst VOR der Mollie-Logik unten, weil die bei einem Testschlüssel
        // früh zurückkehrt — eine Bindung dahinter würde dann nie greifen.
        //
        // Marken-Verhalten: liegt eine aktuelle Marke an, sucht der Kontakt im
        // globalen Scope dieser Marke (dieselbe Adresse existiert im Demo unter
        // zwei Marken). Ohne aktuelle Marke — Konsole, Queue — ohne Scope, damit
        // der Join überhaupt einen Treffer findet, statt an der Marken-Sperre
        // zu scheitern.
        $this->app->bind(ContactLocator::class, function () {
            return new class implements ContactLocator
            {
                public function locateByEmail(string $email): ?Identity
                {
                    $normalized = EmailNormalizer::normalize($email);

                    if ($normalized === null) {
                        return null;
                    }

                    $contact = $this->query()->where('email_normalized', $normalized)->first();

                    return $contact ? $this->toIdentity($contact) : null;
                }

                public function locateByUuid(string $uuid): ?Identity
                {
                    $contact = $this->query()->where('uuid', $uuid)->first();

                    return $contact ? $this->toIdentity($contact) : null;
                }

                protected function query()
                {
                    // Mit anliegender Marke im Scope bleiben, sonst darüber hinweg.
                    if (app()->bound('brand-context') && app('brand-context')->hasCurrent()) {
                        return Contact::query();
                    }

                    return Contact::withoutGlobalScopes();
                }

                protected function toIdentity(Contact $contact): Identity
                {
                    return Identity::contact(
                        (string) $contact->uuid,
                        $contact->email,
                        $contact->displayName(),
                    );
                }
            };
        });

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
        $this->meldungsTypenRegistrieren();
    }

    /**
     * Die fünf Meldungs-Typen des Demos. Typen MÜSSEN in einem Provider stehen,
     * nicht im aufrufenden Code (README statamic-notifications): die Registry
     * lebt pro Prozess, und ein ad hoc registrierter Typ ist dem geplanten
     * Digest-Prozess unbekannt und wird dort still übersprungen.
     *
     * Jeder Typ sagt, wie er heißt (label), über welche Kanäle er voreingestellt
     * geht (defaultChannels) und wie er rendert (renderUsing). Das Wording und
     * die URL gehören dem Host, nie dem Addon — deshalb ist render ein Closure.
     */
    protected function meldungsTypenRegistrieren(): void
    {
        Notifications::registerType('payment.paid', function ($type) {
            $type->label('Zahlung eingegangen')
                ->defaultChannels(['in_app', 'mail', 'digest'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Eine Zahlung ist eingegangen.',
                    'link' => $item->link ?? '/cp/payments',
                ]);
        });

        // Als einziger Typ pflichtig: eine fehlgeschlagene Zahlung muss den
        // Empfänger erreichen, auch wenn er sonst alles abgeschaltet hat.
        // required() ignoriert Präferenzen — sparsam einzusetzen, hier zu Recht.
        Notifications::registerType('payment.failed', function ($type) {
            $type->label('Zahlung fehlgeschlagen')
                ->defaultChannels(['in_app', 'mail'])
                ->required()
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Eine Zahlung ist fehlgeschlagen.',
                    'link' => $item->link ?? '/cp/payments',
                ]);
        });

        Notifications::registerType('funnel.completed', function ($type) {
            $type->label('Funnel abgeschlossen')
                ->defaultChannels(['in_app', 'digest'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Ein Funnel wurde abgeschlossen.',
                    'link' => $item->link ?? '/cp/funnels',
                ]);
        });

        Notifications::registerType('subscription.cancelled', function ($type) {
            $type->label('Abo gekündigt')
                ->defaultChannels(['in_app', 'mail'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Ein Abo wurde gekündigt.',
                    'link' => $item->link ?? '/cp/payments/subscriptions',
                ]);
        });

        Notifications::registerType('booking.requested', function ($type) {
            $type->label('Termin angefragt')
                ->defaultChannels(['in_app', 'mail'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Ein Termin wurde angefragt.',
                    'link' => $item->link ?? '/cp/bookings',
                ]);
        });
    }
}
