<?php

namespace App\Demo;

use App\Demo\Postfach\DemoImapServerFactory;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Das Postfach der Agentur (statamic-inbox).
 *
 * Die Demo hat kein echtes Postfach und darf nie eins erreichen (siehe
 * App\Demo\Postfach). Die Gespraeche entstehen trotzdem auf dem echten Weg:
 * die Mails liegen als RFC822 in einem Server im Speicher, und der echte
 * Abrufer des Addons holt, parst, bereinigt und faedelt sie. So wie das
 * Addon selbst es in `scripts/playground-seed.php` tut.
 *
 * Was danach dasteht, unter der Vorgabemarke `nordlicht` (dort landet ein
 * Besucher im CP):
 *  - ein Verlauf mit Henrike Albers, drei Mails, die letzte mit Zitat und
 *    UNGELESEN; sie ist LeadHub-Kontakt, das Gespraech haengt an ihr
 *  - ein HTML-Newsletter mit entfernten Bildern, die das Addon blockiert
 *  - eine Anfrage von jemandem, der kein Kontakt ist
 *  - eine Rueckfrage zur Rechnung, beantwortet, Status „wartet"
 *  - ein Dank, erledigt
 *
 * Das Postfach und die Bilder des Newsletters zeigen auf Hosts unter
 * `.invalid`, die nie aufloesen (RFC 2606). Das ist
 * die dritte Sicherung, nicht die erste: die Adapter sind ersetzt, und die
 * Postfach-Routen sind gesperrt (PostfaecherSchreibgeschuetzt).
 *
 * Wiederholbar: die Inbox-Tabellen werden vorher geleert, der Kontakt ueber
 * seine Adresse wiedergefunden.
 */
class SeedsInbox
{
    public const POSTFACH = 'hallo@nordlicht.beispiel';

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        if (! Schema::hasTable('inbox_mailboxes')) {
            return ['postfach' => 0];
        }

        $marke = $marken['nordlicht'] ?? Brand::query()->where('handle', 'nordlicht')->firstOrFail();

        $this->leeren();

