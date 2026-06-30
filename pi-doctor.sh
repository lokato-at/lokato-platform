#!/usr/bin/env bash
# =============================================================================
# Lokato — Pi-Doctor (Healthcheck, auf dem Pi ausfuehren)
# -----------------------------------------------------------------------------
# Ein Befehl, eine PASS/FAIL-Tabelle ueber den gesamten System-Zustand:
#   Dienste, API-Health/Readiness, DB-Login (= .env<->MariaDB-Konsistenz),
#   Admin-User vorhanden, MQTT-Subscriber, APP_KEY/APP_URL.
#
# Aufruf:
#   bash pi-doctor.sh
#
# Liest die DB-Credentials aus der deployten .env (per sudo) und testet damit
# einen echten MariaDB-Login — schlaegt der fehl, ist .env<->MariaDB out of sync.
# Exit 0 = alles gruen, Exit !=0 = mind. ein harter Fehler.
# =============================================================================
set -uo pipefail

DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/lokato}"
ENV_FILE="$DEPLOY_ROOT/backend/.env"
BASE_URL="${BASE_URL:-http://localhost}"

PASS=0; FAIL=0
ok(){   printf '  \033[32m[ OK ]\033[0m %s\n' "$*"; PASS=$((PASS+1)); }
bad(){  printf '  \033[31m[FAIL]\033[0m %s\n' "$*"; FAIL=$((FAIL+1)); }
warn(){ printf '  \033[33m[WARN]\033[0m %s\n' "$*"; }
hdr(){  printf '\n\033[36m== %s ==\033[0m\n' "$*"; }

if [[ ! -f "$ENV_FILE" ]]; then
    printf '\033[31m.env nicht gefunden: %s — laeuft das Setup schon?\033[0m\n' "$ENV_FILE"
    exit 1
fi
# .env gehoert www-data/root -> einmal sudo aufwaermen, danach gecacht.
sudo -v 2>/dev/null || true
env_get(){ sudo sed -n "s/^$1=//p" "$ENV_FILE" 2>/dev/null | head -n1; }

# --- DB-Credentials aus der .env (fuer DB-/Admin-Checks) ---
DB_U="$(env_get DB_USERNAME)"; DB_P="$(env_get DB_PASSWORD)"
DB_D="$(env_get DB_DATABASE)"; DB_H="$(env_get DB_HOST)"; DB_H="${DB_H:-127.0.0.1}"

# ---------------------------------------------------------------------------
hdr "systemd-Dienste"
PHP_VER="$(ls /etc/php 2>/dev/null | sort -V | tail -n1)"
check_svc(){  # erster aktiver Kandidat zaehlt als OK
    local name
    for name in "$@"; do
        if systemctl is-active --quiet "$name" 2>/dev/null; then ok "$name aktiv"; return; fi
    done
    bad "$* NICHT aktiv  ->  journalctl -u ${1} -n 30 --no-pager"
}
check_svc nginx
check_svc "php${PHP_VER}-fpm" php-fpm
check_svc mariadb mysql
check_svc mosquitto
check_svc lokato-mqtt

# ---------------------------------------------------------------------------
hdr "API"
if ! command -v curl >/dev/null 2>&1; then
    warn "curl nicht installiert — API-Checks uebersprungen"
else
    for ep in health readiness; do
        code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/$ep" 2>/dev/null || echo 000)"
        if [[ "$code" == "200" ]]; then ok "/api/$ep -> 200"
        else bad "/api/$ep -> $code  (nginx/php-fpm/DB pruefen)"; fi
    done
fi

# ---------------------------------------------------------------------------
hdr "Datenbank (= .env <-> MariaDB-Konsistenz)"
if [[ -z "$DB_U" || -z "$DB_D" ]]; then
    bad "DB_USERNAME/DB_DATABASE in .env leer"
elif mysql -h "$DB_H" -u "$DB_U" -p"$DB_P" -e "USE \`$DB_D\`; SELECT 1;" >/dev/null 2>&1; then
    ok ".env-Credentials loggen in MariaDB ein (User '$DB_U', DB '$DB_D')"
else
    bad ".env-Login schlaegt fehl — DB_PASSWORD in .env passt NICHT zum MariaDB-User '$DB_U'."
    warn "Fix: ALTER USER '$DB_U'@'localhost' IDENTIFIED BY '<.env-DB_PASSWORD>';  dann  config:clear"
fi

# ---------------------------------------------------------------------------
hdr "Admin-User (Login moeglich?)"
ucount="$(mysql -h "$DB_H" -u "$DB_U" -p"$DB_P" -N -B \
            -e "SELECT COUNT(*) FROM \`$DB_D\`.users;" 2>/dev/null || echo "")"
if [[ "$ucount" =~ ^[0-9]+$ ]] && (( ucount > 0 )); then
    ok "$ucount User in der users-Tabelle"
elif [[ "$ucount" == "0" ]]; then
    bad "Kein User angelegt — Login unmoeglich."
    warn "Fix: ADMIN_USER_EMAIL=.. ADMIN_USER_PASSWORD=.. sudo -E -u www-data php $DEPLOY_ROOT/backend/artisan db:seed --class=AdminUserSeeder --force"
else
    warn "users-Tabelle nicht lesbar (DB-Login oben gescheitert?)"
fi

# ---------------------------------------------------------------------------
hdr "MQTT-Ingest"
if systemctl is-active --quiet lokato-mqtt 2>/dev/null; then
    if journalctl -u lokato-mqtt --since "-10 min" 2>/dev/null | grep -q "Subscribed"; then
        ok "lokato-mqtt aktiv und 'Subscribed' geloggt (<=10 min)"
    else
        warn "lokato-mqtt aktiv, aber kein 'Subscribed' in 10 min (nur kein Neustart?) -> journalctl -u lokato-mqtt -n 30"
    fi
else
    bad "lokato-mqtt nicht aktiv -> journalctl -u lokato-mqtt -n 30 --no-pager"
fi

# ---------------------------------------------------------------------------
hdr "Konfiguration"
if sudo grep -qE '^APP_KEY=base64:' "$ENV_FILE" 2>/dev/null; then
    ok "APP_KEY gesetzt"
else
    bad "APP_KEY leer -> sudo -u www-data php $DEPLOY_ROOT/backend/artisan key:generate --force"
fi
APP_URL="$(env_get APP_URL)"; IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [[ -n "$IP" && "$APP_URL" == *"$IP"* ]]; then
    ok "APP_URL ($APP_URL) passt zur aktuellen IP"
else
    warn "APP_URL=$APP_URL stimmt nicht mit aktueller IP ($IP) ueberein (kosmetisch, Same-Origin)"
fi

# ---------------------------------------------------------------------------
printf '\n'
if (( FAIL == 0 )); then
    printf '\033[32m==> %d OK, 0 FAIL — System gesund.\033[0m\n' "$PASS"
    exit 0
else
    printf '\033[31m==> %d OK, %d FAIL — siehe Markierungen oben.\033[0m\n' "$PASS" "$FAIL"
    exit 1
fi
