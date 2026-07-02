#!/usr/bin/env bash
# =============================================================================
# Lokato — Produktions-Setup fuer Raspberry Pi OS (Bookworm 64-bit empfohlen)
# =============================================================================
# Nativ, kein Docker. Installiert nginx + php-fpm + MariaDB + Mosquitto via
# apt, deployt Backend nach /var/www/lokato/backend und Frontend nach
# /var/www/lokato/frontend/dist, startet den MQTT-Subscriber als systemd-Unit.
#
# Wird idempotent ausgefuehrt — beim zweiten Lauf werden bestehende DB,
# Konfigs und .env-Dateien NICHT ueberschrieben.
#
# Aufruf:
#   ./start-prod-raspi.sh
#
# Wichtige Env-Variablen (optional):
#   PI_IP=192.168.1.100          Statische IP, die der Pi bekommen soll
#   INSTALL_DEPS=0               apt-Block ueberspringen (bei wiederholtem Run)
#   CONFIG_NETWORK=0             IP-Konfiguration ueberspringen
#   DB_PASSWORD=changeme         Passwort fuer den Laravel-DB-User
#   ALERT_EMAIL=you@example.com  Mail-Empfaenger fuer Log-Audit-Cron-Anomalien
#   ADMIN_USER_EMAIL=…           Vom AdminUserSeeder gelesen, FALLS du das
#   ADMIN_USER_PASSWORD=…        Seeder spaeter manuell ausfuehrst
#                                (`artisan db:seed --class=AdminUserSeeder`).
#                                Dieses Script seedet bewusst NICHT — siehe
#                                docs/PRODUCTION.md "Admin-User fuer Erststart".
# =============================================================================

set -Eeuo pipefail

# ----- Konfiguration ---------------------------------------------------------
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_SRC="$PROJECT_ROOT/backend"
FRONTEND_SRC="$PROJECT_ROOT/frontend"
DOCKER_DIR="$PROJECT_ROOT/docker"

DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/lokato}"
BACKEND_DEPLOY="$DEPLOY_ROOT/backend"
FRONTEND_DEPLOY="$DEPLOY_ROOT/frontend"

LOG_DIR="/var/log/lokato"

# PI_IP ist die statische Adresse, die der Pi annehmen soll. Wenn das Netz
# DHCP-only ist, setze CONFIG_NETWORK=0 und nimm die Router-vergebene IP.
PI_IP="${PI_IP:-192.168.1.100}"
PI_GATEWAY="${PI_GATEWAY:-192.168.1.1}"
PI_DNS="${PI_DNS:-192.168.1.1 1.1.1.1}"
PI_NETMASK_CIDR="${PI_NETMASK_CIDR:-24}"

DB_NAME="${DB_NAME:-lokato_db}"
DB_USER="${DB_USER:-lokato}"
DB_PASSWORD="${DB_PASSWORD:-changeme}"

INSTALL_DEPS="${INSTALL_DEPS:-1}"
CONFIG_NETWORK="${CONFIG_NETWORK:-1}"

# ----- Helpers ---------------------------------------------------------------
info() { echo -e "\033[36m==> $*\033[0m"; }
ok()   { echo -e "\033[32m[OK] $*\033[0m"; }
warn() { echo -e "\033[33m[WARN] $*\033[0m"; }
fail() { echo -e "\033[31m[ERROR] $*\033[0m"; exit 1; }

need_cmd() { command -v "$1" >/dev/null 2>&1; }

# Fuehrt Operationen als www-data aus, mit HOME im Deploy-Pfad damit
# Composer/npm-Caches dort landen (nicht in /root, nicht in /home/pi).
as_www_data() {
    sudo -u www-data \
        HOME="$DEPLOY_ROOT" \
        PATH="/usr/local/bin:/usr/bin:/bin" \
        "$@"
}

# Legt $target aus $example an, falls es noch nicht existiert.
# Rueckgabe: 0 = frisch angelegt, 1 = existierte bereits (wird NICHT angefasst).
# Wichtig: Aufrufer MUESSEN den Rueckgabewert behandeln (if / || true), sonst
# bricht `set -e` bei "existiert bereits" (return 1) das Skript ab.
ensure_file_from_example() {
    local target="$1"
    local example="$2"
    if [[ -f "$target" ]]; then
        return 1
    fi
    if [[ ! -f "$example" ]]; then
        fail "Template fehlt: $example"
    fi
    sudo cp "$example" "$target"
    ok "$(basename "$target") aus $(basename "$example") angelegt."
    return 0
}