        return BrandContext::runFor($marke, fn () => $this->imMarkenkontext());
    }

    /** @return array<string, int> */
    protected function imMarkenkontext(): array
    {
        LeadHub::create([
            'email' => 'henrike.albers@kammerchor-altona.beispiel',
            'first_name' => 'Henrike',
            'last_name' => 'Albers',
            'full_name' => 'Henrike Albers',
            'status' => 'qualified',
            'source' => 'website',
        ]);

        $postfach = Mailbox::create([
            'name' => 'Studio',
            'email' => self::POSTFACH,
            'from_name' => 'Nordlicht Studio',
            'imap_host' => 'imap.nordlicht.invalid', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'username' => self::POSTFACH,
            'password' => 'demo-kein-echtes-passwort',
            'smtp_host' => 'smtp.nordlicht.invalid', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'inbox_folder' => 'INBOX',
            'sent_folder' => 'Gesendet',
            'import_since' => Carbon::now()->subDays(90),
        ]);

        $fabrik = app(MailboxClientFactory::class);

        if (! $fabrik instanceof DemoImapServerFactory) {
            // Ohne die Bindung im AppServiceProvider holte der Abrufer unten
            // echte Post von einem echten Host. Lieber hier aufhoeren.
            throw new \RuntimeException('statamic-inbox ist nicht an den Demo-Server gebunden (AppServiceProvider).');
        }

        $server = $fabrik->server($postfach);

        foreach ($this->mails() as [$ordner, $roh]) {
            $server->zustellen($ordner, $roh);
        }

        app(MailboxFetcher::class)->fetch($postfach->fresh());

        $this->zustaende();

        return [
            'postfach' => 1,
            'gespräche' => Conversation::query()->count(),
        ];
    }

    protected function zustaende(): void
    {
        $gespraech = fn (string $betreff) => Conversation::query()
            ->where('subject', 'like', $betreff.'%')
            ->firstOrFail();

        foreach (['Pixelpost', 'Anmeldeformular', 'Rechnung', 'Danke'] as $betreff) {
            $gespraech($betreff)->forceFill(['unread' => false])->save();
        }

        // Absichtlich ungelesen: die Liste zeigt den Punkt, das Menue die Zahl.
        $gespraech('Neue Website')->forceFill(['unread' => true])->save();
        $gespraech('Rechnung')->forceFill(['status' => Conversation::STATUS_WAITING])->save();
        $gespraech('Danke')->forceFill(['status' => Conversation::STATUS_CLOSED])->save();
    }

    /** @return list<array{0: string, 1: string}> */
    protected function mails(): array
    {
        $vor = fn (string $abstand) => Carbon::now()->sub($abstand)->toRfc2822String();
        $wir = 'Nordlicht Studio <'.self::POSTFACH.'>';
        $henrike = 'Henrike Albers <henrike.albers@kammerchor-altona.beispiel>';

        return [
            ['INBOX', $this->text([
                'Date' => $vor('6 days'),
                'Message-ID' => '<ha-001@kammerchor-altona.beispiel>',
                'Subject' => 'Neue Website für den Kammerchor',
                'From' => $henrike,
                'To' => $wir,
            ], "Hallo zusammen,\n\nwir sind ein Kammerchor mit 28 Leuten und unsere Website ist von 2014. Wir bräuchten einen Probenkalender, eine Seite für Konzerte mit Ticketverkauf und einen geschützten Bereich für Noten.\n\nMachen Sie so etwas, und was würde das ungefähr kosten?\n\nViele Grüße\nHenrike Albers\nVorstand Kammerchor Altona\n")],
            ['Gesendet', $this->text([
                'Date' => $vor('5 days'),
                'Message-ID' => '<ns-out-001@nordlicht.beispiel>',
                'In-Reply-To' => '<ha-001@kammerchor-altona.beispiel>',
                'References' => '<ha-001@kammerchor-altona.beispiel>',
                'Subject' => 'Re: Neue Website für den Kammerchor',
                'From' => $wir,
                'To' => $henrike,
            ], "Hallo Frau Albers,\n\ndanke für die Anfrage. Genau das bauen wir: Kalender, Konzerte mit Kasse und ein Notenbereich mit Anmeldung.\n\nIch schlage ein Gespräch von 30 Minuten vor, danach bekommen Sie ein Angebot. Passt Ihnen Donnerstag um 17 Uhr?\n\nViele Grüße\nMira Andresen\nNordlicht Studio\n")],
            ['INBOX', $this->text([
                'Date' => $vor('4 hours'),
                'Message-ID' => '<ha-002@kammerchor-altona.beispiel>',
                'In-Reply-To' => '<ns-out-001@nordlicht.beispiel>',
                'References' => '<ha-001@kammerchor-altona.beispiel> <ns-out-001@nordlicht.beispiel>',
                'Subject' => 'Re: Neue Website für den Kammerchor',
                'From' => $henrike,
                'To' => $wir,
            ], "Hallo Frau Andresen,\n\nDonnerstag 17 Uhr passt. Ich bringe unseren Kassenwart mit, der kümmert sich um den Ticketverkauf.\n\nHenrike\n\nAm ".Carbon::now()->sub('5 days')->format('d.m.Y')." schrieb Nordlicht Studio <".self::POSTFACH.">:\n> Hallo Frau Albers,\n>\n> danke für die Anfrage. Genau das bauen wir: Kalender, Konzerte mit Kasse\n> und ein Notenbereich mit Anmeldung.\n")],
            ['INBOX', $this->newsletter($vor('1 day'))],
            ['INBOX', $this->text([
                'Date' => $vor('2 days'),
                'Message-ID' => '<tw-001@musikschule-elbufer.beispiel>',
                'Subject' => 'Anmeldeformular für die Musikschule',
                'From' => 'Tobias Wendt <t.wendt@musikschule-elbufer.beispiel>',
                'To' => self::POSTFACH,
            ], "Guten Tag,\n\nwir suchen jemanden, der unser Anmeldeformular für den Instrumentalunterricht neu baut. Heute kommen die Anmeldungen als PDF per Mail, und wir tippen alles ab.\n\nHätten Sie im November Kapazität?\n\nMit freundlichen Grüßen\nTobias Wendt\nMusikschule am Elbufer\n")],
            ['INBOX', $this->text([
                'Date' => $vor('3 days'),
                'Message-ID' => '<fk-001@praxis-lindhorst.beispiel>',
                'Subject' => 'Rechnung NS-2026-041',
                'From' => 'Frida Kolbe <verwaltung@praxis-lindhorst.beispiel>',
                'To' => self::POSTFACH,
            ], "Hallo,\n\nin der Rechnung NS-2026-041 steht die Betreuung für September zweimal. Können Sie das prüfen?\n\nDanke und Grüße\nFrida Kolbe\n")],
            ['Gesendet', $this->text([
                'Date' => $vor('2 days 20 hours'),
                'Message-ID' => '<ns-out-002@nordlicht.beispiel>',
                'In-Reply-To' => '<fk-001@praxis-lindhorst.beispiel>',
                'References' => '<fk-001@praxis-lindhorst.beispiel>',
                'Subject' => 'Re: Rechnung NS-2026-041',
                'From' => $wir,
                'To' => 'Frida Kolbe <verwaltung@praxis-lindhorst.beispiel>',
            ], "Hallo Frau Kolbe,\n\nSie haben recht, die Position ist doppelt. Die Buchhaltung schickt Ihnen bis Freitag eine korrigierte Rechnung.\n\nViele Grüße\nMira Andresen\n")],
            ['INBOX', $this->text([
                'Date' => $vor('9 days'),
                'Message-ID' => '<ct-001@halbmond.beispiel>',
                'Subject' => 'Danke für den Relaunch',
                'From' => 'Carla Timm <carla@halbmond.beispiel>',
                'To' => self::POSTFACH,
            ], "Hallo Nordlicht,\n\ndie neue Seite ist seit Montag online, und die Vorbestellungen für die Platte laufen. Danke euch!\n\nCarla\n")],
        ];
    }

    /** @param array<string, string> $kopf */
    protected function text(array $kopf, string $inhalt): string
    {
        return "MIME-Version: 1.0\r\n".$this->kopf($kopf)
            ."Content-Type: text/plain; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            .str_replace("\n", "\r\n", $inhalt);
    }

    /** HTML mit Zaehlpixel und Titelbild von fremden Hosts. Das Addon laedt beide nicht. */
    protected function newsletter(string $datum): string
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html><head><style>body{font-family:Georgia,serif}</style></head>
            <body>
            <img src="https://bilder.pixelpost.invalid/wochenbrief-39.jpg" alt="Titelbild Wochenbrief 39" width="560">
            <h1>Der Webdesign-Wochenbrief, Ausgabe 39</h1>
            <p>Diese Woche: warum Formulare mit drei Feldern öfter abgeschickt werden als mit neun, und ein Blick auf Schriften für lange Texte.</p>
            <p><a href="https://pixelpost.beispiel/ausgabe-39">Im Browser lesen</a></p>
            <img src="https://zaehler.pixelpost.invalid/open.gif?u=4411" width="1" height="1" alt="">
            </body></html>
            HTML;

        return "MIME-Version: 1.0\r\n".$this->kopf([
            'Date' => $datum,
            'Message-ID' => '<wb-39.4411@pixelpost.beispiel>',
            'Subject' => 'Pixelpost: Der Webdesign-Wochenbrief, Ausgabe 39',
            'From' => 'Pixelpost <newsletter@pixelpost.beispiel>',
            'To' => self::POSTFACH,
            'List-Unsubscribe' => '<https://pixelpost.beispiel/abmelden?u=4411>',
        ])."Content-Type: multipart/alternative; boundary=\"b_pixelpost_39\"\r\n\r\n"
            ."--b_pixelpost_39\r\nContent-Type: text/plain; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            ."Der Webdesign-Wochenbrief, Ausgabe 39. https://pixelpost.beispiel/ausgabe-39\r\n\r\n"
            ."--b_pixelpost_39\r\nContent-Type: text/html; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            .str_replace("\n", "\r\n", $html)."\r\n\r\n--b_pixelpost_39--\r\n";
    }

    /** @param array<string, string> $kopf */
    protected function kopf(array $kopf): string
    {
        $zeilen = '';

        foreach ($kopf as $name => $wert) {
            $zeilen .= "{$name}: {$wert}\r\n";
        }

        return $zeilen;
    }

    /** Ueber den Query Builder: die Modelle tragen den Markenscope. */
    protected function leeren(): void
    {
        foreach (['inbox_attachments', 'inbox_messages', 'inbox_conversations', 'inbox_fetch_failures', 'inbox_mailboxes'] as $tabelle) {
            if (Schema::hasTable($tabelle)) {
                DB::table($tabelle)->delete();
            }
        }

        // Die Zeitleisten-Eintraege zeigen auf Nachrichten-Ids, die es nach
        // dem Leeren nicht mehr gibt; ihr Dedupe-Schluessel wuerde den neuen
        // Eintrag verhindern.
        if (Schema::hasTable('leadhub_events')) {
            DB::table('leadhub_events')->where('source_type', 'inbox_message')->delete();
        }
    }
}
