<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\WebhookManager\Auth\Support\SignatureGenerator;
use Goldnead\WebhookManager\Contracts\Repositories\InboundEndpointRepositoryInterface;
use Goldnead\WebhookManager\Contracts\Repositories\OutboundWebhookRepositoryInterface;
use Goldnead\WebhookManager\Contracts\Repositories\RuleRepositoryInterface;
use Goldnead\WebhookManager\Contracts\Repositories\TemplateRepositoryInterface;
use Goldnead\WebhookManager\Domain\Delivery\Models\Delivery;
use Goldnead\WebhookManager\Domain\InboundEndpoint\Actions\CreateInboundEndpointAction;
use Goldnead\WebhookManager\Domain\InboundEndpoint\Actions\UpdateInboundEndpointAction;
use Goldnead\WebhookManager\Domain\InboundEndpoint\Models\InboundEndpoint;
use Goldnead\WebhookManager\Domain\Log\Models\LogEntry;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Actions\CreateOutboundWebhookAction;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Actions\DispatchOutboundWebhookAction;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Actions\UpdateOutboundWebhookAction;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Domain\Rule\Actions\CreateRuleAction;
use Goldnead\WebhookManager\Domain\Rule\Actions\TestRuleAction;
use Goldnead\WebhookManager\Domain\Rule\Models\Rule;
use Goldnead\WebhookManager\Domain\Settings\Models\WebhookSetting;
use Goldnead\WebhookManager\Domain\Template\Actions\CreateTemplateAction;
use Goldnead\WebhookManager\Domain\Template\Actions\UpdateTemplateAction;
use Goldnead\WebhookManager\Domain\Template\Models\Template;
use Goldnead\WebhookManager\Registries\PresetRegistry;
use Goldnead\WebhookManager\Services\DeliveryReplayService;
use Goldnead\WebhookManager\Support\Settings;
use Goldnead\WebhookManager\ValueObjects\ExecutionContext;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * Sechs Eingänge, sieben Ausgänge, und alles einmal wirklich geklopft.
 *
 * Acht Tabellen, alle acht leer: so stand der Webhook-Manager im Demo. Das
 * Addon kann Wiederholungen planen, Fehler klassifizieren, einen Empfänger
 * abschalten, Signaturen prüfen, Anfragen drosseln und eine Lieferung noch
 * einmal schicken — und keine Zeile davon war zu sehen.
 *
 * Hier passiert es. Angelegt wird über die Domain-Actions
 * ({@see CreateInboundEndpointAction}, {@see CreateOutboundWebhookAction} …),
 * nicht über `Model::create()`: nur so entsteht die Geheimnis-Prüfspur in
 * `webhook_secret_audits`, die sonst leer bliebe. Gefeuert wird über
 * {@see DispatchOutboundWebhookAction} und den Motor, nicht über den
 * HTTP-Client direkt: nur so entstehen Momentaufnahme, Klassifizierung,
 * Wiederholungsplan und Sicherung.
 *
 * Eingehende Anfragen laufen durch den echten Router
 * ({@see Route::dispatch()}), damit die Marken-Auflösung aus der URL, die
 * Drossel, die Prüfung und der Wiederholungsschutz genau das tun, was sie im
 * Betrieb tun. Ein Seeder, der stattdessen den Prozessor von Hand aufruft,
 * beweist über die URL nichts.
 */
class SeedsWebhooks
{
    /** Der tote Empfänger. Auf Port 9 lauscht nichts, und das ist Absicht. */
    protected const TOTER_EMPFAENGER = 'http://127.0.0.1:9/praxis/webhook';

    protected const SCHLUESSEL_CREW = 'crew-schluessel-Ännchen-2027';

    protected const TOKEN_PROBE = 'probe-token-ÖÄÜ-0815';

    protected const HMAC_GEHEIMNIS = 'signatur-geheimnis-für-nordlicht';

    /** Die Handles, die dieser Seeder besitzt — für das Aufräumen. */
    protected array $eigeneAusgaenge = [
        'marken-bruecke', 'praxis-tot', 'falsch-signiert',
        'nordlicht-slack', 'halbmond-json',
    ];

    protected array $eigeneEingaenge = [
        'studio-eingang', 'crew-eingang', 'probe-eingang',
        'praxis-eingang', 'signatur-eingang', 'hausnetz-eingang',
    ];

    /** Antwortcodes der eingehenden Anfragen, für den Bericht. @var array<int, int> */
    protected array $antworten = [];

    /** Der Handle des zuletzt wiederholten Lieferversuchs. */
    protected ?int $wiederholt = null;

