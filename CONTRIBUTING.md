# Contributing to Lokato

Thanks for taking the time to look at this project. Lokato started as a
student project at FH OÖ (Campus Hagenberg) and is open-sourced for
transparency and as a reference for similar attendance / room-occupancy
systems.

## How to contribute

### Bug reports

Open a GitHub issue with:

- What you tried
- What you expected to happen
- What actually happened (logs, screenshots if helpful)
- Your environment: Docker-Compose (Win/Mac/Linux) or Pi-native

For **security issues**, please follow [`SECURITY.md`](SECURITY.md) instead of
opening a public issue.

### Pull requests

1. Fork and create a feature branch off `main`
2. Keep changes focused — one PR per logical change
3. Run the test suites locally before opening the PR:
   ```bash
   # Backend
   docker compose exec php-fpm php artisan test
   # Frontend
   cd frontend && npm run test
   ```
4. If you touch infrastructure (`docker-compose.yml`, `start-prod-raspi.sh`,
   nginx configs, php-fpm pool): explain why in the PR description, ideally
   with a before/after sketch
5. Don't bump major dependencies in feature PRs — please open a separate
   "dep bump" PR

### Areas where contributions are especially welcome

- More frontend test coverage (Vitest / component tests)
- Pi 5 / Pi Zero 2 W compatibility verification
- ESP32-S3 firmware variant (currently classic ESP32)
- Internationalisation (currently mostly German UI)
- Accessibility improvements on the room-tablet view

### Areas where we are unlikely to merge changes

- A rewrite of `backend/app/Services/ScanIngestService.php` — this is the
  business core, atomic and locked by design (see comments in the file)
- A switch to Redis / a different cache driver — `database`-driver was a
  deliberate decision to keep the Pi simple
- Replacing SSE with WebSockets — same reasoning, SSE has no extra runtime
  cost

If you want to do any of the above, please open an issue first to discuss.

## Code style

- **PHP**: PSR-12, run `composer run-script test` before pushing
- **Vue / TypeScript**: ESLint + Prettier configured in the repo, run
  `npm run lint && npm run format:check`
- **Arduino / ESP32** (in `lokato-main`): match the existing style in
  `esp32_rfid_mqtt_prod/`

## Communication

This project is small and we don't run a Discord / Slack. Issues and PRs on
GitHub are the only channels. Replies may take a few days — we're students.
