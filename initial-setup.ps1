param(
    [switch]$SkipFrontend  # optional: wenn gesetzt, wird npm install übersprungen
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host "Lokato – Initial Setup" -ForegroundColor Cyan
Write-Host "==================================="

# Hilfsfunktionen
function Test-CommandExists {
    param(
        [Parameter(Mandatory = $true)][string]$Name
    )
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Fail {
    param([string]$Message)
    Write-Host ""
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

function Info {
    param([string]$Message)
    Write-Host $Message -ForegroundColor Cyan
}

function Success {
    param([string]$Message)
    Write-Host $Message -ForegroundColor Green
}

function Warn {
    param([string]$Message)
    Write-Host $Message -ForegroundColor Yellow
}

function Invoke-DockerCompose {
    param(
        [string[]]$Args
    )
    if (Test-CommandExists "docker") {
        try {
            # Prefer "docker compose" (neuer Syntax)
            docker compose @Args
        } catch {
            if (Test-CommandExists "docker-compose") {
                docker-compose @Args
            } else {
                throw $_
            }
        }
    } else {
        throw "docker not found"
    }
}

# Basis-Verzeichnis auf Projekt-Root setzen
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptRoot

Write-Host "Projektpfad: $ScriptRoot"
Write-Host ""

# 1) Voraussetzung-Checks
$missing = @()

if (-not (Test-CommandExists "docker")) {
    $missing += "Docker (Docker Desktop + CLI)"
}
if (-not (Test-CommandExists "php")) {
    $missing += "PHP (>= 8.2)"
}
if (-not (Test-CommandExists "composer")) {
    $missing += "Composer"
}
if (-not (Test-CommandExists "node")) {
    $missing += "Node.js (LTS)"
}
if (-not (Test-CommandExists "npm")) {
    $missing += "npm"
}

if ($missing.Count -gt 0) {
    Write-Host "Einige Voraussetzungen fehlen:" -ForegroundColor Red
    foreach ($m in $missing) {
        Write-Host " - $m" -ForegroundColor Red
    }
    Write-Host ""
    Write-Host "Bitte installiere die obigen Komponenten und starte das Script erneut." -ForegroundColor Yellow
    exit 1
}

Success "Alle benötigten Tools gefunden:"
Write-Host " - Docker"
Write-Host " - PHP"
Write-Host " - Composer"
Write-Host " - Node.js"
Write-Host " - npm"
Write-Host ""

# 2) Docker / MySQL
if (-not (Test-Path "$ScriptRoot\docker\docker-compose.yml") -and -not (Test-Path "$ScriptRoot\docker\docker-compose.yaml")) {
    Warn "Kein docker-compose.yml im Ordner 'docker' gefunden. Überspringe DB-Setup."
} else {
    Info "Starte MySQL in Docker..."
    try {
        Push-Location "$ScriptRoot\docker"
        Invoke-DockerCompose @("up", "-d")
        Pop-Location
        Success "MySQL-Container gestartet (docker compose up -d)."
    } catch {
        Warn "Konnte Docker-Compose nicht starten: $($_.Exception.Message)"
        Warn "Bitte prüfe manuell, ob Docker Desktop läuft und starte ggf. 'docker compose up -d' im Ordner 'docker'."
    }
}

Write-Host ""

# 3) Backend-Setup (Laravel)
if (-not (Test-Path "$ScriptRoot\backend")) {
    Fail "Backend-Ordner 'backend' nicht gefunden. Bist du im Projektroot?"
}

Info "Backend-Setup (Laravel)..."
Push-Location "$ScriptRoot\backend"

# composer install
if (-not (Test-Path "vendor")) {
    Info "Führe 'composer install' aus..."
    composer install
    Success "composer install fertig."
} else {
    Warn "vendor/ existiert bereits – überspringe composer install."
}

# .env
if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Info "Erstelle .env aus .env.example..."
        Copy-Item ".env.example" ".env"
        Success ".env erstellt. Bitte prüfe bei Bedarf DB-Zugangsdaten."
    } else {
        Warn "Keine .env und keine .env.example gefunden – bitte manuell erstellen."
    }
} else {
    Warn ".env existiert bereits – lasse sie unverändert."
}

# App-Key
Info "Generiere APP_KEY (php artisan key:generate)..."
php artisan key:generate -n
Success "APP_KEY gesetzt."

# Migration + Seed
Info "Führe Migrationen & Seeder aus (php artisan migrate --seed)..."
try {
    php artisan migrate --seed -n
    Success "Migrationen & Seeder erfolgreich."
} catch {
    Warn "Fehler bei 'php artisan migrate --seed': $($_.Exception.Message)"
    Warn "Bitte prüfe die Datenbank-Verbindung und führe den Befehl ggf. manuell aus."
}

Pop-Location

Write-Host ""

# 4) Frontend-Setup (Vue/Vite)
if ($SkipFrontend) {
    Warn "Frontend-Setup übersprungen (SkipFrontend-Switch gesetzt)."
} else {
    if (-not (Test-Path "$ScriptRoot\frontend")) {
        Warn "Frontend-Ordner 'frontend' nicht gefunden – überspringe Frontend-Setup."
    } else {
        Info "Frontend-Setup (npm install)..."
        Push-Location "$ScriptRoot\frontend"

        if (-not (Test-Path "node_modules")) {
            npm install
            Success "npm install fertig."
        } else {
            Warn "node_modules/ existiert bereits – überspringe npm install."
        }

        Pop-Location
    }
}

Write-Host ""
Success "Initial-Setup abgeschlossen. 🎉"

Write-Host ""
Write-Host "Nächste Schritte:" -ForegroundColor Cyan
Write-Host " 1) Backend starten:"
Write-Host "    cd backend"
Write-Host "    php artisan serve --host=127.0.0.1 --port=8001"
Write-Host "    (falls Fehler 'Failed to listen on 127.0.0.1:8001', dann: php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=8001)"
Write-Host ""
Write-Host " 2) Frontend starten:"
Write-Host "    cd frontend"
Write-Host "    npm run dev"
Write-Host ""
Write-Host " 3) MySQL läuft bereits über Docker (docker compose up -d im Ordner 'docker')."
Write-Host ""
