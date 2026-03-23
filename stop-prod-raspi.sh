#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_DIR="$PROJECT_ROOT/.run"
DOCKER_DIR="$PROJECT_ROOT/docker"

stop_pidfile() {
  local pidfile="$1"
  if [[ -f "$pidfile" ]]; then
    local pid
    pid="$(cat "$pidfile")"
    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid"
      echo "Stopped PID $pid from $(basename "$pidfile")"
    fi
    rm -f "$pidfile"
  fi
}

stop_pidfile "$RUN_DIR/backend.pid"
stop_pidfile "$RUN_DIR/mqtt-subscriber.pid"
stop_pidfile "$RUN_DIR/frontend.pid"

(
  cd "$DOCKER_DIR"
  docker compose down
)
