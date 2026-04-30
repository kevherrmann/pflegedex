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

Dann öffnen:

```text
http://localhost:8080
```

In einem zweiten Terminal, falls Migrationen nötig sind:

```bash
docker compose exec app php artisan migrate
```

Frontend läuft im `node` Service über Vite. Der Vite-Port ist standardmäßig:

```text
http://localhost:5173
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
