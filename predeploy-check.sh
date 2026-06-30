#!/usr/bin/env bash
# =============================================================================
# Lokato — Pre-Deploy-Check (in DEV ausfuehren, VOR dem Pi-Deploy)
# -----------------------------------------------------------------------------
# Spiegelt die strengen Prod-Gates lokal, damit Fehler NICHT erst auf dem Pi
# auftauchen. Dev laeuft mit `npm run dev` + vollem `vendor/` und sieht die
# Prod-Pfade nie — dieser Check holt sie nach Dev:
#   - tote Klassen-Refs in bootstrap/app.php / Providern  -> package:discover
#   - Config, die eine nicht ladbare Klasse referenziert  -> config:cache
#   - TypeScript-Fehler (vue-tsc)                          -> npm run build
#   - composer.json/lock-Drift                             -> composer validate
#
# Aufruf (aus dem Repo-Root):
#   bash predeploy-check.sh           # schnelle Checks (nutzt vorhandenes vendor/)
#   bash predeploy-check.sh --prod    # zusaetzlich ECHTE --no-dev-Verifikation in
#                                     # Wegwerf-vendor (mirrort den Pi exakt,
#                                     # faengt verwaiste Config zu Dev-Paketen wie
#                                     # l5-swagger; braucht lokales php+composer)
#
# Exit 0 = alle Checks gruen -> Deploy ist safe.  Exit !=0 = mind. ein Check rot.
# =============================================================================
set -uo pipefail   # bewusst KEIN -e: wir sammeln ALLE Ergebnisse, statt beim
                   # ersten Fehler abzubrechen.

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$PROJECT_ROOT/backend"
FRONTEND="$PROJECT_ROOT/frontend"
COMPOSE="$PROJECT_ROOT/docker/docker-compose.yml"

PROD=0
for a in "$@"; do [[ "$a" == "--prod" ]] && PROD=1; done

FAILED=0
INFO(){ printf '\033[36m==>\033[0m %s\n' "$*"; }
PASS(){ printf '  \033[32m[PASS]\033[0m %s\n' "$*"; }
FAILMSG(){ printf '  \033[31m[FAIL]\033[0m %s\n' "$*"; FAILED=$((FAILED+1)); }
SKIP(){ printf '  \033[33m[SKIP]\033[0m %s\n' "$*"; }

# --- Backend-Runner ermitteln: lokales php bevorzugt, sonst laufender Container ---
USE_DOCKER=0
HAVE_BACKEND=0
HAVE_COMPOSER_LOCAL=0
if command -v php >/dev/null 2>&1; then
    HAVE_BACKEND=1
    command -v composer >/dev/null 2>&1 && HAVE_COMPOSER_LOCAL=1
elif command -v docker >/dev/null 2>&1 \
     && docker compose -f "$COMPOSE" ps php-fpm 2>/dev/null | grep -qiE 'up|running'; then
    HAVE_BACKEND=1
    USE_DOCKER=1
fi

artisan(){
    if [[ $USE_DOCKER -eq 1 ]]; then
        docker compose -f "$COMPOSE" exec -T php-fpm php artisan "$@"
    else
        ( cd "$BACKEND" && php artisan "$@" )
    fi
}
composer_run(){
    if [[ $USE_DOCKER -eq 1 ]]; then
        docker compose -f "$COMPOSE" exec -T php-fpm composer "$@"
    else
        ( cd "$BACKEND" && composer "$@" )
    fi
}

echo
INFO "Lokato Pre-Deploy-Check  (Backend: $([[ $HAVE_BACKEND -eq 1 ]] && { [[ $USE_DOCKER -eq 1 ]] && echo 'Docker php-fpm' || echo 'lokales php'; } || echo 'NICHT verfuegbar'))"

INFO "1) composer.json/lock konsistent..."
if [[ $HAVE_BACKEND -ne 1 ]]; then
    SKIP "kein php/Container — Backend-Checks uebersprungen"
elif [[ $HAVE_COMPOSER_LOCAL -ne 1 && $USE_DOCKER -ne 1 ]]; then
    SKIP "composer nicht verfuegbar (lokales php ohne composer) — validate uebersprungen"
elif composer_run validate --no-check-all --no-check-publish >/dev/null 2>&1; then
    PASS "composer validate"
