$ErrorActionPreference = "Stop"

Write-Host "==> Stopping Docker containers..." -ForegroundColor Cyan
Push-Location "$PSScriptRoot\docker"
docker compose down
Pop-Location

Write-Host ""
Write-Host "Docker stopped. Close the REST-Backend/SSE-Backend/Frontend/MQTT PowerShell-Fenster (php artisan serve (REST) / php artisan serve (SSE) / npm run dev / php artisan mqtt:subscribe) manuell mit Strg+C oder Fenster schließen." -ForegroundColor Yellow
