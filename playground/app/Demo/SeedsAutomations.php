<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationAuditLog;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Support\AuditLogger;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Artisan;

/**
 * Vier Rezepte, eine Vorlage, und Läufe, die wirklich gelaufen sind.
 *
 * Das Addon bringt 77 Knotentypen mit. Ein Demo, in dem keiner davon
 * vorkommt, beweist nur, dass die Registrierung funktioniert — was niemand
 * bezweifelt hat. Hier laufen sie: gegen die 14 Zahlungen, die schon in der
 * Datenbank liegen, in echten Marken, mit echten Läufen, Knotenläufen und
 * Prüfspuren.
 *
 * Zwei Regeln, an die sich diese Datei hält:
 *
 * 1. **Nichts wird per Model::create() hingelegt.** Automationen entstehen
 *    über {@see AutomationRepository::save()}, werden über
 *    {@see VersionManager} versioniert und über {@see AuditLogger} protokolliert
 *    — derselbe Weg, den der Controller im Control Panel geht. Läufe entstehen
 *    über {@see WorkflowRunner::createRun()} und den Job, den auch der
 *    TriggerDispatcher wirft. Wer die Zeilen selbst schreibt, bekommt Daten
 *    ohne Ereignisse: keine Knotenläufe, keine Prüfspur, kein Beweis.
 * 2. **Zweimal laufen ändert nichts.** Automationen per Handle
 *    wiederverwendet, Läufe der eigenen Automationen vor dem Feuern gelöscht.
 *
 * Was hier absichtlich krumm ist, steht in {@see DemoData}: Namen mit
 * Apostroph und Emoji, ein Betrag von 999.999 Cent, ein Datum, das es in
 * keinem Jahr zweimal gibt.
 */
class SeedsAutomations
{
    /** Die Handles der Automationen, die dieser Seeder besitzt. */
    protected array $eigene = [];

    /** @return array<string, int> */
    public function run(): array
    {
        $this->kursBezahlt();
        $this->platteNachfassen();
        $this->schaukasten();
        $this->uebergabeKaputt();
        $this->vorlageInstallieren();

        $this->laeufeAufraeumen();

        $this->laufKursBezahlt();
        $this->laufSequenz();
        $this->laufSchaukasten();
        $this->laufKaputt();

        return BrandContext::withoutBrandScope(fn () => [
            'automationen' => Automation::query()->count(),
            'automation_knoten' => AutomationNode::query()->count(),
            'automation_kanten' => AutomationEdge::query()->count(),
            'automation_laeufe' => AutomationRun::query()->count(),
            'automation_knotenlaeufe' => AutomationNodeRun::query()->count(),
            'automation_pruefspur' => AutomationAuditLog::query()->count(),
            'automation_wartende_jobs' => AutomationScheduledJob::query()->count(),
        ]);
    }

    // -----------------------------------------------------------------
    // Die Rezepte
    // -----------------------------------------------------------------

