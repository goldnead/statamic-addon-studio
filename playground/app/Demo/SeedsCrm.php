<?php

namespace App\Demo;

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityRecord;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Note;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\ScoringRule;
use Goldnead\Leadhub\Models\StageTransition;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\SegmentService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\LeadMagnets\Facades\LeadMagnets;
use Goldnead\LeadMagnets\Models\Download;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Subscription as MarketingSubscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Suppression\Facades\Suppression;
use Goldnead\Suppression\Models\Suppression as SuppressionRecord;
use Goldnead\Suppression\Reasons;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Form;
use Statamic\Facades\User;

/**
 * Die Leute, und alles, was über einen wahr sein kann.
 *
 * ## Warum diese Datei einmal falsch war
 *
 * Bis zum 25.08.2026 stand hier durchweg
 * `Modell::withoutGlobalScopes()->updateOrCreate()`. Das sieht aus wie ein
 * Seeder und ist keiner: es schreibt an den Addons vorbei. Was dabei entstand,
 * war nicht ein bisschen ungenau, sondern in jeder Hinsicht daneben —
 *
 *   - Werte, die kein Addon kennt (`'confirmed'` statt
 *     `Subscription::STATUS_SUBSCRIBED`, `'soft_bounce'` statt
 *     `Reasons::SOFT_BOUNCE_THRESHOLD`, `'customer'` statt eines Schlüssels aus
 *     `config('leadhub.statuses')`),
 *   - falsche Skopierung (ein hartes Bounce auf einer Marke statt global,
 *     obwohl es eine Eigenschaft des Postfachs ist und keine der Beziehung),
 *   - halbe Aggregate (`download_count = 2` neben null Zeilen in
 *     `lead_magnet_downloads`, drei Freigaben ohne `entitlement_id` und damit
 *     ewig „ausstehend"),
 *   - und kein einziges Domänen-Ereignis, weshalb `leadhub_events`,
 *     `suppression_events` und `lead_magnet_downloads` alle auf 0 standen.
 *
 * Jetzt läuft alles über die öffentlichen Fassaden: `LeadHub`, `Suppression`,
 * `LeadMagnets`, `Entitlements`, `Notifications`, `Activity`, und für die
 * Anmeldungen der `SubscriptionService`. Der Unterschied ist nicht Stil. Ein
 * Demo, dessen Daten nie durch die Schreibwege gelaufen sind, prüft die
 * Schreibwege nicht — und die zu prüfen ist der zweite und wichtigere Zweck
 * dieses Playgrounds.
 *
 * ## Marken
 *
 * Kein `withoutGlobalScopes()` mehr, sondern `BrandContext::runFor()`. Die
 * Addons stempeln die Marke dann selbst, genau wie im Betrieb. Dieselbe Adresse
 * kommt unter zwei Marken in zwei Schreibweisen mit zwei Zuständen vor; wenn
 * die Skopierung echt ist, sieht jede Marke ihre eigene.
 *
 * ## Reihenfolge
 *
 * Sie ist nicht beliebig:
 *
 *   1. Listen vor Abonnements (ohne Liste kein Abonnement),
 *   2. Formular-Zuordnungen vor Einsendungen (ohne Zuordnung passiert nichts),
 *   3. Abonnements vor der Sperrliste. `bounce@beispiel.invalid` soll ein
 *      bestätigter Abonnent sein, dessen Postfach danach stirbt — anders
 *      herum hielte das Gatter schon die Bestätigungsmail zurück, die Anmeldung
 *      bliebe „ausstehend", und der Sendelauf hätte niemanden abzuweisen.
 */
