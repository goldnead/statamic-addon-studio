# Das Demo-Projekt

Eine kleine Agentur, **Nordlicht Studio**, mit drei Kunden. Alle Addons der Familie zusammen
installiert, jede Marke mit eigener Handschrift, der Handel gegen Mollies Testkonto.

Es ist zwei Dinge auf einmal, und das ist Absicht:

1. **Ein Schauraum.** Jemandem zeigen, was die Familie kann, ohne eine Kundeninstallation
   anzufassen.
2. **Die einzige Probe, die alles gemeinsam fährt.** Jedes Addon hat seine eigene Testsuite und
   keine davon weiß von den anderen. Zwanzig Addons in einer Site sind eine Lage, die sonst
   niemand herstellt, und genau dort sind die Fehler.

Der zweite Zweck ist der wichtigere. Die Seed-Daten sind deshalb **absichtlich unangenehm**, nicht
hübsch: siehe `app/Demo/DemoData.php`.

## Die drei Kunden

| Handle | Name | Was sie verkaufen |
|---|---|---|
| `chorwerkstatt` | Chorwerkstatt Nord | Kurs, Mitgliedschaft (Abo), Ausbildung (drei Raten), Workshop, ein kostenloses Freebie |
| `halbmond` | Kollektiv Halbmond | Platte, Ticket, Fanclub (Abo) |
| `lindhorst` | Praxis Lindhorst | Erstgespräch (gratis), Fünferkarte, Begleitung (Abo mit Testphase) |

Dazu `nordlicht` als Vorgabemarke der Agentur und `sonderzeichen`, eine Marke, deren Name die
Zeichen trägt, die eine Mail-Kopfzeile, eine URL und ein HTML-Attribut brechen.

## Aufbauen

Das Demo ist aus einem frischen Klon wiederherstellbar, und das ist geprüft, nicht behauptet.
Voraussetzung: die Geschwister-Repos liegen daneben (`../../statamic-*`) — das Playground bindet sie
als Pfad-Repos ein, damit eine lokale Änderung sofort hier sichtbar ist.

```bash
cp .env.example .env
touch database/database.sqlite      # SQLite legt die Datei nicht selbst an
composer install
php artisan key:generate

# CACHE_STORE=array nur fuer diesen einen Aufruf: die .env stellt den Cache auf
# `database`, und genau die Cache-Tabelle legt diese Migration erst an. Ohne den
# Vorsatz laeuft `migrate` in "no such table: cache".
CACHE_STORE=array php artisan migrate --force

php artisan demo:seed
```

**Anmelden:** `mira@nordlicht.beispiel` / `demo-local-password`. Das Konto legt `SeedsTeam` an; es
ist das einzige mit Superuser-Recht, die vier anderen sind absichtlich beschränkt. Bis 25.08.2026
nannten README und diese Datei ein Konto `studio@local`, das kein Schritt erzeugt und das nicht im
Repo liegt — wer den Klon aufbaute, kam nicht hinein.

**Kein `vendor:publish --force`.** Seit die `config/` versioniert ist, würde das genau die
Entscheidungen des Demos überschreiben, die den Aufbau tragen: den Produktkatalog, die
Markenzuordnung, die Feature-Schalter von LeadHub, den Datei-Treiber für Nutzer. Der erste
Klon-Aufbau ist daran gescheitert, und der Fehler sah aus wie ein Addon-Fehler. Was ein Addon an
gebauten Dateien braucht (`public/vendor/…`), liegt bereits im Repo.

`demo:seed` ist **wiederholbar**. Der zweite Lauf ist der, der die Fehler findet: er stößt auf
alles, was sich nicht zweimal schreiben lässt, und genau das hat beim Bauen dieses Demos mehrere
richtige Entscheidungen anderer Addons sichtbar gemacht (der Aktivitätslog lässt sich nicht ändern,
die Statusspalte eines Zugangs ist vor Massenzuweisung geschützt, ein Schritt-Slug ist je Funnel
eindeutig).

`demo:seed --fresh` räumt die Handelsdaten vorher weg.

### Die Reihenfolge ist nicht beliebig

- **Team vor Leuten.** `SeedsCrm` weist Kontakte an CP-Konten zu; in einem frischen Klon gibt es
  noch keins. Genau daran ist der erste Klon-Aufbau gestorben.
- **Automationen vor Webhooks.** Die aus der Vorlage installierte Automation hängt am Auslöser
  `webhook_manager.outbound_failed` und muss stehen, bevor der tote Empfänger feuert.
- **Identität nach den Leuten.** Die Akteure der Aktivitäten sind die dortigen Kontakte.

