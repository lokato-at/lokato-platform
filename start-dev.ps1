param(
    [int]$BackendPort = 8001,
    [int]$FrontendPort = 5173
)

$ErrorActionPreference = "Stop"

Write-Host "==> Starting MySQL via Docker..." -ForegroundColor Cyan
Push-Location "$PSScriptRoot\docker"
docker compose up -d
Pop-Location

Write-Host "==> Starting Laravel backend..." -ForegroundColor Cyan
Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd `"$PSScriptRoot\backend`"; php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=$BackendPort"
)

Write-Host "==> Starting Vue frontend..." -ForegroundColor Cyan
Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd `"$PSScriptRoot\frontend`"; npm run dev -- --port $FrontendPort"
)

Write-Host ""
Write-Host "Backend:  http://127.0.0.1:$BackendPort" -ForegroundColor Green
Write-Host "Frontend: http://localhost:$FrontendPort" -ForegroundColor Green
