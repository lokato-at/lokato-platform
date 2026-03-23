param(
    [int]$BackendPort = 8001,
    [int]$FrontendPort = 5173,
    [int]$SsePort = 8002,
    [switch]$SkipInstalls,
    [switch]$SkipDocker
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$ProjectRoot = $PSScriptRoot
$BackendPath = Join-Path $ProjectRoot "backend"
$FrontendPath = Join-Path $ProjectRoot "frontend"
$DockerPath = Join-Path $ProjectRoot "docker"

function Write-Step {
    param([string]$Message)
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Ok {
    param([string]$Message)
    Write-Host "[OK] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Message)
    Write-Host "[WARN] $Message" -ForegroundColor Yellow
}

function Fail {
    param([string]$Message)
    Write-Host "[ERROR] $Message" -ForegroundColor Red
    exit 1
}

function Test-CommandExists {
    param([Parameter(Mandatory = $true)][string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Ensure-WingetPackage {
    param(
        [Parameter(Mandatory = $true)][string]$CommandName,
        [Parameter(Mandatory = $true)][string]$PackageId,
        [Parameter(Mandatory = $true)][string]$FriendlyName
    )

    if (Test-CommandExists $CommandName) {
        Write-Ok "$FriendlyName ist installiert."
        return
    }

    if ($SkipInstalls) {
        Fail "$FriendlyName fehlt und -SkipInstalls wurde gesetzt."
    }

    if (-not (Test-CommandExists "winget")) {
        Fail "$FriendlyName fehlt und winget ist nicht verfügbar. Bitte installiere $FriendlyName manuell."
    }

    Write-Step "Installiere $FriendlyName via winget..."
    winget install --id $PackageId --exact --accept-package-agreements --accept-source-agreements

    if (-not (Test-CommandExists $CommandName)) {
        Write-Warn "$FriendlyName wurde installiert, ist aber im aktuellen Terminal noch nicht im PATH. Starte das Script ggf. neu."
    }
}

function Invoke-DockerCompose {
    param([string[]]$Args)

    Push-Location $DockerPath
    try {
        docker compose @Args
    } finally {
        Pop-Location
    }
}

function Wait-ForDocker {
    $maxAttempts = 30
    for ($i = 1; $i -le $maxAttempts; $i++) {
        try {
            docker info | Out-Null
            Write-Ok "Docker Engine ist erreichbar."
            return
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    Fail "Docker läuft nicht oder antwortet nicht. Bitte Docker Desktop starten."
}

function Ensure-FileFromExample {
    param(
        [Parameter(Mandatory = $true)][string]$TargetPath,
        [Parameter(Mandatory = $true)][string]$ExamplePath
    )

    if (-not (Test-Path $TargetPath)) {
        Copy-Item $ExamplePath $TargetPath
        Write-Ok "$(Split-Path $TargetPath -Leaf) aus Example-Datei erstellt."
    }
}

function Set-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string]$Key,
        [Parameter(Mandatory = $true)][string]$Value
    )

    $content = Get-Content $FilePath -Raw
    $pattern = "(?m)^$([regex]::Escape($Key))=.*$"

    if ($content -match $pattern) {
        $content = [regex]::Replace($content, $pattern, "$Key=$Value")
    } else {
        $content = $content.TrimEnd() + "`r`n$Key=$Value`r`n"
    }

    Set-Content -Path $FilePath -Value $content
    Write-Ok "$Key in $(Split-Path $FilePath -Leaf) gesetzt."
}

function Start-DetachedPowerShell {
    param(
        [Parameter(Mandatory = $true)][string]$Title,
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][string]$Command
    )

    Start-Process powershell -ArgumentList @(
        "-NoExit",
        "-Command",
        "`$Host.UI.RawUI.WindowTitle = '$Title'; cd `"$WorkingDirectory`"; $Command"
    ) | Out-Null
}

Write-Host "Lokato Dev Startup (Windows)" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan

Ensure-WingetPackage -CommandName "docker" -PackageId "Docker.DockerDesktop" -FriendlyName "Docker Desktop"
Ensure-WingetPackage -CommandName "php" -PackageId "PHP.PHP" -FriendlyName "PHP"
Ensure-WingetPackage -CommandName "composer" -PackageId "Composer.Composer" -FriendlyName "Composer"
Ensure-WingetPackage -CommandName "node" -PackageId "OpenJS.NodeJS.LTS" -FriendlyName "Node.js LTS"
Ensure-WingetPackage -CommandName "npm" -PackageId "OpenJS.NodeJS.LTS" -FriendlyName "npm"

if (-not $SkipDocker) {
    Write-Step "Prüfe Docker..."
    try {
        docker info | Out-Null
    } catch {
        Write-Warn "Docker antwortet noch nicht – versuche Docker Desktop zu starten."
        $dockerDesktop = Join-Path $Env:ProgramFiles "Docker\Docker\Docker Desktop.exe"
        if (Test-Path $dockerDesktop) {
            Start-Process $dockerDesktop | Out-Null
        }
    }

    Wait-ForDocker

    Write-Step "Starte Infrastruktur-Container (MySQL, phpMyAdmin, MQTT)..."
    Invoke-DockerCompose -Args @("up", "-d")
}

Write-Step "Prüfe Backend-Konfiguration..."
Ensure-FileFromExample -TargetPath (Join-Path $BackendPath ".env") -ExamplePath (Join-Path $BackendPath ".env.example")
Set-EnvValue -FilePath (Join-Path $BackendPath ".env") -Key "API_SLOW_REQUEST_MS" -Value "400"
Set-EnvValue -FilePath (Join-Path $BackendPath ".env") -Key "SSE_MAX_CONNECTION_SECONDS" -Value "60"

Write-Step "Prüfe Frontend-Konfiguration..."
$frontendEnv = Join-Path $FrontendPath ".env"
Ensure-FileFromExample -TargetPath $frontendEnv -ExamplePath (Join-Path $FrontendPath ".env.example")
Set-EnvValue -FilePath $frontendEnv -Key "VITE_API_BASE_URL" -Value "http://127.0.0.1:$BackendPort/api/v1"
Set-EnvValue -FilePath $frontendEnv -Key "VITE_SSE_BASE_URL" -Value "http://127.0.0.1:$SsePort/api"

if (-not $SkipInstalls) {
    if (-not (Test-Path (Join-Path $BackendPath "vendor\autoload.php"))) {
        Write-Step "Installiere Backend-Abhängigkeiten..."
        Push-Location $BackendPath
        try {
            composer install --no-interaction --prefer-dist
        } finally {
            Pop-Location
        }
    }

    if (-not (Test-Path (Join-Path $FrontendPath "node_modules"))) {
        Write-Step "Installiere Frontend-Abhängigkeiten..."
        Push-Location $FrontendPath
        try {
            npm install
        } finally {
            Pop-Location
        }
    }
}

Write-Step "Initialisiere Laravel..."
Push-Location $BackendPath
try {
    $envFile = Join-Path $BackendPath ".env"
    $envContent = Get-Content $envFile -Raw
    if ($envContent -notmatch "(?m)^APP_KEY=base64:") {
        php artisan key:generate --force
    }
    php artisan migrate --seed --force
} finally {
    Pop-Location
}

Write-Step "Starte Laravel REST Backend..."
Start-DetachedPowerShell -Title "Lokato REST Backend" -WorkingDirectory $BackendPath -Command "php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=$BackendPort"

Write-Step "Starte Laravel SSE Backend..."
Start-DetachedPowerShell -Title "Lokato SSE Backend" -WorkingDirectory $BackendPath -Command "php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=$SsePort"

Write-Step "Starte MQTT Subscriber..."
Start-DetachedPowerShell -Title "Lokato MQTT Subscriber" -WorkingDirectory $BackendPath -Command "php artisan mqtt:subscribe"

Write-Step "Starte Vue Frontend..."
Start-DetachedPowerShell -Title "Lokato Frontend" -WorkingDirectory $FrontendPath -Command "npm run dev -- --host 0.0.0.0 --port $FrontendPort"

Write-Host ""
Write-Ok "Lokato Dev-Stack wurde gestartet."
Write-Host "Backend REST: http://127.0.0.1:$BackendPort" -ForegroundColor Green
Write-Host "Backend SSE:  http://127.0.0.1:$SsePort" -ForegroundColor Green
Write-Host "Frontend:     http://127.0.0.1:$FrontendPort" -ForegroundColor Green
Write-Host "phpMyAdmin:   http://127.0.0.1:8090" -ForegroundColor Green
Write-Host "MQTT Broker:  mqtt://127.0.0.1:1883" -ForegroundColor Green
