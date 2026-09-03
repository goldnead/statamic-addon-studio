<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;

/**
 * Vier Vorlagen in die et_templates-Sammlung, damit der Schauraum eines hat:
 * echte Einträge, die EmailTemplates::resolve() zurückgibt, statt einer leeren
 * Sammlung und eines layout-Selects ohne Optionen.
 *
 * Drei Dinge macht dieser Seeder:
 *
 *   1. Das layout-Select neu befüllen. Der Blueprint auf der Platte wurde
 *      geschrieben, als config('email-templates.layouts') noch leer war, also
 *      trägt sein Select keine Optionen. Die Optionen entstehen aus den Keys
 *      dieser Config zum Zeitpunkt, an dem der Blueprint gebaut wird — das Addon
 *      baut ihn aber nur neu, wenn er fehlt. Also stoßen wir den kanonischen
 *      Builder des Addons einmal an, damit "Transactional" und "Kampagne"
 *      wählbar werden. Idempotent, und die Marken-Feld-Logik des Addons bleibt.
 *
 *   2. Vier Vorlagen über den öffentlichen CollectionManager schreiben (nicht
 *      per Entry::make von Hand): er wandelt den HTML-Body nach Bard, stempelt
 *      die Marke und ist per Slug idempotent.
 *
 *   3. Eine Vorlage (magic-link) trägt absichtlich unbekannte Merge-Variablen
 *      ({{ login.magic_url }}, {{ login.expires_in }}). Das dokumentierte
 *      "unbekannte Tags bleiben stehen" ist genau der Fall, den ein Schauraum
 *      zeigen soll: ein Tippfehler wird in der Vorschau sichtbar, statt still
 *      im Postfach zu verschwinden.
 */
class SeedsEmailTemplates
{
    /** @return array<string, int> */
    public function run(): array
    {
        $this->blueprintOptionenNachziehen();

        $manager = app(EmailTemplateCollectionManager::class);

        // Die Sammlung, ihr Blueprint und die Live-Preview-Verdrahtung sicher
        // vorhanden — billiger No-op, wenn schon da.
        $manager->ensure();

        // Die Marke steht NICHT in EmailTemplateData: die Entry-Klasse stempelt
        // beim Speichern die aktuelle. Ein Seeder, der einfach durchläuft, legt
        // darum alles unter der Standardmarke ab — genau der Zustand bis zum
        // 03.09.2026: vier Vorlagen, alle `nordlicht`, und unter
        // `chorwerkstatt` eine leere Liste, die sich wie ein kaputter Screen
        // liest statt wie eine leere Marke. Deshalb je Marke ein runFor-Rahmen.
        BrandContext::runFor('nordlicht', function () use ($manager) {
            foreach ($this->vorlagen() as $data) {
                $manager->upsert($data);
            }
        });

        BrandContext::runFor('chorwerkstatt', function () use ($manager) {
            foreach ($this->chorwerkstattVorlagen() as $data) {
                $manager->upsert($data);
            }
        });

        return [
            'vorlagen' => \Statamic\Facades\Entry::query()
                ->where('collection', EmailTemplateCollectionManager::HANDLE)
                ->count(),
        ];
    }

    /**
     * Den Blueprint einmal über den Builder des Addons neu schreiben, damit das
     * layout-Select die Keys aus config('email-templates.layouts') als Optionen
     * bekommt. Ohne das sieht ein Redakteur ein Auswahlfeld, das nichts wählen
     * lässt.
     */
    protected function blueprintOptionenNachziehen(): void
    {
        EmailTemplateBlueprint::make()->save();
    }

