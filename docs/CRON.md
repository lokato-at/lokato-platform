# Cron-Jobs auf dem Raspberry Pi

`start-prod-raspi.sh` legt die Crontab-Einträge für `www-data` **idempotent** an (Block zwischen Markern `>>> lokato managed cron block …` und `<<< lokato managed cron block <<<`). Eigene Einträge **außerhalb** der Marker bleiben unangetastet.

---

## Was läuft automatisch

Zwei unabhängige Aufgaben:

### 1. Laravel-Scheduler — triggert den Daily-Reset

Definition: `backend/routes/console.php`
```php
Schedule::command('children:daily-active-reset')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'Europe/Vienna'));
```

System-Cron ruft minütlich `php artisan schedule:run` auf; Laravel selbst entscheidet ob etwas fällig ist.

Der Reset:
- Setzt alle `children.is_active` auf `false`
- Leert `child_locations` (Räume sind morgens leer)
- Bumpt `SseChangeSignal` (Dashboards reagieren binnen 500 ms)
- Schreibt `last_daily_reset_date/at` nach `app_runtime_state`
- Loggt `daily_reset_finished` nach `cron.log`

`movement_log` bleibt als Append-Only-Historie unangetastet.

### 2. Log-Audit — prüft Laravel-Logs + systemd-Status

Tool: `tools/log_audit/log_audit.py` mit `config.json`.

Prüft:
- 4 Laravel-Logs (`scan`, `cron`, `laravel`, `sse`) auf 13 Pattern-Kategorien
- 3 System-Logs (nginx-error, php-fpm, scheduler) auf nginx-Errors + Scheduler-Fatals
- 5 systemd-Units (`lokato-mqtt`, `nginx`, `mariadb`, `mosquitto`, `php8.4-fpm`) auf `is-active` + Restart-Counter (>10 = Crash-Loop-Verdacht)

Loggt nach `/var/log/lokato/log-audit.log`. Exit-Codes:
- `0` — clean
- `1` — Errors / Crash-Loop / fehlgeschlagener Daily-Reset
- `2` — Log-Files fehlen (Setup unvollständig)

## Die 4 installierten Crontab-Einträge

```cron
# >>> lokato managed cron block -- do not edit between markers >>>
MAILTO=dein.name@example.com    # nur wenn ALERT_EMAIL beim Setup-Lauf gesetzt war
# Laravel-Scheduler
* * * * * cd /var/www/lokato/backend && /usr/bin/php artisan schedule:run >> /var/log/lokato/scheduler.log 2>&1
# Log-Audit
10 6 * * * cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period daily  --config tools/log_audit/config.json 2>&1 | tee -a /var/log/lokato/log-audit.log
20 6 * * 1 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json 2>&1 | tee -a /var/log/lokato/log-audit.log
30 3 * * 0 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py cleanup        --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
# <<< lokato managed cron block <<<
```

## Mail-Alarmierung (optional)

`configure_cron()` setzt einen `MAILTO=…`-Header, wenn `ALERT_EMAIL` beim Setup-Lauf gesetzt war:

```bash
export ALERT_EMAIL="dein.name@example.com"
./start-prod-raspi.sh
```

Voraussetzung — **lokaler MTA installiert:**

```bash
sudo apt install msmtp-mta libsasl2-modules
# Dann ~/.msmtprc für SMTP-Account konfigurieren (Gmail / Mailbox.org / etc.)
```

Cron verschickt automatisch:
- **kompletten Audit-Report** als Mail (auch ohne Exit-Fehler — weil Audit stdout produziert)
- bei Exit-Code ≠ 0 zusätzlich Stderr

Ohne `ALERT_EMAIL` → alles silent in die Log-Datei (Standard-Verhalten).

## Verifikation

```bash
# Aktive Crontab anzeigen
sudo crontab -l -u www-data

# Live-Log des Schedulers (minütlich erwartet, meist "no scheduled commands due")
sudo tail -f /var/log/lokato/scheduler.log

# Manueller Probelauf Daily-Reset (testet ohne 01:00 abzuwarten)
sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset

# Letzten automatischen Reset auslesen
sudo -u www-data php /var/www/lokato/backend/artisan tinker --execute \
  "echo App\\Models\\AppRuntimeState::where('state_key','last_daily_reset_at')->value('state_value');"

# Manueller Audit-Lauf
cd /var/www/lokato
sudo -u www-data python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json
```

## Alternative: `schedule:work` als systemd-Service

Statt minütlichem System-Cron kann man auch einen Daemon laufen lassen:

```ini
# /etc/systemd/system/lokato-scheduler.service
[Unit]
Description=Lokato Laravel Scheduler
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/lokato/backend
ExecStart=/usr/bin/php artisan schedule:work
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now lokato-scheduler
```

→ Wenn diese Variante: den `* * * * * schedule:run`-Cron-Eintrag weg lassen, sonst läuft Scheduler doppelt. Log-Audit weiterhin via Cron.

## nginx-Logs lesbar machen für `www-data`

Default-Permissions auf `/var/log/nginx/`: `640 root:adm`. Damit `www-data` die im Audit liest:

```bash
sudo usermod -aG adm www-data
sudo systemctl restart php8.2-fpm   # damit www-data die neue Gruppe sieht
```
