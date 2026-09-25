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

86 der 105 Tabellen tragen Daten (Stand 16.09.2026, nach `demo:seed --fresh`). Die neunzehn
leeren sind es aus einem Grund:

- `marketing_campaigns`, `marketing_templates` — der flat-Treiber, die Inhalte liegen als YAML
  unter `content/marketing/` und sind dort **nicht** leer.
- `marketing_message_events`, `funnel_mail_deliveries`, `leadhub_sync_logs`,
  `payment_webhook_events`, `payment_chargebacks` — Laufzeitspuren. Sie entstehen, wenn wirklich
  gesendet, geklickt, synchronisiert oder zurückgebucht wird, und nicht vorher.
- `invoice_vat_id_checks` — keine Rechnung wartet auf eine Prüfung. Der Leerzustand sagt genau das.
- `automation_settings`, `automation_opt_outs`, `leadhub_settings`, `webhook_settings` —
  Einstellungs- und Abmeldespeicher, die erst beim Benutzen entstehen.
- `users` (Statamic ist dort ein Datei-Store), `sessions`, `cache_locks`, `jobs`, `job_batches`,
  `failed_jobs`, `password_reset_tokens` — Laravel und Statamic selbst.

**Diese Zählung gehört auf ein frisch geseedetes Demo, nicht auf die eigene Arbeitskopie.** Die
lokale SQLite sammelt über viele Läufe Zeilen an, die kein Seeder mehr schreibt, und sieht
deshalb voller aus als jeder Neuaufbau. Am 16.09.2026 standen lokal Umsatzzeilen, Widerrufe und
Kündigungen, die auf der öffentlichen Demo nicht existierten. Wer wissen will, was ein Besucher
sieht, zählt dort: `ssh … docker exec -u www-data … statamic-demo php -r '…'`.

Genau ein Fehler steht danach im Log, und der ist gewollt: der Webhook-Ausgang auf `127.0.0.1:9`
scheitert absichtlich, damit Wiederholungsplan, Fehlerklassifizierung und Sicherung etwas zu tun
bekommen.

### Die Menge, und warum sie eine Ausnahme ist

Jeder Seeder hier schreibt Zustände: je eine Zeile pro Fall, den ein Bildschirm rendern können
muss. `SeedsInsights` ist der einzige, der stattdessen **Menge** schreibt — rund dreihundert
Zahlungen über achtzehn Monate, mit Wachstum, Sommerloch, Dezemberspitze, Herkünften, zwei
Währungen, Erstattungen, wiedergeholten Warenkörben und Bumps.

Der Grund ist `statamic-insights`. Ein Reporting-Addon beantwortet aus elf Zahlungen in drei
Monaten keine einzige Frage, die jemand an ein Umsatzdiagramm stellt; ein leerer Verlauf sieht
nicht nach wenig Umsatz aus, sondern nach einem kaputten Addon. Die Daten sind deshalb
absichtlich **unauffällig** — die hässlichen Einzelfälle stehen weiter in `SeedsCommerce` und
bleiben dort sichtbar, weil dreihundert Sonderfälle kein Hinweis mehr wären, sondern Rauschen.

Drei Dinge hängen daran und sind beim Bauen aufgefallen:

- **Reihenfolge.** `SeedsInsights` läuft **nach** `SeedsInvoices`. Der Rechnungs-Seeder schreibt
  jeder bezahlten Zahlung eine Rechnung, und dreihundert Demo-Rechnungen je Lauf wären eine
  fortlaufende Nummernreihe, die niemand bestellt hat. Beide Stellen, die über alle Zahlungen
  laufen (`SeedsInvoices`, `SeedsAutomations`), klammern die Menge über
  `provider_id not like 'demo_ins_%'` aus.
- **Die Agentur verkauft jetzt selbst.** Nordlicht Studio ist die Vorgabemarke: wer sich anmeldet,
  landet dort. Ohne eigene Produkte wäre der erste Bildschirm des Schauraums eine leere
  Umsatzansicht. Deshalb `studio-website` und `studio-betreuung` im Katalog.
- **Ein Produkt je Bestellung.** `payment_items` ist eindeutig auf `(payment_id, product)` —
  dieselben Noten als Bump und als Upsell sind kein zweiter Posten, sondern ein Abbruch.

### Suite-Runde vom 23.09.2026 (ThriveCart-Rundgang)

Vier eigene Seeder, alle nach der Menge und nach den Rechnungen, alle wiederholbar. Zahlungen
schreiben sie per `DB::table()` **ohne `PaymentPaid`**; `SeedsInvoices` und `SeedsAutomations`
klammern sie über `DemoData::MENGEN_PRAEFIXE` aus (`demo_ins_`, `demo_abo_`, `demo_seats_`,
`tr_affdemo_`). `--fresh` wischt zusätzlich `affiliate_commissions`, `affiliate_payouts`,
`affiliate_referrals`, `offer_seats`, `offer_seat_pools`.

