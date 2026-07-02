#!/usr/bin/env bash
# =============================================================================
# Lokato — Produktions-Setup fuer Raspberry Pi OS (Bookworm 64-bit empfohlen)
# =============================================================================
# Nativ, kein Docker. Installiert nginx + php-fpm + MariaDB + Mosquitto via apt,
# deployt Backend nach /var/www/lokato/backend, Frontend nach
# /var/www/lokato/frontend/dist, startet den MQTT-Subscriber als systemd-Unit.
#
# Das Skript ist GEFUEHRT: es zeigt am Anfang, was es tun WIRD, fragt vor jedem
# Ueberschreiben nach, erklaert jeden Schritt und sagt bei Fehlern, was zu tun
# ist. Es ist idempotent — beim zweiten Lauf bleiben DB, .env und eigene
# Anpassungen erhalten (ausser du stimmst einem Ueberschreiben aktiv zu).
#
# TIPP: Vor dem ersten Deploy lokal `bash predeploy-check.sh --prod` laufen
# lassen (faengt --no-dev-only-Fehler ab). Nach dem Deploy `bash pi-doctor.sh`
# auf dem Pi (PASS/FAIL ueber den Gesamtzustand).
#
# -----------------------------------------------------------------------------
# Aufruf
# -----------------------------------------------------------------------------
#   Erststart (typisch — statische IP, DB-Pass, Admin + Stammdaten anlegen):
#     PI_IP=192.168.1.50 DB_PASSWORD='einGutesPasswort' \
#     SEED_ADMIN=1 ADMIN_USER_EMAIL=admin@hort.local ADMIN_USER_PASSWORD='...' \
#     SEED_MASTERDATA=1 \
#       ./start-prod-raspi.sh
#
#   Redeploy nach Code-Aenderung (Pakete + Netz stehen schon):
#     INSTALL_DEPS=0 CONFIG_NETWORK=0 ./start-prod-raspi.sh
#
#   Flags:  -y / --yes   alle Rueckfragen automatisch mit JA (nicht-interaktiv)
#           -h / --help  diese Uebersicht
#
# -----------------------------------------------------------------------------
# Konfiguration — ALLE ueber Env-Variablen, alle optional (Default in Klammern).
# Gruppiert danach, wie oft man sie anfasst:
# -----------------------------------------------------------------------------
#   HAEUFIG (Erststart):
#     PI_IP=(192.168.1.100)       Statische Ziel-IP (greift nur bei CONFIG_NETWORK=1)
#     DB_PASSWORD=(changeme)      Passwort des Laravel-DB-Users — UNBEDINGT aendern
#     SEED_ADMIN=(0)              1 = Admin-User am Ende anlegen (sonst /admin leer)
#     ADMIN_USER_EMAIL=(admin@lokato.local)  E-Mail des Admins
#     ADMIN_USER_PASSWORD=()      leer -> Seeder generiert + druckt ein Passwort
#     ADMIN_USER_NAME=(Lokato Admin)         Anzeigename des Admins
#     SEED_MASTERDATA=(0)         1 = Raeume + Devices anlegen (sonst Tablet-404);
#                                 nur wenn rooms leer ist (Seeder nicht idempotent)
#
#   BEI WIEDERHOLTEM LAUF:
#     INSTALL_DEPS=(1)            0 = apt-Block ueberspringen (Pakete schon da)
#     CONFIG_NETWORK=(1)          0 = IP-Konfig ueberspringen (DHCP-IP behalten)
#     ASSUME_YES=(0)              1 = keine Rueckfragen (wie -y)
#
#   SELTEN (Defaults passen meist):
#     DEPLOY_ROOT=(/var/www/lokato)   Ziel fuer Backend + Frontend
#     PI_GATEWAY=(192.168.1.1)        Default-Gateway (nur bei CONFIG_NETWORK=1)
#     PI_DNS=(192.168.1.1 1.1.1.1)    DNS-Server, Space-separiert
#     PI_NETMASK_CIDR=(24)            Netzmaske als CIDR-Praefix
#     DB_NAME=(lokato_db)             DB-Name (muss zur .env passen)
#     DB_USER=(lokato)                DB-User (muss zur .env passen)
#     ALERT_EMAIL=()                  Log-Audit-Cron mailt Anomalien hierhin (MTA noetig)
#
#   Merke: Existiert /var/www/lokato/backend/.env schon, GEWINNT sie — DB_NAME/
#   DB_USER/DB_PASSWORD werden daraus gelesen; ueberschrieben wird nur, wenn du
#   im Lauf ausdruecklich zustimmst (dann wird vorher ein Backup angelegt).
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

# Alle folgenden Werte lassen sich per Env-Var ueberschreiben — die vollstaendige,
# gruppierte Uebersicht (was, wofuer, wie oft man es anfasst) steht im Header oben.

# --- Netzwerk (nur wenn CONFIG_NETWORK=1; sonst bleibt die DHCP-IP) ----------
PI_IP="${PI_IP:-192.168.1.100}"
PI_GATEWAY="${PI_GATEWAY:-192.168.1.1}"
PI_DNS="${PI_DNS:-192.168.1.1 1.1.1.1}"
PI_NETMASK_CIDR="${PI_NETMASK_CIDR:-24}"

