# Demo-Deploy: demo.adriangoldner.dev

Der Playground läuft öffentlich auf **demo.adriangoldner.dev** (Komplett offen,
Login steht in `adg-docs/index.md`). Betrieben wird er auf dem Hetzner-Server
unter `/opt/statamic-demo/`: Docker-Container `statamic-demo` hinter dem
gemeinsamen Caddy, Port lokal `127.0.0.1:8099`.

## Bauen und ausrollen

```bash
./deploy/build.sh                      # baut /tmp/statamic-demo-build
rsync -a /tmp/statamic-demo-build/ root@157.90.224.18:/opt/statamic-demo/app/
rsync -a --delete /tmp/statamic-demo-build/vendor/goldnead/ root@157.90.224.18:/opt/statamic-demo/app/vendor/goldnead/

# im Container (auf dem Server):
docker exec -w /var/www/html statamic-demo composer dump-autoload
docker exec -w /var/www/html statamic-demo php artisan package:discover
docker exec -w /var/www/html statamic-demo php artisan migrate --force
docker exec -w /var/www/html statamic-demo php artisan demo:seed --fresh
```

Der rsync läuft als root und setzt Besitzrechte auf root zurück — ohne diesen
Schritt schreibt die Anwendung keine Session (500 auf jeder CP-Seite) und
spätestens beim Login keine Zeile in die Datenbank:

```bash
ssh root@157.90.224.18 "cd /opt/statamic-demo/app && chown -R 33:33 content users database config storage bootstrap/cache"
```

Danach `pristine.tar.gz` neu ziehen, sonst stellt der nächtliche Reset den
alten Stand wieder her. Das Tar muss bei gestopptem Container laufen, sonst
greift es mitten in einen SQLite-Schreibvorgang:

```bash
cd /opt/statamic-demo
docker compose stop app
tar czf pristine.tar.gz -C app content users database config storage
docker compose up -d app
```

## Was der Server sonst hält

- `.env` — liegt nur dort (APP_KEY, DEMO-Werte). Nicht neu bauen.
- `Dockerfile`, `docker-compose.yml`, `reset.sh` — direkt unter `/opt/statamic-demo/`.
- Reset: `/etc/cron.d/statamic-demo-reset`, täglich 03:17 UTC, Log in
  `/var/log/statamic-demo-reset.log`.
- Caddy-Block `demo.adriangoldner.dev` mit `tls internal` in
  `/root/n8n-docker-caddy/caddy_config/Caddyfile` — ändern nur mit
  `cat neu > datei` (kein `sed -i`, das reißt das Bind-Mount).

## Grundsatz

Auf den Server geht nur: committeter Playground-Stand + Release-Tags der
Addons (`tags.conf`). Eine Session, die gerade an einem Addon baut, geht
nicht mit raus — auch nicht als Mitbringsel im vendor.
