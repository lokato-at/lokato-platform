#!/usr/bin/env bash
# =============================================================================
# Lokato — Statische IP fuer den Raspberry Pi (EINMAL, getrennt vom Deploy)
# =============================================================================
# Bewusst getrennt von start-prod-raspi.sh: eine statische IP zu setzen ist
# OS-Einmal-Setup und kann — wenn du per SSH ueber genau dieses Netz verbunden
# bist — die Verbindung kurz kappen. Der App-Deploy soll das nicht bei jedem
# Lauf riskieren.
#
# Aufruf:
#   PI_IP=192.168.1.50 ./setup-network-raspi.sh        # interaktiv
#   PI_IP=192.168.1.50 ./setup-network-raspi.sh -y     # ohne Rueckfrage
#
# Env-Variablen (Default):
#   PI_IP=(192.168.1.100)          Ziel-IP
#   PI_GATEWAY=(192.168.1.1)       Gateway
#   PI_DNS=(192.168.1.1 1.1.1.1)   DNS-Server (space-separiert)
#   PI_NETMASK_CIDR=(24)           Netzmaske als CIDR-Praefix
# =============================================================================
set -Eeuo pipefail

PI_IP="${PI_IP:-192.168.1.100}"
PI_GATEWAY="${PI_GATEWAY:-192.168.1.1}"
PI_DNS="${PI_DNS:-192.168.1.1 1.1.1.1}"
PI_NETMASK_CIDR="${PI_NETMASK_CIDR:-24}"
ASSUME_YES=0
[[ "${1:-}" == "-y" || "${1:-}" == "--yes" ]] && ASSUME_YES=1

info() { echo -e "\033[36m==> $*\033[0m"; }
ok()   { echo -e "\033[32m  [OK] $*\033[0m"; }
warn() { echo -e "\033[33m  [!]  $*\033[0m"; }
fail() { echo -e "\033[31m\n[FEHLER] $*\033[0m" >&2; exit 1; }

confirm() {
    local prompt="$1" default="${2:-N}" ans hint
    [[ "$ASSUME_YES" == "1" ]] && return 0
    [[ ! -t 0 ]] && { [[ "$default" == "Y" ]] && return 0 || return 1; }
    if [[ "$default" == "Y" ]]; then hint="[J/n]"; else hint="[j/N]"; fi
    read -r -p "$(printf '\033[35m ?? \033[0m%s %s ' "$prompt" "$hint")" ans || ans=""
    [[ "${ans:-$default}" =~ ^[YyJj] ]]
}

[[ $EUID -eq 0 ]] && fail "Bitte NICHT als root — das Skript nutzt sudo selbst."

warn "Setzt eine STATISCHE IP: $PI_IP/$PI_NETMASK_CIDR (Gateway $PI_GATEWAY)."
echo -e "\033[90m       Bist du GERADE per SSH ueber dieses Netz verbunden, kann die Verbindung\033[0m"
echo -e "\033[90m       kurz abreissen. Am Monitor/Tastatur direkt am Pi bist du safe.\033[0m"
confirm "Statische IP jetzt setzen?" Y || { info "Abgebrochen — keine Aenderung."; exit 0; }

if systemctl is-active --quiet NetworkManager 2>/dev/null; then
    info "NetworkManager aktiv -> nmcli-Pfad."
    con="$(nmcli -t -f NAME,DEVICE c show --active | awk -F: '$2!="" && $2!="lo" {print $1; exit}')"
    [[ -z "$con" ]] && fail "Keine aktive Verbindung gefunden."
    info "Setze IP auf Connection \"$con\"..."
    sudo nmcli con mod "$con" \
        ipv4.addresses "$PI_IP/$PI_NETMASK_CIDR" \
        ipv4.gateway "$PI_GATEWAY" \
        ipv4.dns "$PI_DNS" \
        ipv4.method manual
    sudo nmcli con up "$con" || warn "nmcli con up fehlgeschlagen — ggf. Reboot noetig."
    ok "NetworkManager-IP gesetzt."

elif systemctl is-active --quiet dhcpcd 2>/dev/null; then
    info "dhcpcd aktiv -> /etc/dhcpcd.conf-Pfad."
    if sudo grep -q "# lokato static block" /etc/dhcpcd.conf; then
        ok "dhcpcd.conf hat bereits einen Lokato-Block — nichts zu tun."
        exit 0
    fi
    sudo tee -a /etc/dhcpcd.conf >/dev/null <<EOF

# lokato static block
interface eth0
static ip_address=$PI_IP/$PI_NETMASK_CIDR
static routers=$PI_GATEWAY
static domain_name_servers=$PI_DNS
EOF
    sudo systemctl restart dhcpcd
    ok "dhcpcd-IP gesetzt."

else
    fail "Weder NetworkManager noch dhcpcd aktiv — IP bitte manuell konfigurieren."
fi

ok "Fertig. Aktuelle IP(s): $(hostname -I 2>/dev/null)"