| Seeder | Addon | Was entsteht (Marke Chorwerkstatt) |
|---|---|---|
| `SeedsAbos` | insights I1 | 57 Abos, rund 287 bezahlte Zyklen über 18 Monate: Mitgliedschaften mit Kündigungen, Preis rauf (Expansion) und runter (Kontraktion), Jahrespässe, Franken, zwei pausiert (mit `paused_at`, falls payments P1 migriert ist), zwei mit Pausen-Historie, ein ausgesetztes, zwei Testphasen, drei Ratenpläne |
| `SeedsInvoiceExports` | invoices R1, R2 | Fünf eingefrorene Belege `NL2026-08-901` bis `-905` im August 2026: Inland 19 % und 7 %, OSS AT 20 %, OSS FI 25,5 %, Reverse Charge FR, eine Gutschrift. Nummern außerhalb des Zählers |
| `SeedsOffers` | offers O1–O7 | `cw-workshop-spende` (Zahl, was du willst, ab 10 €, nur DE/AT/CH, Kurzlink `/go/workshop-herbst`, schaltet in zehn Tagen auf `/warteliste`), `cw-mitglied-monatlich` (Aufnahmegebühr 49 €), Gutschein `CHOR20` (erste drei Zahlungen, nur Hauptprodukt, funnelweit, Link + QR), `cw-stimmgruppe` (10 Plätze) mit einem gekauften Kontingent: Sofie hat angenommen, Alex ist eingeladen. Seiten `/workshop` und `/warteliste` |
| `SeedsAffiliates` | affiliates X1–X3 | Fünf Partner (aktiv, aktiv mit 40 %, beantragt, Verband, gesperrt), Sätze auf `offer:cw-stimmgruppe` (25 %), `cw-notenpaket` (Bump 10 %), `cw-mitgliedschaft` (5 € fest, 6 Folgezahlungen), JV-Vertrag mit Jonas (30 %, einen Monat alt, noch fünf), 49 Klicks, sieben Verkäufe (Link, Bump, Gutschein `CLARA10`, JV, Festbetrag, einer ohne Partner), sieben Provisionen, eine bezahlte Auszahlung über 287,65 €. Seite `/chorwerkstatt/partner` |

`SeedsCourses` trägt seit derselben Runde K1 bis K6 im Kurs `einsingen-leiten`: Lektion
`warum-einsingen` als Baukasten (Text, Hinweis, Spalten, Download, FAQ, Knopf), Drip am 15. des
Monats, Woche 3 nur für die Gruppe `team` und den Tag „Chorleitung", Zahlungsausfall pausiert den
Drip (Mo Lindqvist ist so pausiert), fünf Teamplätze, Paket `chorleitung-paket` (die Käuferin
`kasse@chor.beispiel` hat es gekauft und eine Chorleiterin im Team). Die Quiz-Lektion
`quiz-fundament` hängt am Fragebogen `cw-stimm-check` (bestanden ab 13 Punkten), Bärbel ist mit
9 Punkten durchgefallen. `courses:install` läuft mit `--merge`, damit die neuen Felder in
bestehende Blueprints kommen. Lektionen rendern über `course_lesson_demo`.

**Rundgang** (CP-Seiten mit `?brand=chorwerkstatt`):

- Abo-Kennzahlen: `/cp/insights/subscriptions`, Berichte unter
  `/cp/insights/reports/payments.mrr_movements` (und `subscription_cohorts`, `upcoming_charges`,
  `subscription_forecast`). Nach einem Deploy `cache:clear`, Statamic cached die Nav-Adressen.
- Steuerbericht: `/cp/utilities/invoice-exports?brand=chorwerkstatt&month=2026-08`
- Angebote und Plätze: `/cp/utilities/offers` → „Workshop für die Stimmgruppe" → „Verkaufte
  Plätze"; Gutscheine `/cp/utilities/coupons`; Kurzlink `/go/workshop-herbst?coupon=CHOR20`.
  Verwaltungs- und Annahme-Link stehen nur im Mail-Log (Tokens sind zufällig, die Demo ist offen).
- Partner: `/cp/affiliates/partners`, `…/commissions`, `…/payouts` (CSV), `…/jv`, `…/rates`.
  Partnerbereich `/chorwerkstatt/partner`, angemeldet als `clara.brandt@partner.beispiel`.
- Kurs: `/courses/einsingen-leiten/warum-einsingen` als `kasse@chor.beispiel`,
  `/courses/stimme-grundlagen/quiz-fundament` als `baerbel@kurs.beispiel`.

