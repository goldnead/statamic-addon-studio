<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\CampaignSender;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Einmal wirklich senden.
 *
 * Der Rest des Demos beschreibt Zustände; dieser Seeder erzeugt sie. Eine
 * Kampagne, die nie gelaufen ist, lässt fünf Reiter im Bericht leer, das
 * Sperr-Gatter ungefragt, den TimelineRecorder stumm, die Marketing-Etiketten
 * in LeadHub ungeschrieben und den Preference-Center-Link ungetestet. Alles
 * daran hängt an einem einzigen Sendelauf, und der kostet hier nichts:
 * `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`.
 *
 * ## Die Treiberfrage, und warum sie `flat` beantwortet wird
 *
 * `statamic-marketing` kann Definitionen — Listen, Kampagnen, Vorlagen — als
 * YAML unter `content/marketing/` halten oder in Tabellen. Der alte Seeder hat
 * `MailingListRecord::withoutGlobalScopes()->updateOrCreate()` geschrieben,
 * während `marketing.storage.driver` auf `flat` stand. Ergebnis: vier Zeilen in
 * `marketing_lists`, die kein Bildschirm je gelesen hat, und drei Marken, deren
 * Listenübersicht leer war. Das ist der Fehler in Reinform — nicht ein falscher
 * Wert, sondern ein Schreibvorgang an der Ablage vorbei.
 *
 * Entschieden wurde für **`flat`**, also YAML, aus drei Gründen:
 *
 * 1. Eine Liste ist eine Definition, keine Laufzeitdatei. Sie gehört in
 *    dieselbe Schublade wie der Produktkatalog, der in diesem Playground
 *    bewusst in `config/statamic-payments.php` steht und nicht in eine Tabelle.
 * 2. `flat` ist die Vorgabe des Addons. Ein Schauraum, der die Vorgabe umgeht,
 *    um vorzeigbar zu sein, zeigt nicht das Addon.
 * 3. `flat` ist die Fassung mit den schärferen Kanten, und ein Demo soll die
 *    fahren: das Markenverzeichnis steckt im Pfad
 *    (`content/marketing/{marke}/lists/…`), `YamlStore::readSegments()` fällt
 *    zu, wenn keine Marke aufgelöst ist, und `guardHandleIsFree()` verweigert
 *    ein Handle, das eine andere Marke schon hält. Unter `eloquent` liefe
 *    nichts davon mit.
 *
 * Die Abonnements, die Meldungen und die Ereignisse liegen weiterhin in der
 * Datenbank. Das ist keine Inkonsequenz, sondern die Trennung, die das Addon
 * selbst zieht: Definitionen sind Inhalt, Laufzeitdaten sind es nicht.
 */