# --- Datenbank (muss zur deployten .env passen; existierende .env gewinnt) ---
DB_NAME="${DB_NAME:-lokato_db}"
DB_USER="${DB_USER:-lokato}"
DB_PASSWORD="${DB_PASSWORD:-changeme}"   # UNBEDINGT aendern

# --- Ablauf-Schalter (bei Redeploy meist beide auf 0) -----------------------
INSTALL_DEPS="${INSTALL_DEPS:-1}"        # 0 = apt-Block ueberspringen
CONFIG_NETWORK="${CONFIG_NETWORK:-1}"    # 0 = IP-Konfiguration ueberspringen

# --- Seeding (Erststart; ADMIN_USER_* siehe Header) -------------------------
# SEED_ADMIN=1: Admin-User anlegen (sonst /admin leer). Liest ADMIN_USER_EMAIL/
# _PASSWORD/_NAME; ohne Passwort generiert + druckt der Seeder eins.
SEED_ADMIN="${SEED_ADMIN:-0}"
# SEED_MASTERDATA=1: Raeume + Devices anlegen (sonst Tablet-404). Nur wenn rooms
# leer ist — Seeder sind nicht idempotent. Kein ChildSeeder.
SEED_MASTERDATA="${SEED_MASTERDATA:-0}"

# --- Interaktivitaet --------------------------------------------------------
# 1 = keine Rueckfragen, alle Prompts automatisch "ja" (fuer CI / unattended).
ASSUME_YES="${ASSUME_YES:-0}"

# ----- Argumente -------------------------------------------------------------
print_usage() { sed -n '2,64p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }
for arg in "$@"; do
    case "$arg" in
        -y|--yes)  ASSUME_YES=1 ;;
        -h|--help) print_usage; exit 0 ;;
        *) echo "Unbekanntes Argument: $arg  (siehe --help)" >&2; exit 2 ;;
    esac
done

# ----- Optik / Ausgabe-Helfer ------------------------------------------------
BAR="=============================================================================="
info()    { echo -e "\033[36m==> $*\033[0m"; }
ok()      { echo -e "\033[32m  [OK] $*\033[0m"; }
warn()    { echo -e "\033[33m  [!]  $*\033[0m"; }
explain() { echo -e "\033[90m       $*\033[0m"; }          # gedimmt: Kontext/Erklaerung
hint()    { echo -e "\033[33m       -> $*\033[0m"; }        # konkreter Handlungshinweis
fail()    { echo -e "\033[31m\n[FEHLER] $*\033[0m" >&2; trap - ERR; exit 1; }

STEP_NO=0
CURRENT_STEP="Start"
step() {
    STEP_NO=$((STEP_NO + 1))
    CURRENT_STEP="$1"
    echo
    echo -e "\033[1;36m--- Schritt $STEP_NO: $1 ---\033[0m"
}

# Faengt UNERWARTETE Fehler ab (die nicht ueber fail() laufen) und sagt, wo.
on_error() {
    echo
    echo -e "\033[31m[ABBRUCH] Unerwarteter Fehler in Schritt: ${CURRENT_STEP} (Skript-Zeile $1).\033[0m" >&2
    echo -e "\033[33m          Die Meldung direkt darueber sagt meist, was fehlt. Nichts wurde\033[0m" >&2
    echo -e "\033[33m          teilweise 'versteckt' — nach dem Fix kannst du das Skript einfach\033[0m" >&2
    echo -e "\033[33m          erneut starten (es ist idempotent und macht dort weiter).\033[0m" >&2
}
trap 'on_error $LINENO' ERR

