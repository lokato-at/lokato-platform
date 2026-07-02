#!/usr/bin/env bash
# =============================================================================
# Lokato — Produktions-Setup fuer Raspberry Pi OS (Bookworm 64-bit empfohlen)
# =============================================================================
# Nativ, kein Docker. Installiert nginx + php-fpm + MariaDB + Mosquitto (apt),
# deployt Backend nach /var/www/lokato/backend, Frontend nach .../frontend/dist,
# startet den MQTT-Subscriber als systemd-Unit.
#
# Gefuehrt: zeigt am Anfang, was passiert, fragt vor jedem Ueberschreiben, und
# sagt bei Fehlern, was zu tun ist. Idempotent — mehrfach ausfuehrbar.
#
# Statische IP setzt ein SEPARATES Skript (Einmal-Setup, SSH-Abriss-Risiko):
#   PI_IP=192.168.1.50 ./setup-network-raspi.sh
#
# TIPP: vor dem ersten Deploy in Dev `bash predeploy-check.sh --prod`,
#       nach dem Deploy `bash pi-doctor.sh` auf dem Pi.
#
# Aufruf:
#   Erststart:  DB_PASSWORD='...' SEED_ADMIN=1 ADMIN_USER_PASSWORD='...' \
#               SEED_MASTERDATA=1 ./start-prod-raspi.sh
#   Redeploy:   INSTALL_DEPS=0 ./start-prod-raspi.sh
#   Flags:      -y/--yes = alle Rueckfragen automatisch ja,  -h/--help
#
# Env-Variablen (Default) — nach "wie oft man sie anfasst":
#   HAEUFIG:  DB_PASSWORD=(changeme)   Passwort des DB-Users — UNBEDINGT aendern
#             SEED_ADMIN=(0)           1 = Admin-User anlegen (sonst /admin leer)
#             ADMIN_USER_EMAIL=(admin@lokato.local) / ADMIN_USER_PASSWORD=() /
#             ADMIN_USER_NAME=(Lokato Admin)
#             SEED_MASTERDATA=(0)      1 = Raeume+Devices anlegen (sonst Tablet-404)
#   REDEPLOY: INSTALL_DEPS=(1)         0 = apt-Block ueberspringen
#             SETUP_LOG_AUDIT=(1)      0 = Log-Audit-Tool + dessen Cron weglassen
#   SELTEN:   DEPLOY_ROOT=(/var/www/lokato) / PI_IP=(192.168.1.100, nur APP_URL-
#             Fallback) / DB_NAME=(lokato_db) / DB_USER=(lokato) /
#             ALERT_EMAIL=() (Log-Audit-Mails) / ASSUME_YES=(0) / RESET_ENV=(0)
#
#   .env-Sicherheit: existiert /var/www/lokato/backend/.env, GEWINNT sie (DB-
#   Creds werden daraus gelesen). Ueberschrieben wird sie NUR bei interaktivem
#   Ja ODER RESET_ENV=1 (dann mit Backup) — blankes -y fasst sie nie an.
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

PI_IP="${PI_IP:-192.168.1.100}"          # nur Fallback fuer APP_URL, falls hostname -I leer

DB_NAME="${DB_NAME:-lokato_db}"
DB_USER="${DB_USER:-lokato}"
DB_PASSWORD="${DB_PASSWORD:-changeme}"   # UNBEDINGT aendern

INSTALL_DEPS="${INSTALL_DEPS:-1}"        # 0 = apt-Block ueberspringen
SETUP_LOG_AUDIT="${SETUP_LOG_AUDIT:-1}"  # 0 = Log-Audit-Tool + Cron weglassen

SEED_ADMIN="${SEED_ADMIN:-0}"            # 1 = Admin-User seeden
SEED_MASTERDATA="${SEED_MASTERDATA:-0}"  # 1 = Raeume+Devices seeden (nur wenn leer)

ASSUME_YES="${ASSUME_YES:-0}"            # 1 = keine Rueckfragen (wie -y)
RESET_ENV="${RESET_ENV:-0}"              # 1 = bestehende .env aus Template neu erzeugen

# ----- Argumente -------------------------------------------------------------
print_usage() { awk 'NR==1{next} /^set -Eeuo pipefail/{exit} {sub(/^# ?/,""); print}' "${BASH_SOURCE[0]}"; }
for arg in "$@"; do
    case "$arg" in
        -y|--yes)  ASSUME_YES=1 ;;
        -h|--help) print_usage; exit 0 ;;
        *) echo "Unbekanntes Argument: $arg  (siehe --help)" >&2; exit 2 ;;
    esac