class SeedsCrm
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken): array
    {
        $this->reste();

        (new SeedsCampaign)->listenSicherstellen($marken);

        $this->formularZuordnungen($marken);
        $this->kontakte($marken);
        $this->einsendungen($marken);
        $this->firmen($marken);
        $this->etikettenUndSegmente($marken);
        $this->pipeline($marken);
        $this->aufgaben($marken);
        $this->scoring($marken);
        $this->notizenUndNachfassen($marken);
        $this->zuweisungen($marken);
        $this->archiv($marken);
        $this->abonnenten($marken);
        $this->sperrliste($marken);
        $this->freebies($marken);
        $this->zugaenge($marken);
        $this->meldungen($marken);
        $this->spuren($marken);

        return [
            'kontakte' => Contact::withoutGlobalScopes()->count(),
            'zeitleiste' => TimelineEvent::withoutGlobalScopes()->count(),
            'abonnenten' => MarketingSubscription::withoutGlobalScopes()->count(),
            'gesperrt' => SuppressionRecord::withoutGlobalScopes()->count(),
            'freebies' => Resource::withoutGlobalScopes()->count(),
            'downloads' => Download::withoutGlobalScopes()->count(),
            'zugaenge' => Entitlement::withoutGlobalScopes()->count(),
            'meldungen' => NotificationItem::withoutGlobalScopes()->count(),
            'spuren' => ActivityRecord::withoutGlobalScopes()->count(),
        ];
    }

    /**
     * Was der alte Seeder hinterlassen hat.
     *
     * Kein Aufräumen um der Ordnung willen: das sind genau die Zeilen, die
     * dieser Umbau beheben soll, und sie stehen bereits in der Datenbank jedes
     * Rechners, auf dem `demo:seed` schon einmal lief. Ein Seeder, der die
     * richtigen Zeilen daneben schreibt und die falschen liegen lässt, hat
     * nichts repariert — er hat verdoppelt.
     *
     * Jede Regel hier ist eine Aussage des jeweiligen Addons, keine Meinung:
     *
     *   - Ein Anmeldestatus, den `Subscription` nicht kennt, ist keiner
     *     (`'confirmed'`).
     *   - Ein Sperrgrund, den `Reasons::assertKnown()` ablehnt, ist keiner
     *     (`'soft_bounce'`).
     *   - Ein globaler Grund auf einer Marke widerspricht `Reasons::isGlobal()`;
     *     `brand_id` MUSS dort 0 sein.
     *   - Eine Freigabe ohne Berechtigung kann seit 2.0 keinen Zustand haben.
     *   - `download_count` ist die Zahl der Zeilen in `lead_magnet_downloads`.
     *     Steht dort etwas anderes, ist es eine Behauptung ohne Beleg.
     *
     * Absichtlich kaputte Fälle sind davon nicht berührt: die stehen in
     * `SeedsCommerce` und `SeedsFunnels` und werden hier nicht angefasst.
     */
    protected function reste(): void
    {
        // Ein `uniqueness_key`, der nicht zu Liste und Adresse derselben Zeile
        // passt, kann nicht vom Addon stammen: er wird aus genau diesen beiden
        // Werten abgeleitet. Der alte Seeder hat ihn sich selbst zusammengebaut
        // (`marke:liste:adresse`), weshalb `subscribe()` die Zeile nie fand und
        // eine zweite danebenlegte.
        foreach (DB::table('marketing_subscriptions')->get(['id', 'list_handle', 'email', 'uniqueness_key']) as $zeile) {
            if ($zeile->uniqueness_key !== MarketingSubscription::uniquenessKeyFor($zeile->list_handle, $zeile->email)) {
                DB::table('marketing_subscriptions')->where('id', $zeile->id)->delete();
            }
        }

        DB::table('marketing_subscriptions')
            ->whereNotIn('status', [
                MarketingSubscription::STATUS_PENDING,
                MarketingSubscription::STATUS_SUBSCRIBED,
                MarketingSubscription::STATUS_UNSUBSCRIBED,
                MarketingSubscription::STATUS_BOUNCED,
                MarketingSubscription::STATUS_COMPLAINED,
            ])
            ->delete();

        $global = array_values(array_filter(Reasons::all(), fn (string $grund) => Reasons::isGlobal($grund)));

        DB::table('suppressions')->whereNotIn('reason', Reasons::all())->delete();
        DB::table('suppressions')->whereIn('reason', $global)->where('brand_id', '!=', Reasons::GLOBAL_BRAND_ID)->delete();

        DB::table('lead_magnet_grants')->whereNull('entitlement_id')->delete();

        DB::statement(
            'update lead_magnet_grants set download_count = ('
            .'select count(*) from lead_magnet_downloads where lead_magnet_downloads.grant_id = lead_magnet_grants.id'
            .') where download_count <> ('
            .'select count(*) from lead_magnet_downloads where lead_magnet_downloads.grant_id = lead_magnet_grants.id)'
        );
    }

    // -- Erfassung ----------------------------------------------------------

    /**
     * Was aus einer Einsendung ein Kontakt macht.
     *
     * Ohne diese Zeilen liegen die drei Formulare da und tun nichts:
     * `CreateOrUpdateLeadFromSubmission` sucht eine Zuordnung zum Handle und
     * kehrt still um, wenn keine da ist. `form_handle` ist über alle Marken
     * eindeutig (siehe Migration), jedes Formular gehört also genau einer Marke.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function formularZuordnungen(array $marken): void
    {
        $zuordnungen = [
            'chorwerkstatt' => ['kursanfrage', 'Kursanfrage', ['kurs', 'chorwerkstatt']],
            'halbmond' => ['fanclub', 'Fanclub', ['fanclub']],
            'lindhorst' => ['erstgespraech', 'Erstgespräch', ['erstgespraech']],
        ];

        foreach ($zuordnungen as $marke => [$formular, $quelle, $etiketten]) {
            BrandContext::runFor($marken[$marke], function () use ($formular, $quelle, $etiketten) {
                FormMapping::query()->updateOrCreate(
                    ['form_handle' => $formular],
                    [
                        'enabled' => true,
                        'email_field' => 'email',
                        'full_name_field' => 'name',
                        'message_field' => 'nachricht',
                        'default_status' => 'new',
                        'default_source' => $quelle,
                        'default_tags' => $etiketten,
                        // Die ganze Einsendung an die Zeitleiste hängen: das ist
                        // der Fall, in dem die Redaktions-Schwärzung aus
                        // `leadhub.timeline_payload_redaction` überhaupt etwas
                        // zu tun bekommt.
                        'attach_full_submission' => true,
                    ],
                );
            });
        }
    }

    /**
     * Echte Statamic-Einsendungen, mit UTM-Werten.
     *
     * Kein `Contact::create()` mit angeklebtem `utm_source`, sondern
     * `$formular->makeSubmission()->save()`. Dabei feuert `SubmissionCreated`,
     * der LeadHub-Listener greift, der `SubmissionMapper` liest die
     * versteckten Felder laut `leadhub.attribution.fields`, und am Ende steht
     * ein Kontakt mit Zeitleisteneintrag, Etiketten und Erstkontakt-Attribution.
     * Genau diese Kette ist das, was ein Demo zeigen soll.
     *
     * Die Kennungen sind fest vergeben, nicht aus `microtime()`: `save()` feuert
     * `SubmissionCreated` nur für eine Einsendung, die es noch nicht gibt, und
     * damit ist der zweite Lauf von `demo:seed` von selbst ein Nichts.
     *
     * Der Weg über HTTP ginge hier NICHT: die Frontseite dieses Playgrounds löst
     * keine Marke auf, `BrandScope` fällt dann zu (`fail_mode = closed`), und
     * die Zuordnung wäre für die Einsendung unsichtbar. Siehe Bericht.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function einsendungen(array $marken): void
    {
        $zeilen = [
            // [Marke, Formular, Kennung, Daten]
            ['chorwerkstatt', 'kursanfrage', 'demo-kursanfrage-1', [
                'name' => 'Bärbel Öztürk-Weiß',
                'email' => 'BÄRBEL.Öztürk@Beispiel.DE',
                'stimmlage' => 'alt',
                'nachricht' => 'Wir sind ein Kammerchor und suchen jemanden für einen Stimmbildungstag.',
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'fruehlingsbrief',
            ]],
            ['chorwerkstatt', 'kursanfrage', 'demo-kursanfrage-2', [
                'name' => '🎵 Der Taktstock',
                'email' => 'taktstock@beispiel.de',
                'stimmlage' => 'weiss_nicht',
                'nachricht' => DemoData::tooLong(),
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'stimmbildung-chor',
            ]],
            // Absichtlich ohne brauchbare Adresse. Der Listener protokolliert
            // „Skipping submission without valid email" und bricht die Einsendung
            // NICHT ab — das ist die Zusage aus PRD §19.5, und sie gehört
            // vorgeführt.
            ['chorwerkstatt', 'kursanfrage', 'demo-kursanfrage-3-ohne-adresse', [
                'name' => 'Ohne Adresse',
                'email' => 'keine-adresse',
                'nachricht' => 'Rufen Sie mich einfach an.',
                'utm_source' => 'plakat',
            ]],
            ['halbmond', 'fanclub', 'demo-fanclub-1', [
                'name' => 'Ægir Þórsson',
                'email' => 'doppelt@beispiel.de',
                'gefunden' => 'konzert',
                'nachricht' => 'Wann kommt die Platte?',
                'utm_source' => 'instagram',
                'utm_medium' => 'social',
                'utm_campaign' => 'vinyl-vorbestellung',
            ]],
            ['halbmond', 'fanclub', 'demo-fanclub-2', [
                'name' => 'Jean-Luc «Loup» Fabre',
                'email' => 'loup@beispiel.de',
                'gefunden' => 'radio',
                'utm_source' => 'radio-eins',
                'utm_medium' => 'referral',
                'utm_campaign' => 'tour-2027',
            ]],
            ['lindhorst', 'erstgespraech', 'demo-erstgespraech-1', [
                'name' => 'Ana María Ñuñez',
                'email' => 'a@b.de',
                'anliegen' => 'heiserkeit',
                'nachricht' => 'Seit drei Wochen belegt nach jeder Probe.',
                'utm_source' => 'empfehlung',
                'utm_medium' => 'mundpropaganda',
                'utm_campaign' => 'praxis',
            ]],
            ['lindhorst', 'erstgespraech', 'demo-erstgespraech-2', [
                'name' => "Sängerin's Ännchen",
                'email' => 'aennchen@beispiel.de',
                'anliegen' => 'buehne',
                'nachricht' => 'Vor Publikum wird die Stimme eng.',
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'praxisbrief',
            ]],
        ];

        foreach ($zeilen as [$marke, $handle, $kennung, $daten]) {
            $formular = Form::find($handle);

            if (! $formular) {
                continue;
            }

            BrandContext::runFor($marken[$marke], function () use ($formular, $kennung, $daten) {
                if ($formular->submission($kennung)) {
                    return;
                }

                $formular->makeSubmission()
                    ->id($kennung)
                    ->data($daten)
                    ->save();
            });
        }
    }

    // -- Kontakte -----------------------------------------------------------

    /**
     * Die Kontakte, über `LeadHub::create()`.
     *
     * Der Status kommt aus `config('leadhub.statuses')` — die Schlüssel dort
     * sind das, was das CP als Filter anbietet und was ein Segment abfragen
     * kann. `customer` und `lead` waren nichts davon: das CP zeigte den rohen
     * Wert, jeder Statusfilter ging daran vorbei, und die Zählung nach Status
     * auf dem Dashboard ließ die Hälfte der Kontakte aus.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function kontakte(array $marken): void
    {
        $namen = DemoData::AWKWARD_NAMES;
        $adressen = DemoData::AWKWARD_EMAILS;

        $zeilen = [
            // [Marke, Name-Index, Adress-Index, Status, Besonderheit]
            ['chorwerkstatt', 0, 0, 'won', null],
            ['chorwerkstatt', 1, 1, 'contacted', null],
            ['chorwerkstatt', 2, 2, 'new', 'lange_adresse'],
            ['chorwerkstatt', 3, 3, 'won', null],
            ['chorwerkstatt', 8, 6, 'qualified', 'totes_postfach'],
            // Zwei Zeilen, dieselbe Adresse in zwei Schreibweisen, dieselbe
            // Marke — und deshalb EIN Kontakt. Der Status ist bei beiden
            // derselbe, und das ist kein Nachlassen: zwei verschiedene Zustände
            // auf dieselbe Person geschrieben ergäben keinen Demo-Fall, sondern
            // zwei Zeilen Statuswechsel bei jedem `demo:seed`, hin und zurück,
            // für immer.
            ['halbmond', 4, 4, 'won', 'doppelt'],
            ['halbmond', 5, 5, 'won', 'doppelt_andere_schreibweise'],
            ['halbmond', 6, 7, 'lost', 'widerspruch'],
            ['lindhorst', 7, 0, 'won', 'gleiche_adresse_andere_marke'],
            ['lindhorst', 9, 3, 'new', 'nur_ein_zeichen'],
            // Die Bösewicht-Marke kam im CRM bisher gar nicht vor.
            ['sonderzeichen', 1, 3, 'contacted', 'marke_mit_sonderzeichen'],
            ['sonderzeichen', 8, 5, 'contacted', 'wird_archiviert'],
        ];

        foreach ($zeilen as $i => [$marke, $n, $e, $status, $note]) {
            $name = $namen[$n];
            $teile = explode(' ', $name, 2);
            $ohneNamen = $note === 'widerspruch';

            BrandContext::runFor($marken[$marke], function () use ($adressen, $e, $teile, $name, $status, $note, $ohneNamen, $i) {
                $kontakt = LeadHub::create([
                    'email' => $adressen[$e],
                    'first_name' => $ohneNamen ? null : $teile[0],
                    'last_name' => $ohneNamen ? null : ($teile[1] ?? null),
                    'full_name' => $ohneNamen ? null : $name,
                    'status' => $status,
                    'source' => $note ?? 'demo',
                ]);

                // `consent` ist der eine Wert, den die Fassade nicht anbietet:
                // sie kennt Einwilligung nur als Nebenwirkung einer Anmeldung
                // oder einer Einsendung mit Zustimmungsfeld. Deshalb hier über
                // das Repository — mit Markenskopierung, ohne
                // `withoutGlobalScopes()`, und nur für einen Wert, für den es
                // keinen Fassadenweg gibt.
                $modell = app(ContactRepository::class)->find($kontakt['uuid']);

                if ($modell && ! $modell->consent && $note !== 'totes_postfach') {
                    $modell->consent = true;
                    $modell->consent_at = Carbon::now()->subDays(30 - $i);
                    app(ContactRepository::class)->save($modell);
                }

                // Widerspruch: nicht „nicht angemeldet", sondern „nie wieder".
                // Über `optOut()`, weil das zusätzlich jedes angeschlossene CRM
                // abmeldet — ein Häkchen in der Spalte täte das nicht.
                if ($note === 'widerspruch') {
                    LeadHub::optOut($kontakt['uuid']);
                } elseif ($modell && $modell->do_not_contact) {
                    // Der alte Seeder hat das Häkchen auf `bounce@…` gesetzt und
                    // damit zwei verschiedene Tatsachen vermischt: ein totes
                    // Postfach ist eine Sache der Zustellbarkeit, ein
                    // Widerspruch eine der Einwilligung. Hier wird es wieder
                    // getrennt.
                    $modell->do_not_contact = false;
                    app(ContactRepository::class)->save($modell);
                }
            });
        }
    }

    /**
     * Firmen, und wer zu welcher gehört.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function firmen(array $marken): void
    {
        BrandContext::runFor($marken['chorwerkstatt'], function () {
            $verband = LeadHub::createCompany([
                'name' => 'Chorverband Nord e. V.',
                'domain' => 'chorverband-nord.beispiel',
                'website' => 'https://chorverband-nord.beispiel',
                'industry' => 'Verband',
                'employee_range' => '11-50',
            ]);

            // Ein Name mit Zeichen, die eine Kopfzeile und ein HTML-Attribut
            // zugleich angreifen, und keine Domain: eine Firma, die nur über
            // ihren Namen dedupliziert werden kann.
            LeadHub::createCompany([
                'name' => 'Müller & Söhne <Chor> „Ännchen"',
                'industry' => 'Chor',
                'employee_range' => '1-10',
            ]);

            if ($kontakt = LeadHub::findByEmail('BÄRBEL.Öztürk@Beispiel.DE')) {
                LeadHub::linkCompany($kontakt['uuid'], $verband['id'], 'Vorstand', primary: true);
            }
        });
    }

    /**
     * Etiketten und Segmente.
     *
     * Segmentregeln werden LIVE ausgewertet, die Mitgliedschaft daneben
     * materialisiert. `sweepSegment()` ist der Lauf, der beides in Deckung
     * bringt; ohne ihn zeigt die Übersicht „0 Mitglieder" bei zutreffenden
     * Regeln, was wie ein Fehler aussieht und keiner ist.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function etikettenUndSegmente(array $marken): void
    {
        $etiketten = [
            'chorwerkstatt' => [
                'BÄRBEL.Öztürk@Beispiel.DE' => ['stammkundin', 'chorleitung'],
                'plus+tag@beispiel.de' => ['chorleitung'],
                'a@b.de' => ['stammkundin'],
            ],
            'halbmond' => [
                'doppelt@beispiel.de' => ['fanclub', 'vinyl'],
                'loup@beispiel.de' => ['fanclub'],
            ],
            'lindhorst' => [
                'a@b.de' => ['erstgespraech'],
            ],
            'sonderzeichen' => [
                'a@b.de' => ['ännchen & co', 'test'],
            ],
        ];

        foreach ($etiketten as $marke => $zuordnung) {
            BrandContext::runFor($marken[$marke], function () use ($zuordnung) {
                foreach ($zuordnung as $adresse => $namen) {
                    if (! $kontakt = LeadHub::findByEmail($adresse)) {
                        continue;
                    }

                    foreach ($namen as $etikett) {
                        LeadHub::addTag($kontakt['uuid'], $etikett);
                    }
                }
            });
        }

        $segmente = [
            'chorwerkstatt' => [
                [
                    'name' => 'Heiße Leads',
                    'handle' => 'heisse-leads',
                    'rules' => ['match' => 'all', 'conditions' => [
                        ['type' => 'field', 'field' => 'engagement_score', 'operator' => 'gte', 'value' => 40],
                        ['type' => 'field', 'field' => 'do_not_contact', 'operator' => 'eq', 'value' => false],
                    ]],
                ],
                [
                    'name' => 'Aus dem Kursformular',
                    'handle' => 'aus-dem-kursformular',
                    'rules' => ['match' => 'all', 'conditions' => [
                        ['type' => 'field', 'field' => 'source_form', 'operator' => 'eq', 'value' => 'kursanfrage'],
                    ]],
                ],
            ],
            'halbmond' => [
                [
                    'name' => 'Fanclub',
                    'handle' => 'fanclub',
                    'rules' => ['match' => 'any', 'conditions' => [
                        ['type' => 'tag', 'operator' => 'has', 'value' => 'fanclub'],
                    ]],
                ],
            ],
        ];

        foreach ($segmente as $marke => $eintraege) {
            BrandContext::runFor($marken[$marke], function () use ($eintraege) {
                $repository = app(SegmentRepository::class);

                foreach ($eintraege as $daten) {
                    $segment = $repository->findByHandle($daten['handle']);

                    $segment = $segment
                        ? $repository->update($segment, ['name' => $daten['name'], 'rules' => $daten['rules'], 'is_active' => true])
                        : $repository->create($daten + ['is_active' => true]);

                    app(SegmentService::class)->sweepSegment($segment);
                }
            });
        }
    }

    /**
     * Eine Pipeline, vier Stufen, drei Deals — und Stufenwechsel.
     *
     * Ohne `leadhub.features.pipelines` antwortet der Bildschirm mit 404; der
     * Schalter steht deshalb in `config/leadhub.php` auf true.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function pipeline(array $marken): void
    {
        BrandContext::runFor($marken['chorwerkstatt'], function () {
            // `LeadHub::createPipeline()` legt bedingungslos an. Der Schutz
            // gegen den zweiten Lauf gehört deshalb hierher.
            if (! Pipeline::query()->where('slug', 'workshops')->exists()) {
                LeadHub::createPipeline('Workshops', [
                    ['name' => 'Anfrage', 'slug' => 'anfrage'],
                    ['name' => 'Gespräch', 'slug' => 'gespraech'],
                    ['name' => 'Angebot raus', 'slug' => 'angebot'],
                    ['name' => 'Gewonnen', 'slug' => 'gewonnen', 'is_terminal' => true, 'terminal_outcome' => 'won'],
                ], 'workshops');
            }

            $deals = [
                ['BÄRBEL.Öztürk@Beispiel.DE', 'Stimmbildungstag Kammerchor', 85000, 'demo-deal-1', ['gespraech', 'angebot', 'gewonnen']],
                ['plus+tag@beispiel.de', 'Probenwochenende', 45000, 'demo-deal-2', ['gespraech']],
                ['taktstock@beispiel.de', DemoData::tooLong(120), 1, 'demo-deal-3', ['gespraech', 'angebot']],
            ];

            foreach ($deals as [$adresse, $titel, $wert, $referenz, $stufen]) {
                if (! $kontakt = LeadHub::findByEmail($adresse)) {
                    continue;
                }

                $deal = LeadHub::upsertOpportunity($kontakt['uuid'], 'workshops', [
                    'title' => $titel,
                    'value_estimate' => $wert,
                    'confidence' => 60,
                    'source_type' => 'demo',
                    'source_id' => $referenz,
                ]);

                // Die Stufenreise ist eine Geschichte, kein Zustand. Sie wird
                // genau einmal erzählt: ein zweiter `demo:seed` würde den Deal
                // sonst von „Gewonnen" zurück auf „Gespräch" schieben und jedes
                // Mal drei erfundene Bewegungen in die Übergangshistorie
                // schreiben.
                if (StageTransition::query()->where('opportunity_id', $deal['id'])->exists()) {
                    continue;
                }

                foreach ($stufen as $stufe) {
                    $deal = LeadHub::moveStage($deal['id'], $stufe);
                }
            }
        });
    }

    /**
     * Aufgaben: eine überfällige, eine für heute, eine erledigte.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function aufgaben(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'BÄRBEL.Öztürk@Beispiel.DE', 'Angebot für den Stimmbildungstag schicken', '-2 days', 'high', 'jonas@nordlicht.beispiel', false],
            ['chorwerkstatt', 'plus+tag@beispiel.de', 'Rückruf wegen Probenwochenende', 'heute', 'normal', 'jonas@nordlicht.beispiel', false],
            ['halbmond', 'doppelt@beispiel.de', 'Vinyl-Vorbestellung bestätigen', '-5 days', 'low', 'said@nordlicht.beispiel', true],
            // Fällig am 31. Januar, 23:59. Das ist einer der Werte aus
            // `DemoData::awkwardDates()` und er steht hier mit Absicht: wer auf
            // ein Monatsende einen Monat addiert, landet im übernächsten Monat.
            // Eine Aufgabenliste, die „nächsten Monat" anbietet, findet das hier
            // oder gar nicht.
            ['lindhorst', 'a@b.de', 'Fünferkarte zum Monatsende abrechnen', 'monatsende', 'normal', 'said@nordlicht.beispiel', false],
        ];

        foreach ($zeilen as [$marke, $adresse, $titel, $faellig, $rang, $nutzer, $erledigt]) {
            BrandContext::runFor($marken[$marke], function () use ($adresse, $titel, $faellig, $rang, $nutzer, $erledigt) {
                if (! $kontakt = LeadHub::findByEmail($adresse)) {
                    return;
                }

                if (Task::query()->where('title', $titel)->exists()) {
                    return;
                }

                $aufgabe = LeadHub::createTask([
                    'title' => $titel,
                    'priority' => $rang,
                    'due_at' => $this->wann($faellig),
                    'assignee_id' => $this->nutzerId($nutzer),
                    'created_by' => $this->nutzerId('mira@nordlicht.beispiel'),
                ], $kontakt['uuid']);

                if ($erledigt) {
                    LeadHub::completeTask($aufgabe['id'], $this->nutzerId($nutzer));
                }
            });
        }
    }

    /**
     * Bewertungsregeln je Marke, und ein paar gesetzte Punkte.
     *
     * Die Regeln stehen seit 1.8.0 in einer Tabelle und nicht mehr in der
     * Konfiguration, und zwar je Marke: eine Regel der einen Marke rechnet in
     * der anderen nicht mit. Genau deshalb bekommt jede Marke hier ihre eigene
     * Baseline (`*`).
     *
     * @param  array<string, Brand>  $marken
     */
    protected function scoring(array $marken): void
    {
        $regeln = [
            'chorwerkstatt' => [
                [ScoringRule::CATCH_ALL, 1, 'Alles andere'],
                ['submission_received', 5, 'Formular ausgefüllt'],
                ['purchase.completed', 25, 'Gekauft'],
                ['email_link_clicked', 3, 'Link in einer Mail geklickt'],
            ],
            'halbmond' => [
                [ScoringRule::CATCH_ALL, 2, 'Alles andere'],
                ['submission_received', 10, 'Formular ausgefüllt'],
            ],
            'lindhorst' => [
                [ScoringRule::CATCH_ALL, 1, 'Alles andere'],
            ],
        ];

        foreach ($regeln as $marke => $eintraege) {
            BrandContext::runFor($marken[$marke], function () use ($eintraege) {
                foreach ($eintraege as [$typ, $punkte, $bezeichnung]) {
                    ScoringRule::query()->updateOrCreate(
                        ['event_type' => $typ],
                        ['points' => $punkte, 'label' => $bezeichnung, 'enabled' => true],
                    );
                }
            });
        }

        $punkte = [
            'chorwerkstatt' => ['BÄRBEL.Öztürk@Beispiel.DE' => 92, 'plus+tag@beispiel.de' => 47, 'a@b.de' => 8],
            'halbmond' => ['doppelt@beispiel.de' => 61],
            'lindhorst' => ['a@b.de' => 0],
        ];

        foreach ($punkte as $marke => $zuordnung) {
            BrandContext::runFor($marken[$marke], function () use ($zuordnung) {
                foreach ($zuordnung as $adresse => $wert) {
                    if (! $kontakt = LeadHub::findByEmail($adresse)) {
                        continue;
                    }

                    // Ein Ausgangswert, kein Sollwert. Danach rechnet das Addon
                    // selbst: die Regeln oben zählen bei jedem Ereignis dazu.
                    // Würde der Wert bei jedem Lauf neu gesetzt, stritte der
                    // Seeder mit `ScoreContactOnActivity` und jeder Lauf hinge
                    // zwei weitere Zeilen in die Zeitleiste.
                    $schonGesetzt = TimelineEvent::query()
                        ->where('contact_id', $kontakt['id'])
                        ->where('type', TimelineEvent::TYPE_SCORE_CHANGED)
                        ->exists();

                    if (! $schonGesetzt) {
                        LeadHub::setScore($kontakt['uuid'], $wert, 'Demo-Ausgangswert');
                    }
                }
            });
        }
    }

    /**
     * Notizen und Nachfassen — eins überfällig, eins heute.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function notizenUndNachfassen(array $marken): void
    {
        $notizen = [
            ['chorwerkstatt', 'BÄRBEL.Öztürk@Beispiel.DE', 'Chor probt dienstags, Termin muss ein Samstag sein.'],
            ['chorwerkstatt', 'plus+tag@beispiel.de', 'Will erst im Herbst entscheiden. Budget steht noch nicht.'],
            ['halbmond', 'doppelt@beispiel.de', 'Hat auf dem Konzert in Kiel nach der Platte gefragt.'],
            ['sonderzeichen', 'a@b.de', 'Notiz mit „Anführungszeichen", <spitzen Klammern> & einem Ampersand.'],
        ];

        foreach ($notizen as [$marke, $adresse, $text]) {
            BrandContext::runFor($marken[$marke], function () use ($adresse, $text) {
                if (! $kontakt = LeadHub::findByEmail($adresse)) {
                    return;
                }

                // Gegen den Rumpf der Notiz, nicht gegen die Zusammenfassung
                // des Zeitleisteneintrags: die lautet immer „Notiz
                // hinzugefügt" und hätte hier nie etwas gefunden.
                $vorhanden = Note::query()
                    ->where('contact_id', $kontakt['id'])
                    ->where('body', $text)
                    ->exists();

                if (! $vorhanden) {
                    LeadHub::addNote($kontakt['uuid'], $text, $this->nutzerId('mira@nordlicht.beispiel'));
                }
            });
        }

        $nachfassen = [
            // `FollowupService::set()` räumt das aktive Nachfassen vorher weg,
            // ist also von selbst wiederholbar.
            ['chorwerkstatt', 'BÄRBEL.Öztürk@Beispiel.DE', '-3 days', 'Angebot ist raus, seitdem nichts gehört.'],
            ['halbmond', 'doppelt@beispiel.de', 'heute', 'Heute anrufen, Karten gehen weg.'],
        ];

        foreach ($nachfassen as [$marke, $adresse, $faellig, $notiz]) {
            BrandContext::runFor($marken[$marke], function () use ($adresse, $faellig, $notiz) {
                if (! $kontakt = LeadHub::findByEmail($adresse)) {
                    return;
                }

                // `set()` räumt das aktive Nachfassen weg und legt ein neues an
                // — jeder Lauf also ein neuer Zeitleisteneintrag. Steht das
                // gewünschte schon da, bleibt es stehen.
                $steht = Followup::query()
                    ->where('contact_id', $kontakt['id'])
                    ->whereNull('completed_at')
                    ->where('note', $notiz)
                    ->exists();

                if (! $steht) {
                    LeadHub::createFollowUp($kontakt['uuid'], [
                        'due_at' => $this->wann($faellig)->toDateTimeString(),
                        'note' => $notiz,
                        'created_by' => $this->nutzerId('mira@nordlicht.beispiel'),
                    ]);
                }
            });
        }
    }

    /**
     * Wem gehört wer.
     *
     * `assigned_to` ist keine Fassaden-Eigenschaft; das CP schreibt sie auf dem
     * Modell und schreibt danach den Zeitleisteneintrag. Genau das wird hier
     * nachgezogen, statt die Spalte still zu setzen.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function zuweisungen(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'BÄRBEL.Öztürk@Beispiel.DE', 'jonas@nordlicht.beispiel'],
            ['chorwerkstatt', 'plus+tag@beispiel.de', 'jonas@nordlicht.beispiel'],
            ['halbmond', 'doppelt@beispiel.de', 'said@nordlicht.beispiel'],
            ['lindhorst', 'a@b.de', 'said@nordlicht.beispiel'],
            // Und eine Marke, in der niemand zuständig ist: der Fall, den der
            // Filter „ohne Eigentümer" und die Tagesübersicht brauchen.
        ];

        foreach ($zeilen as [$marke, $adresse, $nutzer]) {
            $id = $this->nutzerId($nutzer);

            if ($id === null) {
                continue;
            }

            BrandContext::runFor($marken[$marke], function () use ($adresse, $id, $nutzer) {
                $repository = app(ContactRepository::class);
                $kontakt = LeadHub::findByEmail($adresse);

                if (! $kontakt || ! $modell = $repository->find($kontakt['uuid'])) {
                    return;
                }

                if ((string) $modell->assigned_to === $id) {
                    return;
                }

                $modell->assigned_to = $id;
                $repository->save($modell);
                app(TimelineService::class)->recordAssigned($modell, $nutzer);
            });
        }
    }

    /**
     * Ein archivierter Kontakt.
     *
     * Derselbe Dreisatz wie im CP: Repository archiviert, Zeitleiste hält es
     * fest, Ereignis geht raus. Nur die Spalte zu setzen hieße, dass ein
     * Webhook-Abnehmer nie erfährt, dass jemand archiviert wurde.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function archiv(array $marken): void
    {
        BrandContext::runFor($marken['sonderzeichen'], function () {
            $repository = app(ContactRepository::class);
            $kontakt = LeadHub::findByEmail('DOPPELT@beispiel.de');

            if (! $kontakt || ! $modell = $repository->find($kontakt['uuid'])) {
                return;
            }

            if ($modell->archived_at !== null) {
                return;
            }

            $repository->archive($modell);
            app(TimelineService::class)->recordContactArchived($modell);
            event(new LeadHubContactArchived($modell));
        });
    }

    // -- Marketing ----------------------------------------------------------

    /**
     * Die Abonnenten, über den echten Anmeldeweg.
     *
     * `SubscriptionService::subscribe()` und nicht ein geschriebener Status:
     * dabei entsteht die Bestätigungsmail, der `ConfirmationThrottle` wird
     * belastet, das Sperr-Gatter gefragt, die Absenderidentität der Marke
     * aufgelöst — und bei `confirmByToken()` legt `syncContactOnSubscribe()`
     * den LeadHub-Kontakt an, hängt das Etikett `list:{handle}` daran und
     * schreibt den Zeitleisteneintrag.
     *
     * Der Status, der dabei entsteht, heißt `subscribed`. Der alte Seeder
     * schrieb `confirmed` — ein Wert, den `statamic-marketing` nicht kennt:
     * `Subscription::subscribed()` fand nichts, das Dashboard zeigte auf jeder
     * Marke `totalSubscribed = 0`, und das LeadHub-Panel zeigte den rohen
     * Übersetzungsschlüssel `marketing::leadhub.status_confirmed`, weil es zu
     * `confirmed` keinen gibt.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function abonnenten(array $marken): void
    {
        $zeilen = [
            // [Marke, Liste, Adresse, Ziel]
            ['chorwerkstatt', 'chorbrief', 'BÄRBEL.Öztürk@Beispiel.DE', 'subscribed'],
            ['chorwerkstatt', 'chorbrief', 'plus+tag@beispiel.de', 'subscribed'],
            // Gefragt und nie bestätigt: der Zustand, aus dem eine
            // Double-Opt-in-Liste zum größten Teil besteht, und der, den ein
            // Bericht gern vergisst.
            ['chorwerkstatt', 'chorbrief', 'wartet@beispiel.de', 'pending'],
            // Einfaches Opt-in: `subscribe()` ist hier schon der Abschluss.
            ['chorwerkstatt', 'kursinfos', 'a@b.de', 'subscribed'],
            ['chorwerkstatt', 'chorbrief', 'weg@beispiel.de', 'unsubscribed'],
            ['halbmond', 'tourmail', 'doppelt@beispiel.de', 'subscribed'],
            // Dieselbe Adresse, andere Schreibweise, andere Marke, andere Liste.
            ['lindhorst', 'praxisbrief', 'DOPPELT@beispiel.de', 'subscribed'],
            // Ein Abonnent, dessen Postfach gleich stirbt. Auf beiden Listen,
            // und auf dem Chorbrief, weil dort die Kampagne läuft: das
            // Sperr-Gatter hat nur dann etwas abzuweisen, wenn der Gesperrte im
            // Verteiler steht.
            //
            // `von_hand`, nicht Doppel-Opt-in: die Bestätigungsmail ist selbst
            // ein Sendeweg und wird vom Gatter zurückgehalten, sobald die Sperre
            // existiert — beim zweiten `demo:seed` wäre die Anmeldung also für
            // immer „ausstehend" und der Nachweis wäre weg. Eine Redaktion, die
            // jemanden von Hand einträgt, bürgt für die Einwilligung; genau
            // dafür hat `subscribe()` `skip_confirmation`.
            ['halbmond', 'tourmail', 'bounce@beispiel.invalid', 'von_hand'],
            ['chorwerkstatt', 'chorbrief', 'bounce@beispiel.invalid', 'von_hand'],
            ['sonderzeichen', 'zeichenbrief', 'a@b.de', 'subscribed'],
        ];

        foreach ($zeilen as $i => [$marke, $liste, $adresse, $ziel]) {
            BrandContext::runFor($marken[$marke], function () use ($liste, $adresse, $ziel) {
                $dienst = app(SubscriptionService::class);
                $listenObjekt = app(MailingListRepository::class)->find($liste);

                if (! $listenObjekt) {
                    return;
                }

                $zielStatus = match ($ziel) {
                    'pending' => MarketingSubscription::STATUS_PENDING,
                    'unsubscribed' => MarketingSubscription::STATUS_UNSUBSCRIBED,
                    default => MarketingSubscription::STATUS_SUBSCRIBED,
                };

                // Steht der Zustand schon, wird nichts angefasst. Sonst führe
                // ein zweiter `demo:seed` `weg@beispiel.de` durch den ganzen
                // Kreis — anmelden, bestätigen, abmelden — und schriebe drei
                // Einwilligungsereignisse für eine Entscheidung, die vor einer
                // Woche gefallen ist.
                $steht = MarketingSubscription::query()
                    ->where('uniqueness_key', MarketingSubscription::uniquenessKeyFor($listenObjekt->handle, $adresse))
                    ->first();

                if ($steht && $steht->status === $zielStatus) {
                    return;
                }

                $abo = $dienst->subscribe($listenObjekt, $adresse, [], [
                    'source' => 'demo',
                    'skip_confirmation' => $ziel === 'von_hand',
                ]);

                if ($ziel === 'pending') {
                    return;
                }

                if ($abo->status === MarketingSubscription::STATUS_PENDING && $abo->confirmation_sent_at !== null) {
                    $dienst->confirmByToken((string) $abo->confirmation_token);
                    $abo->refresh();
                }

                if ($ziel === 'unsubscribed' && $abo->status !== MarketingSubscription::STATUS_UNSUBSCRIBED) {
                    $dienst->unsubscribe($abo, ['reason' => 'demo']);
                }
            });
        }
    }

    /**
     * Die Sperrliste, über `Suppression`.
     *
     * Drei Dinge, die der alte Seeder falsch hatte und die die Fassade gar
     * nicht erst zugelassen hätte:
     *
     *   1. `hard_bounce` lag auf einer Marke. Ein hartes Bounce ist eine
     *      Eigenschaft des Postfachs und gilt überall; `Reasons::scopeFor()`
     *      sagt `global`, `SuppressionService::brandIdFor()` schreibt dafür
     *      `brand_id = 0`. Vorher sagte `isSuppressed('bounce@…', 3)` „frei",
     *      obwohl das Postfach tot ist.
     *   2. `soft_bounce` ist kein gültiger Grund. Er heißt
     *      `soft_bounce_threshold`, und er wird nicht gesetzt, sondern
     *      *erreicht* — deshalb hier fünf einzelne `recordSoftBounce()`.
     *   3. Eine Freigabe wurde als Spalte geschrieben. `release()` schreibt sie
     *      zusammen mit ihrem Prüfeintrag in einer Transaktion.
     *
     * `beschwerde@beispiel.de` bleibt absichtlich unlösbar: `release()`
     * verweigert eine Beschwerde, `releaseComplaint()` ist die andere Tür.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function sperrliste(array $marken): void
    {
        // Global, markenunabhängig — deshalb außerhalb jedes `runFor()`.
        Suppression::suppress('bounce@beispiel.invalid', Reasons::HARD_BOUNCE, [
            'source' => 'demo',
            'provider' => 'demo',
            'provider_event_id' => 'demo-hard-bounce-1',
            'notes' => 'Postfach existiert nicht mehr.',
        ]);

        // Fünf weiche Bounces im Fenster. Der fünfte hebt die Adresse auf
        // `soft_bounce_threshold` — global, weil sonst zwei Marken je vier
        // zählen könnten und keine die Schwelle sieht.
        for ($i = 1; $i <= 5; $i++) {
            Suppression::recordSoftBounce('vollerkasten@beispiel.de', [
                'source' => 'demo',
                'provider' => 'demo',
                'provider_event_id' => 'demo-soft-'.$i,
            ]);
        }

        // Und zwei, die unter der Schwelle bleiben: ein voller Briefkasten ist
        // eine Tatsache über heute und keine Sperre.
        for ($i = 1; $i <= 2; $i++) {
            Suppression::recordSoftBounce('wackelig@beispiel.de', [
                'source' => 'demo',
                'provider' => 'demo',
                'provider_event_id' => 'demo-wackelig-'.$i,
            ]);
        }

        $markenbezogen = [
            ['chorwerkstatt', 'beschwerde@beispiel.de', Reasons::COMPLAINT, 'demo-complaint-1', null],
            // Befristet und schon vorbei: die Zeile, an der sich zeigt, ob
            // Ablauf beachtet wird.
            ['chorwerkstatt', 'abgelaufen@beispiel.de', Reasons::MANUAL, 'demo-manual-1', '-2 days'],
            ['lindhorst', 'freigegeben@beispiel.de', Reasons::MANUAL, 'demo-manual-2', null],
            ['sonderzeichen', 'ännchen@müller-söhne.beispiel', Reasons::MANUAL, 'demo-manual-3', null],
        ];

        foreach ($markenbezogen as [$marke, $adresse, $grund, $referenz, $laeuftAb]) {
            BrandContext::runFor($marken[$marke], function () use ($adresse, $grund, $referenz, $laeuftAb) {
                Suppression::suppress($adresse, $grund, [
                    'source' => 'demo',
                    'provider' => 'demo',
                    'provider_event_id' => $referenz,
                    'expires_at' => $laeuftAb ? Carbon::now()->sub(ltrim($laeuftAb, '-')) : null,
                ]);
            });
        }

        BrandContext::runFor($marken['lindhorst'], function () {
            $eintrag = Suppression::find('freigegeben@beispiel.de');

            if ($eintrag && $eintrag->released_at === null) {
                Suppression::release('freigegeben@beispiel.de', [
                    'actor' => 'studio@local',
                    'reason' => 'Kundin hat sich gemeldet, Adresse war ein Tippfehler.',
                ]);
            }
        });
    }

    // -- Freebies, Zugänge, Meldungen, Spuren --------------------------------

    /**
     * Die Freebies und ihre Freigaben.
     *
     * Der alte Seeder schrieb `Grant`-Zeilen von Hand — ohne `entitlement_id`.
     * Der Zugriffszustand einer Freigabe liegt aber seit 2.0 vollständig in
     * `statamic-entitlements`; eine Freigabe ohne Berechtigung liest sich ewig
     * als „ausstehend", und die Ressourcenliste meldete „0 aktiv / 0 offen" bei
     * drei vorhandenen Freigaben. Dazu stand `download_count = 2` gegen null
     * Zeilen in `lead_magnet_downloads`: eine Zahl ohne den Beleg, den sie
     * behauptet.
     *
     * Jetzt: `LeadMagnets::request()` legt die Freigabe samt Berechtigung an
     * und schickt die Bestätigung, `confirm()` löst sie ein und stößt die
     * Auslieferung an, und ein echter Abruf der signierten Adresse zählt den
     * Download — mitsamt Prüfzeile.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function freebies(array $marken): void
    {
        $zeilen = [
            // [Marke, Handle, Titel, Bestätigung?, TTL Tage, max. Downloads, Liste, Adresse, Zustand]
            ['chorwerkstatt', 'stimm-check', 'Der Stimm-Check als PDF', true, 14, 3, 'chorbrief', 'BÄRBEL.Öztürk@Beispiel.DE', 'heruntergeladen'],
            ['chorwerkstatt', 'drei-uebungen', 'Drei Übungen für den nächsten Probenabend', false, null, null, 'chorbrief', 'plus+tag@beispiel.de', 'aktiv'],
            ['halbmond', 'demo-track', 'Ein Stück, bevor es erscheint', true, 2, 1, 'tourmail', 'doppelt@beispiel.de', 'wartet'],
            ['lindhorst', 'atem-blatt', 'Ein Blatt zum Atem', false, null, null, 'praxisbrief', 'a@b.de', 'entzogen'],
            ['sonderzeichen', 'zeichen-pdf', 'Ännchens PDF mit „allem" & <allem>', false, null, null, 'zeichenbrief', 'a@b.de', 'aktiv'],
        ];

        foreach ($zeilen as [$marke, $handle, $titel, $bestaetigung, $tage, $max, $liste, $adresse, $zustand]) {
            BrandContext::runFor($marken[$marke], function () use ($handle, $titel, $bestaetigung, $tage, $max, $liste, $adresse, $zustand) {
                // Die Ressource selbst ist eine Definition und hat keinen
                // Fassadenweg zum Anlegen — das CP schreibt sie über dasselbe
                // Modell. Mit Markenskopierung, ohne `withoutGlobalScopes()`.
                $ressource = Resource::query()->updateOrCreate(
                    ['handle' => $handle],
                    [
                        'title' => $titel,
                        'description' => 'Kostenlos, gegen die Adresse.',
                        'delivery_type' => 'link',
                        'link_url' => 'https://beispiel.de/'.$handle.'.pdf',
                        'requires_confirmation' => $bestaetigung,
                        'published' => true,
                        'grant_ttl_days' => $tage,
                        'max_downloads' => $max,
                        'marketing_list' => $liste,
                    ],
                );

                // Steht die Freigabe schon im Zielzustand, wird nicht neu
                // gefragt. `request()` ist absichtlich wiederholbar und feuert
                // jedes Mal `ResourceRequested` — bei einer aktiven Freigabe
                // schickt es die Datei sogar noch einmal. Für einen Besucher,
                // der das Formular zweimal ausfüllt, ist genau das richtig; für
                // einen Seeder, der zum zehnten Mal läuft, wären es zehn
                // Anfragen und zehn Auslieferungen, die nie jemand gestellt hat.
                $vorhanden = LeadMagnets::findGrant($ressource, $adresse);

                if ($vorhanden && $this->passt($vorhanden, $zustand)) {
                    return;
                }

                $freigabe = LeadMagnets::request($ressource, $adresse, ['quelle' => 'demo']);

                // Eine Bestätigung, die nie eingelöst wird: der ehrliche
                // häufigste Fall.
                if ($zustand === 'wartet') {
                    return;
                }

                if ($freigabe->plainToken !== null) {
                    LeadMagnets::confirm($freigabe->plainToken);
                    $freigabe->refresh();
                }

                if ($zustand === 'entzogen') {
                    if ($freigabe->entitlement?->state()->grantsAccess()) {
                        LeadMagnets::revoke($freigabe, 'Adresse stammt aus einer eingekauften Liste.');
                    }

                    return;
                }

                // Gezählt werden die Prüfzeilen, nicht `download_count`: der
                // Zähler ist genau das Feld, dem der alte Seeder eine Zahl ohne
                // Beleg gegeben hat, und ihn hier zu befragen hieße, der
                // Behauptung zu glauben.
                if ($zustand !== 'heruntergeladen' || $freigabe->downloads()->count() > 0) {
                    return;
                }

                // Wirklich abrufen, zweimal. Zähler und Prüfzeile entstehen in
                // einer Transaktion im Controller; eine Zahl hier zu setzen wäre
                // wieder die Behauptung ohne Beleg.
                for ($i = 0; $i < 2; $i++) {
                    $this->hole(LeadMagnets::downloadUrl($freigabe->refresh()));
                }
            });
        }
    }

    /**
     * Zugänge, über `Entitlements`.
     *
     * Der alte Seeder hat `status`, `brand_id` und `revoked_at` per
     * `forceFill()` geschrieben — mit einem Kommentar, warum das nötig sei.
     * War es nicht: die Spalten sind gegen Massenzuweisung geschützt, weil sie
     * über Zugriff entscheiden, und der Weg an ihnen vorbei heißt
     * `Entitlements::grant()` bzw. `revoke()`. Der schreibt sie, feuert die
     * Ereignisse und ist über den Schlüssel (Subjekt, Produkt, Quelle,
     * Referenz) von selbst wiederholbar.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function zugaenge(array $marken): void
    {
        $zeilen = [
            // [Marke, Produkt, Adresse, Ablauf, Gnadenfrist, entzogen?]
            ['chorwerkstatt', 'cw-kurs', DemoData::AWKWARD_EMAILS[0], null, null, false],
            // Gestern abgelaufen: der Zustand, an dem sich entscheidet, ob
            // „hat Zugriff" nach der Zeit fragt oder nur danach, ob eine Zeile
            // existiert.
            ['chorwerkstatt', 'cw-mitgliedschaft', DemoData::AWKWARD_EMAILS[1], '-1 day', null, false],
            // In der Gnadenfrist nach einer fehlgeschlagenen Abbuchung.
            ['halbmond', 'hm-fanclub', DemoData::AWKWARD_EMAILS[4], '-2 days', '+3 days', false],
            ['lindhorst', 'lh-fuenferkarte', DemoData::AWKWARD_EMAILS[3], null, null, true],
            ['sonderzeichen', 'sz-zugang', DemoData::AWKWARD_EMAILS[5], null, null, false],
        ];

        foreach ($zeilen as $i => [$marke, $produkt, $adresse, $bis, $gnade, $entzogen]) {
            BrandContext::runFor($marken[$marke], function () use ($produkt, $adresse, $bis, $gnade, $entzogen, $i) {
                $zugang = Entitlements::grant(
                    subject: new SubjectReference('email', $adresse),
                    productSlug: $produkt,
                    source: 'payment',
                    sourceRef: 'demo_tr_'.($i + 1),
                    startsAt: Carbon::now()->subDays(30),
                    expiresAt: $bis ? $this->wann($bis) : null,
                    graceUntil: $gnade ? $this->wann($gnade) : null,
                    meta: ['demo' => true],
                );

                if ($entzogen && $zugang->revoked_at === null) {
                    Entitlements::revoke($zugang, 'Rückerstattung nach Widerruf');
                }
            });
        }
    }

    /**
     * Meldungen, über `Notifications`.
     *
     * Der alte Seeder schrieb `recipient_type = 'user'` ohne Empfänger — eine
     * Meldung, die niemandem gehört und die niemand je in seiner Glocke sieht.
     * `notify()` verlangt eine Identität, und seit es fünf CP-Konten gibt, ist
     * das auch beantwortbar.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function meldungen(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'jonas@nordlicht.beispiel', 'payment.paid', 'Bärbel Öztürk-Weiß hat den Frühlingskurs gekauft.', false],
            ['chorwerkstatt', 'jonas@nordlicht.beispiel', 'funnel.completed', '🎵 Der Taktstock ist durch den Funnel gelaufen.', false],
            ['halbmond', 'said@nordlicht.beispiel', 'subscription.cancelled', 'Ein Fanclub-Abo wurde gekündigt.', true],
            ['lindhorst', 'said@nordlicht.beispiel', 'booking.requested', 'Ein Erstgespräch wurde angefragt.', false],
            ['chorwerkstatt', 'mira@nordlicht.beispiel', 'payment.failed', 'Eine Zahlung über 450,00 EUR ist fehlgeschlagen.', false],
            ['sonderzeichen', 'a@b.de', 'payment.paid', 'Müller & Söhne <Chor> „Ännchen" hat bezahlt.', false],
        ];

        foreach ($zeilen as $i => [$marke, $adresse, $typ, $text, $gelesen]) {
            $nutzer = User::findByEmail($adresse);

            if (! $nutzer) {
                continue;
            }

            BrandContext::runFor($marken[$marke], function () use ($nutzer, $typ, $text, $gelesen, $i) {
                Notifications::notify($nutzer, $typ, [
                    'message' => $text,
                    'dedupe_key' => 'demo-'.$i,
                    'read_at' => $gelesen ? Carbon::now()->subHours(2) : null,
                ]);
            });
        }
    }

    /**
     * Spuren, über `Activity`.
     *
     * `event_id` bleibt aus der Marke und dem Index abgeleitet statt zufällig,
     * damit ein zweiter Lauf keine zweite Kopie erzeugt — der Recorder
     * dedupliziert darüber und über `dedupe_key`.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function spuren(array $marken): void
    {
        $ereignisse = ['page.viewed', 'form.submitted', 'payment.paid', 'email.opened', 'email.clicked', 'funnel.step_entered'];

        foreach ($ereignisse as $i => $typ) {
            foreach (['chorwerkstatt', 'halbmond', 'sonderzeichen'] as $marke) {
                BrandContext::runFor($marken[$marke], function () use ($typ, $marke, $i) {
                    Activity::record($typ, [
                        'event_id' => sprintf('demo0000-0000-4000-8000-%012d', crc32($marke.$i) % 1000000000000),
                        'dedupe_key' => 'demo-'.$marke.'-'.$i,
                        'source' => 'demo',
                        'properties' => ['pfad' => '/f/fruehlingskurs', 'variante' => $i % 2 ? 'a' : 'b'],
                        'occurred_at' => Carbon::now()->subHours($i * 7),
                    ]);
                });
            }
        }
    }

    /** Steht eine Freigabe schon in dem Zustand, den das Demo zeigen will? */
    protected function passt(Grant $freigabe, string $zustand): bool
    {
        $stand = $freigabe->entitlement?->state();

        if ($stand === null) {
            return false;
        }

        return match ($zustand) {
            'wartet' => $stand === EntitlementState::Pending,
            'entzogen' => $stand === EntitlementState::Revoked,
            'heruntergeladen' => $stand->grantsAccess() && $freigabe->downloads()->count() > 0,
            default => $stand->grantsAccess(),
        };
    }

    // -- Werkzeug -----------------------------------------------------------

    /** `-2 days`, `+3 days`, `heute` oder ein Schlüssel aus `DemoData::awkwardDates()`. */
    protected function wann(string $ausdruck): Carbon
    {
        if ($ausdruck === 'heute') {
            return Carbon::today()->setTime(17, 0);
        }

        $unangenehm = DemoData::awkwardDates();

        if (isset($unangenehm[$ausdruck])) {
            return Carbon::parse($unangenehm[$ausdruck]);
        }

        return str_starts_with($ausdruck, '-')
            ? Carbon::now()->sub(ltrim($ausdruck, '-'))
            : Carbon::now()->add(ltrim($ausdruck, '+'));
    }

    /** Die Id eines CP-Kontos, oder null, wenn es das Konto nicht gibt. */
    protected function nutzerId(string $adresse): ?string
    {
        $nutzer = User::findByEmail($adresse);

        return $nutzer ? (string) $nutzer->id() : null;
    }

    /**
     * Eine Adresse wirklich abrufen, durch den HTTP-Kernel.
     *
     * Kein Netzwerk, aber die volle Kette: Route, `signed`-Middleware,
     * Markenauflösung aus dem Routenwert, Controller. Der Marken-Kontext wird
     * danach wiederhergestellt — die Middleware setzt ihn, und ein Seeder, der
     * seine Marke unter sich verliert, schreibt die nächste Zeile in die falsche.
     */
    protected function hole(string $url): int
    {
        $vorher = BrandContext::hasCurrent() ? BrandContext::current() : null;

        try {
            return app(HttpKernel::class)->handle(Request::create($url, 'GET'))->getStatusCode();
        } finally {
            BrandContext::setCurrent($vorher);
        }
    }
}