### Was der Aufbau erzeugt

62 der 70 Tabellen tragen Daten. Die acht leeren sind es aus einem Grund: `marketing_*` fährt den
flat-Treiber (die Listen liegen als YAML unter `content/marketing/`, das ist Laufzeitstand und
gehört deshalb nicht ins Repo), `users` ist bei Statamic ein Datei-Store, und vier Tabellen sind
Einstellungs- und Abmeldespeicher, die erst beim Benutzen entstehen.

Genau ein Fehler steht danach im Log, und der ist gewollt: der Webhook-Ausgang auf `127.0.0.1:9`
scheitert absichtlich, damit Wiederholungsplan, Fehlerklassifizierung und Sicherung etwas zu tun
bekommen.

## Mollie

Der Playground nimmt einen **Testschlüssel** aus `MOLLIE_KEY` und fährt dann gegen Mollies echtes
Testkonto. Ohne Schlüssel fällt er auf `App\Support\PlaygroundGateway` zurück. Ein **Live-Schlüssel
wird abgelehnt**: eine Demo, die behauptet echt bezahlt zu haben, während sie eine Attrappe fragt,
ist schlimmer als eine, die zugibt keinen Schlüssel zu haben.

Mollie prüft, ob die Webhook-Adresse von **seiner** Seite erreichbar ist, und lehnt `localhost` mit
422 ab. Der Playground setzt deshalb `webhook_url => false` und **holt** den Zustand statt ihn
geschoben zu bekommen:

```bash
php artisan demo:poll        # alles, was noch offen ist
php artisan demo:poll 49     # eine bestimmte Zahlung
```

Das ist dieselbe Methode, die auch die Webhook-Route ruft. Für den Betrieb ist es **falsch**: wer
den Tab schließt, wird von einem Webhook nachverfolgt und von nichts anderem.

Testkarte: `4242 4242 4242 4242`, ein Ablaufdatum in der Zukunft, drei beliebige Ziffern als CVC.

## Was absichtlich kaputt ist

Nicht anfassen, das ist der Punkt:

- **Angebote**, die auf ein Produkt zeigen, das es nicht gibt oder das der Katalog ablehnt
- **Produkte** mit negativem Preis, einem Preis als Text, ohne Preis, und eines mit einem Punkt im
  Handle
- Eine **Produktzeile, die die Konfiguration überschatten will** (`cw-kurs`, 259,00 statt 249,00:
  die Konfiguration gewinnt, und der Bildschirm sagt das), und ein **Produktzeiger auf ein Konzert,
  das nie angelegt wurde** (`sz-phantom`: markiert und über der Tabelle gezählt)
- **Gutscheine**, die abgelaufen, noch nicht gestartet, aufgebraucht oder auf 500 % getippt sind
- Ein **Abo**, das der Anbieter nie bestätigt hat, und eines, das er gesperrt hat
- Der Funnel **`sackgassen`**: eine Schleife, eine Waise, eine Sackgasse, ein abgeschalteter Schritt
  mittendrin, ein Slug der wie ein Addon-Pfad aussieht, und eine Beschriftung über 200 Zeichen
- Der Funnel **`vinyl`** hat eine Frist, die schon vorbei ist
- Ein Beitrag ohne Marke, einer mit Datum in der Zukunft, einer aus 1999, ein Entwurf
- Ein **Webhook-Ausgang auf `127.0.0.1:9`**, der nie antwortet: er ist der einzige Fehler im Log
  nach einem Aufbau, und er soll da sein. Ohne ihn hätten Wiederholungsplan,
  Fehlerklassifizierung und Sicherung nichts zu tun
- Ein **Termin in der Nacht der Zeitumstellung** (28.03.2027, 02:30 — die Stunde gibt es nicht) und
  einer in einer anderen Zeitzone als sein Ereignis
- Ein **Konto ohne jede Rolle** und eines, dessen Name `&`, `<` und Anführungszeichen trägt
- Eine **Mailvorlage mit einem Platzhalter, den niemand füllt**: unbekannte Tags bleiben stehen,
  statt still zu verschwinden

## Der Aufbau im Code

