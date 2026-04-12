#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$PROJECT_ROOT/backend"
FRONTEND_DIR="$PROJECT_ROOT/frontend"
DOCKER_DIR="$PROJECT_ROOT/docker"
RUN_DIR="$PROJECT_ROOT/.run"
LOG_DIR="$PROJECT_ROOT/logs"
BACKEND_PORT="${BACKEND_PORT:-8001}"
FRONTEND_PORT="${FRONTEND_PORT:-4173}"
INSTALL_DEPS="${INSTALL_DEPS:-1}"

info() { echo -e "\033[36m==> $*\033[0m"; }
ok() { echo -e "\033[32m[OK] $*\033[0m"; }
warn() { echo -e "\033[33m[WARN] $*\033[0m"; }
fail() { echo -e "\033[31m[ERROR] $*\033[0m"; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1
}

ensure_root_tools() {
  if [[ "$INSTALL_DEPS" != "1" ]]; then
    return
  fi

  info "Installiere Systempakete für Raspberry Pi OS (falls nötig)..."
  sudo apt-get update
  sudo apt-get install -y \
    docker.io docker-compose-plugin \
    php-cli php-mysql php-mbstring php-xml php-curl php-zip unzip composer \
    nodejs npm curl git
  sudo systemctl enable --now docker
}

ensure_file_from_example() {
  local target="$1"
  local example="$2"
  if [[ ! -f "$target" ]]; then
    cp "$example" "$target"
    ok "$(basename "$target") aus Example-Datei erstellt."
  fi
}

ensure_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  if grep -qE "^${key}=" "$file"; then
    return
  fi
  printf '\n%s=%s\n' "$key" "$value" >> "$file"
  ok "$key in $(basename "$file") ergänzt."
}

wait_for_docker() {
  for _ in {1..30}; do
    if docker info >/dev/null 2>&1; then
      ok "Docker Engine ist erreichbar."
      return
    fi
    sleep 2
  done
  fail "Docker läuft nicht oder antwortet nicht."
}

start_process() {
  local pidfile="$1"
  local logfile="$2"
  shift 2

  if [[ -f "$pidfile" ]] && kill -0 "$(cat "$pidfile")" >/dev/null 2>&1; then
    warn "Prozess $(basename "$pidfile") läuft bereits mit PID $(cat "$pidfile")."
    return
  fi

  nohup "$@" >"$logfile" 2>&1 &
  echo $! > "$pidfile"
}

mkdir -p "$RUN_DIR" "$LOG_DIR"

ensure_root_tools

for cmd in docker php composer node npm; do
  need_cmd "$cmd" || fail "$cmd ist nicht installiert."
done

info "Prüfe Docker..."
sudo systemctl start docker || true
wait_for_docker

info "Starte Infrastruktur-Container..."
(
  cd "$DOCKER_DIR"
  docker compose up -d
)

info "Prüfe Umgebungsdateien..."
ensure_file_from_example "$BACKEND_DIR/.env" "$BACKEND_DIR/.env.example"
ensure_file_from_example "$FRONTEND_DIR/.env" "$FRONTEND_DIR/.env.example"
ensure_env_value "$BACKEND_DIR/.env" "APP_ENV" "production"
ensure_env_value "$BACKEND_DIR/.env" "APP_DEBUG" "false"
ensure_env_value "$BACKEND_DIR/.env" "API_SLOW_REQUEST_MS" "400"

info "Installiere Backend-Abhängigkeiten..."
(
  cd "$BACKEND_DIR"
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
)

info "Installiere Frontend-Abhängigkeiten..."
(
  cd "$FRONTEND_DIR"
  if [[ -f package-lock.json ]]; then
    npm ci
  else
    npm install
  fi
)

info "Baue Frontend..."
(
  cd "$FRONTEND_DIR"
  npm run build
)

info "Optimiere Laravel für Produktion..."
(
  cd "$BACKEND_DIR"
  if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
  fi
  php artisan migrate --force
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
)

info "Starte Laravel API und Frontend Preview..."
start_process "$RUN_DIR/backend.pid" "$LOG_DIR/backend.log" bash -lc "cd '$BACKEND_DIR' && php -d variables_order=GPCS artisan serve --host=0.0.0.0 --port=$BACKEND_PORT"
start_process "$RUN_DIR/frontend.pid" "$LOG_DIR/frontend.log" bash -lc "cd '$FRONTEND_DIR' && npx vite preview --host 0.0.0.0 --port $FRONTEND_PORT"

ok "Lokato Produktionsstart abgeschlossen."
echo "Backend API:  http://$(hostname -I | awk '{print $1}'):$BACKEND_PORT"
echo "Frontend:     http://$(hostname -I | awk '{print $1}'):$FRONTEND_PORT"
echo "Logs:         $LOG_DIR"
