# Lokato — Platform

> Real-time room-occupancy system for after-school childcare facilities.
> RFID scanners at room doors publish over MQTT, Laravel ingests, Vue
> displays live across staff dashboards and per-room tablets.

This repository contains the **server-side platform** (Laravel + Vue).
The matching ESP32 RFID firmware and hardware notes live in
[`lokato-main`](https://github.com/lokato-at/lokato-main).

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3.5-42B883?logo=vuedotjs)](https://vuejs.org)

---

## What this is

Lokato replaces a manual magnet-board with a live digital view of which
child is in which room. Each room door has an RFID scanner; staff and
parents see live updates without reloading the page.

| User | Device | View |
|---|---|---|
| Carer (staff) | Tablet / PC | Dashboard + admin area |
| Parent | Entrance tablet (shared) | Read-only "who's here" overview |
| Per-room | Wall-mounted tablet | Live occupancy for one room |

The platform is designed for **a single facility on a LAN**, not for
multi-tenant SaaS. One Raspberry Pi runs the whole thing.

---

## Architecture (one-paragraph version)

ESP32 + UHF-RFID scanners publish scan events over MQTT (Mosquitto). A
Laravel CLI subscriber (`lokato-mqtt` systemd unit) calls
`ScanIngestService::ingestScan()`, which atomically updates child
location and movement history in MariaDB. A single SSE endpoint
(`/api/stream`) pushes live updates to all open browsers, using a
database-backed cache as a low-cost wake-up signal so the polling loop
stays cheap when nothing is happening.

Detailed diagrams: [`docs/diagrams/`](docs/diagrams/).

---

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Frontend | Vue 3.5, TypeScript, Pinia, Vite 7 |
| Realtime | Server-Sent Events |
| MQTT | Mosquitto 2 |
| Database | MariaDB (prod) / MySQL 8 (dev) |
| Cache / Session / Queue | `database` driver (no Redis required) |
| Reverse Proxy | nginx |
| PHP runtime | php-fpm (FastCGI on 127.0.0.1:9000) |

---

## Quick start (development on Windows or Linux)

```bash
git clone https://github.com/lokato-at/lokato-platform
cd lokato-platform

# Copy env templates
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

# Start backend stack (db + mqtt + nginx + php-fpm + mqtt-subscriber)
docker compose up -d

# Start frontend on host (HMR over bind-mount)
cd frontend && npm install && npm run dev
```

App at `http://localhost`. Mosquitto on `:1883`.

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for the full walk-through.

## Quick start (production on Raspberry Pi)

```bash
git clone https://github.com/lokato-at/lokato-platform /var/www/lokato
cd /var/www/lokato
sudo PI_IP=192.168.1.100 ./start-prod-raspi.sh
```

Pi-native (no Docker): nginx + php-fpm + MariaDB + Mosquitto all from apt.
Idempotent — re-runs are safe.

See [`docs/PRODUCTION.md`](docs/PRODUCTION.md) for the full guide.

---

## Documentation

| File | Topic |
|---|---|
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Windows + Docker Compose dev setup |
| [`docs/PRODUCTION.md`](docs/PRODUCTION.md) | Raspberry Pi native production deploy |
| [`docs/CRON.md`](docs/CRON.md) | Scheduler, log audit, mail alerts |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Common failure modes |
| [`backend/README.md`](backend/README.md) | Backend code layout, routes, SSE modes |
| [`frontend/README.md`](frontend/README.md) | Frontend code layout, stores, SSE patterns |

---

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). For security issues, see
[`SECURITY.md`](SECURITY.md).

## License

[MIT](LICENSE). Free to use, modify, redistribute. No warranty.

## Acknowledgements

- Student project at **FH OÖ, Campus Hagenberg** (winter semester 2025 / summer
  semester 2026)
- Project supervision: **Wolfgang Hochleitner**
- Maintainers: Edina Abazovic, Nikolai Hermann, Selina Catic, Tristan Trunez
- Originally developed in partnership with a real-world after-school
  childcare facility; deployed and tested on-site
