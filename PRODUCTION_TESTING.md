# Produktions-Testing auf dem Raspberry Pi

Praktische Test-Checkliste **nach** dem Setup mit `start-prod-raspi.sh`. Setup-Anleitung selbst steht im Root-`README.md`.

Diese Datei nimmt an, dass der Pi nativ läuft (nginx + php-fpm + MariaDB + Mosquitto via apt), Backend unter `/var/www/lokato/backend/` und Frontend unter `/var/www/lokato/frontend/dist/`.

---

## 1. Smoketest direkt nach `./start-prod-raspi.sh`

```bash
# Alle Lokato-Dienste sollten "active (running)" sein:
systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt

# HTTP-Health (sollte {"status":"ok",...} liefern):
curl http://localhost/api/health

# Readiness (prüft DB-Erreichbarkeit + Migrations-Status):
curl http://localhost/api/readiness

# MQTT-Subscriber lebt + ist subscribed:
journalctl -u lokato-mqtt --since "1 min ago" | grep -i "subscribed"
```

Erwartete Ausgaben:
- `systemctl status` → alle fünf Dienste **active (running)**
- `/api/health` → `{"status":"ok","uptime_seconds":N,...}`
- `/api/readiness` → 200 OK
- `journalctl` → mind. eine Zeile `Subscribed. Waiting for messages on topic: /api/v1/scan`

## 2. Echte Pi-IP feststellen + Frontend erreichbar

```bash
hostname -I              # zeigt aktuelle IP-Adressen
ip -4 addr show eth0     # nur LAN-IF
```

Vom Pi aus selbst:
```bash
curl http://localhost/                  # → Vue-HTML lädt
```

Von einem Tablet im selben Netz:
```
http://<pi-ip>/#/dashboard
http://<pi-ip>/#/tablet/1
```

## 3. End-to-End-Scan testen (ohne ESP32)

```bash
# Subscriber-Log live mitlesen (in einem Terminal):
journalctl -u lokato-mqtt -f

# In einem zweiten Terminal: simulierten Scan publizieren
mosquitto_pub -h localhost -t "/api/v1/scan" \
  -m '{"device_key":"RaspberryChild02","tracker_uid":"0x80691500004023FDD55DFC23","event_time":"2026-06-04T10:00:00+02:00"}'
```

Erwartete Reihenfolge in `journalctl`:
```
MQTT RECEIVED /api/v1/scan (len=…)
MQTT scan ingested. movement_id=…
```

Im Browser auf dem Tablet sollte die Bewegung **innerhalb 500 ms** sichtbar werden.

Verfügbare Test-Kombinationen (aus den Seeds):

| `device_key` | Raum | Beispiel `tracker_uid` |
|---|---|---|
| `RaspberryChild01` | Untergeschoss (3) | `0x80691500005023FDD541FC7F` (GELB-HERZ) |
| `RaspberryChild02` | Obergeschoss (2) | `0x80691500004023FDD55DFC23` (SCHWARZ-BLUME) |
| `RaspberryChild03` | Garten (1) | `0x80691500004023FDD525FCA2` (BLAU-BLUME) |

Vollständige Liste aller Kinder:
```bash
sudo -u www-data php /var/www/lokato/backend/artisan tinker --execute "foreach (App\Models\Child::all() as \$c) { echo \$c->name . ' -> ' . \$c->tracker_uid . PHP_EOL; }"
```

## 4. Daily-Reset testen

```bash
# Manueller Lauf
sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset

# Log prüfen:
sudo tail -n 20 /var/www/lokato/backend/storage/logs/cron.log
```

Erwartet: `daily_reset_started` gefolgt von `daily_reset_finished` mit `affected_children_count`.

**Hinweis:** der manuelle Lauf testet nur den Command. Damit der Reset auch automatisch um 01:00 läuft, muss der Laravel-Scheduler via System-Cron getriggert werden — siehe Root-`README.md` → Abschnitt „Cron-Jobs auf dem Pi". Verifikation, dass der automatische Lauf wirklich gestartet wurde:
```bash
# Letzten automatischen Reset prüfen (in app_runtime_state-Tabelle):
sudo -u www-data php /var/www/lokato/backend/artisan tinker --execute "echo App\Models\AppRuntimeState::where('state_key','last_daily_reset_at')->value('state_value');"
```