    /**
     * Geld da, Mail raus, Kontakt anlegen, Etikett dran, Notiz rein.
     *
     * Der Fall, für den dieses Addon gebaut wurde: ein Ereignis aus einem
     * Nachbar-Addon (`payments.paid`), eine Mail, und drei Schreibvorgänge im
     * CRM, die vorher jemand von Hand als Laravel-Listener geschrieben hätte.
     * Die Kette ist bewusst länger als nötig: `leadhub.create_or_update_lead` legt
     * `lead` in den Kontext, und erst dadurch lösen `{{ lead.id }}` in den
     * beiden folgenden Knoten überhaupt auf.
     */
    protected function kursBezahlt(): void
    {
        $this->automation('chorwerkstatt', 'kurs-bezahlt', 'Kurs bezahlt', true,
            'Zahlung für cw-kurs bestätigt: Mail an die Käuferin, Kontakt im CRM anlegen oder ergänzen, Etikett und Notiz setzen.',
            [
                $this->knoten('ausloeser', 'payments.paid', 'Zahlung bestätigt', 0, 0, [
                    'product' => 'cw-kurs',
                ]),
                $this->knoten('mail', 'send_email', 'Zugang schicken', 260, 0, [
                    'to' => '{{ payment.email }}',
                    'subject' => 'Dein Platz im Frühlingskurs steht',
                    'body' => "Hallo {{ payment.name }},\n\n"
                        ."deine Zahlung über {{ payment.amount_cent }} Cent ({{ payment.currency }}) ist angekommen.\n"
                        ."Produkt: {{ payment.product }}\nAnbieter: {{ payment.provider }}\n\n"
                        .'Bis zum ersten Abend.',
                    'reply_to' => 'antwort@chorwerkstatt.beispiel',
                    // Ohne Schlüssel schickt ein zweiter Lauf dieselbe Mail
                    // noch einmal. Der Anbieter liefert seinen Webhook gern
                    // dreimal.
                    'dedupe' => 'kurs-zugang',
                ]),
                $this->knoten('kontakt', 'leadhub.create_or_update_lead', 'Kontakt anlegen', 520, 0, [
                    'email' => '{{ payment.email }}',
                    'first_name' => '{{ payment.name }}',
                    'source' => 'Zahlung',
                ]),
                $this->knoten('etikett', 'leadhub.add_tag', 'Etikett dran', 780, 0, [
                    'lead_id' => '{{ lead.id }}',
                    'tag' => 'kurs-bezahlt',
                ]),
                $this->knoten('notiz', 'leadhub.add_note', 'Notiz ins CRM', 1040, 0, [
                    'lead_id' => '{{ lead.id }}',
                    'body' => 'Hat {{ payment.product }} bezahlt: {{ payment.amount_cent }} Cent über {{ payment.provider }}.',
                ]),
            ],
            [
                ['ausloeser', 'mail'],
                ['mail', 'kontakt'],
                ['kontakt', 'etikett'],
                ['etikett', 'notiz'],
            ],
        );
    }

    /**
     * Zwei Mails, zwei Tage Pause dazwischen.
     *
     * Die einfachste Form einer Serie, und die einzige, die beweist, dass ein
     * Lauf überhaupt pausieren kann: der `delay`-Knoten parkt den Lauf im
     * Zustand `waiting` und legt einen Eintrag in `automation_scheduled_jobs`.
     * Ein Demo ohne einen einzigen wartenden Lauf zeigt die Hälfte des Motors.
     */
    protected function platteNachfassen(): void
    {
        $this->automation('halbmond', 'platte-nachfassen', 'Platte nachfassen', true,
            'Nach dem Vinylkauf: Bestätigung sofort, zwei Tage später die Nachfrage.',
            [
                $this->knoten('ausloeser', 'payments.paid', 'Vinyl bezahlt', 0, 0, [
                    'product' => 'hm-vinyl',
                ]),
                $this->knoten('mail_sofort', 'send_email', 'Bestätigung', 260, 0, [
                    'to' => '{{ payment.email }}',
                    'subject' => 'Deine Platte ist notiert',
                    'body' => "Danke. Nummeriert, unterschrieben, geht raus, sobald der Stapel steht.\n\n"
                        .'Bestellung: {{ payment.id }}',
                ]),
                $this->knoten('pause', 'delay', 'Zwei Tage warten', 520, 0, [
                    'amount' => 2,
                    'unit' => 'days',
                ]),
                $this->knoten('mail_spaeter', 'send_email', 'Nachfassen', 780, 0, [
                    'to' => '{{ payment.email }}',
                    'subject' => 'Angekommen?',
                    'body' => 'Kurze Frage: ist die Platte da, und ist sie heil? Antwort genügt.',
                ]),
            ],
            [
                ['ausloeser', 'mail_sofort'],
                ['mail_sofort', 'pause'],
                ['pause', 'mail_spaeter'],
            ],
        );
    }