    /**
     * @return array<int, EmailTemplateData>
     */
    protected function vorlagen(): array
    {
        return [
            // Kampagne: die einzige der vier mit der Marketing-Hülle. Nutzt nur
            // dokumentierte Merge-Variablen, inklusive der Abmelde-URL im Body
            // (dort gehört sie hin, nicht in die Blade-Hülle).
            EmailTemplateData::fromArray([
                'slug' => 'willkommen',
                'title' => 'Willkommen',
                'subject' => 'Willkommen bei {{ sender.name }}, {{ contact.first_name }}',
                'preview' => 'Schön, dass du da bist.',
                'layout' => 'kampagne',
                'body' => '<h2>Hallo {{ contact.first_name }},</h2>'
                    .'<p>schön, dass du dich eingetragen hast. Wir schreiben dir '
                    .'als {{ contact.full_name }} an {{ contact.email }}.</p>'
                    .'<p>Alle zwei Wochen eine Übung zum Mitnehmen, mehr nicht.</p>'
                    .'<p><a href="{{ unsubscribe_url }}">Wenn es doch zu viel wird, hier abmelden.</a></p>',
                'description' => 'Begrüßung nach der Eintragung. Kampagnen-Hülle.',
            ]),

            // Transaktional: Beleg nach einer Zahlung. Der Betrag steht als
            // krummer Wert aus DemoData im Text, damit die Formatierung an einer
            // Zahl mit Nachkommastelle geprüft wird.
            EmailTemplateData::fromArray([
                'slug' => 'zahlung-bestaetigt',
                'title' => 'Zahlung bestätigt',
                'subject' => 'Zahlung bestätigt, {{ contact.first_name }}',
                'preview' => 'Wir haben deine Zahlung erhalten.',
                'layout' => 'transactional',
                'body' => '<p>{{ contact.salutation }},</p>'
                    .'<p>wir haben deine Zahlung über <strong>'
                    .number_format(DemoData::AWKWARD_AMOUNTS['krumm'] / 100, 2, ',', '.')
                    .'&nbsp;EUR</strong> am {{ date }} erhalten. Danke.</p>'
                    .'<p>Dein Zugang steht dir ab sofort offen.</p>',
                'description' => 'Beleg nach erfolgreicher Zahlung.',
            ]),

            // Transaktional: Kündigungsbestätigung. Ruhig, keine Rückgewinnung.
            EmailTemplateData::fromArray([
                'slug' => 'abo-gekuendigt',
                'title' => 'Abo gekündigt',
                'subject' => 'Schade, {{ contact.first_name }} — dein Abo endet',
                'preview' => 'Deine Kündigung ist eingegangen.',
                'layout' => 'transactional',
                'body' => '<p>Hallo {{ contact.first_name }},</p>'
                    .'<p>deine Kündigung ist eingegangen. Dein Zugang bleibt bis zum '
                    .'Ende des bezahlten Zeitraums bestehen, danach ist Schluss.</p>'
                    .'<p>Wenn du zurückwillst, weißt du, wo wir sind.</p>',
                'description' => 'Bestätigung nach einer Abo-Kündigung.',
            ]),

            // Transaktional, und absichtlich mit unbekannten Merge-Variablen:
            // {{ login.magic_url }} und {{ login.expires_in }} stehen NICHT im
            // dokumentierten Beispielsatz. In der Vorschau bleiben sie sichtbar
            // stehen — der dokumentierte Fall, den der Schauraum zeigen soll.
            EmailTemplateData::fromArray([
                'slug' => 'magic-link',
                'title' => 'Anmelde-Link',
                'subject' => 'Dein Anmelde-Link, {{ contact.first_name }}',
                'preview' => 'Ein Klick, und du bist drin.',
                'layout' => 'transactional',
                'body' => '<p>Hallo {{ contact.first_name }},</p>'
                    .'<p>klick auf den Link, dann bist du angemeldet:</p>'
                    .'<p><a href="{{ login.magic_url }}">Jetzt anmelden</a></p>'
                    .'<p>Der Link läuft in {{ login.expires_in }} ab. Wenn du das '
                    .'nicht warst, ignorier diese Mail.</p>',
                'description' => 'Passwortloser Login. Trägt absichtlich zwei unbekannte '
                    .'Merge-Variablen, um "unbekannte Tags bleiben stehen" zu zeigen.',
            ]),
        ];
    }

