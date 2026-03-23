$ErrorActionPreference = "Stop"

Write-Host "==> Stopping Docker containers..." -ForegroundColor Cyan
Push-Location "$PSScriptRoot\docker"
docker compose down
Pop-Location

Write-Host ""
Write-Host "Docker stopped. Close the backend/frontend/MQTT PowerShell-Fenster (artisan serve / npm run dev / php artisan mqtt:subscribe) manuell mit Strg+C oder Fenster schließen." -ForegroundColor Yellow