## 5. SSE-Verbindung im Browser live verifizieren

1. Chrome/Edge öffnen → **F12** → Tab **Netzwerk** → Filter **EventStream** (in Edge: **EventSource**)
2. `http://<pi-ip>/#/dashboard` aufrufen
3. Sichtbar werden sollte eine Verbindung zu `/api/stream` mit `pending`-Status (= offen)
4. Tab anklicken → unten erscheinen Events live: `stream.ready`, später `child.moved`, `room.occupancy.updated`, …
5. Nach 60 s erscheint `stream.draining`, dann reconnected der Client automatisch (sichtbar als neuer Eintrag in der Network-Liste)

## 6. Reboot-Test

```bash
sudo reboot
# nach ca. 30 s: Pi neu prüfen
systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt
curl http://localhost/api/health
```

Alle Dienste müssen automatisch hochkommen. Wenn nicht, prüfen ob `systemctl enable` für alle gemacht wurde — `start-prod-raspi.sh` tut das automatisch.

## 7. Log-Audit-Tool manuell laufen lassen

```bash
cd /var/www/lokato
python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json
```

Exit-Code:
- `0` — sauber
- `1` — Errors oder fehlgeschlagene Daily-Resets gefunden → prüfen
- `2` — Log-Dateien fehlen → Setup unvollständig

Siehe `tools/log_audit/README.md` für Cron-Setup und Pattern-Details.

## 8. Production-Test-Checkliste (vor Hort-Einsatz)

- [ ] `systemctl status` zeigt alle 5 Dienste active
- [ ] `/api/health` und `/api/readiness` antworten 200
- [ ] Test-Scan löst Movement-Log-Eintrag aus
- [ ] Tablet im Hort-WLAN kann `http://<pi-ip>/#/tablet/1` öffnen
- [ ] Dashboard auf einem Admin-Gerät zeigt alle Räume korrekt
- [ ] `daily_reset_finished` taucht im `cron.log` täglich auf
- [ ] `log_audit.py check --period daily` liefert Exit 0
- [ ] Reboot-Test: alle Dienste kommen automatisch hoch
- [ ] DB-Backup-Strategie geklärt (siehe Abschnitt 9)
- [ ] Hort-Personal kennt die Bookmarks der Tablets
- [ ] Auflistung der hinterlegten Kinder geprüft (mit Hort-Liste abgeglichen)

## 9. DB-Backup

Phase 2/3 hat das nicht automatisiert. Empfehlung als manueller Schritt vor jedem produktiven Einsatz (oder als zusätzlicher Cron-Job):

```bash
# Dump anlegen
sudo mysqldump --single-transaction --routines lokato_db \
  > /var/backups/lokato-$(date +%F).sql

# Restore
sudo mysql lokato_db < /var/backups/lokato-2026-06-04.sql
```

## 10. Häufige Fehlerbilder

Siehe Root-`README.md` Abschnitt „Troubleshooting" — dort sind die vier wichtigsten Szenarien abgehakt (WLAN-Reach, MQTT-Subscriber-Crash, MySQL-Connection-refused, Pi-IP-Wechsel).

## 11. Manuelle MQTT-Diagnose

```bash
# Was kommt am Broker an (alle Topics):
mosquitto_sub -h localhost -t "#" -v

# Speziell das Scan-Topic:
mosquitto_sub -h localhost -t "/api/v1/scan" -v

# Subscriber-Status:
systemctl status lokato-mqtt
sudo journalctl -u lokato-mqtt -n 50 --no-pager
```

Wichtige Hinweise:
- MQTT-Topics sind **exakt**. `/api/v1/scan` ≠ `api/v1/scan`. Publisher muss das gleiche Topic wie `MQTT_TOPIC_SCAN` verwenden.
- Subscriber-Client-ID enthält die PID (`lokato-laravel-subscriber-1234`) — damit kollidieren mehrere parallele Instanzen nicht in der Broker-Session.