    /**
     * Der Schaukasten: alle neun Logik-Knoten in einem Graphen.
     *
     * Nicht als Kunststück, sondern weil die Logik-Knoten die Knoten sind, die
     * ein Demo sonst nie erreicht — ein Rezept aus dem echten Leben braucht
     * selten mehr als Filter und Mail, und `switch`, `loop`, `parallel`,
     * `throttle` und `wait_until` bleiben deshalb ungeprüfte Behauptungen.
     *
     * Der Filter trägt sechzehn Bedingungen, weil `ConditionEvaluator` zwanzig
     * Operatoren kennt und ein Demo, das nur `equals` benutzt, über die
     * anderen neunzehn nichts sagt. Die restlichen vier stecken im `branch`
     * (Modus „irgendeine") und im `wait_until`.
     *
     * Die Beispieldaten hängen am Auslöser-Knoten (`sample_payload`), nicht im
     * Seeder: so füllt der Test-Knopf im Control Panel dieselbe Ladung, die
     * hier unten wirklich durchgelaufen ist.
     */
    protected function schaukasten(): void
    {
        $this->automation('nordlicht', 'schaukasten-logik', 'Schaukasten: Logik', true,
            'Alle Logik-Knoten an einem Auftrag: Variablen, Doppel-Sperre, Filter, Verzweigung, Weiche, Schleife, Warten, Fächer.',
            [
                $this->knoten('ausloeser', 'manual', 'Von Hand starten', 0, 0, [
                    'sample_payload' => json_encode($this->beispielAuftrag(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]),
                $this->knoten('variablen', 'set_variable', 'Variablen setzen', 240, 0, [
                    'variables' => [
                        'kanal' => '{{ auftrag.kanal }}',
                        'betrag_euro' => '{{ auftrag.betrag_cent }}',
                        'kennung' => 'nordlicht/{{ auftrag.nummer }}',
                    ],
                ]),
                $this->knoten('doppelsperre', 'throttle', 'Doppelt abfangen', 480, 0, [
                    // Absichtlich NICHT die Auftragsnummer: die bleibt über
                    // alle Läufe gleich, und dann hielte die Sperre schon den
                    // zweiten Lauf an, bevor irgendetwas anderes drankommt.
                    'key' => 'schaukasten/{{ lauf }}',
                    'window_minutes' => 60,
                ]),
                $this->knoten('filter', 'filter', 'Sechzehn Bedingungen', 720, 0, [
                    'mode' => 'all',
                    'conditions' => $this->alleOperatoren(),
                ]),
                $this->knoten('verzweigung', 'branch', 'Offen oder nicht', 960, 0, [
                    'mode' => 'any',
                    'conditions' => [
                        ['field' => 'auftrag.status', 'operator' => 'status_is', 'value' => 'offen'],
                        ['field' => 'auftrag.status', 'operator' => 'equals', 'value' => 'wartet'],
                    ],
                ]),
                $this->knoten('abbruch', 'stop', 'Storniert, Ende', 1200, 220, [
                    'reason' => 'Der Auftrag ist nicht mehr offen.',
                ]),
                $this->knoten('weiche', 'switch', 'Stufe wählen', 1200, -120, [
                    'value' => '{{ auftrag.stufe }}',
                    'cases' => ['gold' => 'gold', 'silber' => 'silber'],
                ]),
                $this->knoten('log_silber', 'add_log_entry', 'Silber vermerken', 1440, 60, [
                    'level' => 'info',
                    'message' => 'Stufe silber für {{ vars.kennung }}',
                ]),
                $this->knoten('sonst', 'stop', 'Unbekannte Stufe', 1440, 200, [
                    'reason' => 'Stufe {{ auftrag.stufe }} ist keine, die wir bedienen.',
                ]),
                $this->knoten('schleife', 'loop', 'Je Stück', 1440, -160, [
                    'items' => '{{ auftrag.stuecke }}',
                    'mode' => 'inline',
                    'item_key' => 'item',
                    'max_iterations' => 25,
                ]),
                $this->knoten('log_stueck', 'add_log_entry', 'Stück protokollieren', 1680, -280, [
                    'level' => 'info',
                    'message' => 'Stück {{ loop.index }} von {{ loop.count }}: {{ item.titel }} ({{ item.dauer }}s)',
                    'context_keys' => ['vars.kennung'],
                ]),
                $this->knoten('warten', 'wait_until', 'Bis der Betrag passt', 1680, -80, [
                    'mode' => 'all',
                    'conditions' => [
                        ['field' => 'auftrag.betrag_cent', 'operator' => 'less_than_or_equal', 'value' => 999999],
                        ['field' => 'auftrag.kunde', 'operator' => 'is_not_empty'],
                    ],
                    'recheck_minutes' => 15,
                ]),
                $this->knoten('faecher', 'parallel', 'Zwei Wege gleichzeitig', 1920, -80, [
                    'mode' => 'inline',
                    'branches' => ['mail' => 'Versand', 'pruef' => 'Prüfung'],
                ]),
                $this->knoten('log_mail', 'add_log_entry', 'Versandzweig', 2160, -180, [
                    'level' => 'notice',
                    'message' => 'Versand angestoßen für {{ vars.kennung }}',
                ]),
                $this->knoten('log_pruef', 'add_log_entry', 'Prüfzweig', 2160, 20, [
                    'level' => 'notice',
                    'message' => 'Prüfung angestoßen für {{ vars.kennung }}',
                ]),
            ],
            [
                ['ausloeser', 'variablen'],
                ['variablen', 'doppelsperre'],
                ['doppelsperre', 'filter'],
                ['filter', 'verzweigung'],
                ['verzweigung', 'weiche', 'true'],
                ['verzweigung', 'abbruch', 'false'],
                ['weiche', 'schleife', 'gold'],
                ['weiche', 'log_silber', 'silber'],
                ['weiche', 'sonst', 'default'],
                ['schleife', 'log_stueck', 'loop'],
                ['schleife', 'warten', 'done'],
                ['warten', 'faecher'],
                ['faecher', 'log_mail', 'mail'],
                ['faecher', 'log_pruef', 'pruef'],
            ],
        );
    }

    /**
     * Die, die rot wird.
     *
     * Ein Demo, in dem alles grün ist, zeigt die uninteressantere Hälfte der
     * Oberfläche. Hier steht am Ende der Kette ein Webhook auf Port 9 — der
     * Discard-Port, auf dem garantiert nichts lauscht. Der Knoten scheitert
     * mit „Connection refused", der Lauf endet als `failed`, und die
     * Lauf-Ansicht hat endlich etwas zu zeigen.
     */
    protected function uebergabeKaputt(): void
    {
        $this->automation('lindhorst', 'karte-uebergeben', 'Fünferkarte übergeben', true,
            'Nach dem Kauf der Fünferkarte: Bestätigung raus, dann Übergabe an die Praxissoftware — die es nicht gibt.',
            [
                $this->knoten('ausloeser', 'payments.paid', 'Karte bezahlt', 0, 0, [
                    'product' => 'lh-fuenferkarte',
                ]),
                $this->knoten('mail', 'send_email', 'Bestätigung', 260, 0, [
                    'to' => '{{ payment.email }}',
                    'subject' => 'Fünf Termine, notiert',
                    'body' => 'Deine Karte über {{ payment.amount_cent }} Cent ist hinterlegt.',
                ]),
                $this->knoten('uebergabe', 'send_webhook', 'An die Praxissoftware', 520, 0, [
                    // Port 9 ist der Discard-Port. Hier lauscht nichts, und
                    // genau das ist der Punkt.
                    'url' => 'http://127.0.0.1:9/praxis/karten',
                    'method' => 'POST',
                    'headers' => ['X-Praxis' => 'lindhorst'],
                    'payload' => '{"karte": "{{ payment.id }}", "kunde": "{{ payment.email }}"}',
                    'timeout' => 2,
                ]),
                $this->knoten('nie', 'add_log_entry', 'Wird nie erreicht', 780, 0, [
                    'level' => 'info',
                    'message' => 'Karte {{ payment.id }} übergeben.',
                ]),
            ],
            [
                ['ausloeser', 'mail'],
                ['mail', 'uebergabe'],
                ['uebergabe', 'nie'],
            ],
        );
    }

    /**
     * Eine der mitgelieferten Vorlagen, installiert.
     *
     * Sechzehn Vorlagen liegen im Katalog, keine einzige war je installiert —
     * der Weg „Vorlage → eigene Automation" stand nur in der Beschreibung.
     * Genommen wird die, die beide Addons verbindet: sie hängt am Auslöser
     * `webhook_manager.outbound_failed` und feuert, sobald der tote Empfänger
     * aus {@see SeedsWebhooks} seine Wiederholungen aufgebraucht hat. Dafür
     * muss dieser Seeder vor {@see SeedsWebhooks} laufen — sonst steht die
     * Automation da, wenn das Ereignis schon vorbei ist.
     *
     * Kopiert wird über {@see TemplateRegistry::get()} — dieselbe Quelle, die
     * der Installier-Endpunkt im Control Panel liest. Nur der Handle ist fest
     * statt zufällig, damit ein zweiter Seeder-Lauf keine zweite Kopie anlegt.
     */
    protected function vorlageInstallieren(): void
    {
        $vorlage = app(TemplateRegistry::class)->get('webhook_failure_alert');

        if ($vorlage === null) {
            return;
        }

        // In der Marke, deren Empfänger tot ist. Der globale Bereich ist scharf:
        // eine Automation in Nordlicht hört ein Ereignis nicht, das unter
        // Lindhorst ausgelöst wird — und der tote Empfänger aus
        // {@see SeedsWebhooks} gehört Lindhorst.
        $this->automation('lindhorst', 'webhook-failure-alert', $vorlage['name'], true,
            $vorlage['description'] ?? null,
            array_map(fn (array $k) => [
                'node_key' => $k['node_key'],
                'type' => $k['type'],
                'label' => $k['label'] ?? null,
                'position_x' => (int) ($k['position_x'] ?? 0),
                'position_y' => (int) ($k['position_y'] ?? 0),
                'config' => $k['config'] ?? [],
            ], $vorlage['nodes']),
            array_map(fn (array $k) => [
                $k['from_node_key'],
                $k['to_node_key'],
                $k['from_output'] ?? 'default',
            ], $vorlage['edges'] ?? []),
            vorlage: 'webhook_failure_alert',
        );
    }

    // -----------------------------------------------------------------
    // Die Läufe
    // -----------------------------------------------------------------

    /**
     * Läufe der eigenen Automationen wegräumen, bevor neu gefeuert wird.
     *
     * Ein Seeder, den man zweimal laufen lässt, darf die Lauf-Liste nicht
     * verdoppeln. Fremde Läufe bleiben unangetastet.
     */
    protected function laeufeAufraeumen(): void
    {
        BrandContext::withoutBrandScope(function () {
            $ids = Automation::query()
                ->whereIn('handle', $this->eigene)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            $laufIds = AutomationRun::query()->whereIn('automation_id', $ids)->pluck('id');

            AutomationNodeRun::query()->whereIn('automation_run_id', $laufIds)->delete();
            AutomationScheduledJob::query()->whereIn('automation_id', $ids)->delete();
            AutomationRun::query()->whereIn('id', $laufIds)->delete();
        });
    }

    /**
     * Die drei bezahlten cw-kurs-Zahlungen durch die Kette schicken.
     *
     * Geworfen wird das echte Ereignis des Zahlungs-Addons, nicht der
     * Trigger von Hand: nur so läuft der Weg, den ein bezahlter Mollie-Webhook
     * im Betrieb auch nimmt — Listener, Anmelde-Schranke, Job, Motor.
     */
    protected function laufKursBezahlt(): void
    {
        $this->fuerMarke('chorwerkstatt', function () {
            foreach ($this->zahlungen('cw-kurs', 'paid') as $zahlung) {
                PaymentPaid::dispatch($zahlung);
            }
        });
    }

    /**
     * Die Sequenz zweimal: einmal geparkt, einmal durchgelaufen.
     *
     * Der erste Lauf bleibt stehen, wo er im Betrieb auch stehen bliebe — im
     * `delay`, zwei Tage vor der zweiten Mail. Für den zweiten wird die
     * Fälligkeit vorgezogen und `automations:run-due` angestoßen, also genau
     * der Weg, den der Laravel-Scheduler jede Minute geht. Ein Demo, das nur
     * wartende Läufe hat, zeigt nie, wie einer weitergeht.
     */
    protected function laufSequenz(): void
    {
        $vinyl = $this->zahlungen('hm-vinyl', 'paid');

        if ($vinyl->isEmpty()) {
            return;
        }

        $this->fuerMarke('halbmond', fn () => PaymentPaid::dispatch($vinyl->first()));

        // Zweiter Lauf derselben Zahlung: er parkt genauso, und den holen wir
        // dann ein. Die `dedupe`-Sperre steht hier bewusst nicht an der Mail —
        // das Nachfassen soll wirklich rausgehen.
        $this->fuerMarke('halbmond', fn () => PaymentPaid::dispatch($vinyl->first()));

        $job = BrandContext::withoutBrandScope(fn () => AutomationScheduledJob::query()
            ->where('status', AutomationScheduledJob::STATUS_PENDING)
            ->orderByDesc('id')
            ->first());

        if ($job === null) {
            return;
        }

        // Die zwei Tage im Schnelldurchlauf. Kein Eingriff in den Motor: nur
        // die Uhr, gegen die er prüft.
        BrandContext::withoutBrandScope(fn () => AutomationScheduledJob::query()
            ->whereKey($job->getKey())
            ->update(['due_at' => now()->subMinute()]));

        Artisan::call('automations:run-due', ['--brand' => 'halbmond']);
    }

    /**
     * Vier Läufe durch den Schaukasten, und nur einer davon geht durch.
     *
     * 1. voll durch: Filter erfüllt, Verzweigung wahr, Weiche auf gold,
     *    Schleife über drei Stücke, Warten sofort erfüllt, Fächer auf zwei Wege
     * 2. dieselbe Lauf-Kennung: die Doppel-Sperre hält ihn an
     * 3. Betrag zu klein: der Filter hält ihn an
     * 4. storniert: die Verzweigung schickt ihn in den Stop-Knoten
     */
    protected function laufSchaukasten(): void
    {
        $kennung = 'lauf-'.now()->format('Ymd-His');

        $this->fuerMarke('nordlicht', function () use ($kennung) {
            $this->manuellStarten('schaukasten-logik', ['lauf' => $kennung.'-a']);
            $this->manuellStarten('schaukasten-logik', ['lauf' => $kennung.'-a']);
            $this->manuellStarten('schaukasten-logik', [
                'lauf' => $kennung.'-b',
                'auftrag' => ['betrag_cent' => DemoData::AWKWARD_AMOUNTS['ein_cent']],
            ]);
            $this->manuellStarten('schaukasten-logik', [
                'lauf' => $kennung.'-c',
                'auftrag' => ['status' => 'storniert'],
            ]);
        });
    }

    /** Die Fünferkarte übergeben, an eine Software, die es nicht gibt. */
    protected function laufKaputt(): void
    {
        $this->fuerMarke('lindhorst', function () {
            foreach ($this->zahlungen('lh-fuenferkarte', 'paid') as $zahlung) {
                PaymentPaid::dispatch($zahlung);
            }
        });
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /**
     * Eine Automation anlegen oder auf den neuen Stand bringen.
     *
     * Genau der Ablauf des CP-Controllers: speichern über das Repository,
     * versionieren, protokollieren. Die Prüfspur ist der Grund, warum das hier
     * nicht abgekürzt wird — `automation_audit_logs` ist eine der drei
     * Tabellen, die im Demo leer standen.
     *
     * @param  list<array<string, mixed>>  $knoten
     * @param  list<array{0:string,1:string,2?:string}>  $kanten
     */
    protected function automation(
        string $marke,
        string $handle,
        string $name,
        bool $aktiv,
        ?string $beschreibung,
        array $knoten,
        array $kanten,
        ?string $vorlage = null,
    ): void {
        $this->eigene[] = $handle;

        $this->fuerMarke($marke, function () use ($handle, $name, $beschreibung, $aktiv, $knoten, $kanten, $vorlage) {
            $vorhanden = Automation::query()->where('handle', $handle)->first();
            $neu = $vorhanden === null;

            $automation = $vorhanden ?? new Automation(['handle' => $handle]);
            $automation->name = $name;
            $automation->description = $beschreibung;
            $automation->enabled = $aktiv;
            $automation->version = (int) ($automation->version ?: 0) + 1;

            $automation = app(AutomationRepository::class)->save(
                $automation,
                $knoten,
                array_map(fn (array $k) => [
                    'from_node_key' => $k[0],
                    'to_node_key' => $k[1],
                    'from_output' => $k[2] ?? 'default',
                ], $kanten),
            );

            // Nur beim Anlegen. Ein Seeder, der bei jedem Lauf eine Revision
            // schreibt, füllt den Verlauf mit Rauschen statt mit Geschichte.
            if ($neu) {
                app(VersionManager::class)->snapshot(
                    $automation,
                    $vorlage ? "Aus der Vorlage „{$vorlage}“ installiert" : 'Vom Demo-Seeder angelegt',
                );
            }

            app(AuditLogger::class)->record($neu ? 'created' : 'updated', $automation, array_filter([
                'name' => $automation->name,
                'version' => $automation->version,
                'template' => $vorlage,
            ]));

            if ($aktiv && $neu) {
                app(AuditLogger::class)->record('enabled', $automation);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function knoten(string $key, string $typ, string $label, int $x, int $y, array $config = []): array
    {
        return [
            'node_key' => $key,
            'type' => $typ,
            'label' => $label,
            'position_x' => $x,
            'position_y' => $y,
            'config' => $config,
        ];
    }

    /**
     * Einen manuellen Lauf starten, so wie der TriggerDispatcher es täte.
     *
     * Kein Testmodus: ein Testlauf überspringt jede Aktion mit Nebenwirkung
     * und schreibt „hätte gesendet" in die Ausgabe. Das ist beim Bauen richtig
     * und im Demo wertlos.
     *
     * @param  array<string, mixed>  $ueberschreibungen
     */
    protected function manuellStarten(string $handle, array $ueberschreibungen = []): void
    {
        $automation = Automation::query()->with(['nodes', 'edges'])->where('handle', $handle)->first();

        if ($automation === null) {
            return;
        }

        $ausloeser = $automation->nodes->first(fn ($n) => $n->type === 'manual');

        if ($ausloeser === null) {
            return;
        }

        // Beispieldaten aus dem Knoten selbst, damit der Test-Knopf im CP
        // dieselbe Ladung sieht. Darüber nur, was dieser Lauf anders macht.
        $daten = json_decode((string) ($ausloeser->config['sample_payload'] ?? '{}'), true) ?: [];
        $daten = array_replace_recursive($daten, $ueberschreibungen);

        $kontext = AutomationContext::make($daten);
        $lauf = app(WorkflowRunner::class)->createRun($automation, $kontext, $ausloeser);

        RunAutomation::dispatchSync($lauf->id, $kontext->all(), false);
    }

    /** Zahlungen eines Produkts in einem Zustand. */
    protected function zahlungen(string $produkt, string $status)
    {
        return Payment::query()
            ->where('product', $produkt)
            ->where('status', $status)
            ->orderBy('id')
            ->get();
    }

    /**
     * Etwas unter einer Marke tun.
     *
     * Ohne das läuft im Mehrmarkenbetrieb gar nichts: der globale Bereich
     * fällt zu, wenn keine Marke gesetzt ist, und jede Abfrage liefert null
     * Zeilen — der Seeder meldet Erfolg und hat nichts getan.
     */
    protected function fuerMarke(string $handle, \Closure $was): mixed
    {
        $marke = Brand::query()->where('handle', $handle)->first();

        if ($marke === null) {
            return null;
        }

        return BrandContext::runFor($marke, $was);
    }

    /**
     * Ein Auftrag, an dem sich alle Operatoren festmachen lassen.
     *
     * Die Werte kommen aus {@see DemoData}: ein Kundenname mit Ampersand und
     * spitzer Klammer, ein Betrag, der jede Spaltenbreite ausreizt, ein Datum
     * am Monatsende, und ein Stücktitel, der mit einem Emoji anfängt.
     *
     * @return array<string, mixed>
     */
    protected function beispielAuftrag(): array
    {
        return [
            'lauf' => 'beispiel',
            'site' => 'default',
            'formular' => 'anmeldung',
            'sammlung' => 'posts',
            'auftrag' => [
                'nummer' => 'NL-2027-0031',
                'kunde' => DemoData::AWKWARD_NAMES[1],
                'betrag_cent' => DemoData::AWKWARD_AMOUNTS['sehr_gross'],
                'dauer_min' => 90,
                'kanal' => 'studio',
                'stufe' => 'gold',
                'status' => 'offen',
                'eingang' => DemoData::awkwardDates()['monatsende'],
                'marken' => ['nordlicht', 'halbmond'],
                'notiz' => '',
                'stuecke' => [
                    ['titel' => 'Ännchen von Tharau', 'dauer' => 214],
                    ['titel' => DemoData::AWKWARD_NAMES[8], 'dauer' => 95],
                    ['titel' => DemoData::AWKWARD_NAMES[9], 'dauer' => 1],
                ],
            ],
        ];
    }

    /**
     * Sechzehn Bedingungen, sechzehn Operatoren.
     *
     * `ConditionEvaluator` kennt zwanzig. Vier davon (`status_is`, `equals` im
     * Modus „irgendeine", `less_than_or_equal`, `is_not_empty`) stehen in der
     * Verzweigung und im Warteknoten, damit auch die beiden Modi `all` und
     * `any` einmal vorkommen.
     *
     * @return list<array<string, mixed>>
     */
    protected function alleOperatoren(): array
    {
        return [
            ['field' => 'auftrag.betrag_cent', 'operator' => 'greater_than', 'value' => 1000],
            ['field' => 'auftrag.betrag_cent', 'operator' => 'greater_than_or_equal', 'value' => 99900],
            ['field' => 'auftrag.dauer_min', 'operator' => 'less_than', 'value' => 120],
            ['field' => 'auftrag.kanal', 'operator' => 'equals', 'value' => 'studio'],
            ['field' => 'auftrag.stufe', 'operator' => 'does_not_equal', 'value' => 'bronze'],
            ['field' => 'auftrag.kunde', 'operator' => 'contains', 'value' => 'Chor'],
            ['field' => 'auftrag.kunde', 'operator' => 'does_not_contain', 'value' => 'Testkunde'],
            ['field' => 'auftrag.nummer', 'operator' => 'starts_with', 'value' => 'NL-'],
            ['field' => 'auftrag.nummer', 'operator' => 'ends_with', 'value' => '-0031'],
            ['field' => 'auftrag.marken', 'operator' => 'includes_tag', 'value' => 'nordlicht'],
            ['field' => 'auftrag.notiz', 'operator' => 'is_empty'],
            ['field' => 'auftrag.eingang', 'operator' => 'date_before', 'value' => '2099-01-01'],
            ['field' => 'auftrag.eingang', 'operator' => 'date_after', 'value' => '2020-01-01'],
            ['field' => 'auftrag.stufe', 'operator' => 'matches_regex', 'value' => '/^(gold|silber)$/'],
            ['field' => 'formular', 'operator' => 'form_is', 'value' => 'anmeldung'],
            ['field' => 'sammlung', 'operator' => 'collection_is', 'value' => 'posts'],
            ['field' => 'site', 'operator' => 'site_is', 'value' => 'default'],
        ];
    }
}
