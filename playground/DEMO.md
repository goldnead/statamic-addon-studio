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

```bash
composer install
php artisan migrate --force

# Ohne das ist die Anmeldung ein 500er: ein Addon, dessen gebaute Dateien nicht
# veröffentlicht sind, nimmt das ganze Control Panel mit.
php artisan vendor:publish --provider="Goldnead\BrandContext\ServiceProvider" --force
# ...und so für jedes Addon; die Liste steht unten.

php artisan demo:seed
```

`demo:seed` ist **wiederholbar**. Der zweite Lauf ist der, der die Fehler findet: er stößt auf
alles, was sich nicht zweimal schreiben lässt, und genau das hat beim Bauen dieses Demos drei
richtige Entscheidungen anderer Addons sichtbar gemacht (der Aktivitätslog lässt sich nicht ändern,
die Statusspalte eines Zugangs ist vor Massenzuweisung geschützt, ein Schritt-Slug ist je Funnel
eindeutig).

`demo:seed --fresh` räumt die Handelsdaten vorher weg.

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
- **Gutscheine**, die abgelaufen, noch nicht gestartet, aufgebraucht oder auf 500 % getippt sind
- Ein **Abo**, das der Anbieter nie bestätigt hat, und eines, das er gesperrt hat
- Der Funnel **`sackgassen`**: eine Schleife, eine Waise, eine Sackgasse, ein abgeschalteter Schritt
  mittendrin, ein Slug der wie ein Addon-Pfad aussieht, und eine Beschriftung über 200 Zeichen
- Der Funnel **`vinyl`** hat eine Frist, die schon vorbei ist
- Ein Beitrag ohne Marke, einer mit Datum in der Zukunft, einer aus 1999, ein Entwurf

## Der Aufbau im Code

```
app/Demo/DemoData.php       Die unangenehmen Werte, an einer Stelle
app/Demo/SeedsBrands.php    Fünf Marken
app/Demo/SeedsCommerce.php  Katalog, Angebote, Gutscheine, Zahlungen, Abos
app/Demo/SeedsCrm.php       Kontakte, Listen, Sperren, Freebies, Zugänge, Meldungen, Spuren
app/Demo/SeedsFunnels.php   Vier Wege, einer davon absichtlich krumm
app/Console/Commands/DemoSeed.php
app/Console/Commands/DemoPoll.php
```

Der Katalog wird in `config/statamic-payments.php` geschrieben, nicht in eine Tabelle: ein Produkt
ist Konfiguration, und ein Zahlungs-Addon, das Preise mitliefert, wäre falsch.

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

Oben rechts im Control Panel. Fast alles hängt an einer Marke: Kontakte, Abonnenten, Sperren,
Zugänge, Meldungen, Spuren, Webhooks. Auf der Agenturmarke ist deshalb wenig zu sehen, und das ist
richtig so.