done

# ----- Ausgabe-Helfer --------------------------------------------------------
BAR="=============================================================================="
info()    { echo -e "\033[36m==> $*\033[0m"; }
ok()      { echo -e "\033[32m  [OK] $*\033[0m"; }
warn()    { echo -e "\033[33m  [!]  $*\033[0m"; }
explain() { echo -e "\033[90m       $*\033[0m"; }
hint()    { echo -e "\033[33m       -> $*\033[0m"; }
fail()    { echo -e "\033[31m\n[FEHLER] $*\033[0m" >&2; trap - ERR; exit 1; }

STEP_NO=0
CURRENT_STEP="Start"
step() {
    STEP_NO=$((STEP_NO + 1)); CURRENT_STEP="$1"
    echo; echo -e "\033[1;36m--- Schritt $STEP_NO: $1 ---\033[0m"
}

# Faengt UNERWARTETE Fehler (die nicht ueber fail() laufen) und sagt, wo.
on_error() {
    echo
    echo -e "\033[31m[ABBRUCH] Unerwarteter Fehler in: ${CURRENT_STEP} (Skript-Zeile $1).\033[0m" >&2
    echo -e "\033[33m          Meldung darueber sagt meist, was fehlt. Nach dem Fix Skript einfach\033[0m" >&2
    echo -e "\033[33m          erneut starten (idempotent).\033[0m" >&2
}
trap 'on_error $LINENO' ERR