class SeedsCampaign
{
    /** Die Kampagne, an der der Bericht etwas zu zeigen hat. */
    public const HANDLE = 'fruehlingsbrief';

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken): array
    {
        $this->listenSicherstellen($marken);

        $kampagne = $this->kampagne($marken);
        $gesendet = $this->senden($marken, $kampagne);
        [$oeffnungen, $klicks] = $this->reaktionen($marken, $kampagne);

        return [
            'kampagnen' => Message::withoutGlobalScopes()->distinct()->count('campaign_handle'),
            'nachrichten' => Message::withoutGlobalScopes()->count(),
            'geoeffnet' => $oeffnungen,
            'geklickt' => $klicks,
            'sendelauf' => $gesendet,
        ];
    }

    /**
     * Die Listen, als YAML, über das Repository.
     *
     * Öffentlich und für sich allein lauffähig, weil SeedsCrm sie braucht: ohne
     * Liste gibt es kein Abonnement, und die Reihenfolge der Seeder soll nicht
     * zur stillen Voraussetzung werden.
     *
     * @param  array<string, Brand>  $marken
     */
    public function listenSicherstellen(array $marken): void
    {
        $this->reste();

        $definitionen = [
            'chorwerkstatt' => [
                ['chorbrief', 'Der Chorbrief', 'Alle zwei Wochen, mit einer Übung zum Mitnehmen.', true],
                ['kursinfos', 'Kursinfos', 'Nur wenn ein Kurs startet.', false],
            ],
            'halbmond' => [
                ['tourmail', 'Tourmail', 'Wo wir spielen, und wann Karten weggehen.', true],
            ],
            'lindhorst' => [
                ['praxisbrief', 'Praxisbrief', 'Einmal im Monat, ruhig.', true],
            ],
            // Die Bösewicht-Marke hat auch eine Liste. Ohne sie taucht sie in
            // keinem Marketing-Bildschirm auf, und genau das war der Zustand.
            'sonderzeichen' => [
                ['zeichenbrief', 'Ännchens Rundbrief', 'Mit allem, was eine Kopfzeile bricht.', false],
            ],
        ];

        $repository = app(MailingListRepository::class);

        foreach ($definitionen as $marke => $eintraege) {
            BrandContext::runFor($marken[$marke], function () use ($repository, $eintraege) {
                foreach ($eintraege as [$handle, $name, $beschreibung, $doi]) {
                    $repository->save(new MailingList(
                        handle: $handle,
                        name: $name,
                        description: $beschreibung,
                        doubleOptIn: $doi,
                    ));
                }
            });
        }
    }

    /**
     * Die Zeilen, die der alte Seeder in `marketing_lists` hinterlassen hat.
     *
     * Sie sind unter dem `flat`-Treiber unsichtbar und werden von nichts mehr
     * geschrieben — sie wegzuräumen ist Teil der Reparatur und nicht Datenverlust:
     * dieselben vier Listen stehen danach als YAML da, wo das Addon sie liest.
     * Eng auf die vier Handles begrenzt, damit ein Handle, das jemand von Hand
     * angelegt hat, bleibt.
     */
    protected function reste(): void
    {
        DB::table('marketing_lists')
            ->whereIn('handle', ['chorbrief', 'kursinfos', 'tourmail', 'praxisbrief'])
            ->delete();
    }

    /**
     * Der Frühlingsbrief.
     *
     * Zwei Links im Text, und beide sind Absicht: einer zeigt nach draußen und
     * wird vom Renderer auf die signierte Klick-Umleitung umgeschrieben, einer
     * ist der Abmeldelink, den der Renderer in Ruhe lässt.
     *
     * @param  array<string, Brand>  $marken
     */
    protected function kampagne(array $marken): Campaign
    {
        return BrandContext::runFor($marken['chorwerkstatt'], function () {
            $repository = app(CampaignRepository::class);

            $vorhanden = $repository->find(self::HANDLE);

            // Eine bereits versendete Kampagne wird nicht zurückgesetzt. Ein
            // zweiter Seed-Lauf darf keinen zweiten Versand auslösen: die
            // Nachrichten liegen, die Ereignisse liegen, und `isSendable()`
            // sagt danach nein — was hier heißt, dass alles stimmt.
            if ($vorhanden && $vorhanden->status === Campaign::STATUS_SENT) {
                return $vorhanden;
            }

            $kampagne = $vorhanden ?? new Campaign(
                handle: self::HANDLE,
                name: 'Frühlingsbrief',
            );

            $kampagne->name = 'Frühlingsbrief';
            $kampagne->subject = 'Ännchens Frühlingsbrief: drei Übungen für die nächste Probe';
            // Betreff B. Damit ist die Kampagne ein A/B-Test, und der
            // VariantAssigner hat im Bericht etwas zu zeigen.
            $kampagne->variantSubject = 'Drei Übungen, die Ihren Chor in zehn Minuten hörbar ändern';
            $kampagne->preheader = 'Kurz, praktisch, ohne Theorie.';
            $kampagne->listHandle = 'chorbrief';
            $kampagne->status = Campaign::STATUS_DRAFT;
            $kampagne->content = implode("\n", [
                '<p>Hallo {{ first_name }},</p>',
                '<p>drei Übungen für den nächsten Probenabend, jede unter fünf Minuten.</p>',
                '<p><a href="https://chorwerkstatt.beispiel/uebungen">Die drei Übungen ansehen</a></p>',
                '<p>Bis bald,<br>Chorwerkstatt Nord</p>',
                '<p><a href="{{ unsubscribe_url }}">Abmelden oder Einstellungen ändern</a></p>',
            ]);

            return $repository->save($kampagne);
        });
    }

    /**
     * Der Sendelauf.
     *
     * `CampaignSender::queue()` und nicht ein selbstgebauter Durchlauf über die
     * Abonnenten: der Job dazwischen ist der, der das Sperr-Gatter je Bündel
     * einmal fragt, `do_not_contact` prüft, das Segment schneidet und die
     * Varianten verteilt. Wer die Nachrichten selbst schriebe, hätte genau die
     * vier Prüfungen übersprungen, um deren Wirkung es hier geht.
     *
     * @param  array<string, Brand>  $marken
     * @return int Zahl der tatsächlich erzeugten Nachrichten
     */
    protected function senden(array $marken, Campaign $kampagne): int
    {
        if ($kampagne->status === Campaign::STATUS_SENT) {
            return 0;
        }

        return BrandContext::runFor($marken['chorwerkstatt'], function () use ($kampagne) {
            $vorher = Message::query()->where('campaign_handle', self::HANDLE)->count();

            // Läuft synchron durch: die Queue steht auf `sync`, also erledigt
            // `dispatch()` den StartCampaignJob und jeder SendMessageJob sofort.
            app(CampaignSender::class)->queue($kampagne);

            return Message::query()->where('campaign_handle', self::HANDLE)->count() - $vorher;
        });
    }

    /**
     * Öffnungen und Klicks, über die echten Routen.
     *
     * Nicht `TrackingService` direkt: die Pixelroute leitet die Marke aus der
     * Nachrichten-UUID ab und die Klickroute prüft eine Signatur, und beides
     * ist Teil dessen, was hier belegt werden soll. Die Adressen kommen aus dem
     * gerenderten Mailtext, also aus dem, was der Empfänger wirklich vor sich
     * hat.
     *
     * @param  array<string, Brand>  $marken
     * @return array{0:int,1:int}
     */
    protected function reaktionen(array $marken, Campaign $kampagne): array
    {
        $oeffnungen = 0;
        $klicks = 0;

        BrandContext::runFor($marken['chorwerkstatt'], function () use (&$oeffnungen, &$klicks, $kampagne) {
            $nachrichten = Message::query()
                ->where('campaign_handle', self::HANDLE)
                ->orderBy('id')
                ->get();

            foreach ($nachrichten->values() as $i => $nachricht) {
                // Nicht jeder öffnet. Zwei von drei ist eine Quote, die einem
                // Bericht etwas zu rechnen gibt; hundert Prozent wäre eine
                // Zahl, an der niemand einen Fehler sähe.
                if ($i % 3 === 2) {
                    continue;
                }

                if ($this->bereits($nachricht, MessageEvent::TYPE_OPEN)) {
                    $oeffnungen++;
                } elseif ($this->hole(route('marketing.track.open', ['uuid' => $nachricht->uuid])) === 200) {
                    $oeffnungen++;
                }

                if ($i % 2 !== 0) {
                    continue;
                }

                if ($this->bereits($nachricht, MessageEvent::TYPE_CLICK)) {
                    $klicks++;

                    continue;
                }

                $ziel = $this->klickAdresse($kampagne, $nachricht);

                // 302: die Umleitung ist die Bestätigung, dass die Signatur
                // gehalten hat. 403 hieße, sie hat es nicht.
                if ($ziel && $this->hole($ziel) === 302) {
                    $klicks++;
                }
            }
        });

        return [$oeffnungen, $klicks];
    }

    protected function bereits(Message $nachricht, string $typ): bool
    {
        return MessageEvent::query()
            ->where('message_id', $nachricht->id)
            ->where('type', $typ)
            ->exists();
    }

    /**
     * Die signierte Klick-Adresse, aus der gerenderten Mail geschnitten.
     *
     * `marketing_messages` speichert den Text nicht — die Mail existiert nur
     * unterwegs. Sie wird deshalb über denselben Renderer noch einmal gebaut,
     * mit derselben Nachricht: `rewriteLinks()` benutzt `URL::signedRoute()`
     * ohne Ablauf, die Adresse ist also für dieselbe Nachricht und dieselbe
     * Ziel-URL dieselbe. Die Signatur hier nachzubauen hieße dagegen, sie ein
     * zweites Mal zu erfinden und dann zu prüfen, ob die eigene Erfindung zur
     * eigenen Erfindung passt.
     */
    protected function klickAdresse(Campaign $kampagne, Message $nachricht): ?string
    {
        $liste = app(MailingListRepository::class)->find((string) $kampagne->listHandle);
        $abo = Subscription::query()->find($nachricht->subscription_id);

        if (! $liste || ! $abo) {
            return null;
        }

        $html = app(CampaignRenderer::class)->render($kampagne, $liste, $abo, $nachricht)->html;

        if (! preg_match('#href="([^"]*/c/[^"]+)"#', $html, $treffer)) {
            return null;
        }

        return html_entity_decode($treffer[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Eine Adresse wirklich abrufen, durch den HTTP-Kernel.
     *
     * Kein Netzwerk — der Seeder läuft auch, wenn gerade kein `artisan serve`
     * offen ist — aber die volle Kette: Route, Middleware, Signaturprüfung,
     * Markenauflösung, Controller. Der Marken-Kontext wird danach
     * wiederhergestellt, weil die Middleware ihn setzt und ein Seeder, der
     * seine eigene Marke unter sich verliert, in der nächsten Zeile in die
     * falsche schreibt.
     */
    protected function hole(string $url): int
    {
        $vorher = BrandContext::hasCurrent() ? BrandContext::current() : null;

        try {
            $antwort = app(HttpKernel::class)->handle(Request::create($url, 'GET'));

            return $antwort->getStatusCode();
        } finally {
            BrandContext::setCurrent($vorher);
        }
    }
}
