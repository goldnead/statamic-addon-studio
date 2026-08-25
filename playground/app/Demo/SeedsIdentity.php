<?php

namespace App\Demo;

use Goldnead\Activity\Facades\Activity;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\Contracts\ContactLocator;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Models\NotificationPreference;
use Illuminate\Support\Facades\Artisan;

/**
 * Wer hat das getan, und wer soll davon erfahren.
 *
 * Der bestehende Demo-Seed schrieb Aktivitäten und Meldungen am Vertrag vorbei
 * direkt ins Model: von dreizehn Aktivitäten trug genau eine eine Identität (die
 * langweiligste, `system`), und alle fünf Meldungen hatten `recipient_id` NULL —
 * womit der Empfänger-Filter, laut README der Daseinszweck des Meldungs-Screens,
 * tot war.
 *
 * Dieser Seeder macht es so, wie die Addons es verlangen:
 *
 *   1. Aktivitäten über `Activity::record()` statt `Activity::create()`, mit
 *      echten Akteuren: ein Kontakt, ein anonymer Besucher, ein CP-Benutzer, und
 *      eine nachträglich anonymisierte Zeile.
 *   2. Meldungen über `Notifications::notify()` statt `NotificationItem::create()`,
 *      adressiert an echte LeadHub-Kontakte, damit `recipient_id`, `contact_uuid`
 *      und `email` gefüllt sind und der Filter greift.
 *   3. Ein paar Präferenzen als Abweichungen, je Marke eine.
 *   4. Ein Digest-Lauf, damit `notification_digest_runs` Zeilen bekommt.
 *
 * Der ganze Seed-Abschnitt läuft unter `IdentityContext::actingAs(system)`: der
 * Seeder selbst ist die handelnde Instanz, einzelne Zeilen überschreiben das mit
 * ihrem eigenen Akteur.
 */
class SeedsIdentity
{
    /** @return array<string, int> */
    public function run(): array
    {
        IdentityContext::actingAs(Identity::system('demo-seed'), function () {
            $this->spuren();
            $this->meldungen();
            $this->einstellungen();
        });

        // Bewusst außerhalb des actingAs: ein Digest ist ein eigener Prozess mit
        // eigenem Marken-Durchlauf, nicht der Seeder in Person.
        $this->digestLauf();

        return [
            'identitaets_spuren' => Activity::query()->withoutGlobalScopes()
                ->whereNotNull('actor_type')
                ->where('actor_type', '!=', Identity::TYPE_SYSTEM)
                ->count(),
            'adressierte_meldungen' => NotificationItem::withoutGlobalScopes()
                ->whereNotNull('recipient_id')
                ->count(),
            'praeferenzen' => NotificationPreference::withoutGlobalScopes()->count(),
            'digest_laeufe' => NotificationDigestRun::withoutGlobalScopes()->count(),
        ];
    }