    /**
     * Dieselben vier Sorten für die zweite Marke, damit ein Durchgang unter
     * `chorwerkstatt` nicht auf eine leere Liste läuft und man die Hüllen in
     * zwei Farben nebeneinander sehen kann (Chorwerkstatt Nord: #7a4a1e).
     *
     * Eigene Slugs, weil ein Slug je Sammlung eindeutig ist: die Marke steht im
     * Eintrag, nicht im Dateinamen.
     *
     * Der Inhalt ist der einer Chorwerkstatt und nicht der eines Abo-Produkts:
     * Workshops mit festen Terminen, kein Abo, keine Rückgewinnung.
     *
     * @return array<int, EmailTemplateData>
     */
    protected function chorwerkstattVorlagen(): array
    {
        return [
            // Die einzige mit der Kampagnen-Hülle, Gegenstück zu `willkommen`.
            EmailTemplateData::fromArray([
                'slug' => 'cw-willkommen',
                'title' => 'Willkommen in der Chorwerkstatt',
                'subject' => 'Willkommen in der Chorwerkstatt, {{ contact.first_name }}',
                'preview' => 'Was dich erwartet und wann wir schreiben.',
                'layout' => 'kampagne',
                'body' => '<h2>Hallo {{ contact.first_name }},</h2>'
                    .'<p>du stehst jetzt auf der Liste von {{ sender.name }}. Wir schreiben '
                    .'dir an {{ contact.email }}, wenn ein neuer Workshoptermin steht.</p>'
                    .'<p>Einmal im Monat, dazu eine Übung, die auch ohne Chor funktioniert. '
                    .'Mehr kommt nicht.</p>'
                    .'<p><a href="{{ unsubscribe_url }}">Wenn es zu viel wird, hier abmelden.</a></p>',
                'description' => 'Begrüßung nach der Eintragung. Kampagnen-Hülle, Marke Chorwerkstatt.',
            ]),

            // Beleg nach der Anmeldung. Wieder der krumme Betrag, damit die
            // Formatierung an einer Nachkommastelle geprüft wird.
            EmailTemplateData::fromArray([
                'slug' => 'cw-workshop-bestaetigt',
                'title' => 'Workshop-Platz bestätigt',
                'subject' => 'Dein Platz am {{ date }} steht, {{ contact.first_name }}',
                'preview' => 'Anmeldung und Zahlung sind da.',
                'layout' => 'transactional',
                'body' => '<p>{{ contact.salutation }},</p>'
                    .'<p>dein Platz ist gebucht. Wir haben <strong>'
                    .number_format(DemoData::AWKWARD_AMOUNTS['krumm'] / 100, 2, ',', '.')
                    .'&nbsp;EUR</strong> am {{ date }} erhalten.</p>'
                    .'<p>Bring bequeme Kleidung und etwas zu trinken mit. Noten stellen wir.</p>',
                'description' => 'Beleg nach Anmeldung und Zahlung für einen Workshop.',
            ]),

            // Erinnerung kurz vorher. Der Fall, den ein Abo-Produkt nicht hat.
            EmailTemplateData::fromArray([
                'slug' => 'cw-probe-erinnerung',
                'title' => 'Erinnerung an den Probentag',
                'subject' => 'Übermorgen, {{ contact.first_name }}',
                'preview' => 'Kurze Erinnerung an deinen Termin.',
                'layout' => 'transactional',
                'body' => '<p>Hallo {{ contact.first_name }},</p>'
                    .'<p>am {{ date }} sehen wir uns. Wir fangen pünktlich an, '
                    .'also plan ein paar Minuten Puffer ein.</p>'
                    .'<p>Wenn du doch nicht kannst, sag kurz Bescheid. Dann rückt '
                    .'jemand von der Warteliste nach.</p>',
                'description' => 'Erinnerung zwei Tage vor dem Workshop.',
            ]),

            // Abmeldung. Ruhig, keine Rückgewinnung, wie beim Gegenstück.
            EmailTemplateData::fromArray([
                'slug' => 'cw-abmeldung-bestaetigt',
                'title' => 'Abmeldung bestätigt',
                'subject' => 'Du bist abgemeldet, {{ contact.first_name }}',
                'preview' => 'Wir schreiben dir nicht mehr.',
                'layout' => 'transactional',
                'body' => '<p>Hallo {{ contact.first_name }},</p>'
                    .'<p>deine Abmeldung ist eingegangen. Wir schreiben dir ab sofort nicht mehr.</p>'
                    .'<p>Die Termine stehen weiter öffentlich auf unserer Seite.</p>',
                'description' => 'Bestätigung nach der Abmeldung von der Liste.',
            ]),
        ];
    }
}
