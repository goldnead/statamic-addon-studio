# Demo-Deploy: demo.adriangoldner.dev

Der Playground läuft öffentlich auf **demo.adriangoldner.dev** (Komplett offen,
Login steht in `adg-docs/index.md`). Betrieben wird er als Docker-Container
hinter einem gemeinsamen Caddy, auf einem lokal gebundenen Port.

> **Dieses Repo ist öffentlich.** Host, Zielverzeichnis und Portnummer stehen
> deshalb als Platzhalter. Die echten Werte stehen in
> `GoldnerOS/memory/reference-statamic-demo-deploy.md`. Vor dem Ausrollen
> einmal setzen:
>
> ```bash
> HOST=…            # Zielserver
> APPDIR=…          # Zielverzeichnis auf dem Server
> CONTAINER=statamic-demo
> ```

## Bauen und ausrollen

**Vier Schritte, und alle vier gehoeren dazu.** Wer nach dem rsync aufhoert,
hinterlaesst eine Demo auf HTTP 500; wer nach dem chown aufhoert, verliert den
Stand beim naechsten Reset um 03:17 UTC. Beides ist am 05.09.2026 passiert —
die Schritte 3 und 4 wurden ueberlesen, weil sie unter dem Codeblock stehen.

| # | Schritt | Was passiert, wenn er fehlt |
|---|---|---|
| 1 | `build.sh` | — |
| 2 | rsync auf den Server | nichts kommt an |
| 3 | **chown auf 33:33** | HTTP 500 auf jeder Seite (siehe unten) |
| 4 | **`pristine.tar.gz` neu ziehen** | der Reset um 03:17 UTC holt den alten Stand zurueck |

### Schritt 1 und 2 — bauen und uebertragen

```bash
./deploy/build.sh                      # baut /tmp/statamic-demo-build
rsync -a /tmp/statamic-demo-build/ "root@$HOST:$APPDIR/app/"
rsync -a --delete /tmp/statamic-demo-build/vendor/goldnead/ "root@$HOST:$APPDIR/app/vendor/goldnead/"

# im Container (auf dem Server):
# ACHTUNG: kein composer im Container (03.09.2026 geprueft, /usr/local/bin
# haelt nur php). "composer dump-autoload" schlaegt fehl und wird nicht
# gebraucht: die Autoload-Dateien kommen fertig aus dem Build mit, und die
# Addons laden per PSR-4, nicht ueber den Classmap.
docker exec -w /var/www/html statamic-demo php artisan package:discover
docker exec -w /var/www/html statamic-demo php artisan migrate --force
docker exec -w /var/www/html statamic-demo php artisan demo:seed --fresh
```

### Schritt 3 — Besitzrechte zurechtrücken

Der rsync überträgt die Besitzrechte der Bauseite mit, im Container läuft aber
alles als `www-data` (uid 33). Ohne diesen Schritt schreibt die Anwendung keine
Session und keine Zeile in die Datenbank:

```bash
ssh "root@$HOST" "cd $APPDIR/app && chown -R 33:33 content users database config storage bootstrap/cache"
```

**Daran erkennt man einen vergessenen chown** (beides HTTP 500, beides im
`storage/logs/laravel.log` des Containers):

```
tempnam(): file created in the system's temporary directory
  at vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php:111
```
> Laravel kann seinen Zwischenspeicher nicht schreiben und weicht auf `/tmp`
> aus. Trifft **jede** Seite, auch das CP-Login.

```
SQLSTATE[HY000]: General error: 8 attempt to write a readonly database
```
> Nur die Frontseite, weil erst der Stache dort schreibt. Kommt oft **nach**
> dem ersten Fehler zum Vorschein: das CP antwortet dann schon wieder mit 200,
> und man haelt die Sache faelschlich fuer erledigt. Beide Meldungen einzeln
> pruefen, `/` und `/cp/auth/login`.

Die sechs Verzeichnisse oben reichen. Ein `chown -R` auf den ganzen `app`-Ordner
schadet nicht, ist aber breiter als noetig.

### Schritt 4 — `pristine.tar.gz` neu ziehen

Sonst stellt der nächtliche Reset den alten Stand wieder her. Das Tar muss bei
gestopptem Container laufen, sonst greift es mitten in einen
SQLite-Schreibvorgang:

```bash
cd "$APPDIR"
docker compose stop app
tar czf pristine.tar.gz -C app content users database config storage
docker compose up -d app
```

## Was der Server sonst hält

- `.env` — liegt nur dort (APP_KEY, DEMO-Werte). Nicht neu bauen.
- `Dockerfile`, `docker-compose.yml`, `reset.sh` — direkt unter `$APPDIR`.
- Reset: eine `cron.d`-Datei, täglich 03:17 UTC, mit eigenem Log.
- Caddy-Block `demo.adriangoldner.dev` mit `tls internal` in der gemeinsamen
  Caddyfile — ändern nur mit
  `cat neu > datei` (kein `sed -i`, das reißt das Bind-Mount).

## Grundsatz

Auf den Server geht nur: committeter Playground-Stand + Release-Tags der
Addons (`tags.conf`). Eine Session, die gerade an einem Addon baut, geht
nicht mit raus — auch nicht als Mitbringsel im vendor.