Frontend-Konten haben alle `demo-local-password`; die Seiten zeigen ein Anmeldeformular
(`partials/anmelden`). Kurzlinks und QR-Codes zeigen auf `https://demo.adriangoldner.dev`
(`config/statamic-offers.php`, lokal per `OFFERS_LINKS_BASE_URL` umstellbar). Der Einwilligungsdienst
`affiliates` steht in `config/statamic-consent.php`; ohne ihn setzt das Partnerprogramm nie einen
Keks.

#### Zweite Runde: payments, funnels, automations

| Seeder | Addon | Was entsteht |
|---|---|---|
| `SeedsAbos` (erweitert) | payments P1–P3, O6 | `abo48` pausiert im Kundenkonto bis in fünf Wochen, mit einer früheren Pause im Verlauf; `abo49` im CP pausiert ohne Termin; `abo38` von Plus auf Mitgliedschaft gewechselt (`meta.switches`), Karte läuft in sechs Wochen ab; `abo58` mit `CHOR20` auf den ersten drei Zyklen (zwei bezahlt zu 15,20 €). Die zwei „zurück aus der Pause" zeigen fortgesetzte Abos |
| `SeedsCommerce` (Katalog) | payments P1, P2, P4, P9 | `cw-mitgliedschaft` pausierbar, wechselbar auf `cw-mitgliedschaft-plus` und `cw-jahrespass` (beide neu im Katalog); der Jahrespass ersetzt eine laufende Mitgliedschaft; die Ausbildung in Raten ist im Kundenkonto nicht selbst kündbar. Zahlungen tragen jetzt ihre Marke |
| `SeedsKundenkonto` | payments P7, P9 | Markeneinstellungen Chorwerkstatt: Begrüßung, Pausieren und Wechseln erlaubt, Sperrliste `wegwerf.example` |
| `SeedsSuiteKasse` | funnels F1–F7 | Funnel `suite-kasse`: Angebote `sk-workshop` (zwei Zahlweisen, DE/AT/CH), Bumps `sk-noten` und `sk-aufnahme` mit Regeln je Zahlweise, `sk-spende` (Betragsfeld), A/B-Test auf Kauf mit 200 Besuchen, drei bis zehn Tage alt (B gewinnt 42:10; funnels entscheidet erst ab 100 Besuchen je Fassung, deren letzter einen Tag alt ist), `SUITE10`, In-App-Hinweis, Tracking-Schnipsel, Pixel-ID als Platzhalter. Seite `/einbetten-beispiel` |
| `SeedsSuiteAutomations` | automations A1, A2 | Zehn Abläufe unter Chorwerkstatt, je ein Auslöser und ein Log-Knoten (keine Mail), alle `sync`. Drei echte Durchläufe mit gesetzter Marke: Abo pausiert, Kurs eingeschrieben, Upsell abgelehnt |

Der Einwilligungsdienst `meta_pixel` steht in `config/statamic-consent.php`. `FUNNELS_META_CAPI_TOKEN`
ist auf der Demo **nicht** gesetzt; es geht nichts an Meta. Captcha und Warenkorb-Abbruch-Mails
bleiben aus (echte Schlüssel, echte Mails).

**Neue Vorlagen der Addons.** `resources/views/vendor/` ist ignoriert, die veröffentlichten
Vorlagen kommen nicht mit dem Build. `deploy/rollout.sh` schreibt die von payments (Kundenkonto)
und funnels (Kasse) deshalb bei jedem Ausrollen mit `--force` neu. Lokal einmal:

```bash
php artisan vendor:publish --tag=statamic-payments-views --force
php artisan vendor:publish --tag=statamic-funnels-views --force
php artisan vendor:publish --tag=statamic-funnels --force   # embed.js, funnels.css
```

**Rundgang, zweite Runde:**

- Abos: `/cp/utilities/subscriptions?brand=chorwerkstatt`, suchen nach `abo48`, `abo38`, `abo58`.
- Kundenkonto: `/!/statamic-payments/konto/anmelden`, Adresse `abo48@beispiel.de`; der
  Anmeldelink steht im Mail-Log. Dort „Pausiert bis …" mit „Fortsetzen". Mit `abo38@beispiel.de`
  der Wechsel.
