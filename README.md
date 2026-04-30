# Pflegedex

Pflegedex ist das MVP einer lokalen, on-premise Pflegedokumentations-App für ein einzelnes Pflegeheim.

## Phase 0 Stand

Dieses Repository enthält aktuell:

- Laravel 11 Backend
- Inertia.js + React 18 + TypeScript Frontend
- Tailwind CSS
- Laravel Breeze Auth-Grundlage
- Pest Test Setup
- Docker Compose für lokale Entwicklung
- PostgreSQL 16
- Redis für Cache, Sessions und Queues
- Caddy als lokaler Webserver
- vorbereitete Ollama-Anbindung per `OLLAMA_URL`
- Sander-Pflege-inspiriertes Basisdesign mit Bordeaux `#9B1C3B`

## Lokaler Start mit Docker

Voraussetzungen auf deiner Maschine:

- Docker / Podman-kompatibles Docker Compose
- optional: Ollama lokal auf dem Host, falls KI-Funktionen getestet werden sollen

Start:

```bash
cp .env.example .env
docker compose up --build
```

Falls PostgreSQL beim allerersten Start mit `Operation not permitted` auf dem Datenverzeichnis abbricht, hole die aktuelle Compose-Konfiguration und entferne den leeren, fehlerhaft initialisierten Entwicklungs-Volume einmalig:

```bash
git pull
docker compose down -v
docker compose up --build
```

Das Compose-Setup nutzt für PostgreSQL im lokalen Entwicklungsmodus bewusst ein `tmpfs`-Datenverzeichnis. Das umgeht auf Nobara/Fedora/rootless/SELinux-nahen Setups die beobachteten `chmod: Operation not permitted`-Fehler beim Initialisieren des offiziellen PostgreSQL-Images.

Wichtig: Die lokale Entwicklungsdatenbank ist damit nicht dauerhaft persistent. Nach `docker compose down` oder Container-Neuanlage müssen die Migrationen erneut laufen. Für Phase 0 ist das gewollt, damit der lokale Start zuverlässig funktioniert. Persistente PostgreSQL-Volumes härten wir später gezielt passend zu deinem Docker/Podman-Setup.

Dann öffnen:

```text
http://localhost:8080
```

In einem zweiten Terminal, falls du Migrationen manuell neu ausführen möchtest:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Im lokalen Docker-Setup führt der `app`-Container Migrationen und Seeder standardmäßig automatisch aus (`PFLEGEDEX_AUTO_MIGRATE=true`). Dadurch ist die tmpfs-Entwicklungsdatenbank nach einem frischen Containerstart direkt nutzbar.

Wenn ein Composer-Download in `vendor/composer/tmp-...zip` durch einen abgebrochenen Containerstart beschädigt wurde, entferne einmalig den lokalen Vendor-Ordner und baue neu:

```bash
rm -rf vendor
docker compose down
docker compose up --build
```

Der Docker-Entrypoint schützt Composer-Installationen mit einem Lock, damit `app` und `queue` nicht gleichzeitig in denselben gemounteten `vendor/`-Ordner schreiben.

Frontend läuft im `node` Service über Vite. Der Vite-Port ist standardmäßig:

```text
http://localhost:5173
```

Wenn der Browser eine weiße Seite zeigt und in der Konsole Assets von `http://0.0.0.0:5173/...` blockiert werden, setze in deiner lokalen `.env`:

```env
VITE_HMR_HOST=localhost
VITE_PORT=5173
```

Danach den Node-Service neu starten:

```bash
docker compose up -d node
```

## Lokale Konfiguration

Wichtige Variablen aus `.env.example`:

```env
APP_URL=http://localhost:8080
INTRANET_DOMAIN=localhost
DB_HOST=postgres
REDIS_HOST=redis
OLLAMA_URL=http://host.docker.internal:11434
AI_MODEL=gemma4:e2b-q4_K_M
```

`host.docker.internal` wird im Compose-Setup per `host-gateway` gesetzt. Dadurch kann Laravel im Container einen Ollama-Prozess erreichen, der direkt auf deinem Nobara-Linux-Host läuft.

## Späterer Heimserver

Für den Heimserver sollen Code und Container gleich bleiben. Geändert wird nur die Konfiguration, zum Beispiel:

```env
APP_URL=https://pflegedex.sander.local
INTRANET_DOMAIN=pflegedex.sander.local
OLLAMA_URL=http://ollama-server.heim.local:11434
```

## Entwicklung ohne Docker

Falls PHP/Composer/Node lokal installiert sind:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

## Tests und Checks

```bash
php artisan test
npm run build
```

## Datenschutz-Leitplanken für kommende Phasen

- Pflegedaten verlassen nicht den Heimserver.
- KI-Ausgaben sind immer nur Entwürfe.
- Signierte Pflegeberichte werden nicht überschrieben.
- Spätere Berichtsversionen werden append-only nachvollziehbar gespeichert.
- LLM-Eingaben werden vor Verarbeitung pseudonymisiert.
