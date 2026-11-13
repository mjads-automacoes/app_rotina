# Servidor local de desenvolvimento: powershell -File scripts/serve.ps1 [-Port 8200]
# Liga o SQLite do PHP deste computador (se vier desligado) e guarda os dados em data/ (ou ROTINA_DATA_DIR).
param([int]$Port = 8200)
$raiz = Split-Path -Parent $PSScriptRoot
Write-Host "Rotina em http://127.0.0.1:$Port  (Ctrl+C para parar)"
& php -d extension=pdo_sqlite -d extension=sqlite3 -S "127.0.0.1:$Port" -t "$raiz\public" "$raiz\public\router.php"