    /** @return array<string, int> */
    public function run(): array
    {
        $this->einstellung();
        $this->beispieleAusDemAddon();
        $this->vorlagen();
        $this->eingaenge();
        $this->ausgaenge();
        $this->regel();

        $this->telemetrieAufraeumen();

        $this->anfragen();
        $this->ausliefern();
        $this->wiederholen();

        ksort($this->antworten);

        return BrandContext::withoutBrandScope(fn () => [
            'webhook_ausgaenge' => OutboundWebhook::query()->count(),
            'webhook_eingaenge' => InboundEndpoint::query()->count(),
            'webhook_vorlagen' => Template::query()->count(),
            'webhook_regeln' => Rule::query()->count(),
            'webhook_lieferungen' => Delivery::query()->count(),
            'webhook_protokoll' => LogEntry::query()->count(),
            'webhook_einstellungen' => WebhookSetting::query()->count(),
            'webhook_anfragen' => array_sum($this->antworten),
            // Die Antwortcodes als eine Zeile: was das Addon auf welchem Weg
            // abweist, ist die eigentliche Aussage dieses Seeders.
            'webhook_antwortcodes' => implode(', ', array_map(
                fn (int $code, int $wie_oft) => "{$code}×{$wie_oft}",
                array_keys($this->antworten),
                $this->antworten,
            )),
            'webhook_wiederholte_lieferung' => $this->wiederholt ?? 0,
        ]);
    }

    // -----------------------------------------------------------------
    // Konfiguration
    // -----------------------------------------------------------------

    /**
     * Eine Einstellung, die vom Konfigurationsfile abweicht.
     *
     * `webhook_settings` hält nur die Unterschiede: ein Wert, der wieder dem
     * File entspricht, löscht seine Zeile. Genau eine Abweichung reicht, um zu
     * zeigen, dass der Weg existiert — und der User-Agent ist der harmloseste
     * Wert, den man dafür nehmen kann.
     */
    protected function einstellung(): void
    {
        app(Settings::class)->save([
            'http.user_agent' => 'Nordlicht-Studio-Webhooks/1.0',
        ]);
    }

    /**
     * Erst das Kommando des Addons, dann das eigene.
     *
     * `webhook-manager:seed-examples` legt zwei Vorlagen und zwei
     * abgeschaltete Ausgänge an und überspringt, was schon da ist — aber nur,
     * solange eine Marke gesetzt ist. Das Kommando setzt selbst keine (es
     * trägt, anders als seine Geschwister, kein `RunsForEachBrand`), und im
     * Mehrmarkenbetrieb heißt das: `findByHandle()` liest durch einen
     * geschlossenen Bereich und findet nichts, während der Insert auf die
     * Standardmarke fällt. Beim zweiten Aufruf bricht es am eindeutigen Index
     * ab. Deshalb steht hier eine Marke drumherum.
     */
    protected function beispieleAusDemAddon(): void
    {
        $this->fuerMarke('nordlicht', fn () => Artisan::call('webhook-manager:seed-examples'));
    }