# Maskiert ein Passwort fuer die Anzeige (erstes+letztes Zeichen sichtbar).
mask() {
    local s="$1"
    if   [[ -z "$s" ]];          then echo "(leer)"
    elif [[ ${#s} -le 2 ]];      then echo "****"
    else echo "${s:0:1}****${s: -1}"; fi
}

# confirm <frage> [Y|N]  -> Exit 0 = ja, 1 = nein. Beachtet ASSUME_YES und
# nicht-interaktive Umgebungen (dann zaehlt der Default).
confirm() {
    local prompt="$1" default="${2:-N}" ans hint
    if [[ "$ASSUME_YES" == "1" ]]; then
        echo -e "\033[35m ?? \033[0m$prompt \033[90m[ASSUME_YES -> ja]\033[0m"
        return 0
    fi
    if [[ ! -t 0 ]]; then
        # Kein Terminal (Pipe/Cron) und kein ASSUME_YES -> Default nehmen.
        [[ "$default" == "Y" ]] && return 0 || return 1
    fi
    if [[ "$default" == "Y" ]]; then hint="[J/n]"; else hint="[j/N]"; fi
    read -r -p "$(printf '\033[35m ?? \033[0m%s %s ' "$prompt" "$hint")" ans || ans=""
    ans="${ans:-$default}"
    [[ "$ans" =~ ^[YyJj] ]]
}

# Fuehrt Operationen als www-data aus, mit HOME im Deploy-Pfad damit
# Composer/npm-Caches dort landen (nicht in /root, nicht in /home/pi).
as_www_data() {
    sudo -u www-data \
        HOME="$DEPLOY_ROOT" \
        PATH="/usr/local/bin:/usr/bin:/bin" \
        "$@"
}

# Legt $target aus $example an. Rueckgabe: 0 = frisch angelegt, 1 = war schon da.
# Aufrufer MUESSEN das behandeln (if / || true), sonst bricht `set -e` bei return 1.
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

# ----- 0) Preflight: Repo-Vollstaendigkeit + Platz ---------------------------
#         Laeuft VOR den langsamen Schritten (apt/composer/npm). Faengt
#         "falsches Verzeichnis" und unvollstaendige Checkouts frueh ab.
preflight() {
    step "Preflight — pruefe, ob das Repo vollstaendig und der Ort richtig ist"
    explain "Bevor irgendwas installiert wird, teste ich, dass alle Pflichtdateien da sind."
    [[ -f "$BACKEND_SRC/artisan" ]]       || fail "backend/artisan fehlt — laeuft das Skript im falschen Ordner? (PROJECT_ROOT=$PROJECT_ROOT)"
    [[ -f "$FRONTEND_SRC/package.json" ]] || fail "frontend/package.json fehlt."

    local req f
    req=(
        "$BACKEND_SRC/.env.raspi.example"
        "$FRONTEND_SRC/.env.raspi.example"
        "$DOCKER_DIR/nginx/prod.conf"
        "$DOCKER_DIR/php-fpm/lokato-pool.conf"
        "$DOCKER_DIR/mosquitto/config/mosquitto-pi.conf"
        "$DOCKER_DIR/systemd/lokato-mqtt.service"
        "$DOCKER_DIR/sql/init/01_schema.sql"
    )
    for f in "${req[@]}"; do
        [[ -f "$f" ]] || fail "Pflichtdatei fehlt: $f"
    done

    # Freier Platz auf / — Build/Composer/npm brauchen Luft.
    local free_mb
    free_mb="$(df -Pm / 2>/dev/null | awk 'NR==2 {print $4}')"
    if [[ "$free_mb" =~ ^[0-9]+$ ]] && (( free_mb < 1500 )); then
        warn "Nur ${free_mb} MB frei auf / — Composer/npm-Build koennten knapp werden."
        confirm "Trotzdem fortfahren?" Y || fail "Abgebrochen — schaffe erst Platz frei (z.B. 'sudo apt-get clean')."
    fi
    ok "Repo vollstaendig, Ort stimmt."
}

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
    step "System-Pakete (apt)"
    if [[ "$INSTALL_DEPS" != "1" ]]; then
        explain "INSTALL_DEPS=0 — ich ueberspringe apt (du sagst: Pakete sind schon da)."
        return
    fi
    explain "Installiere nginx, php-fpm + Extensions, MariaDB, Mosquitto, node/npm, rsync ..."
    explain "Das ist der langsamste Teil (mehrere Minuten). Beim Redeploy: INSTALL_DEPS=0."

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
    step "Netzwerk — statische IP"
    if [[ "$CONFIG_NETWORK" != "1" ]]; then
        explain "CONFIG_NETWORK=0 — Netzwerk bleibt unveraendert (der Pi behaelt seine IP)."
        return
    fi

    warn "Ich setze gleich eine STATISCHE IP: $PI_IP/$PI_NETMASK_CIDR (Gateway $PI_GATEWAY)."
    explain "Wenn du GERADE per SSH ueber genau dieses Netz verbunden bist, kann die"
    explain "Verbindung kurz abreissen. Am Monitor/Tastatur direkt am Pi bist du safe."
    if ! confirm "Statische IP jetzt setzen?" Y; then
        warn "Netzwerk uebersprungen (auf deinen Wunsch) — der Pi behaelt seine aktuelle IP."
        return
    fi

    info "Pruefe Netzwerk-Manager..."
    if systemctl is-active --quiet NetworkManager 2>/dev/null; then
        explain "NetworkManager aktiv -> nmcli-Pfad."
        local con
        con="$(nmcli -t -f NAME,DEVICE c show --active | awk -F: '$2!="" && $2!="lo" {print $1; exit}')"
        if [[ -z "$con" ]]; then
            warn "Keine aktive Verbindung gefunden — IP-Setting uebersprungen."
            return
        fi
        info "Setze statische IP auf Connection \"$con\" -> $PI_IP/$PI_NETMASK_CIDR"
        sudo nmcli con mod "$con" \
            ipv4.addresses "$PI_IP/$PI_NETMASK_CIDR" \
            ipv4.gateway "$PI_GATEWAY" \
            ipv4.dns "$PI_DNS" \
            ipv4.method manual
        sudo nmcli con up "$con" || warn "nmcli con up fehlgeschlagen — manueller Reboot ggf. noetig."
        ok "NetworkManager-IP gesetzt."

    elif systemctl is-active --quiet dhcpcd 2>/dev/null; then
        explain "dhcpcd aktiv -> /etc/dhcpcd.conf-Pfad."
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
        warn "Weder NetworkManager noch dhcpcd aktiv — bitte IP manuell konfigurieren."
    fi
}

# ----- 3) MariaDB: DB + User -------------------------------------------------
configure_database() {
    step "Datenbank — MariaDB, DB + User"
    info "MariaDB starten..."
    sudo systemctl enable --now mariadb 2>/dev/null \
        || sudo systemctl enable --now mysql

    # Deployte .env ist die Wahrheit ueber die DB-Credentials — uebernehmen, sonst
    # laufen MariaDB-User-Pass und .env auseinander (migrate: "Access denied").
    local deployed_env="$BACKEND_DEPLOY/.env"
    if [[ -f "$deployed_env" ]]; then
        local v
        v="$(sudo sed -n 's/^DB_DATABASE=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_NAME="$v"
        v="$(sudo sed -n 's/^DB_USERNAME=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_USER="$v"
        v="$(sudo sed -n 's/^DB_PASSWORD=//p' "$deployed_env" | head -n1)"; [[ -n "$v" ]] && DB_PASSWORD="$v"
        explain "Bestehende .env gefunden -> DB-Credentials daraus uebernommen (User '$DB_USER', DB '$DB_NAME')."
    fi

    # User + Datenbank anlegen (idempotent ueber IF NOT EXISTS).
    # Zwei Host-Rows fuer denselben User, bewusst:
    #   @localhost  -> Socket-Verbindungen (z.B. `mysql -h localhost` beim Debuggen)
    #   @127.0.0.1  -> TCP. Laravel + MQTT-Subscriber verbinden per DB_HOST=127.0.0.1
    #                  (TCP). @localhost matcht TCP nur ueber Reverse-DNS und bricht,
    #                  sobald jemand `skip-name-resolve` aktiviert -> darum die IP
    #                  explizit als eigene Row.
    # Beide werden bei JEDEM Lauf auf dasselbe $DB_PASSWORD ge-ALTERt -> kein Drift.
    info "Datenbank \"$DB_NAME\" und User \"$DB_USER\" sicherstellen..."
    sudo mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    ok "DB + User bereit (Passwort: $(mask "$DB_PASSWORD"))."

    # Initial-Schema einspielen, wenn noch keine Tabellen da sind.
    # (Das Initial-SQL ist aus dem Docker-Setup. Idempotent: schon Tabellen da -> skip.)
    local schema="$DOCKER_DIR/sql/init/01_schema.sql"
    if [[ -f "$schema" ]]; then
        local table_count
        table_count="$(sudo mysql -N -B -e "SHOW TABLES FROM \`$DB_NAME\`;" | wc -l)"
        if [[ "$table_count" -eq 0 ]]; then
            info "Initial-Schema importieren ($schema)..."
            sudo mysql "$DB_NAME" < "$schema"
            ok "Schema importiert."
        else
            explain "DB enthaelt bereits $table_count Tabellen — Schema-Import uebersprungen."
        fi
    else
        warn "Schema-Datei nicht gefunden: $schema (kein Initial-Import)."
    fi
}

# ----- 4) Mosquitto ----------------------------------------------------------
configure_mosquitto() {
    step "Mosquitto (MQTT-Broker)"
    # Pi-spezifische Config (apt-Pfade, 0.0.0.0-Listener). Die normale
    # mosquitto.conf nutzt Container-Pfade (/mosquitto/...) und wuerde nativ crashen.
    local pi_conf="$DOCKER_DIR/mosquitto/config/mosquitto-pi.conf"
    if [[ -f "$pi_conf" ]]; then
        sudo install -m 644 "$pi_conf" /etc/mosquitto/conf.d/lokato.conf
        ok "Mosquitto-Config installiert."
    fi
    sudo systemctl enable --now mosquitto
    ok "Mosquitto laeuft."
}

# ----- 5) nginx --------------------------------------------------------------
configure_nginx() {
    step "nginx (Reverse Proxy + statisches Frontend)"
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
    step "php-fpm (PHP-Laufzeit fuer die API)"
    local php_ver
    php_ver="$(detect_php_version)"
    if [[ -z "$php_ver" ]]; then
        fail "Konnte PHP-Version unter /etc/php/ nicht ermitteln — ist php-fpm installiert? (INSTALL_DEPS=1)"
    fi
    info "php-fpm Pool fuer PHP $php_ver einspielen..."

    sudo install -m 644 \
        "$DOCKER_DIR/php-fpm/lokato-pool.conf" \
        "/etc/php/$php_ver/fpm/pool.d/lokato.conf"

    # Default-www-Pool deaktivieren (Verwirrung minimieren).
    if [[ -f "/etc/php/$php_ver/fpm/pool.d/www.conf" ]]; then
        sudo mv "/etc/php/$php_ver/fpm/pool.d/www.conf" \
                "/etc/php/$php_ver/fpm/pool.d/www.conf.disabled"
        explain "Default-www-Pool nach www.conf.disabled verschoben."
    fi

    sudo mkdir -p "$LOG_DIR"
    sudo chown www-data:www-data "$LOG_DIR"

    sudo systemctl enable "php$php_ver-fpm"
    sudo systemctl restart "php$php_ver-fpm"
    ok "php$php_ver-fpm laeuft."
}

# ----- 7) systemd-Unit fuer MQTT-Subscriber ---------------------------------
configure_systemd_mqtt() {
    step "systemd-Unit fuer den MQTT-Subscriber"
    sudo install -m 644 \
        "$DOCKER_DIR/systemd/lokato-mqtt.service" \
        /etc/systemd/system/lokato-mqtt.service
    sudo systemctl daemon-reload
    sudo systemctl enable lokato-mqtt
    explain "Start erfolgt erst NACH dem Backend-Deploy (siehe letzter Schritt)."
    ok "systemd-Unit installiert."
}

# ----- 8) Deploy: Verzeichnisse + Permissions -------------------------------
prepare_deploy_dirs() {
    step "Deploy-Verzeichnisse anlegen ($DEPLOY_ROOT)"
    sudo mkdir -p "$BACKEND_DEPLOY" "$FRONTEND_DEPLOY/dist"
    sudo chown -R www-data:www-data "$DEPLOY_ROOT"
    ok "Verzeichnisse bereit."
}

# Kopiert das Backend-Template nach .env und spiegelt die Runtime-Werte hinein
# (DB_PASSWORD -> synchron mit MariaDB, APP_URL -> echte Pi-IP). Ueberschreibt
# bedingungslos — der Aufrufer klaert Existenz/Consent VORHER.
install_backend_env() {
    local target="$BACKEND_DEPLOY/.env"
    sudo cp "$BACKEND_SRC/.env.raspi.example" "$target"
    local _ip _pw_esc
    _ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -z "$_ip" ]] && _ip="$PI_IP"
    # sed-Sonderzeichen im Passwort escapen (\ / & |).
    _pw_esc="$(printf '%s' "$DB_PASSWORD" | sed -e 's/[\\/&|]/\\&/g')"
    sudo sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$_pw_esc|" "$target"
    sudo sed -i "s|^APP_URL=.*|APP_URL=http://$_ip|" "$target"
    ok ".env gesetzt: DB_PASSWORD synchron mit MariaDB, APP_URL=http://$_ip"
}

# ----- 9) Backend deployen ---------------------------------------------------
deploy_backend() {
    step "Backend deployen (Code syncen, vendor + Cache aufraeumen, migrieren)"
    info "Code nach $BACKEND_DEPLOY syncen (rsync, ohne vendor/.env/Caches)..."
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
    explain "rsync --delete raeumt verwaiste Dateien im Ziel weg (z.B. alte Config eines"
    explain "frueheren Branches) — nur vendor/ und .env sind bewusst ausgenommen."

    # Storage-Struktur sicherstellen (rsync hat sie geleert).
    sudo install -d -o www-data -g www-data -m 775 \
        "$BACKEND_DEPLOY/storage/logs" \
        "$BACKEND_DEPLOY/storage/framework/cache" \
        "$BACKEND_DEPLOY/storage/framework/sessions" \
        "$BACKEND_DEPLOY/storage/framework/views" \
        "$BACKEND_DEPLOY/storage/app/public/children" \
        "$BACKEND_DEPLOY/bootstrap/cache"

    # --- .env: anlegen (fehlt) oder auf Wunsch neu erzeugen (mit Backup) -----
    local env_target="$BACKEND_DEPLOY/.env"
    if [[ ! -f "$env_target" ]]; then
        explain "Noch keine .env im Deploy — ich lege sie aus dem Pi-Template an."
        install_backend_env
    else
        explain "Es existiert bereits eine .env: $env_target (enthaelt deine Werte)."
        if confirm "Diese .env aus dem Template NEU erzeugen? (Backup wird angelegt, eigene Werte wie Passwort/IP gehen sonst verloren)" N; then
            local bak="$env_target.bak.$(date +%Y%m%d-%H%M%S)"
            sudo cp "$env_target" "$bak"
            install_backend_env
            ok "Alte .env gesichert unter $bak."
        else
            explain "Bestehende .env bleibt unveraendert."
        fi
    fi

    # --- Cache zuruecksetzen -------------------------------------------------
    # Stale bootstrap-cache loeschen, sonst 'BootServiceProvider not found', wenn
    # das Cache-File noch dev-Pakete referenziert.
    info "Bootstrap-Cache zuruecksetzen (verhindert Referenzen auf alte Pakete)..."
    sudo find "$BACKEND_DEPLOY/bootstrap/cache" -maxdepth 1 -name "*.php" -delete

    sudo chown -R www-data:www-data "$BACKEND_DEPLOY"

    # --- vendor/ konsistent machen, BEVOR composer install laeuft -----------
    # vendor/ ist oben vom rsync ausgeschlossen (--exclude=vendor/) und ueber-
    # lebt daher Branch-Wechsel und Redeploys unangetastet. Zwei Stale-Faelle
    # wuerden sonst den Deploy hart abbrechen (composer install -> post-autoload-
    # dump -> package:discover crasht unter `set -e`):
    #
    #   (a) vendor/ fehlt oder ist unvollstaendig (abgebrochener Install, kein
    #       vendor/composer/installed.json). Da ist nichts zu retten:
    #       komplett verwerfen, composer baut sauber von Grund auf neu.
    #   (b) vendor/ ist intakt, aber der optimierte Classmap zeigt noch auf
    #       inzwischen entfernte/umbenannte Klassen (Paket per Branch-Wechsel
    #       raus, z.B. l5-swagger). Das laesst den pre-package-uninstall-Hook
    #       von `composer install` crashen. Classmap OHNE Scripts neu erzeugen
    #       raeumt die toten Eintraege weg, BEVOR composer deinstalliert.
    if [[ ! -d "$BACKEND_DEPLOY/vendor" || ! -f "$BACKEND_DEPLOY/vendor/composer/installed.json" ]]; then
        warn "vendor/ fehlt oder ist unvollstaendig — wird verworfen, composer baut sauber neu."
        sudo rm -rf "$BACKEND_DEPLOY/vendor"
    else
        info "Autoload defensiv neu generieren (raeumt stale Classmap-Eintraege)..."
        as_www_data composer dump-autoload \
            --working-dir="$BACKEND_DEPLOY" --no-scripts -o --no-interaction 2>/dev/null || true
    fi

    info "Composer install (--no-dev) im Deploy-Verzeichnis (kann 1-2 min dauern)..."
    as_www_data composer install \
        --working-dir="$BACKEND_DEPLOY" \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist
    ok "PHP-Abhaengigkeiten installiert."

    # APP_KEY generieren, wenn leer.
    if ! sudo grep -qE '^APP_KEY=base64:' "$BACKEND_DEPLOY/.env"; then
        info "APP_KEY generieren..."
        as_www_data php "$BACKEND_DEPLOY/artisan" key:generate --force
    fi

    info "Laravel-Config leeren + DB-Login pruefen + migrieren..."
    as_www_data php "$BACKEND_DEPLOY/artisan" config:clear

    # DB-Login mit den .env-Credentials VOR migrate testen — gibt bei Drift einen
    # Klartext-Hinweis statt des rohen "SQLSTATE ... Access denied".
    local _du _dp _dd _dh
    _du="$(sudo sed -n 's/^DB_USERNAME=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dp="$(sudo sed -n 's/^DB_PASSWORD=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dd="$(sudo sed -n 's/^DB_DATABASE=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dh="$(sudo sed -n 's/^DB_HOST=//p'     "$BACKEND_DEPLOY/.env" | head -n1)"
    if ! mysql -h "${_dh:-127.0.0.1}" -u "$_du" -p"$_dp" \
            -e "USE \`$_dd\`; SELECT 1;" >/dev/null 2>&1; then
        fail "DB-Login mit den .env-Credentials (User '$_du', DB '$_dd', Host '${_dh:-127.0.0.1}') fehlgeschlagen.
       Zwei moegliche Ursachen:
       1) .env-DB_PASSWORD passt nicht zum MariaDB-User. Fix:
            sudo mysql -e \"ALTER USER '$_du'@'localhost'  IDENTIFIED BY '<.env-DB_PASSWORD>';
                            ALTER USER '$_du'@'127.0.0.1' IDENTIFIED BY '<.env-DB_PASSWORD>'; FLUSH PRIVILEGES;\"
          oder DB_PASSWORD in $BACKEND_DEPLOY/.env angleichen, dann erneut starten.
       2) Host-Matching: der User existiert nur als '@localhost', aber Laravel
          verbindet per TCP (DB_HOST=127.0.0.1). Bei aktivem 'skip-name-resolve'
          matcht '@localhost' TCP nicht. Fix: obige '@127.0.0.1'-Row anlegen
          (macht dieses Skript in configure_database bereits) oder Skript erneut laufen lassen."
    fi
    ok "DB-Login ok (.env <-> MariaDB konsistent)."

    as_www_data php "$BACKEND_DEPLOY/artisan" migrate --force
    as_www_data php "$BACKEND_DEPLOY/artisan" config:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" route:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" view:cache
    ok "Migriert + Config/Routes/Views gecached."

    # storage:link — public/storage -> storage/app/public. Damit nginx unter
    # /storage/children/<id>.jpg die hochgeladenen Foto-Dateien findet.
    if [[ ! -L "$BACKEND_DEPLOY/public/storage" ]]; then
        as_www_data php "$BACKEND_DEPLOY/artisan" storage:link
    fi

    # --- Optional: Admin-User seeden (SEED_ADMIN=1) — idempotent (updateOrCreate) ---
    # Bewusst KEIN `artisan tinker` mehr: auf dem Pi (headless, kein PsySH/readline-TTY)
    # ist der Tinker-Einzeiler unzuverlaessig. Der Seeder ist der robuste Weg.
    if [[ "$SEED_ADMIN" == "1" ]]; then
        info "Admin-User anlegen (SEED_ADMIN=1)..."
        as_www_data env \
            ADMIN_USER_EMAIL="${ADMIN_USER_EMAIL:-admin@lokato.local}" \
            ADMIN_USER_NAME="${ADMIN_USER_NAME:-Lokato Admin}" \
            ADMIN_USER_PASSWORD="${ADMIN_USER_PASSWORD:-}" \
            php "$BACKEND_DEPLOY/artisan" db:seed --class=AdminUserSeeder --force
        ok "Admin-User geseedet (E-Mail: ${ADMIN_USER_EMAIL:-admin@lokato.local})."
        [[ -z "${ADMIN_USER_PASSWORD:-}" ]] && hint "Kein ADMIN_USER_PASSWORD gesetzt — der Seeder hat oben ein Passwort GEDRUCKT. Notieren!"
    else
        explain "SEED_ADMIN=0 — kein Admin-User angelegt. /admin bleibt leer (siehe Zusammenfassung)."
    fi

    # --- Optional: Stammdaten seeden (SEED_MASTERDATA=1) — nur wenn rooms leer ---
    # (Seeder nicht idempotent). DeviceSeeder nach RoomSeeder (sucht Raeume per Name).
    if [[ "$SEED_MASTERDATA" == "1" ]]; then
        local _rooms_count
        _rooms_count="$(mysql -h "${_dh:-127.0.0.1}" -u "$_du" -p"$_dp" -N -B \
            -e "SELECT COUNT(*) FROM \`$_dd\`.rooms;" 2>/dev/null || echo "")"
        if [[ "$_rooms_count" == "0" ]]; then
            info "Stammdaten anlegen (SEED_MASTERDATA=1, rooms-Tabelle leer)..."
            as_www_data php "$BACKEND_DEPLOY/artisan" db:seed --class=RoomSeeder   --force
            as_www_data php "$BACKEND_DEPLOY/artisan" db:seed --class=DeviceSeeder --force
            ok "Stammdaten geseedet (Raeume + Devices)."
        elif [[ "$_rooms_count" =~ ^[0-9]+$ ]]; then
            explain "rooms enthaelt bereits $_rooms_count Eintraege — Stammdaten-Seed uebersprungen (kein Duplikat)."
        else
            warn "rooms-Count nicht lesbar — Stammdaten-Seed uebersprungen."
        fi
    fi

    ok "Backend deployt."
}

# ----- 10a) tools/log_audit deployen (fuer Cron-Audits) ----------------------
deploy_tools() {
    step "Log-Audit-Tool deployen"
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
    step "Frontend bauen + deployen (Vite-Build -> statisches dist/)"
    # .env-Pi-Template sicherstellen — der Build liest daraus VITE_API_BASE_URL.
    # `|| true`, weil ensure_file_from_example bei vorhandener .env 1 zurueckgibt.
    ensure_file_from_example "$FRONTEND_SRC/.env" "$FRONTEND_SRC/.env.raspi.example" || true

    info "npm-Abhaengigkeiten + Build ($FRONTEND_SRC)..."
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
    step "Cron-Jobs (Laravel-Scheduler + Log-Audit)"

    # Marker-Pattern bewusst ohne Shell-Sonderzeichen, damit sed sauber loescht.
    local marker_start=">>> lokato managed cron block -- do not edit between markers >>>"
    local marker_end="<<< lokato managed cron block <<<"

    sudo mkdir -p "$LOG_DIR"
    sudo chown www-data:www-data "$LOG_DIR"

    # Bestehende crontab lesen (leer ist OK), Block zwischen Markern entfernen.
    local existing
    existing="$(sudo crontab -l -u www-data 2>/dev/null || true)"
    local cleaned
    cleaned="$(printf '%s\n' "$existing" | sed "/$marker_start/,/$marker_end/d")"

    # MAILTO nur wenn ALERT_EMAIL gesetzt ist. Cron mailt dann bei stdout-Ausgabe
    # (also bei Anomalien). Lokaler MTA noetig (z.B. msmtp-mta).
    local mailto_line=""
    if [[ -n "${ALERT_EMAIL:-}" ]]; then
        mailto_line="MAILTO=$ALERT_EMAIL"
        explain "ALERT_EMAIL gesetzt -- cron mailt Anomalien an $ALERT_EMAIL."
    fi

    # Audit-Befehle nutzen "tee -a" statt ">> 2>&1": stdout bleibt fuer cron sichtbar
    # (MAILTO greift), gleichzeitig wird alles in die Log-Datei archiviert.
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

    printf '%s\n' "$new_crontab" | sudo crontab -u www-data -
    ok "Crontab fuer www-data aktualisiert (idempotent — Block zwischen Markern)."
}

# ----- 11) Services finalisieren --------------------------------------------
finalize_services() {
    step "Services neu laden + MQTT-Subscriber starten"
    local php_ver
    php_ver="$(detect_php_version)"

    sudo systemctl reload "php$php_ver-fpm"
    sudo systemctl reload nginx
    sudo systemctl restart lokato-mqtt
    ok "php-fpm + nginx neu geladen, lokato-mqtt gestartet."
}

# ----- Plan-Anzeige am Anfang (Transparenz) ---------------------------------
print_plan() {
    local net_effect db_pw seed_a seed_m mode
    if [[ "$CONFIG_NETWORK" == "1" ]]; then net_effect="$PI_IP/$PI_NETMASK_CIDR wird gesetzt"; else net_effect="unveraendert (DHCP-IP behalten)"; fi
    db_pw="$(mask "$DB_PASSWORD")"
    if [[ "$SEED_ADMIN" == "1" ]]; then seed_a="JA  -> ${ADMIN_USER_EMAIL:-admin@lokato.local}"; else seed_a="nein (SEED_ADMIN=1 zum Anlegen)"; fi
    if [[ "$SEED_MASTERDATA" == "1" ]]; then seed_m="JA  (Raeume + Devices)"; else seed_m="nein (SEED_MASTERDATA=1 zum Anlegen)"; fi
    if [[ "$ASSUME_YES" == "1" ]]; then mode="nicht-interaktiv (alle Rueckfragen = ja)"; else mode="interaktiv (fragt vor jedem Ueberschreiben)"; fi

    echo
    echo -e "\033[1;36m$BAR\033[0m"
    echo -e "\033[1;36m  Lokato — Produktions-Setup fuer den Raspberry Pi\033[0m"
    echo -e "\033[1;36m$BAR\033[0m"
    echo
    echo -e "\033[1mDAS passiert mit DIESEN Einstellungen\033[0m (alle per Env-Var aenderbar, siehe Datei-Kopf):"
    echo
    printf '  %-24s %s\n' "Quelle (Code):"       "$PROJECT_ROOT"
    printf '  %-24s %s\n' "Ziel (Deploy):"       "$DEPLOY_ROOT"
    printf '  %-24s %s\n' "Statische IP:"        "$net_effect"
    if [[ "$INSTALL_DEPS" == "1" ]]; then
        printf '  %-24s %s\n' "System-Pakete (apt):" "werden installiert  (INSTALL_DEPS=0 zum Ueberspringen)"
    else
        printf '  %-24s %s\n' "System-Pakete (apt):" "uebersprungen  (INSTALL_DEPS=1 zum Installieren)"
    fi
    printf '  %-24s %s\n' "Datenbank:"           "$DB_NAME / User $DB_USER / Passwort $db_pw"
    printf '  %-24s %s\n' "Admin-User anlegen:"  "$seed_a"
    printf '  %-24s %s\n' "Stammdaten anlegen:"  "$seed_m"
    printf '  %-24s %s\n' "Modus:"               "$mode"
    echo
    echo -e "\033[1mAblauf:\033[0m Preflight -> apt -> Netzwerk -> DB -> Mosquitto -> nginx -> php-fpm ->"
    echo -e "        systemd -> Backend (vendor/Cache-Reset falls noetig, migrieren) -> Tools ->"
    echo -e "        Frontend-Build -> Cron -> Services -> Zusammenfassung."
    echo
    echo -e "\033[32mSicher:\033[0m Bestehende .env und DB werden NICHT ohne Rueckfrage ueberschrieben."
    echo -e "        Das Skript ist idempotent — du kannst es jederzeit erneut laufen lassen."
    echo
}

# ----- Health-Check + Summary -----------------------------------------------
print_summary() {
    local actual_ip
    actual_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -z "$actual_ip" ]] && actual_ip="$PI_IP"

    cat <<EOF

$BAR
  Lokato Production Setup abgeschlossen
$BAR

  Erreichbar unter:     http://$actual_ip/
  API-Health:           http://$actual_ip/api/health
  API-Readiness:        http://$actual_ip/api/readiness

  Backend-Code:         $BACKEND_DEPLOY
  Runtime-.env:         $BACKEND_DEPLOY/.env   (ansehen: sudo cat, editieren: sudo nano)
  Frontend-Build:       $FRONTEND_DEPLOY/dist
  Laravel-Logs:         $BACKEND_DEPLOY/storage/logs/
  php-fpm-Log:          $LOG_DIR/php-fpm.log

  MQTT-Subscriber:      systemctl status lokato-mqtt
  MQTT-Logs:            journalctl -u lokato-mqtt -f
  Healthcheck (alles):  bash $PROJECT_ROOT/pi-doctor.sh

  Bookmark fuer Tablets:        http://$actual_ip/
  Dashboard-Bookmark:           http://$actual_ip/#/dashboard
  Tablet-Bookmark (Raum 1):     http://$actual_ip/#/tablet/1

  -- Naechste Schritte / Kontrolle:
   1) DB-Passwort in $BACKEND_DEPLOY/.env ist aktuell: $(mask "$DB_PASSWORD")
      -> aendern? .env editieren UND MariaDB-User angleichen:
         sudo mysql -e "ALTER USER '$DB_USER'@'localhost'  IDENTIFIED BY '<neu>';
                        ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '<neu>'; FLUSH PRIVILEGES;"
   2) APP_URL in $BACKEND_DEPLOY/.env sollte http://$actual_ip sein.
   3) Admin-User (fuer /admin) — falls NICHT mit SEED_ADMIN=1 gestartet:
         ADMIN_USER_EMAIL=admin@hort.local ADMIN_USER_PASSWORD=DEIN_PASSWORT \\
           sudo -E -u www-data php $BACKEND_DEPLOY/artisan db:seed \\
           --class=AdminUserSeeder --force
      (Seeder ist idempotent — updateOrCreate per E-Mail. KEIN tinker noetig.)
   4) Stammdaten (sonst Tablet-404) — falls NICHT mit SEED_MASTERDATA=1 gestartet:
         sudo -u www-data php $BACKEND_DEPLOY/artisan db:seed --class=RoomSeeder   --force
         sudo -u www-data php $BACKEND_DEPLOY/artisan db:seed --class=DeviceSeeder --force
      (NICHT idempotent — nur auf leeren Tabellen ausfuehren.)
   5) Reboot zum Test, dass alles automatisch hochkommt.

  -> Voller Gesundheits-Check jetzt:   bash $PROJECT_ROOT/pi-doctor.sh

$BAR

EOF
}

# ----- Main ------------------------------------------------------------------
# 1) Nicht als root (das Skript eskaliert punktuell per sudo).
if [[ $EUID -eq 0 ]]; then
    fail "Bitte NICHT als root aufrufen — das Skript eskaliert selbst per sudo, wo noetig."
fi

# 2) Plan zeigen + Bestaetigung einholen, BEVOR irgendwas passiert.
print_plan
if ! confirm "Mit genau diesen Einstellungen fortfahren?" Y; then
    info "Abgebrochen — es wurde nichts geaendert."
    info "Passe die Env-Variablen an (siehe Datei-Kopf) und starte erneut."
    exit 0
fi

# 3) sudo aufwaermen (einmal Passwort), danach laufen die Schritte durch.
if ! sudo -n true 2>/dev/null; then
    info "sudo benoetigt — du wirst einmal nach deinem Passwort gefragt."
    sudo -v || fail "sudo verweigert."
fi

info "Los geht's — bei einem Fehler sagt das Skript, was fehlt, und du kannst neu starten."

preflight
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