    /**
     * Aktivitäten mit echten Akteuren, je Zeile über `Activity::record()`.
     *
     * `record()` ist der einzige Schreibpfad, den das Aktivitäts-Addon anbietet:
     * er hydriert Marke, Akteur und Kontext, spreizt die Join-Keys des Akteurs in
     * die eigenen Spalten und ist über `event_id`/`dedupe_key` idempotent. Der
     * bestehende Demo-Seed umging ihn mit `firstOrCreate` aufs Model — genau der
     * Fehler, den diese Datei behebt.
     */
    protected function spuren(): void
    {
        $marken = $this->marken();

        // Kontakt als Akteur: der Kontakt selbst hat etwas getan.
        $this->record($marken['chorwerkstatt'], 'course.enrolled', [
            'actor' => $this->kontakt('a@b.de'),
            'dedupe_key' => 'identity-demo-course-enrolled',
            'properties' => ['kurs' => 'fruehlingskurs'],
        ]);

        // CP-Benutzer als Akteur, Kontakt als Betroffener: der Akteur ist jemand
        // anderes als das Subjekt. Deshalb der Kontakt-Join explizit, zusätzlich
        // zum user_id, das aus dem Akteur kommt.
        $bearbeiter = $this->benutzer();
        $betroffener = $this->kontakt('a@b.de');
        $this->record($marken['chorwerkstatt'], 'contact.status_changed', [
            'actor' => $bearbeiter,
            'contact_uuid' => $betroffener?->contactUuid,
            'dedupe_key' => 'identity-demo-status-changed',
            'properties' => ['von' => 'lead', 'nach' => 'customer'],
        ]);

        // Anonymer Besucher: eine Spur vor jeder Identifikation.
        $this->record($marken['chorwerkstatt'], 'page.viewed', [
            'actor' => Identity::anonymous('anon-demo-1'),
            'dedupe_key' => 'identity-demo-page-viewed',
            'properties' => ['pfad' => '/f/fruehlingskurs'],
        ]);

        // Kontakt als Akteur unter einer anderen Marke.
        $this->record($marken['halbmond'], 'email.clicked', [
            'actor' => $this->kontakt('DOPPELT@beispiel.de', 'halbmond'),
            'dedupe_key' => 'identity-demo-email-clicked',
            'properties' => ['kampagne' => 'tourmail'],
        ]);

        // Eine anonyme Spur, die danach anonymisiert wird: die persönlichen
        // Felder gehen, die zählbare Tatsache bleibt. Über den offiziellen
        // Erasure-Pfad des Addons (`activity:anonymize`), nicht per Model-Update
        // — der Log lässt sich sonst nicht ändern, und genau das ist Absicht.
        $this->record($marken['halbmond'], 'form.submitted', [
            'actor' => Identity::anonymous('anon-demo-anonymisiert'),
            'dedupe_key' => 'identity-demo-form-submitted',
            'properties' => ['formular' => 'newsletter'],
        ]);

        Artisan::call('activity:anonymize', ['--anonymous-id' => 'anon-demo-anonymisiert']);
    }

    /**
     * Meldungen mit echtem Empfänger, je über `Notifications::notify()`.
     *
     * Ein Empfänger muss identifizierbar sein — deshalb echte Kontakte, deren
     * uuid/E-Mail in `recipient_id`, `contact_uuid` und `email` landen. Damit
     * greift der Empfänger-Filter, und der Digest weiß, wem er etwas schuldet.
     *
     * Idempotent über `dedupe_key`. Der Marken-Kontext je Meldung, damit
     * `brand_id` und die Absender-Identität aus der richtigen Marke kommen.
     */
    protected function meldungen(): void
    {
        $zeilen = [
            // [Marke, Typ, E-Mail des Empfängers, Text, dedupe]
            ['chorwerkstatt', 'payment.paid', 'a@b.de',
                'Deine Zahlung für den Frühlingskurs ist eingegangen.', 'meld-payment-paid'],
            ['chorwerkstatt', 'funnel.completed', 'BÄRBEL.Öztürk@Beispiel.DE',
                'Du hast den Funnel „Frühlingskurs" abgeschlossen.', 'meld-funnel-completed'],
            ['chorwerkstatt', 'payment.failed', 'BÄRBEL.Öztürk@Beispiel.DE',
                'Deine Zahlung über 19,99 EUR ist fehlgeschlagen.', 'meld-payment-failed'],
            ['halbmond', 'subscription.cancelled', 'DOPPELT@beispiel.de',
                'Dein Fanclub-Abo wurde gekündigt.', 'meld-subscription-cancelled'],
            ['lindhorst', 'booking.requested', 'a@b.de',
                'Dein Erstgespräch wurde angefragt.', 'meld-booking-requested'],
        ];

        foreach ($zeilen as [$marke, $typ, $email, $text, $dedupe]) {
            $brand = $this->marken()[$marke];

            BrandContext::runFor($brand, function () use ($email, $marke, $typ, $text, $dedupe) {
                $empfaenger = $this->kontakt($email, $marke);

                if ($empfaenger === null) {
                    return;
                }

                Notifications::notify($empfaenger, $typ, [
                    'message' => $text,
                    'link' => '/cp/'.explode('.', $typ)[0],
                    'dedupe_key' => $dedupe,
                ]);
            });
        }
    }

