#!/usr/bin/env bash
# =============================================================================
# Lokato — Stoppt die Lokato-spezifischen Dienste auf dem Raspberry Pi.
# =============================================================================
# Standard: stoppt nur den MQTT-Subscriber (= einziger Lokato-eigener Dienst).
# nginx, php-fpm, MariaDB und Mosquitto laufen weiter — die werden auf dem
# Pi geteilt und sind in der Regel als systemd-Defaults dauerhaft an.
#
# Mit --full werden ALLE Lokato-relevanten Dienste gestoppt (z. B. fuer
# Wartung / Power-Off-Sequenz vor dem shutdown).
# =============================================================================

set -Eeuo pipefail

MODE="minimal"
if [[ "${1:-}" == "--full" ]]; then
    MODE="full"
fi

info() { echo -e "\033[36m==> $*\033[0m"; }
ok()   { echo -e "\033[32m[OK] $*\033[0m"; }

stop_unit() {
    local unit="$1"
    if systemctl is-active --quiet "$unit"; then
        sudo systemctl stop "$unit"
        ok "Gestoppt: $unit"
    else
        ok "Bereits inaktiv: $unit"
    fi
}

detect_php_fpm_unit() {
    # Erstes aktives php*-fpm.service zurueckgeben (z. B. php8.2-fpm).
    systemctl list-units --type=service --state=active --no-legend 'php*-fpm.service' \
        2>/dev/null | awk '{print $1}' | head -n 1
}

info "Stoppe Lokato MQTT-Subscriber..."
stop_unit lokato-mqtt

if [[ "$MODE" == "full" ]]; then
    info "Full-Stop: nginx, php-fpm, mosquitto, MariaDB werden ebenfalls gestoppt..."
    stop_unit nginx
    fpm="$(detect_php_fpm_unit || true)"
    if [[ -n "$fpm" ]]; then
        stop_unit "$fpm"
    fi
    stop_unit mosquitto
    # MariaDB-Name variiert (mariadb / mysql)
    if systemctl is-active --quiet mariadb; then
        stop_unit mariadb
    elif systemctl is-active --quiet mysql; then
        stop_unit mysql
    fi
fi

ok "Fertig."
