<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;

/**
 * Ausgehende Webhooks auf die Brücken der Suite (webhook-manager 2.10,
 * Suite-Nachtrag 24.09.2026).
 *
 * Drei Ausgänge der Chorwerkstatt auf Momente aus payments, offers und
 * courses. Sie zeigen im CP die gruppierte Auslöser-Auswahl und die
 * Payload-Vorschau je Auslöser. Ziel ist `https://example.invalid/hook`:
 * `.invalid` löst nie auf (RFC 6761), jede Zustellung scheitert sofort und
 * steht als echte Zeile mit Fehler in der Lieferliste. Kein Versand an einen
 * fremden Rechner.
 *
 * Die Zustellungen entstehen danach von selbst, wenn die Ereignisse im
 * Seed-Lauf fallen: Platz angenommen ({@see SeedsOffers}, und
 * {@see SeedsSuiteAutomations} für Tom), Abo pausiert und Kurs eingeschrieben
 * ({@see SeedsSuiteAutomations}). Dieser Seeder läuft deshalb davor.
 *
 * Erbt die Helfer von {@see SeedsWebhooks} (Anlegen über die Action des
 * Addons, Telemetrie-Aufräumen), räumt aber nur die eigenen Ausgänge.
 */
class SeedsSuiteWebhooks extends SeedsWebhooks
{
    public const ZIEL = 'https://example.invalid/hook';

    /** @var list<string> */
    protected array $eigeneAusgaenge = ['cw-abo-pausiert', 'cw-platz-angenommen', 'cw-kurs-eingeschrieben'];

    /** @var list<string> */
    protected array $eigeneEingaenge = [];

    /** @return array<string, int> */
    public function run(): array
    {
        $this->fuerMarke('chorwerkstatt', function () {
            foreach ([
                ['cw-abo-pausiert', 'Abo pausiert an die Buchhaltung', 'payments.subscription_paused'],
                ['cw-platz-angenommen', 'Platz angenommen an die Teamliste', 'offers.seat_accepted'],
                ['cw-kurs-eingeschrieben', 'Kurs-Einschreibung ans CRM', 'courses.learner_enrolled'],
            ] as [$handle, $name, $ausloeser]) {
                $this->ausgang([
                    'name' => $name,
                    'handle' => $handle,
                    'description' => 'Demo: Beispieladresse, die nie auflöst. Jede Zustellung scheitert mit Absicht.',
                    'enabled' => true,
                    'trigger_type' => $ausloeser,
                    'url' => self::ZIEL,
                    'method' => 'POST',
                    'auth_type' => 'none',
                    'auth_config' => [],
                    'payload_type' => 'raw_json',
                    'payload_template' => '{"ereignis": "{{ payload:event }}", "wann": "{{ payload:occurred_at }}", "gegenstand": "{{ payload:subject_id }}"}',
                    'queue_enabled' => false,
                    'timeout_seconds' => 2,
                    'retry_strategy' => [
                        'strategy' => 'linear',
                        'max_attempts' => 1,
                        'base_delay_seconds' => 0,
                        'max_delay_seconds' => 0,
                        'retry_on_status' => [],
                        'retry_on_network_errors' => false,
                    ],
                ]);
            }
        });

        $this->telemetrieAufraeumen();

        return [
            'suite_webhooks' => BrandContext::withoutBrandScope(
                fn () => OutboundWebhook::query()->whereIn('handle', $this->eigeneAusgaenge)->count()
            ),
        ];
    }
}