    /**
     * Eine eigene Payload-Vorlage mit Token.
     *
     * Zwei Namensräume, zwei Verhalten: `entry:*` löst nur unter einem
     * Entry-Auslöser auf und wird sonst zu einer leeren Zeichenkette,
     * `system:timestamp_iso` löst immer auf. Der Zeitstempel steht außerdem
     * aus einem zweiten Grund drin: er macht jeden Rumpf einmalig, und damit
     * hält der Wiederholungsschutz am anderen Ende zwei echte Lieferungen
     * nicht für dieselbe.
     */
    protected function vorlagen(): void
    {
        $this->fuerMarke('nordlicht', function () {
            $this->vorlage([
                'name' => 'Studio-Brücke — JSON',
                'handle' => 'studio-bruecke-json',
                'type' => Template::TYPE_OUTBOUND_BODY,
                // Kein `description`: die Tabelle hat keine solche Spalte, und
                // das Modell ist `$guarded = []` — ein Schlüssel zu viel ist
                // hier kein ignoriertes Feld, sondern ein SQL-Fehler.
                'meta' => ['zweck' => 'Rumpf der Brücke von Nordlicht zu Halbmond.'],
                'body' => json_encode([
                    'quelle' => 'nordlicht',
                    'anlass' => '{{ system:trigger }}',
                    'titel' => '{{ entry:title|default(\'ohne Titel\') }}',
                    'eintrag' => '{{ entry:id }}',
                    'site' => '{{ site:handle }}',
                    'gesendet' => '{{ system:timestamp_iso }}',
                    'vorgang' => '{{ system:correlation_id }}',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /**
     * Ein Eingang je Prüfverfahren, verteilt über vier Marken.
     *
     * Sechs Verfahren kennt das Addon, sechs stehen hier. Die Marke steckt im
     * Pfad (`/webhooks/inbound/{marke}/{handle}`), weil ein Absender nichts
     * anderes in der Hand hat als eine URL — ohne dieses Segment antwortet ein
     * Mehrmarken-Betrieb jeder Lieferung mit 404.
     */
    protected function eingaenge(): void
    {
        $this->fuerMarke('nordlicht', function () {
            // Offen, aber gedrosselt: drei Anfragen pro Minute. Der Eingang,
            // an dem die 429 zu sehen ist.
            $this->eingang([
                'name' => 'Studio-Eingang',
                'handle' => 'studio-eingang',
                'description' => 'Offener Sammelpunkt der Agentur. Ohne Prüfung, dafür scharf gedrosselt.',
                'auth_type' => 'none',
                'auth_config' => [],
                'rate_limit_config' => ['per_minute' => 3],
                'replay_protection_enabled' => false,
                'action_type' => 'audit_log',
                'logging_mode' => 'full',
            ]);

            // Signatur plus Wiederholungsschutz: derselbe Rumpf zweimal
            // bekommt eine 409, eine falsche Signatur eine 401.
            $this->eingang([
                'name' => 'Signatur-Eingang',
                'handle' => 'signatur-eingang',
                'description' => 'HMAC SHA256. Das Verfahren, das man wollen sollte.',
                'auth_type' => 'hmac',
                'auth_config' => [
                    'secret' => self::HMAC_GEHEIMNIS,
                    'algorithm' => 'sha256',
                    // Absichtlich NICHT gesetzt: eine Signatur ohne Zeitstempel
                    // läuft nie ab, und das Addon schreibt genau dafür eine
                    // Warnung ins Protokoll. Die soll im Demo zu sehen sein.
                    // 'require_timestamp' => true,
                ],
                'replay_protection_enabled' => true,
                'action_type' => 'audit_log',
            ]);

        });

        // Die Marke, deren Name eine Mail-Kopfzeile, eine URL und ein
        // HTML-Attribut zerlegt, bekommt auch einen Eingang: ihr *Handle* ist
        // brav, und genau das muss die URL tragen.
        $this->fuerMarke('sonderzeichen', function () {
            $this->eingang([
                'name' => 'Hausnetz-Eingang',
                'handle' => 'hausnetz-eingang',
                'description' => 'Nur aus dem eigenen Netz. Das schwächste der sechs Verfahren, und deshalb hier mit nichts dahinter.',
                'auth_type' => 'ip_allowlist',
                'auth_config' => ['ips' => ['127.0.0.1', '10.0.0.0/8', '::1']],
                'replay_protection_enabled' => false,
                'action_type' => 'noop',
            ]);
        });

        $this->fuerMarke('halbmond', function () {
            $this->eingang([
                'name' => 'Crew-Eingang',
                'handle' => 'crew-eingang',
                'description' => 'Fester Kopfzeilen-Schlüssel. Was die meisten SaaS-Anbieter anbieten.',
                'auth_type' => 'static_header',
                'auth_config' => ['header' => 'X-Studio-Schluessel', 'value' => self::SCHLUESSEL_CREW],
                'replay_protection_enabled' => true,
                'action_type' => 'noop',
            ]);
        });

        $this->fuerMarke('chorwerkstatt', function () {
            $this->eingang([
                'name' => 'Probe-Eingang',
                'handle' => 'probe-eingang',
                'description' => 'Bearer-Token, mit Feldzuordnung: fehlt die Adresse, ist es eine 422 und keine halbe Zeile.',
                'auth_type' => 'bearer',
                'auth_config' => ['token' => self::TOKEN_PROBE],
                'replay_protection_enabled' => false,
                'action_type' => 'audit_log',
                'mapping_config' => [
                    'email' => ['path' => 'teilnehmer.mail', 'required' => true, 'type' => 'string'],
                    'name' => ['path' => 'teilnehmer.name', 'default' => 'ohne Namen'],
                    'stimmlage' => ['path' => 'teilnehmer.stimme', 'default' => 'unbekannt'],
                ],
            ]);
        });

        $this->fuerMarke('lindhorst', function () {
            // Der kaputte: Basic-Auth kommt durch, danach soll ein Eintrag in
            // einer Sammlung entstehen, die es nicht gibt. Der Handler meldet
            // sauber „nicht ok", und weil dieser Eingang seinen Fehlerstatus
            // auf 403 gesetzt hat, ist das die Antwort. Ohne diese Zeile kennt
            // die ganze Kette keine einzige 403.
            $this->eingang([
                'name' => 'Praxis-Eingang',
                'handle' => 'praxis-eingang',
                'description' => 'Basic-Auth. Schreibt in eine Sammlung, die es nicht gibt — absichtlich.',
                'auth_type' => 'basic',
                'auth_config' => ['username' => 'praxis', 'password' => 'lindhorst-2027'],
                'replay_protection_enabled' => false,
                'action_type' => 'create_entry',
                'action_config' => ['collection' => 'gibt-es-nicht', 'slug_field' => 'slug'],
                'response_config' => ['success_status' => 201, 'failure_status' => 403],
            ]);
        });
    }

    /**
     * Zwei Presets und drei Ausgänge von Hand.
     *
     * Ein Preset ist kein zweiter Datentyp: es baut einen ganz normalen
     * Ausgang und übergibt ihn derselben Action, die das Formular benutzt.
     * Genau deshalb steht es hier — sieben werden angeboten, keines war je
     * installiert.
     */
    protected function ausgaenge(): void
    {
        $this->fuerMarke('nordlicht', function () {
            $this->preset('slack', [
                'name' => 'Nordlicht in Slack',
                'handle' => 'nordlicht-slack',
                'trigger_type' => 'entry.published',
                'webhook_url' => 'https://hooks.slack.example/services/T0NORD/B0LICHT/'.str_repeat('x', 24),
                'message' => 'Neu veröffentlicht: {{ entry:title }} ({{ site:handle }})',
            ]);

            // Die Brücke: der eigene Ausgang klopft am eigenen Eingang der
            // Marke Halbmond an. Beide Richtungen des Addons in einer Zeile,
            // und der einzige Ausgang im Demo, der wirklich eine 200 sieht.
            $this->ausgang([
                'name' => 'Brücke zu Halbmond',
                'handle' => 'marken-bruecke',
                'description' => 'Nordlicht meldet Halbmond, was veröffentlicht wurde — über den eigenen Eingang.',
                'enabled' => true,
                'trigger_type' => 'entry.published',
                'url' => rtrim((string) config('app.url'), '/').'/webhooks/inbound/halbmond/crew-eingang',
                'method' => 'POST',
                'auth_type' => 'static_header',
                'auth_config' => ['header' => 'X-Studio-Schluessel', 'value' => self::SCHLUESSEL_CREW],
                'payload_type' => 'raw_json',
                'payload_template_handle' => 'studio-bruecke-json',
                // Direkt senden statt über die Warteschlange, damit der Seeder
                // die Antwort in derselben Sekunde in der Hand hat.
                'queue_enabled' => false,
                'log_body_mode' => 'full',
                'timeout_seconds' => 10,
            ]);

            // Richtige Adresse, falsches Geheimnis. Der Klassifizierer macht
            // daraus `auth`, der Wiederholungsplaner macht daraus gar nichts:
            // 401 steht nicht in `retry_on_status`, und eine Signatur wird
            // beim zweiten Versuch nicht richtiger.
            $this->ausgang([
                'name' => 'Falsch signiert',
                'handle' => 'falsch-signiert',
                'description' => 'Signiert mit einem Geheimnis, das der Eingang nicht kennt.',
                'enabled' => true,
                'trigger_type' => 'entry.published',
                'url' => rtrim((string) config('app.url'), '/').'/webhooks/inbound/nordlicht/signatur-eingang',
                'method' => 'POST',
                'auth_type' => 'hmac',
                'auth_config' => ['secret' => 'das-ist-nicht-das-geheimnis', 'algorithm' => 'sha256'],
                'payload_type' => 'raw_json',
                'payload_template' => '{"anlass": "{{ system:trigger }}", "gesendet": "{{ system:timestamp_iso }}"}',
                'queue_enabled' => false,
                'log_body_mode' => 'full',
            ]);
        });

        $this->fuerMarke('halbmond', function () {
            $this->preset('generic_json', [
                'name' => 'Halbmond an das Presseblatt',
                'handle' => 'halbmond-json',
                'trigger_type' => 'entry.published',
                'url' => 'https://presse.halbmond.beispiel/eingang',
                'payload_template' => '{"platte": "{{ entry:title }}", "wann": "{{ system:timestamp_iso }}"}',
            ]);
        });

        $this->fuerMarke('lindhorst', function () {
            $this->ausgang([
                'name' => 'Praxissoftware (tot)',
                'handle' => 'praxis-tot',
                'description' => 'Port 9. Hier lauscht nichts, und genau darum geht es.',
                'enabled' => true,
                'trigger_type' => 'entry.published',
                'url' => self::TOTER_EMPFAENGER,
                'method' => 'POST',
                'auth_type' => 'none',
                'auth_config' => [],
                'payload_type' => 'raw_json',
                'payload_template' => '{"termin": "{{ entry:title }}", "wann": "{{ system:timestamp_iso }}"}',
                'queue_enabled' => false,
                'timeout_seconds' => 2,
                // Linear mit null Sekunden Grundabstand: der Plan wird
                // geschrieben wie im Betrieb, nur ohne die halbe Minute
                // Wartezeit dazwischen. Ein Seeder, der auf den echten
                // Abstand wartet, läuft niemand zweimal.
                'retry_strategy' => [
                    'strategy' => 'linear',
                    'max_attempts' => 3,
                    'base_delay_seconds' => 0,
                    'max_delay_seconds' => 60,
                    'retry_on_status' => [500, 502, 503, 504],
                    'retry_on_network_errors' => true,
                ],
            ]);
        });
    }

    /**
     * Eine Regel, und einmal ausgelöst.
     *
     * Die Regel-Maschine ist der dritte Weg des Addons neben Ein- und Ausgang
     * — „wenn X, und wenn Y stimmt, dann Z" — und stand im Demo genauso leer
     * wie der Rest. Ausgelöst wird über {@see TestRuleAction}, also über den
     * Knopf, den das Control Panel auf der Regel-Seite anbietet.
     */
    protected function regel(): void
    {
        $this->fuerMarke('nordlicht', function () {
            $regel = app(RuleRepositoryInterface::class)->findByHandle('lange-titel-melden');

            $daten = [
                'name' => 'Lange Titel melden',
                'handle' => 'lange-titel-melden',
                // Auch hier keine Beschreibung: `webhook_rules` hat die Spalte
                // nicht.
                'enabled' => true,
                'trigger_type' => 'entry.published',
                'trigger_config' => ['collection' => 'posts'],
                'conditions' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'collection', 'op' => 'equals', 'value' => 'posts'],
                        ['field' => 'data.title', 'op' => 'exists'],
                    ],
                ],
                'actions' => [
                    ['handle' => 'write_log_note', 'config' => [
                        'level' => 'warning',
                        'type' => 'langer_titel',
                        'message' => 'Ein Titel überschreitet die Spaltenbreite, die dafür vorgesehen war.',
                    ]],
                ],
                'stop_on_failure' => false,
                'order_index' => 0,
            ];

            if ($regel === null) {
                $regel = app(CreateRuleAction::class)($daten);
            } else {
                $regel->fill($daten);
                $regel = app(RuleRepositoryInterface::class)->save($regel);
            }

            app(TestRuleAction::class)($regel, [
                'collection' => 'posts',
                'data' => ['title' => DemoData::tooLong(220)],
            ], 'default');
        });
    }

    // -----------------------------------------------------------------
    // Verkehr
    // -----------------------------------------------------------------

    /**
     * Lieferungen und Protokollzeilen der eigenen Objekte wegräumen.
     *
     * Konfiguration wird wiederverwendet, Telemetrie neu erzeugt. Sonst
     * verdoppelt jeder zweite Seeder-Lauf die Lieferliste, und die Zahlen im
     * Bericht bedeuten nichts mehr.
     */
    protected function telemetrieAufraeumen(): void
    {
        BrandContext::withoutBrandScope(function () {
            $ausgaenge = OutboundWebhook::query()->whereIn('handle', $this->eigeneAusgaenge)->pluck('id');
            $eingaenge = InboundEndpoint::query()->whereIn('handle', $this->eigeneEingaenge)->pluck('id');

            $lieferungen = Delivery::query()->whereIn('outbound_webhook_id', $ausgaenge)->pluck('id');

            LogEntry::query()
                ->whereIn('related_webhook_id', $ausgaenge)
                ->orWhereIn('related_endpoint_id', $eingaenge)
                ->orWhereIn('related_delivery_id', $lieferungen)
                ->delete();

            Delivery::query()->whereIn('id', $lieferungen)->delete();

            // Die Drossel merkt sich über die Minute hinweg, wie oft geklopft
            // wurde. Ohne das Zurücksetzen bekäme ein zweiter Seeder-Lauf
            // innerhalb derselben Minute schon auf die erste Anfrage eine 429,
            // und die Reihenfolge im Demo wäre eine andere als die erzählte.
            foreach ($eingaenge as $id) {
                app(RateLimiter::class)->clear('webhook-manager:inbound:'.$id);
            }
        });
    }

    /**
     * Einmal gegen jeden Eingang klopfen, richtig und falsch.
     *
     * Durch den echten Router, nicht am Prozessor vorbei: die Marke kommt aus
     * dem Pfad, und das entscheidet eine Zwischenschicht, keine Abfrage.
     */
    protected function anfragen(): void
    {
        $rumpf = fn (array $daten) => json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 1) Ohne Prüfung, dreimal erlaubt, beim vierten Mal gedrosselt.
        foreach (range(1, 4) as $nr) {
            $this->klopfen('nordlicht', 'studio-eingang', $rumpf([
                'anlass' => 'sammlung.eingang',
                'nummer' => $nr,
                'kunde' => DemoData::AWKWARD_NAMES[0],
            ]));
        }

        // 2) Fester Schlüssel: einmal richtig, einmal falsch.
        $this->klopfen('halbmond', 'crew-eingang', $rumpf(['anlass' => 'crew.meldung', 'wann' => now()->toIso8601String()]), [
            'X-Studio-Schluessel' => self::SCHLUESSEL_CREW,
        ]);
        $this->klopfen('halbmond', 'crew-eingang', $rumpf(['anlass' => 'crew.meldung', 'wann' => now()->addSecond()->toIso8601String()]), [
            'X-Studio-Schluessel' => 'geraten',
        ]);

        // 3) Bearer: richtig, ohne Token, und mit einer Ladung, der das
        //    Pflichtfeld der Zuordnung fehlt.
        $this->klopfen('chorwerkstatt', 'probe-eingang', $rumpf([
            'teilnehmer' => ['mail' => DemoData::AWKWARD_EMAILS[1], 'name' => DemoData::AWKWARD_NAMES[2], 'stimme' => 'Alt'],
        ]), ['Authorization' => 'Bearer '.self::TOKEN_PROBE]);

        $this->klopfen('chorwerkstatt', 'probe-eingang', $rumpf(['teilnehmer' => ['name' => 'ohne Adresse']]));

        $this->klopfen('chorwerkstatt', 'probe-eingang', $rumpf(['teilnehmer' => ['name' => 'ohne Adresse']]), [
            'Authorization' => 'Bearer '.self::TOKEN_PROBE,
        ]);

        // 4) Basic: kommt durch, und scheitert danach an der Sammlung, die es
        //    nicht gibt — Antwort 403, weil dieser Eingang das so gesetzt hat.
        $this->klopfen('lindhorst', 'praxis-eingang', $rumpf(['slug' => 'termin-2027-01-31', 'titel' => 'Erstgespräch']), [], [
            'PHP_AUTH_USER' => 'praxis',
            'PHP_AUTH_PW' => 'lindhorst-2027',
        ]);

        // 5) HMAC: richtig signiert, dieselbe Lieferung noch einmal (409),
        //    und einmal mit einer Signatur aus dem falschen Geheimnis (401).
        $signiert = $rumpf(['anlass' => 'signatur.probe', 'betrag_cent' => DemoData::AWKWARD_AMOUNTS['krumm']]);
        $stempel = (string) time();
        $signatur = SignatureGenerator::compute($stempel.'.'.$signiert, self::HMAC_GEHEIMNIS);

        $kopf = [
            'X-Webhook-Timestamp' => $stempel,
            'X-Webhook-Signature' => 'sha256='.$signatur,
        ];

        $this->klopfen('nordlicht', 'signatur-eingang', $signiert, $kopf);
        $this->klopfen('nordlicht', 'signatur-eingang', $signiert, $kopf);

        $this->klopfen('nordlicht', 'signatur-eingang', $signiert, [
            'X-Webhook-Timestamp' => $stempel,
            'X-Webhook-Signature' => 'sha256='.SignatureGenerator::compute($stempel.'.'.$signiert, 'falsches-geheimnis'),
        ]);

        // 6) IP-Liste: aus dem Hausnetz und von außerhalb.
        $this->klopfen('sonderzeichen', 'hausnetz-eingang', $rumpf(['anlass' => 'hausnetz']), [], [], '127.0.0.1');
        $this->klopfen('sonderzeichen', 'hausnetz-eingang', $rumpf(['anlass' => 'von-draussen']), [], [], '203.0.113.7');

        // 7) Zwei Wege, die gar nicht erst in die Kette kommen: ein Handle,
        //    den es nicht gibt, und eine Methode, die dieser Eingang nicht
        //    annimmt.
        $this->klopfen('nordlicht', 'gibt-es-nicht', $rumpf(['anlass' => 'ins-leere']));
        // Bewusst nicht am gedrosselten Eingang: die Drossel ist Schritt 1 der
        // Kette, die Methodenprüfung Schritt 2 — am Studio-Eingang wäre nach
        // vier Anfragen die 429 die Antwort und die 405 nie zu sehen.
        $this->klopfen('halbmond', 'crew-eingang', '', [], [], '127.0.0.1', 'GET');
    }

    /**
     * Die Ausgänge feuern.
     *
     * Der tote Empfänger genau so oft, wie die Sicherung Fehlschläge braucht,
     * um zu fallen — die Zahl kommt aus der Konfiguration und nicht aus dieser
     * Datei, damit der Ausgang sich auch dann selbst abschaltet, wenn die
     * Schwelle woanders steht. Jeder Lauf kostet drei Versuche: einen sofort,
     * zwei über `webhook-manager:dispatch-retries`, also über den Weg, den der
     * Laravel-Scheduler jede Minute geht. Ein abgelehnter Verbindungsversuch
     * auf dem Loopback kostet eine Millisekunde; die Wiederholung ist hier
     * bezahlbar, weil der Empfänger nah und tot ist.
     */
    protected function ausliefern(): void
    {
        // Ohne laufenden Entwicklungsserver gibt es die Brücke nicht: sie ist
        // die einzige Lieferung im Demo, die eine echte 200 sieht.
        if ($this->siteErreichbar()) {
            $this->fuerMarke('nordlicht', function () {
                $this->feuern('marken-bruecke', 'nordlicht-notiz');
                $this->feuern('marken-bruecke', 'zugabe-und-was-danach-kam');
                $this->feuern('falsch-signiert', 'nordlicht-notiz');
            });
        }

        $this->fuerMarke('lindhorst', function () {
            foreach (range(1, $this->schwelle()) as $nr) {
                $this->feuern('praxis-tot', 'termin-'.$nr);

                // Der Rest des Wiederholungsplans. Ohne dieses Kommando bleibt
                // eine Lieferung für immer bei „nächster Versuch in …" stehen.
                Artisan::call('webhook-manager:dispatch-retries', ['--brand' => 'lindhorst']);
                Artisan::call('webhook-manager:dispatch-retries', ['--brand' => 'lindhorst']);
            }
        });
    }

    /**
     * Eine gescheiterte Lieferung noch einmal schicken.
     *
     * Sie scheitert wieder — auf Port 9 lauscht weiterhin nichts. Das ist
     * nicht der Mangel des Beispiels, sondern sein Inhalt: die Wiederholung
     * erhöht den Versuchszähler, setzt den Zustand zurück und schreibt eine
     * eigene Protokollzeile, und das ist genau das, was der Knopf im Control
     * Panel tut.
     */
    protected function wiederholen(): void
    {
        $this->fuerMarke('lindhorst', function () {
            $lieferung = Delivery::query()
                ->where('status', Delivery::STATUS_FAILED)
                ->orderBy('id')
                ->first();

            if ($lieferung !== null) {
                app(DeliveryReplayService::class)->replayOne($lieferung);
                $this->wiederholt = (int) $lieferung->id;
            }
        });
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $daten */
    protected function eingang(array $daten): void
    {
        $vorhanden = app(InboundEndpointRepositoryInterface::class)->findByHandle($daten['handle']);

        $vorhanden === null
            ? app(CreateInboundEndpointAction::class)($daten)
            : app(UpdateInboundEndpointAction::class)($vorhanden, $daten);
    }

    /** @param array<string, mixed> $daten */
    protected function ausgang(array $daten): void
    {
        $vorhanden = app(OutboundWebhookRepositoryInterface::class)->findByHandle($daten['handle']);

        if ($vorhanden === null) {
            app(CreateOutboundWebhookAction::class)($daten);

            return;
        }

        // Die Sicherung hat den Ausgang beim letzten Lauf abgeschaltet und den
        // Zähler stehen lassen. Beides zurücksetzen, sonst fällt sie beim
        // zweiten Seeder-Lauf schon beim ersten Fehlschlag.
        $daten['consecutive_failures'] = 0;

        app(UpdateOutboundWebhookAction::class)($vorhanden, $daten);
    }

    /** @param array<string, mixed> $daten */
    protected function vorlage(array $daten): void
    {
        $vorhanden = app(TemplateRepositoryInterface::class)->findByHandle($daten['handle']);

        $vorhanden === null
            ? app(CreateTemplateAction::class)($daten)
            : app(UpdateTemplateAction::class)($vorhanden, $daten);
    }

    /**
     * Ein Preset installieren — genau wie der Assistent im Control Panel:
     * Eingaben rein, `build()` macht daraus die Angaben eines Ausgangs, und
     * die gehen an dieselbe Action wie ein von Hand gefülltes Formular.
     *
     * @param  array<string, mixed>  $eingaben
     */
    protected function preset(string $handle, array $eingaben): void
    {
        $preset = app(PresetRegistry::class)->get($handle);

        if ($preset === null) {
            return;
        }

        $this->ausgang($preset->build($eingaben) + ['handle' => $eingaben['handle']]);
    }

    /**
     * Einen Ausgang für einen Eintrag feuern.
     *
     * Über {@see DispatchOutboundWebhookAction}, also über denselben Weg wie
     * der Auslöser und die Aktion „Webhook senden" in der Eintragsliste:
     * Momentaufnahme anlegen, dann senden. Wer stattdessen den HTTP-Client
     * nimmt, hat eine Anfrage gemacht und keine Lieferung.
     */
    protected function feuern(string $handle, string $referenz): void
    {
        $hook = app(OutboundWebhookRepositoryInterface::class)->findByHandle($handle);

        if ($hook === null) {
            return;
        }

        $ereignis = new TriggerEvent(
            triggerHandle: $hook->trigger_type,
            sourceType: 'entry',
            sourceReference: $referenz,
            payload: [
                'id' => $referenz,
                'title' => 'Nordlicht-Notiz: '.DemoData::AWKWARD_NAMES[1],
                'collection' => 'posts',
                'data' => ['title' => 'Nordlicht-Notiz: '.DemoData::AWKWARD_NAMES[1]],
            ],
            site: 'default',
            eventAt: new \DateTimeImmutable,
        );

        app(DispatchOutboundWebhookAction::class)($hook, new ExecutionContext($ereignis));
    }

    /**
     * Eine Anfrage an einen Eingang, durch den echten Router.
     *
     * @param  array<string, string>  $kopfzeilen
     * @param  array<string, string>  $server
     */
    protected function klopfen(
        string $marke,
        string $handle,
        string $rumpf,
        array $kopfzeilen = [],
        array $server = [],
        string $ip = '127.0.0.1',
        string $methode = 'POST',
    ): void {
        $pfad = '/'.trim((string) config('webhook-manager.inbound.route_prefix', 'webhooks/inbound'), '/')
            ."/{$marke}/{$handle}";

        $anfrage = Request::create(
            rtrim((string) config('app.url'), '/').$pfad,
            $methode,
            [],
            [],
            [],
            array_merge(['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], $server),
            $rumpf,
        );

        foreach ($kopfzeilen as $name => $wert) {
            $anfrage->headers->set($name, $wert);
        }

        // Der Controller nimmt seinen Request aus dem Container, nicht aus der
        // Pipeline (`__invoke(Request $request)` wird typbasiert aufgelöst).
        // In einem Konsolenlauf liegt dort ein leerer Request, und dann liest
        // er einen leeren Handle und antwortet auf alles mit 404. Der
        // HTTP-Kernel legt den echten Request an dieselbe Stelle; hier wird
        // dasselbe von Hand gemacht und danach zurückgesetzt.
        $vorher = app()->bound('request') ? app('request') : null;
        app()->instance('request', $anfrage);

        try {
            $antwort = Route::dispatch($anfrage);
            $status = $antwort->getStatusCode();
        } catch (\Throwable $e) {
            $status = 0;
        } finally {
            $vorher === null ? app()->forgetInstance('request') : app()->instance('request', $vorher);
        }

        $this->antworten[$status] = ($this->antworten[$status] ?? 0) + 1;
    }

    /**
     * Wie viele endgültige Fehlschläge die Sicherung braucht.
     *
     * Aus der Konfiguration gelesen statt hier festgeschrieben: eine
     * Installation, die den Wert höher setzt, soll trotzdem eine gefallene
     * Sicherung im Demo sehen. Nach oben begrenzt, damit ein sehr großer Wert
     * den Seeder nicht in hunderte Verbindungsversuche schickt; ist die
     * Sicherung ganz aus, bleiben es drei Lieferungen, und die Liste zeigt
     * dann eben nur den Wiederholungsplan.
     */
    protected function schwelle(): int
    {
        if (! config('webhook-manager.circuit_breaker.enabled', true)) {
            return 3;
        }

        $schwelle = (int) config('webhook-manager.circuit_breaker.threshold', 10);

        return $schwelle > 0 ? min($schwelle, 12) : 3;
    }

    /**
     * Läuft der Entwicklungsserver?
     *
     * Die Brücke schickt eine echte HTTP-Anfrage an die eigene Site. Ohne
     * laufenden Server wäre das nur eine weitere abgelehnte Verbindung, und
     * der eine Ausgang, der im Demo grün sein soll, wäre rot.
     */
    protected function siteErreichbar(): bool
    {
        $url = parse_url((string) config('app.url'));
        $host = $url['host'] ?? '127.0.0.1';
        $port = $url['port'] ?? (($url['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $verbindung = @fsockopen($host, (int) $port, $fehler, $text, 1.0);

        if ($verbindung === false) {
            return false;
        }

        fclose($verbindung);

        return true;
    }

    /** Etwas unter einer Marke tun. Ohne Marke fällt der Lesebereich zu. */
    protected function fuerMarke(string $handle, \Closure $was): mixed
    {
        $marke = Brand::query()->where('handle', $handle)->first();

        if ($marke === null) {
            return null;
        }

        return BrandContext::runFor($marke, $was);
    }
}