# ----- Vorbedingungen --------------------------------------------------------
if [[ $EUID -eq 0 ]]; then
    fail "Bitte NICHT als root aufrufen — das Script eskaliert per sudo punktuell."
fi
if ! sudo -n true 2>/dev/null; then
    info "sudo benoetigt — du wirst gleich nach deinem Passwort gefragt."
    sudo -v || fail "sudo verweigert."
fi

# ----- 1) apt: System-Pakete -------------------------------------------------
detect_php_version() {
    # Auf Pi OS Bookworm: php8.2. Bullseye: php7.4 oder php8.1 (via PPA).
    # Wir lassen Pakete einfach mit "php" + "php-fpm" installieren, der
    # Default-Metapaketname zieht die Distributions-Default-Version.
    if [[ -d /etc/php ]]; then
        ls /etc/php/ 2>/dev/null | sort -V | tail -n 1
    else
        echo ""
    fi
}

install_system_packages() {
    if [[ "$INSTALL_DEPS" != "1" ]]; then
        warn "INSTALL_DEPS=0 — apt-Block uebersprungen."
        return
    fi

    info "apt update + Pakete installieren (das kann dauern)..."
    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
        nginx \
        php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip \
        php-bcmath php-sockets php-intl php-gd php-opcache \
        composer \
        default-mysql-server \
        mosquitto mosquitto-clients \
        nodejs npm \
        rsync curl git unzip
    ok "Systempakete installiert."
}

# ----- 2) Netzwerk: statische IP --------------------------------------------
configure_network() {
    if [[ "$CONFIG_NETWORK" != "1" ]]; then
        warn "CONFIG_NETWORK=0 — Netzkonfiguration uebersprungen."
        return
    fi

    info "Pruefe Netzwerk-Manager..."
    if systemctl is-active --quiet NetworkManager 2>/dev/null; then
        info "NetworkManager aktiv → nmcli-Pfad."
        local con
        con="$(nmcli -t -f NAME,DEVICE c show --active | awk -F: '$2!="" && $2!="lo" {print $1; exit}')"
        if [[ -z "$con" ]]; then
            warn "Keine aktive Verbindung gefunden — IP-Setting uebersprungen."
            return
        fi
        info "Setze statische IP auf Connection \"$con\" → $PI_IP/$PI_NETMASK_CIDR"
        sudo nmcli con mod "$con" \
            ipv4.addresses "$PI_IP/$PI_NETMASK_CIDR" \
            ipv4.gateway "$PI_GATEWAY" \
            ipv4.dns "$PI_DNS" \
            ipv4.method manual
        sudo nmcli con up "$con" || warn "nmcli con up fehlgeschlagen — manueller Reboot ggf. noetig."
        ok "NetworkManager-IP gesetzt."

    elif systemctl is-active --quiet dhcpcd 2>/dev/null; then
        info "dhcpcd aktiv → /etc/dhcpcd.conf-Pfad."
        local conf=/etc/dhcpcd.conf
        if sudo grep -q "# lokato static block" "$conf"; then
            ok "dhcpcd.conf hat bereits Lokato-Block — uebersprungen."
            return
        fi
        sudo tee -a "$conf" >/dev/null <<EOF

# lokato static block
interface eth0
static ip_address=$PI_IP/$PI_NETMASK_CIDR
static routers=$PI_GATEWAY
static domain_name_servers=$PI_DNS
EOF
        sudo systemctl restart dhcpcd
        ok "dhcpcd-IP gesetzt."

    else
        warn "Weder NetworkManager noch dhcpcd aktiv. Konfiguriere IP manuell."
    fi
}