```
app/Demo/DemoData.php            Die unangenehmen Werte, an einer Stelle
app/Demo/SeedsBrands.php         Fünf Marken
app/Demo/SeedsCommerce.php       Katalog, Angebote, Gutscheine, Zahlungen, Abos
app/Demo/SeedsProducts.php       Elf Produktdaten, alle sechs Arten, zwei absichtlich krumm
app/Demo/SeedsTeam.php           Fünf Konten, drei Rollen, vier Markenzuordnungen
app/Demo/SeedsCrm.php            Kontakte, Firmen, Aufgaben, Pipeline, Tags, Segmente, Sperren,
                                 Freebies, Zugänge, Meldungen, Spuren
app/Demo/SeedsFunnels.php        Vier Wege, einer davon absichtlich krumm
app/Demo/SeedsEvents.php         Tour, Kurs, Sprechstunde, samt abgesagtem Termin
app/Demo/SeedsEmailTemplates.php Vier Vorlagen, eine mit unbekanntem Platzhalter
app/Demo/SeedsIdentity.php       Wer was getan hat: Kontakt, Anonym, CP-Konto, System
app/Demo/SeedsAutomations.php    Sechs Rezepte, zehn der elf Logik-Knoten, ein roter Lauf
app/Demo/SeedsWebhooks.php       Sechs Eingänge (je ein Prüfverfahren), ein toter Empfänger
app/Demo/SeedsProof.php          Termine über den Cal.com-Weg, Einwilligungen über den echten Keks
app/Demo/SeedsCampaign.php       Listen als YAML, eine Kampagne, ein echter Sendelauf
app/Console/Commands/DemoSeed.php
app/Console/Commands/DemoPoll.php
```

**Alles geht durch die öffentlichen Fassaden der Addons, nie direkt aufs Modell.** Das ist die
wichtigste Regel hier, und sie ist teuer gelernt: der frühere Seeder schrieb mit
`Model::withoutGlobalScopes()->updateOrCreate()`. Dabei entstanden Werte, die die Addons gar nicht
kennen (ein Abonnentenstatus `confirmed`, den es nicht gibt — also zeigte jedes Marketing-Dashboard
null), falsche Skopierungen (ein hartes Bounce auf einer Marke statt global, also war ein totes
Postfach anderswo mailbar) und halbe Aggregate (Freigaben ohne Berechtigung). Vor allem aber feuerte
kein einziges Domain-Ereignis, weshalb `leadhub_events`, `suppression_events` und
`lead_magnet_downloads` alle auf null standen. Ein Seeder, der die Zeile selbst schreibt, belegt
nur, dass die Tabelle Spalten hat.

Der Katalog wird in `config/statamic-payments.php` geschrieben, nicht in eine Tabelle: ein Produkt
ist Konfiguration, und ein Zahlungs-Addon, das Preise mitliefert, wäre falsch. Seit
`statamic-products` liegt der Katalog **zweimal** da — Konfiguration als der alte Wohnsitz,
Tabelle als der neue —, und eine Zeile existiert absichtlich in beiden. Die Konfiguration gewinnt,
und genau das zeigt der Bildschirm.

## Sprache

`APP_LOCALE=de`. Die Addons liefern deutsche Sprachdateien mit, und ein Demo, in dem das Banner
englisch und der Inhalt deutsch ist, sieht nach Halbfertigem aus statt nach einer Entscheidung. Für
Screenshots im README eines Addons vorher auf `en` stellen.

## Wie die Marken aussehen

Jede Marke bringt ihr eigenes Stylesheet in `public/marken/` mit und setzt darin auch die
`--csnt-*`-Tokens des Einwilligungsbanners. Das ist der Punkt: dieselbe Mechanik, drei Handschriften.
Chorwerkstatt petrol mit fast scharfen Ecken, Halbmond signalrot und rechtwinklig, Lindhorst salbei
mit Pillen und Satzschrift. Wenn ein Banner in allen drei Marken gleich aussieht, ist ein Token
verloren gegangen.

## Marken umschalten

Oben rechts im Control Panel. Das Dashboard begrüßt einen mit einem Wegweiser, der die drei Kunden
mit ihren echten Zahlen zeigt und direkt dorthin springt — ohne ihn ist das Erste nach dem Anmelden
ein leeres Dashboard auf einer Installation voller Daten.

**Was dabei auffällt und eine Entscheidung ist, keine Nebensache:** Kontakte, Termine, Abonnenten,
Zugänge, Meldungen, Spuren und **Produktdaten** sind markengetrennt — eine Agentur mit drei Marken
bekommt drei Kataloge. **Zahlungen, Angebote und Abos sind es nicht** —
der ganze Handelsteil liegt in einem Topf, jeder mit Zahlungsrecht sieht alle Kunden. Fast alles hängt an einer Marke: Kontakte, Abonnenten, Sperren,
Zugänge, Meldungen, Spuren, Webhooks. Auf der Agenturmarke ist deshalb wenig zu sehen, und das ist
richtig so.