    /**
     * Präferenzen als Abweichungen, je Marke eine. Absicht ist nicht die Menge,
     * sondern dass überhaupt welche existieren: die persistierte Zeile ist der
     * Nachweis, dass jemand eine Wahl getroffen hat, und der Digest-Pfad wie der
     * Sofort-Versand lesen sie.
     */
    protected function einstellungen(): void
    {
        $abweichungen = [
            // [Marke, E-Mail, Typ, Kanal, an/aus]
            ['chorwerkstatt', 'a@b.de', 'payment.paid', 'mail', false],
            ['halbmond', 'DOPPELT@beispiel.de', 'subscription.cancelled', 'mail', false],
            ['lindhorst', 'a@b.de', 'booking.requested', 'mail', false],
        ];

        $resolver = app(\Goldnead\Notifications\Preferences\PreferenceResolver::class);

        foreach ($abweichungen as [$marke, $email, $typ, $kanal, $an]) {
            $brand = $this->marken()[$marke];

            BrandContext::runFor($brand, function () use ($resolver, $email, $marke, $typ, $kanal, $an) {
                $empfaenger = $this->kontakt($email, $marke);

                if ($empfaenger === null) {
                    return;
                }

                $resolver->set($empfaenger, $typ, $kanal, $an);
            });
        }
    }

    /**
     * Der Digest-Lauf. Erst jetzt bekommt `notification_digest_runs` Zeilen: das
     * Kommando läuft je Marke, sammelt die offenen Meldungen im Wochenfenster,
     * stempelt sie und schreibt je Empfänger eine Lauf-Zeile.
     */
    protected function digestLauf(): void
    {
        Artisan::call('notifications:send-digests', ['--frequency' => 'weekly']);
    }

    /**
     * Eine Aktivität schreiben, im Marken-Kontext, mit stabilem event_id für die
     * Idempotenz über Läufe hinweg.
     *
     * @param  array<string, mixed>  $attribute
     */
    protected function record(Brand $brand, string $eventType, array $attribute): void
    {
        BrandContext::runFor($brand, function () use ($eventType, $attribute) {
            $attribute['event_id'] ??= $this->eventId($attribute['dedupe_key'] ?? $eventType);
            $attribute['source'] ??= 'demo';

            Activity::record($eventType, $attribute);
        });
    }

    /**
     * Einen Kontakt als Identität, über den gebundenen ContactLocator — die
     * einzige Stelle, an der der Join per E-Mail lebt. Im Marken-Kontext trifft
     * er die richtige Marke; das umgeht zugleich die Schwäche von
     * IdentityContext::resolve() bei internationalisierten Adressen (dazu der
     * Bericht).
     */
    protected function kontakt(string $email, ?string $marke = null): ?Identity
    {
        if ($marke === null) {
            return app(ContactLocator::class)->locateByEmail($email);
        }

        return BrandContext::runFor(
            $this->marken()[$marke],
            fn () => app(ContactLocator::class)->locateByEmail($email),
        );
    }

    /** Der CP-Benutzer als Akteur: ein Mensch im Control Panel, der etwas tut. */
    protected function benutzer(): Identity
    {
        $user = \Statamic\Facades\User::findByEmail('studio@local');

        if ($user === null) {
            return Identity::system('demo-seed');
        }

        return Identity::user($user->id(), $user->email(), $user->get('name') ?? 'Studio');
    }

    /** @var array<string, Brand>|null */
    protected ?array $markenCache = null;

    /** @return array<string, Brand> */
    protected function marken(): array
    {
        return $this->markenCache ??= Brand::query()
            ->get()
            ->keyBy('handle')
            ->all();
    }

    /**
     * Ein stabiler, gültiger UUID-String aus einem Schlüssel, damit ein zweiter
     * Lauf nicht eine zweite Kopie schreibt (dieselbe Ableitung wie im
     * bestehenden Seed).
     */
    protected function eventId(string $key): string
    {
        return sprintf('demo0000-0000-4000-8000-%012d', crc32('identity-'.$key) % 1000000000000);
    }
}