# Maskiert ein Passwort fuer die Anzeige.
mask() {
    local s="$1"
    if [[ -z "$s" ]]; then echo "(leer)"
    elif [[ ${#s} -le 2 ]]; then echo "****"
    else echo "${s:0:1}****${s: -1}"; fi
}

# confirm <frage> [Y|N] -> 0=ja, 1=nein. Beachtet ASSUME_YES + nicht-interaktiv.
confirm() {
    local prompt="$1" default="${2:-N}" ans h
    [[ "$ASSUME_YES" == "1" ]] && { echo -e "\033[35m ?? \033[0m$prompt \033[90m[-y -> ja]\033[0m"; return 0; }
    [[ ! -t 0 ]] && { [[ "$default" == "Y" ]] && return 0 || return 1; }
    if [[ "$default" == "Y" ]]; then h="[J/n]"; else h="[j/N]"; fi
    read -r -p "$(printf '\033[35m ?? \033[0m%s %s ' "$prompt" "$h")" ans || ans=""
    [[ "${ans:-$default}" =~ ^[YyJj] ]]
}

# Als www-data ausfuehren, HOME im Deploy-Pfad (Composer/npm-Cache landet dort).
as_www_data() {
    sudo -u www-data HOME="$DEPLOY_ROOT" PATH="/usr/local/bin:/usr/bin:/bin" "$@"
}

# Legt $target aus $example an. 0 = angelegt, 1 = war schon da (Aufrufer: || true).
ensure_file_from_example() {
    local target="$1" example="$2"
    [[ -f "$target" ]] && return 1
    [[ -f "$example" ]] || fail "Template fehlt: $example"
    sudo cp "$example" "$target"
    ok "$(basename "$target") aus $(basename "$example") angelegt."
}

detect_php_version() {
    # Bookworm: php8.2. Metapaket "php" zieht die Distro-Default-Version.
    [[ -d /etc/php ]] && ls /etc/php/ 2>/dev/null | sort -V | tail -n 1 || echo ""
}

# Kopiert das Backend-Template nach .env und spiegelt DB_PASSWORD + APP_URL
# hinein. Ueberschreibt bedingungslos — Aufrufer klaert Existenz/Consent VORHER.
install_backend_env() {
    local target="$BACKEND_DEPLOY/.env" _ip _pw
    sudo cp "$BACKEND_SRC/.env.raspi.example" "$target"
    _ip="$(hostname -I 2>/dev/null | awk '{print $1}')"; [[ -z "$_ip" ]] && _ip="$PI_IP"
    _pw="$(printf '%s' "$DB_PASSWORD" | sed -e 's/[\\/&|]/\\&/g')"   # sed-Sonderzeichen escapen
    sudo sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$_pw|" "$target"
    sudo sed -i "s|^APP_URL=.*|APP_URL=http://$_ip|" "$target"
    ok ".env gesetzt: DB_PASSWORD synchron mit MariaDB, APP_URL=http://$_ip"
}

# Liest DB-Credentials aus einer vorhandenen Deploy-.env in die Globals
# (die .env ist die Wahrheit, sobald sie existiert — auch nach User-Edit).
load_deployed_env_values() {
    local f="$BACKEND_DEPLOY/.env" v
    [[ -f "$f" ]] || return 0
    v="$(sudo sed -n 's/^DB_DATABASE=//p' "$f" | head -n1)"; [[ -n "$v" ]] && DB_NAME="$v"
    v="$(sudo sed -n 's/^DB_USERNAME=//p' "$f" | head -n1)"; [[ -n "$v" ]] && DB_USER="$v"
    v="$(sudo sed -n 's/^DB_PASSWORD=//p' "$f" | head -n1)"; [[ -n "$v" ]] && DB_PASSWORD="$v"
}

# ----- Plan-Anzeige ----------------------------------------------------------
print_plan() {
    local db_pw seed_a seed_m audit mode
    db_pw="$(mask "$DB_PASSWORD")"
    [[ "$SEED_ADMIN"      == "1" ]] && seed_a="JA -> ${ADMIN_USER_EMAIL:-admin@lokato.local}" || seed_a="nein (SEED_ADMIN=1)"
    [[ "$SEED_MASTERDATA" == "1" ]] && seed_m="JA (Raeume + Devices)"                          || seed_m="nein (SEED_MASTERDATA=1)"
    [[ "$SETUP_LOG_AUDIT" == "1" ]] && audit="JA"                                              || audit="nein (SETUP_LOG_AUDIT=1)"
    [[ "$ASSUME_YES"      == "1" ]] && mode="nicht-interaktiv (-y)"                             || mode="interaktiv"

    echo
    echo -e "\033[1;36m$BAR\n  Lokato — Produktions-Setup fuer den Raspberry Pi\n$BAR\033[0m"
    echo
    echo -e "\033[1mDAS passiert mit DIESEN Einstellungen\033[0m (per Env-Var aenderbar, siehe Datei-Kopf):"
    echo
    printf '  %-22s %s\n' "Ziel (Deploy):"      "$DEPLOY_ROOT"
    [[ "$INSTALL_DEPS" == "1" ]] \
        && printf '  %-22s %s\n' "System-Pakete (apt):" "werden installiert  (INSTALL_DEPS=0 = ueberspringen)" \
        || printf '  %-22s %s\n' "System-Pakete (apt):" "uebersprungen  (INSTALL_DEPS=1 = installieren)"
    printf '  %-22s %s\n' "Datenbank:"          "$DB_NAME / User $DB_USER / Passwort $db_pw"
    printf '  %-22s %s\n' "Admin-User anlegen:" "$seed_a"
    printf '  %-22s %s\n' "Stammdaten anlegen:" "$seed_m"
    printf '  %-22s %s\n' "Log-Audit einrichten:" "$audit"
    printf '  %-22s %s\n' "Modus:"              "$mode"
    echo
    echo -e "\033[1mAblauf:\033[0m Preflight -> apt -> .env-Review -> DB -> Mosquitto -> nginx ->"
    echo -e "        php-fpm -> systemd -> Backend -> Frontend -> Cron -> Services."
    echo -e "\033[90m        (Statische IP NICHT hier — separat via ./setup-network-raspi.sh)\033[0m"
    echo
    echo -e "\033[32mSicher:\033[0m Beim Schritt '.env-Review' kannst du die Config editieren — u.a."
    echo -e "        DB_PASSWORD ($db_pw) — BEVOR sie in MariaDB uebernommen wird. Bestehende"
    echo -e "        .env/DB werden nie ohne Rueckfrage ueberschrieben. Idempotent."
    echo
}

# ----- 0) Preflight ----------------------------------------------------------
preflight() {
    step "Preflight — Repo vollstaendig, Ort richtig, genug Platz?"
    [[ -f "$BACKEND_SRC/artisan" ]]       || fail "backend/artisan fehlt — falscher Ordner? (PROJECT_ROOT=$PROJECT_ROOT)"
    [[ -f "$FRONTEND_SRC/package.json" ]] || fail "frontend/package.json fehlt."

    local req f
    req=(
        "$BACKEND_SRC/.env.raspi.example"
        "$FRONTEND_SRC/.env.raspi.example"
        "$DOCKER_DIR/nginx/prod.conf"
        "$DOCKER_DIR/php-fpm/lokato-pool.conf"
        "$DOCKER_DIR/mosquitto/config/mosquitto-pi.conf"
        "$DOCKER_DIR/systemd/lokato-mqtt.service"
    )
    for f in "${req[@]}"; do [[ -f "$f" ]] || fail "Pflichtdatei fehlt: $f"; done

    local free_mb
    free_mb="$(df -Pm / 2>/dev/null | awk 'NR==2 {print $4}')"
    if [[ "$free_mb" =~ ^[0-9]+$ ]] && (( free_mb < 1500 )); then
        warn "Nur ${free_mb} MB frei auf / — Composer/npm-Build koennten knapp werden."
        confirm "Trotzdem fortfahren?" Y || fail "Abgebrochen — erst Platz schaffen ('sudo apt-get clean')."
    fi
    ok "Repo vollstaendig, Ort stimmt."
}

# ----- 1) apt ----------------------------------------------------------------
install_system_packages() {
    step "System-Pakete (apt)"
    if [[ "$INSTALL_DEPS" != "1" ]]; then explain "INSTALL_DEPS=0 — uebersprungen."; return; fi
    explain "Langsamster Teil (mehrere Minuten). Beim Redeploy: INSTALL_DEPS=0."
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

# ----- 2) Deploy-Verzeichnisse ----------------------------------------------
prepare_deploy_dirs() {
    step "Deploy-Verzeichnisse anlegen ($DEPLOY_ROOT)"
    sudo mkdir -p "$BACKEND_DEPLOY" "$FRONTEND_DEPLOY/dist"
    sudo chown -R www-data:www-data "$DEPLOY_ROOT"
    ok "Verzeichnisse bereit."
}

# ----- 3) .env-Review — deine Chance, die Config VOR dem DB-Setup anzupassen -
prepare_env() {
    step ".env-Review — Runtime-Config pruefen/anpassen"
    local target="$BACKEND_DEPLOY/.env" do_overwrite=0

    if [[ ! -f "$target" ]]; then
        install_backend_env
    else
        # Ueberschreiben NUR bei explizitem RESET_ENV=1 oder interaktivem Ja.
        # Blankes -y (ASSUME_YES) fasst eine bestehende .env NICHT an.
        if [[ "$RESET_ENV" == "1" ]]; then
            do_overwrite=1; explain "RESET_ENV=1 — .env wird neu aus dem Template erzeugt."
        elif [[ "$ASSUME_YES" != "1" && -t 0 ]]; then
            confirm "Bestehende .env aus dem Template NEU erzeugen? (Backup wird angelegt)" N && do_overwrite=1
        fi
        if [[ "$do_overwrite" == "1" ]]; then
            local bak="$target.bak.$(date +%Y%m%d-%H%M%S)"
            sudo cp "$target" "$bak"; install_backend_env
            ok "Alte .env gesichert unter $bak."
        else
            explain "Bestehende .env bleibt unveraendert (RESET_ENV=1 erzwingt Neu-Erzeugen)."
        fi
    fi

    explain "Config: $target  —  pruefe DB_PASSWORD (gleich fuer MariaDB), APP_URL, MQTT_*."
    if [[ "$ASSUME_YES" != "1" && -t 0 ]] && confirm "Die .env JETZT im Editor oeffnen?" Y; then
        local ed="${EDITOR:-}"
        [[ -z "$ed" ]] && { command -v nano >/dev/null 2>&1 && ed=nano || { command -v vi >/dev/null 2>&1 && ed=vi; }; }
        if [[ -n "$ed" ]]; then
            sudo "$ed" "$target"
        else
            warn "Kein Editor gefunden. Editiere in 2. Terminal:  sudo nano $target"
            confirm "Weiter, wenn fertig?" Y || fail "Abgebrochen — .env anpassen, dann Skript erneut starten."
        fi
    fi

    load_deployed_env_values   # (evtl. editierte) .env ist ab hier die Wahrheit
    ok "Uebernommen: DB '$DB_NAME', User '$DB_USER', Passwort $(mask "$DB_PASSWORD")."
}

# ----- 4) MariaDB: DB + User -------------------------------------------------
configure_database() {
    step "Datenbank — MariaDB, DB + User"
    sudo systemctl enable --now mariadb 2>/dev/null || sudo systemctl enable --now mysql
    load_deployed_env_values

    # Zwei Host-Rows fuer denselben User: @localhost fuer Socket, @127.0.0.1 fuer
    # TCP (Laravel/MQTT verbinden per DB_HOST=127.0.0.1; @localhost matcht TCP nur
    # ueber Reverse-DNS und bricht bei skip-name-resolve). Beide bei jedem Lauf
    # auf dasselbe Passwort -> kein Drift. Schema baut ausschliesslich `migrate`.
    info "DB \"$DB_NAME\" + User \"$DB_USER\" (@localhost + @127.0.0.1) sicherstellen..."
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
    ok "DB + User bereit (Passwort $(mask "$DB_PASSWORD"))."
}

# ----- 5) Mosquitto ----------------------------------------------------------
configure_mosquitto() {
    step "Mosquitto (MQTT-Broker)"
    # Pi-Config (apt-Pfade, 0.0.0.0-Listener); die Container-mosquitto.conf wuerde nativ crashen.
    local pi_conf="$DOCKER_DIR/mosquitto/config/mosquitto-pi.conf"
    [[ -f "$pi_conf" ]] && sudo install -m 644 "$pi_conf" /etc/mosquitto/conf.d/lokato.conf
    sudo systemctl enable --now mosquitto
    ok "Mosquitto laeuft."
}

# ----- 6) nginx --------------------------------------------------------------
configure_nginx() {
    step "nginx (Reverse Proxy + statisches Frontend)"
    sudo install -m 644 "$DOCKER_DIR/nginx/prod.conf" /etc/nginx/sites-available/lokato
    sudo ln -sf /etc/nginx/sites-available/lokato /etc/nginx/sites-enabled/lokato
    sudo rm -f /etc/nginx/sites-enabled/default   # sonst hoeren beide auf :80
    sudo nginx -t
    sudo systemctl enable nginx
    sudo systemctl reload nginx 2>/dev/null || sudo systemctl restart nginx
    ok "nginx aktiv."
}

# ----- 7) php-fpm ------------------------------------------------------------
configure_php_fpm() {
    step "php-fpm (PHP-Laufzeit fuer die API)"
    local php_ver; php_ver="$(detect_php_version)"
    [[ -z "$php_ver" ]] && fail "PHP-Version unter /etc/php/ nicht gefunden — php-fpm installiert? (INSTALL_DEPS=1)"
    sudo install -m 644 "$DOCKER_DIR/php-fpm/lokato-pool.conf" "/etc/php/$php_ver/fpm/pool.d/lokato.conf"
    # Default-www-Pool deaktivieren (Klarheit).
    [[ -f "/etc/php/$php_ver/fpm/pool.d/www.conf" ]] && \
        sudo mv "/etc/php/$php_ver/fpm/pool.d/www.conf" "/etc/php/$php_ver/fpm/pool.d/www.conf.disabled"
    sudo mkdir -p "$LOG_DIR"; sudo chown www-data:www-data "$LOG_DIR"
    sudo systemctl enable "php$php_ver-fpm"
    sudo systemctl restart "php$php_ver-fpm"
    ok "php$php_ver-fpm laeuft."
}

# ----- 8) systemd-Unit MQTT-Subscriber --------------------------------------
configure_systemd_mqtt() {
    step "systemd-Unit fuer den MQTT-Subscriber"
    sudo install -m 644 "$DOCKER_DIR/systemd/lokato-mqtt.service" /etc/systemd/system/lokato-mqtt.service
    sudo systemctl daemon-reload
    sudo systemctl enable lokato-mqtt   # Start erst nach Backend-Deploy (finalize_services)
    ok "systemd-Unit installiert."
}

# ----- 9) Backend deployen ---------------------------------------------------
deploy_backend() {
    step "Backend deployen (syncen, vendor/Cache aufraeumen, migrieren)"
    [[ -f "$BACKEND_DEPLOY/.env" ]] || fail "Deploy-.env fehlt — prepare_env uebersprungen? Skript erneut starten."

    info "Code syncen (rsync --delete; vendor/.env ausgenommen)..."
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

    # Stale Bootstrap-Cache weg (sonst 'ServiceProvider not found' aus altem Build).
    sudo find "$BACKEND_DEPLOY/bootstrap/cache" -maxdepth 1 -name "*.php" -delete
    sudo chown -R www-data:www-data "$BACKEND_DEPLOY"

    # vendor/ ist rsync-ausgenommen und ueberlebt Branch-Wechsel. Fehlt/unvoll-
    # staendig -> verwerfen (composer baut neu); sonst Classmap defensiv neu
    # erzeugen (raeumt Verweise auf entfernte Klassen, bevor composer deinstalliert).
    if [[ ! -f "$BACKEND_DEPLOY/vendor/composer/installed.json" ]]; then
        warn "vendor/ fehlt/unvollstaendig — wird verworfen, composer baut neu."
        sudo rm -rf "$BACKEND_DEPLOY/vendor"
    else
        as_www_data composer dump-autoload \
            --working-dir="$BACKEND_DEPLOY" --no-scripts -o --no-interaction 2>/dev/null || true
    fi

    info "composer install --no-dev (kann 1-2 min dauern)..."
    as_www_data composer install --working-dir="$BACKEND_DEPLOY" \
        --no-dev --optimize-autoloader --no-interaction --prefer-dist

    # APP_KEY nur setzen, wenn leer (ueberschreibt nichts).
    sudo grep -qE '^APP_KEY=base64:' "$BACKEND_DEPLOY/.env" \
        || as_www_data php "$BACKEND_DEPLOY/artisan" key:generate --force

    info "Config leeren, DB-Login pruefen, migrieren..."
    as_www_data php "$BACKEND_DEPLOY/artisan" config:clear

    # DB-Login mit den .env-Creds VOR migrate testen -> Klartext statt rohem SQLSTATE.
    local _du _dp _dd _dh
    _du="$(sudo sed -n 's/^DB_USERNAME=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dp="$(sudo sed -n 's/^DB_PASSWORD=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dd="$(sudo sed -n 's/^DB_DATABASE=//p' "$BACKEND_DEPLOY/.env" | head -n1)"
    _dh="$(sudo sed -n 's/^DB_HOST=//p'     "$BACKEND_DEPLOY/.env" | head -n1)"
    if ! mysql -h "${_dh:-127.0.0.1}" -u "$_du" -p"$_dp" -e "USE \`$_dd\`; SELECT 1;" >/dev/null 2>&1; then
        fail "DB-Login (User '$_du', DB '$_dd', Host '${_dh:-127.0.0.1}') fehlgeschlagen. Ursachen:
       1) .env-DB_PASSWORD != MariaDB-User. Fix:
            sudo mysql -e \"ALTER USER '$_du'@'localhost'  IDENTIFIED BY '<pw>';
                            ALTER USER '$_du'@'127.0.0.1' IDENTIFIED BY '<pw>'; FLUSH PRIVILEGES;\"
       2) Host-Matching (skip-name-resolve?): User nur als '@localhost', Laravel per TCP.
          Skript legt '@127.0.0.1' bereits an -> einfach erneut starten."
    fi
    ok "DB-Login ok (.env <-> MariaDB konsistent)."

    as_www_data php "$BACKEND_DEPLOY/artisan" migrate --force
    as_www_data php "$BACKEND_DEPLOY/artisan" config:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" route:cache
    as_www_data php "$BACKEND_DEPLOY/artisan" view:cache

    # storage:link -> public/storage, damit nginx die Kinderfotos ausliefert.
    [[ -L "$BACKEND_DEPLOY/public/storage" ]] || as_www_data php "$BACKEND_DEPLOY/artisan" storage:link

    # Optional: Admin-User (SEED_ADMIN=1), idempotent (updateOrCreate). KEIN tinker
    # (headless auf dem Pi unzuverlaessig — PsySH kann nicht nach HOME schreiben).
    if [[ "$SEED_ADMIN" == "1" ]]; then
        as_www_data env \
            ADMIN_USER_EMAIL="${ADMIN_USER_EMAIL:-admin@lokato.local}" \
            ADMIN_USER_NAME="${ADMIN_USER_NAME:-Lokato Admin}" \
            ADMIN_USER_PASSWORD="${ADMIN_USER_PASSWORD:-}" \
            php "$BACKEND_DEPLOY/artisan" db:seed --class=AdminUserSeeder --force
        ok "Admin-User geseedet (${ADMIN_USER_EMAIL:-admin@lokato.local})."
        [[ -z "${ADMIN_USER_PASSWORD:-}" ]] && hint "Kein ADMIN_USER_PASSWORD gesetzt — Seeder hat oben eins GEDRUCKT. Notieren!"
    fi

    # Optional: Stammdaten (SEED_MASTERDATA=1) — nur wenn rooms leer (nicht idempotent).
    if [[ "$SEED_MASTERDATA" == "1" ]]; then
        local rc
        rc="$(mysql -h "${_dh:-127.0.0.1}" -u "$_du" -p"$_dp" -N -B -e "SELECT COUNT(*) FROM \`$_dd\`.rooms;" 2>/dev/null || echo "")"
        if [[ "$rc" == "0" ]]; then
            as_www_data php "$BACKEND_DEPLOY/artisan" db:seed --class=RoomSeeder   --force
            as_www_data php "$BACKEND_DEPLOY/artisan" db:seed --class=DeviceSeeder --force
            ok "Stammdaten geseedet (Raeume + Devices)."
        else
            explain "rooms nicht leer ($rc) — Stammdaten-Seed uebersprungen (kein Duplikat)."
        fi
    fi
    ok "Backend deployt."
}

# ----- 10) Frontend bauen + deployen ----------------------------------------
deploy_frontend() {
    step "Frontend bauen + deployen (Vite -> statisches dist/)"
    ensure_file_from_example "$FRONTEND_SRC/.env" "$FRONTEND_SRC/.env.raspi.example" || true
    info "npm-Install + Build..."
    pushd "$FRONTEND_SRC" >/dev/null
    if [[ -f package-lock.json ]]; then npm ci; else npm install; fi
    npm run build
    popd >/dev/null
    sudo rsync -a --delete "$FRONTEND_SRC/dist/" "$FRONTEND_DEPLOY/dist/"
    sudo chown -R www-data:www-data "$FRONTEND_DEPLOY"
    ok "Frontend deployt."
}

# ----- 11) Log-Audit-Tool (optional, SETUP_LOG_AUDIT=1) ----------------------
deploy_tools() {
    step "Log-Audit-Tool deployen"
    if [[ "$SETUP_LOG_AUDIT" != "1" ]]; then explain "SETUP_LOG_AUDIT=0 — uebersprungen."; return; fi
    local src="$PROJECT_ROOT/tools/log_audit" dst="$DEPLOY_ROOT/tools/log_audit"
    if [[ ! -d "$src" ]]; then warn "tools/log_audit nicht im Repo — uebersprungen."; return; fi
    sudo mkdir -p "$dst"
    sudo rsync -a --delete "$src/" "$dst/"
    sudo chown -R www-data:www-data "$DEPLOY_ROOT/tools"
    ok "Log-Audit-Tool deployt."
}

# ----- 12) Cron: Scheduler (Pflicht) + Log-Audit (optional) ------------------
configure_cron() {
    local title="Cron-Jobs (Laravel-Scheduler)"
    [[ "$SETUP_LOG_AUDIT" == "1" ]] && title="Cron-Jobs (Laravel-Scheduler + Log-Audit)"
    step "$title"
    local ms=">>> lokato managed cron block -- do not edit between markers >>>"
    local me="<<< lokato managed cron block <<<"
    sudo mkdir -p "$LOG_DIR"; sudo chown www-data:www-data "$LOG_DIR"

    local cleaned
    cleaned="$(sudo crontab -l -u www-data 2>/dev/null | sed "/$ms/,/$me/d" || true)"

    # MAILTO nur bei ALERT_EMAIL (Cron mailt Log-Audit-Anomalien; lokaler MTA noetig).
    local mailto=""; [[ -n "${ALERT_EMAIL:-}" ]] && mailto="MAILTO=$ALERT_EMAIL"

    # Log-Audit-Zeilen nur bei SETUP_LOG_AUDIT=1. Scheduler ist immer dabei.
    local audit=""
    if [[ "$SETUP_LOG_AUDIT" == "1" ]]; then
        audit="$(cat <<EOF
# Log-Audit (Daily / Weekly / Cleanup)
10 6 * * * cd $DEPLOY_ROOT && /usr/bin/python3 tools/log_audit/log_audit.py check --period daily  --config tools/log_audit/config.json 2>&1 | tee -a $LOG_DIR/log-audit.log
20 6 * * 1 cd $DEPLOY_ROOT && /usr/bin/python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json 2>&1 | tee -a $LOG_DIR/log-audit.log
30 3 * * 0 cd $DEPLOY_ROOT && /usr/bin/python3 tools/log_audit/log_audit.py cleanup        --config tools/log_audit/config.json >> $LOG_DIR/log-audit.log 2>&1
EOF
)"
    fi

    printf '%s\n' "$(cat <<EOF
$cleaned
# $ms
$mailto
# Laravel-Scheduler -- triggert routes/console.php (u.a. Daily-Reset 01:00 Vienna)
* * * * * cd $BACKEND_DEPLOY && /usr/bin/php artisan schedule:run >> $LOG_DIR/scheduler.log 2>&1
$audit
# $me
EOF
)" | sudo crontab -u www-data -
    ok "Crontab aktualisiert (idempotent, Block zwischen Markern)."
}

# ----- 13) Services finalisieren --------------------------------------------
finalize_services() {
    step "Services neu laden + MQTT-Subscriber starten"
    local php_ver; php_ver="$(detect_php_version)"
    sudo systemctl reload "php$php_ver-fpm"
    sudo systemctl reload nginx
    sudo systemctl restart lokato-mqtt
    ok "php-fpm + nginx neu geladen, lokato-mqtt gestartet."
}

# ----- 14) Zusammenfassung ---------------------------------------------------
print_summary() {
    local ip; ip="$(hostname -I 2>/dev/null | awk '{print $1}')"; [[ -z "$ip" ]] && ip="$PI_IP"
    cat <<EOF

$BAR
  Lokato Production Setup abgeschlossen
$BAR

  Erreichbar:     http://$ip/            (Health: http://$ip/api/health)
  Runtime-.env:   $BACKEND_DEPLOY/.env   (ansehen: sudo cat / editieren: sudo nano)
  Frontend-Build: $FRONTEND_DEPLOY/dist
  Logs:           $BACKEND_DEPLOY/storage/logs/   +   journalctl -u lokato-mqtt -f
  Healthcheck:    bash $PROJECT_ROOT/pi-doctor.sh

  Bookmarks:      Dashboard  http://$ip/#/dashboard
                  Tablet 1   http://$ip/#/tablet/1   (2, 3 analog)

  Nach dem Erststart pruefen / erledigen:
   1) DB-Passwort in der .env ($(mask "$DB_PASSWORD")) aendern? -> .env editieren UND
        sudo mysql -e "ALTER USER '$DB_USER'@'localhost'  IDENTIFIED BY '<neu>';
                       ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '<neu>'; FLUSH PRIVILEGES;"
   2) Admin-User (falls ohne SEED_ADMIN=1 gestartet) — Seeder, NICHT tinker:
        ADMIN_USER_EMAIL=admin@hort.local ADMIN_USER_PASSWORD=... \\
          sudo -E -u www-data php $BACKEND_DEPLOY/artisan db:seed --class=AdminUserSeeder --force
   3) Stammdaten (falls ohne SEED_MASTERDATA=1 gestartet, sonst Tablet-404):
        sudo -u www-data php $BACKEND_DEPLOY/artisan db:seed --class=RoomSeeder   --force
        sudo -u www-data php $BACKEND_DEPLOY/artisan db:seed --class=DeviceSeeder --force
   4) Statische IP noch nicht gesetzt? -> PI_IP=... ./setup-network-raspi.sh
   5) Reboot-Test: kommt alles automatisch hoch?

$BAR

EOF
}

# ----- Main ------------------------------------------------------------------
[[ $EUID -eq 0 ]] && fail "Bitte NICHT als root — das Skript eskaliert selbst per sudo."

print_plan
confirm "Mit genau diesen Einstellungen fortfahren?" Y || {
    info "Abgebrochen — nichts geaendert. Env-Variablen anpassen (Datei-Kopf) und erneut starten."
    exit 0
}

if ! sudo -n true 2>/dev/null; then
    info "sudo benoetigt — einmal Passwort."
    sudo -v || fail "sudo verweigert."
fi

preflight
install_system_packages
prepare_deploy_dirs
prepare_env
configure_database
configure_mosquitto
configure_nginx
configure_php_fpm
configure_systemd_mqtt
deploy_backend
deploy_frontend
deploy_tools
configure_cron
finalize_services
print_summary