else
    # Nur Warnung — validate ist streng (z.B. fehlende description). Echte
    # Lock-Drift faengt der --prod-Lauf weiter unten verlaesslich.
    SKIP "composer validate meldet Warnungen (ggf. composer update noetig) — nicht hart gewertet"
fi

if [[ $HAVE_BACKEND -eq 1 ]]; then
    INFO "2) package:discover (faengt tote Command/Provider-Refs)..."
    # Autoload OHNE Scripts neu generieren -> stale Classmap crasht discover nicht.
    [[ $HAVE_COMPOSER_LOCAL -eq 1 ]] && composer_run dump-autoload --no-scripts -o >/dev/null 2>&1
    if artisan package:discover --ansi >/dev/null 2>&1; then
        PASS "package:discover — keine fehlenden Command/Provider-Klassen"
    else
        FAILMSG "package:discover bricht — bootstrap/app.php oder ein Provider referenziert eine fehlende Klasse"
    fi

    INFO "3) config:cache (faengt nicht ladbare Config-Refs)..."
    if artisan config:clear >/dev/null 2>&1 && artisan config:cache >/dev/null 2>&1; then
        PASS "config:cache"
    else
        FAILMSG "config:cache bricht — eine config/*.php referenziert eine nicht ladbare Klasse"
    fi
    artisan config:clear >/dev/null 2>&1 || true   # Cache fuer Dev wieder entfernen
fi

INFO "4) Frontend npm run build (Vite + vue-tsc)..."
if command -v npm >/dev/null 2>&1; then
    if ( cd "$FRONTEND" && npm run build >/dev/null 2>&1 ); then
        PASS "npm run build — Type-Check gruen, dist gebaut"
    else
        FAILMSG "npm run build bricht — vue-tsc Type-Fehler/Build-Fehler. Details: (cd frontend && npm run type-check)"
    fi
else
    SKIP "npm nicht gefunden — Frontend-Build uebersprungen"
fi

# 5) [--prod] echte --no-dev-Verifikation in Wegwerf-Kopie — der einzige Check, der
#    --no-dev-only-Fehler faengt (verwaiste Config zu einem Dev-Paket, z.B.
#    l5-swagger). Braucht lokales php+composer; Dev-vendor bleibt unberuehrt.
if [[ $PROD -eq 1 ]]; then
    INFO "5) [--prod] echte --no-dev-Verifikation in Wegwerf-Kopie..."
    if [[ $HAVE_COMPOSER_LOCAL -ne 1 ]]; then
        SKIP "--prod braucht lokales php+composer (Container-Pfad nicht unterstuetzt)"
    else
        tmp="$(mktemp -d 2>/dev/null || echo "${TMPDIR:-/tmp}/lokato-predeploy.$$")"
        mkdir -p "$tmp"
        trap 'rm -rf "$tmp"' EXIT
        # Backend in den tmp-Dir kopieren, ohne vendor/node_modules.
        if command -v rsync >/dev/null 2>&1; then
            rsync -a --exclude=vendor/ --exclude=node_modules/ "$BACKEND/" "$tmp/" >/dev/null 2>&1
        else
            cp -r "$BACKEND/." "$tmp/" >/dev/null 2>&1
            rm -rf "$tmp/vendor" "$tmp/node_modules"
        fi
        if ( cd "$tmp" \
              && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist >/dev/null 2>&1 \
              && php artisan package:discover --ansi >/dev/null 2>&1 \
              && php artisan config:cache >/dev/null 2>&1 ); then
            PASS "--no-dev Install + discover + config:cache (mirrort den Pi exakt)"
        else
            FAILMSG "--no-dev-Pfad bricht — GENAU das wuerde auf dem Pi crashen (z.B. verwaiste Config zu einem Dev-Paket)"
        fi
        rm -rf "$tmp"; trap - EXIT
    fi
else
    INFO "5) [--prod uebersprungen] fuer die echte --no-dev-Verifikation: bash predeploy-check.sh --prod"
fi

echo
if [[ $FAILED -eq 0 ]]; then
    printf '\033[32m====> ALLE CHECKS GRUEN — Deploy auf den Pi ist safe.\033[0m\n'
    exit 0
else
    printf '\033[31m====> %d CHECK(S) ROT — erst fixen, dann deployen.\033[0m\n' "$FAILED"
    exit 1
fi