- Kasse: `/f/suite-kasse/kasse?coupon=SUITE10` (Fassung B, Gutschein vorbelegt, Land, „Für die
  Stimmgruppe" kreuzt die Übe-Aufnahmen an), `/f/suite-kasse/aufzeichnung` (Betragsfeld). Mit
  einem Instagram- oder Facebook-Browser erscheint der Hinweis, im normalen Browser zu öffnen.
- CP: `/cp/utilities/funnels` → „Suite: Kasse mit Regeln" (A/B, Bump-Regeln, Einstellungen).
- Abläufe: `/cp/automations`, Durchläufe unter `/cp/automations/runs`.

**Webhook-Brücken (24.09.2026).** `SeedsSuiteWebhooks` legt in Chorwerkstatt drei ausgehende
Webhooks auf Suite-Momente an: `payments.subscription_paused`, `offers.seat_accepted`,
`courses.learner_enrolled`. Ziel ist `https://example.invalid/hook`; `.invalid` löst nie auf,
jede Zustellung scheitert sofort und steht als Zeile mit Fehler in der Lieferliste. Die
Zustellungen entstehen im Seed-Lauf durch die echten Ereignisse (Plätze von Sofie und Tom,
pausiertes Abo, Einschreibung). Dazu der Ablauf `platz-angenommen` in automations. Rundgang:
Webhooks → Ausgehend → „Platz angenommen an die Teamliste" (gruppierte Auslöser, Payload-Vorschau),
Lieferungen.

Veröffentlichte Sprachdateien von webhook-manager (`resources/lang/vendor/webhook-manager`,
`lang/vendor/webhook-manager`, beide ignoriert) überdecken die Labels des Pakets. Nicht
veröffentlichen; das Addon braucht sie nicht.

**Einbetten von einer eigenen Seite aus (F4).** Die Demo kann keine fremde Herkunft stellen.
`/einbetten-beispiel` zeigt den Schnipsel und führt ihn auf derselben Herkunft einmal vor. So
geht es von außen:

1. Im CP unter Funnels → „Suite: Kasse mit Regeln" → Einstellungen die Herkunft der eigenen Seite
   unter „Einbetten" eintragen, z. B. `http://localhost:8000` (Schema, Host, Port, ohne Pfad).
2. Eine HTML-Datei mit dem Schnipsel von `/einbetten-beispiel` anlegen und von genau dieser
   Herkunft ausliefern (`python3 -m http.server 8000`), nicht als `file://`.
3. Popup und eingebettete Kasse laden. Ohne Eintrag verweigert der Browser den Rahmen
   (`frame-ancestors` in der Antwort der Kasse), das Popup öffnet sich dann als neues Fenster.

Der nächtliche Reset nimmt den Eintrag wieder heraus.

### Postfach (statamic-inbox, 25.09.2026)

`SeedsInbox` legt unter `nordlicht` das Postfach „Studio" (`hallo@nordlicht.beispiel`) mit fünf
Gesprächen an: ein Verlauf mit Henrike Albers (LeadHub-Kontakt, drei Mails, die letzte mit Zitat
und ungelesen), ein HTML-Newsletter mit blockierten entfernten Bildern, eine Anfrage ohne Kontakt,
eine Rechnungsfrage im Status „wartet", ein erledigter Dank. Die Mails laufen durch den echten
Abrufer des Addons, aber aus einem Server im Speicher. CP: `/cp/inbox`.

**Die Demo erreicht nie einen Mailserver.** Drei Sicherungen, jede reicht allein:

1. `AppServiceProvider` bindet `MailboxClientFactory` an `App\Demo\Postfach\DemoImapServerFactory`
   (Speicher, `inbox:fetch` findet nichts, der Verbindungstest gelingt ohne Verbindung) und
   `TransportFactory` an `DemoSmtp` (Antworten gehen ins Systemlog). Wichtig: das Addon sendet
   über den SMTP des Postfachs, nicht über den Mailer der Site. `MAIL_MAILER=log` allein griffe
   hier nicht.
2. `PostfaecherSchreibgeschuetzt` beantwortet Anlegen, Ändern und Verbindungstest eines Postfachs
   mit 403. Das Demo-Konto ist Superuser, ein entzogenes Recht griffe dort nicht. Ansehen geht.
3. Die Hosts des Postfachs liegen unter `.invalid`.

Die Rolle `agentur` hat `view inbox` und `reply inbox`, nicht `manage inbox mailboxes`. Einen
Scheduler hat die Demo nicht; `inbox:fetch` liefe ohnehin gegen den leeren Speicher-Server.

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
app/Demo/SeedsAbos.php           57 Abos mit Verlauf für die Abo-Kennzahlen
app/Demo/SeedsInvoiceExports.php Ein Steuermonat für den Rechnungsexport
app/Demo/SeedsOffers.php         Spendenpreis, Aufnahmegebühr, Kurzlink, Plätze für Gruppen
app/Demo/SeedsAffiliates.php     Partnerprogramm: Partner, Sätze, JV, Provisionen, Auszahlung
app/Demo/SeedsKundenkonto.php    Markeneinstellungen für Kundenkonto und Kassenschutz
app/Demo/SeedsSuiteKasse.php     Funnel suite-kasse: A/B, Bump-Regeln, Einbetten, Tracking
app/Demo/SeedsSuiteAutomations.php Zehn Abläufe auf die neuen Auslöser, drei Durchläufe
app/Demo/DemoSeiten.php          Seite anlegen und in den Baum hängen
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