# ----- 3) MariaDB: DB + User -------------------------------------------------
configure_database() {
    info "MariaDB starten..."
    sudo systemctl enable --now mariadb 2>/dev/null \
        || sudo systemctl enable --now mysql

    # Wenn schon eine deployte .env existiert, ist SIE die Wahrheit ueber die
    # DB-Credentials. Uebernimm sie, damit der MariaDB-User auf exakt das
    # Passwort gesetzt wird, das auch in der .env steht — sonst laufen beide
    # auseinander und `migrate` scheitert mit "Access denied for user".
    local deployed_env="$BACKEND_DEPLOY/.env"
    if [[ -f "$deployed_env" ]]; then
        local v
        v="$(sudo sed -n 's/^DB_DATABASE=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_NAME="$v"
        v="$(sudo sed -n 's/^DB_USERNAME=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_USER="$v"
        v="$(sudo sed -n 's/^DB_PASSWORD=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_PASSWORD="$v"
        info "Bestehende .env gefunden — DB-Credentials daraus uebernommen (User '$DB_USER', DB '$DB_NAME')."
    fi

    # User + Datenbank anlegen (idempotent ueber IF NOT EXISTS).
    info "Datenbank \"$DB_NAME\" und User \"$DB_USER\" sicherstellen..."
    sudo mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

    # Initial-Schema einspielen, wenn die Tabelle "children" noch fehlt.
    # (Das Initial-SQL ist aus dem Docker-Setup. Idempotent: wenn schon
    # Tabellen da sind, ueberspringen.)
    local schema="$DOCKER_DIR/sql/init/01_schema.sql"
    if [[ -f "$schema" ]]; then
        local table_count
        table_count="$(sudo mysql -N -B -e "SHOW TABLES FROM \`$DB_NAME\`;" | wc -l)"
        if [[ "$table_count" -eq 0 ]]; then
            info "Initial-Schema importieren ($schema)..."
            sudo mysql "$DB_NAME" < "$schema"
            ok "Schema importiert."
        else
            ok "DB enthaelt bereits $table_count Tabellen — Schema-Import uebersprungen."
        fi
    else
        warn "Schema-Datei nicht gefunden: $schema (kein Initial-Import)."
    fi
}

# ----- 4) Mosquitto ----------------------------------------------------------
configure_mosquitto() {
    info "Mosquitto konfigurieren..."
    # Pi-specific config (apt paths, 0.0.0.0 listener). The plain mosquitto.conf
    # next to it uses container paths (/mosquitto/...) which would crash mosquitto
    # on a native Pi install.
    local pi_conf="$DOCKER_DIR/mosquitto/config/mosquitto-pi.conf"
    if [[ -f "$pi_conf" ]]; then
        sudo install -m 644 "$pi_conf" /etc/mosquitto/conf.d/lokato.conf
        ok "Mosquitto-Config installiert."
    fi
    sudo systemctl enable --now mosquitto
}

# ----- 5) nginx --------------------------------------------------------------
configure_nginx() {
    info "nginx-Site einspielen..."
    sudo install -m 644 \
        "$DOCKER_DIR/nginx/prod.conf" \
        /etc/nginx/sites-available/lokato
    sudo ln -sf /etc/nginx/sites-available/lokato /etc/nginx/sites-enabled/lokato
    # Default-Site disablen, sonst hoeren beide auf :80.
    sudo rm -f /etc/nginx/sites-enabled/default
    sudo nginx -t
    sudo systemctl enable nginx
    sudo systemctl reload nginx 2>/dev/null || sudo systemctl restart nginx
    ok "nginx aktiv."
}

# ----- 6) php-fpm ------------------------------------------------------------
configure_php_fpm() {
    local php_ver
    php_ver="$(detect_php_version)"
    if [[ -z "$php_ver" ]]; then
        fail "Konnte PHP-Version unter /etc/php/ nicht ermitteln."
    fi
    info "php-fpm Pool fuer PHP $php_ver einspielen..."

    sudo install -m 644 \
        "$DOCKER_DIR/php-fpm/lokato-pool.conf" \
        "/etc/php/$php_ver/fpm/pool.d/lokato.conf"

    # Default-www-Pool deaktivieren (kollidiert sonst auf 9000 nicht, aber
    # der Listen-Default ist ein Unix-Socket — Verwirrung minimieren).
    if [[ -f "/etc/php/$php_ver/fpm/pool.d/www.conf" ]]; then
        sudo mv "/etc/php/$php_ver/fpm/pool.d/www.conf" \
                "/etc/php/$php_ver/fpm/pool.d/www.conf.disabled"
        ok "Default-www-Pool nach www.conf.disabled verschoben."
    fi

    sudo mkdir -p "$LOG_DIR"
    sudo chown www-data:www-data "$LOG_DIR"

    sudo systemctl enable "php$php_ver-fpm"
    sudo systemctl restart "php$php_ver-fpm"
    ok "php$php_ver-fpm laeuft."
}

# ----- 7) systemd-Unit fuer MQTT-Subscriber ---------------------------------
configure_systemd_mqtt() {
    info "systemd-Unit lokato-mqtt einspielen..."
    sudo install -m 644 \
        "$DOCKER_DIR/systemd/lokato-mqtt.service" \
        /etc/systemd/system/lokato-mqtt.service
    sudo systemctl daemon-reload
    sudo systemctl enable lokato-mqtt
    # Erst nach Backend-Deploy starten (siehe finalize_services).
    ok "systemd-Unit installiert (Start erfolgt nach Backend-Deploy)."
}

# ----- 8) Deploy: Verzeichnisse + Permissions -------------------------------
prepare_deploy_dirs() {
    info "Deploy-Verzeichnisse anlegen..."
    sudo mkdir -p "$BACKEND_DEPLOY" "$FRONTEND_DEPLOY/dist"
    sudo chown -R www-data:www-data "$DEPLOY_ROOT"
}

# ----- 9) Backend deployen ---------------------------------------------------
deploy_backend() {
    info "Backend nach $BACKEND_DEPLOY syncen..."
    sudo rsync -a --delete \
        --exclude=.env \
        --exclude=vendor/ \
        --exclude=node_modules/ \
        --exclude=storage/logs/ \
        --exclude=storage/framework/cache/ \
        --exclude=storage/framework/sessions/ \
        --exclude=storage/framework/views/ \
        --exclude=bootstrap/cache/*.php \
        --exclude=.idea/ \
        --exclude=.cursor/ \
        --exclude=.junie/ \
        --exclude=tests/ \
        "$BACKEND_SRC/" "$BACKEND_DEPLOY/"

    # Storage-Struktur sicherstellen (rsync hat sie geleert).
    sudo install -d -o www-data -g www-data -m 775 \
        "$BACKEND_DEPLOY/storage/logs" \
        "$BACKEND_DEPLOY/storage/framework/cache" \
        "$BACKEND_DEPLOY/storage/framework/sessions" \
        "$BACKEND_DEPLOY/storage/framework/views" \
        "$BACKEND_DEPLOY/storage/app/public/children" \
        "$BACKEND_DEPLOY/bootstrap/cache"

    # .env aus Pi-Template, wenn noch keine da ist. Bei FRISCHER .env die
    # Runtime-Werte hineinspiegeln: DB_PASSWORD (sonst Drift zum MariaDB-User,
    # siehe configure_database) und APP_URL (echte Pi-IP). Eine bereits
    # vorhandene .env wird oben gar nicht erst angefasst.
    if ensure_file_from_example "$BACKEND_DEPLOY/.env" "$BACKEND_SRC/.env.raspi.example"; then
        local _ip _pw_esc
        _ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
        [[ -z "$_ip" ]] && _ip="$PI_IP"
        # sed-Sonderzeichen im Passwort escapen (\ / & |), damit der Wert
        # buchstabengetreu landet.
        _pw_esc="$(printf '%s' "$DB_PASSWORD" | sed -e 's/[\\/&|]/\\&/g')"
        sudo sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$_pw_esc|" "$BACKEND_DEPLOY/.env"
        sudo sed -i "s|^APP_URL=.*|APP_URL=http://$_ip|" "$BACKEND_DEPLOY/.env"
        ok ".env-Runtime-Werte gesetzt (DB_PASSWORD synchron mit MariaDB, APP_URL=http://$_ip)."
    fi

    # Stale bootstrap-cache loeschen, sonst landet 'BoostServiceProvider not
    # found' im Image, wenn das Cache-File noch dev-Pakete referenziert.
    sudo find "$BACKEND_DEPLOY/bootstrap/cache" -maxdepth 1 -name "*.php" -delete

    sudo chown -R www-data:www-data "$BACKEND_DEPLOY"

    info "Composer install (--no-dev) im Deploy-Verzeichnis..."
    as_www_data composer install \
        --working-dir="$BACKEND_DEPLOY" \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist

    # APP_KEY generieren, wenn leer.
    if ! sudo grep -qE '^APP_KEY=base64:' "$BACKEND_DEPLOY/.env"; then
        info "APP_KEY generieren..."
        as_www_data php "$BACKEND_DEPLOY/artisan" key:generate --force
    fi

    info "Laravel optimieren + migrieren..."
    as_www_data php "$BACKEND_DEPLOY/artisan" config:clear
    as_www_data php "$BACKEND_DEPLOY/artisan" migrate --force
    as_www_data php "$BACKEND_DEPLOY/artisan" config:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" route:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" view:cache

    # storage:link einrichten — public/storage → storage/app/public. Damit
    # nginx unter /storage/children/<id>.jpg die hochgeladenen Foto-Dateien
    # findet (siehe nginx prod.conf, location /storage/).
    if [[ ! -L "$BACKEND_DEPLOY/public/storage" ]]; then
        as_www_data php "$BACKEND_DEPLOY/artisan" storage:link
    fi
    ok "Backend deployt."
}

# ----- 10a) tools/log_audit deployen (fuer Cron-Audits) ----------------------
deploy_tools() {
    local tools_src="$PROJECT_ROOT/tools/log_audit"
    local tools_dst="$DEPLOY_ROOT/tools/log_audit"

    if [[ ! -d "$tools_src" ]]; then
        warn "tools/log_audit nicht im Repo gefunden — Cron-Audit muss anders verdrahtet werden."
        return
    fi

    info "tools/log_audit nach $tools_dst syncen..."
    sudo mkdir -p "$tools_dst"
    sudo rsync -a --delete "$tools_src/" "$tools_dst/"
    sudo chown -R www-data:www-data "$DEPLOY_ROOT/tools"
    ok "Log-Audit-Tool deployt."
}

# ----- 10) Frontend bauen + deployen -----------------------------------------
deploy_frontend() {
    info "Frontend bauen ($FRONTEND_SRC)..."
    # .env-Pi-Template sicherstellen — der Build liest daraus VITE_API_BASE_URL.
    # `|| true`, weil ensure_file_from_example bei vorhandener .env 1 zurueckgibt
    # und das unter `set -e` sonst abbrechen wuerde.
    ensure_file_from_example "$FRONTEND_SRC/.env" "$FRONTEND_SRC/.env.raspi.example" || true

    pushd "$FRONTEND_SRC" >/dev/null
    if [[ -f package-lock.json ]]; then
        npm ci
    else
        npm install
    fi
    npm run build
    popd >/dev/null

    info "Frontend-dist nach $FRONTEND_DEPLOY/dist syncen..."
    sudo rsync -a --delete \
        "$FRONTEND_SRC/dist/" "$FRONTEND_DEPLOY/dist/"
    sudo chown -R www-data:www-data "$FRONTEND_DEPLOY"
    ok "Frontend deployt."
}

# ----- 10b) Cron-Jobs fuer www-data anlegen (idempotent) --------------------
configure_cron() {
    info "Cron-Jobs fuer www-data einrichten (Scheduler + Log-Audit)..."

    # Marker-Pattern bewusst ohne Shell-Sonderzeichen, damit sed sauber loescht.
    local marker_start=">>> lokato managed cron block -- do not edit between markers >>>"
    local marker_end="<<< lokato managed cron block <<<"

    # Sicherstellen, dass /var/log/lokato existiert (legt configure_php_fpm
    # eigentlich an, aber configure_cron kann auch unabhaengig laufen).
    sudo mkdir -p "$LOG_DIR"
    sudo chown www-data:www-data "$LOG_DIR"

    # Bestehende crontab lesen (leer ist OK), Block zwischen Markern entfernen.
    local existing
    existing="$(sudo crontab -l -u www-data 2>/dev/null || true)"
    local cleaned
    cleaned="$(printf '%s\n' "$existing" | sed "/$marker_start/,/$marker_end/d")"

    # MAILTO-Zeile: nur wenn ALERT_EMAIL env-Var beim Skript-Aufruf gesetzt ist.
    # Cron mailt dann automatisch bei jeder stdout-Ausgabe (also bei Anomalien,
    # weil die Audit-Befehle ohne >>-Redirect laufen). Lokaler MTA noetig --
    # z.B. apt install msmtp-mta && msmtprc konfigurieren.
    local mailto_line=""
    if [[ -n "${ALERT_EMAIL:-}" ]]; then
        mailto_line="MAILTO=$ALERT_EMAIL"
        info "ALERT_EMAIL gesetzt -- cron mailt Anomalien an $ALERT_EMAIL."
    fi

    # Neuen Block anhaengen.
    # Audit-Befehle nutzen "tee -a" statt ">> 2>&1": stdout bleibt sichtbar fuer
    # cron, damit MAILTO greift; gleichzeitig wird alles in die log-Datei
    # archiviert. Cleanup hat keinen Mail-Wert und bleibt mit reinem Redirect.
    local new_crontab
    new_crontab="$(cat <<EOF
$cleaned
# $marker_start
$mailto_line
# Laravel-Scheduler -- triggert routes/console.php (u.a. Daily-Reset 01:00 Vienna)
* * * * * cd /var/www/lokato/backend && /usr/bin/php artisan schedule:run >> /var/log/lokato/scheduler.log 2>&1
# Log-Audit (Daily / Weekly / Cleanup)
10 6 * * * cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period daily  --config tools/log_audit/config.json 2>&1 | tee -a /var/log/lokato/log-audit.log
20 6 * * 1 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json 2>&1 | tee -a /var/log/lokato/log-audit.log
30 3 * * 0 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py cleanup        --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
# $marker_end
EOF
)"

    # Neue crontab schreiben.
    printf '%s\n' "$new_crontab" | sudo crontab -u www-data -
    ok "Crontab fuer www-data aktualisiert (idempotent — Block zwischen Markern)."
}

# ----- 11) Services finalisieren --------------------------------------------
finalize_services() {
    local php_ver
    php_ver="$(detect_php_version)"

    info "Reload php-fpm + nginx, MQTT-Subscriber starten..."
    sudo systemctl reload "php$php_ver-fpm"
    sudo systemctl reload nginx
    sudo systemctl restart lokato-mqtt
    ok "Alle Services neu geladen."
}

# ----- 12) Health-Check + Summary -------------------------------------------
print_summary() {
    local actual_ip
    actual_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -z "$actual_ip" ]] && actual_ip="$PI_IP"

    cat <<EOF

==============================================================================
  Lokato Production Setup abgeschlossen
==============================================================================

  Erreichbar unter:     http://$actual_ip/
  API-Health:           http://$actual_ip/api/health
  API-Readiness:        http://$actual_ip/api/readiness

  Backend-Code:         $BACKEND_DEPLOY
  Frontend-Build:       $FRONTEND_DEPLOY/dist
  Laravel-Logs:         $BACKEND_DEPLOY/storage/logs/
  php-fpm-Log:          $LOG_DIR/php-fpm.log

  MQTT-Subscriber:      systemctl status lokato-mqtt
  MQTT-Logs:            journalctl -u lokato-mqtt -f

  Bookmark fuer Tablets:        http://$actual_ip/
  Dashboard-Bookmark:           http://$actual_ip/#/dashboard
  Tablet-Bookmark (Raum 1):     http://$actual_ip/#/tablet/1

  -- Was du EINMALIG nach dem ersten Lauf erledigen solltest:
   1) DB-Passwort in $BACKEND_DEPLOY/.env aendern (aktuell: $DB_PASSWORD)
      und MariaDB-User-Passwort entsprechend mit ALTER USER neu setzen.
   2) APP_URL in $BACKEND_DEPLOY/.env auf "http://$actual_ip" pruefen.
   3) Admin-User anlegen (sonst ist /admin nicht erreichbar). Zwei Wege:

      a) Tinker (einmaliger Befehl, beliebige Credentials):
         sudo -u www-data php $BACKEND_DEPLOY/artisan tinker --execute \\
           "App\\\\Models\\\\User::create(['name' => 'Admin', 'email' => 'admin@hort.local', 'password' => bcrypt('DEIN_PASSWORT')]);"

      b) Seeder mit Env-Vars (idempotent — updateOrCreate per E-Mail):
         ADMIN_USER_EMAIL=admin@hort.local ADMIN_USER_PASSWORD=DEIN_PASSWORT \\
           sudo -E -u www-data php $BACKEND_DEPLOY/artisan db:seed \\
           --class=AdminUserSeeder --force

   4) Reboot zum Test, dass alles automatisch hochkommt.

==============================================================================

EOF
}

# ----- Main ------------------------------------------------------------------
info "Lokato Produktions-Setup startet (Pi-IP-Ziel: $PI_IP)"

install_system_packages
configure_network
configure_database
configure_mosquitto
configure_nginx
configure_php_fpm
configure_systemd_mqtt

prepare_deploy_dirs
deploy_backend
deploy_tools
deploy_frontend
configure_cron
finalize_services

print_summary
