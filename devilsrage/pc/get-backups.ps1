# Devil's Rage: copy the server's nightly backups to this PC.
# Right-click → "Run with PowerShell", or in a terminal:  powershell -File get-backups.ps1
# Needs the same SSH key you use for "ssh ubuntu@40.160.146.234".
# The files contain passwords (.env): keep them on this PC, don't share them.

$server = "ubuntu@40.160.146.234"
$dest = Join-Path $HOME "Documents\DevilsRage-backups"
New-Item -ItemType Directory -Force -Path $dest | Out-Null

Write-Host "Copying backups from $server to $dest ..."
scp -p "${server}:~/backups/nightly/*" "$dest\"
if ($LASTEXITCODE -ne 0) { Write-Host "Copy failed (is the server up, and does 'ssh $server' work?)"; exit 1 }

# Keep the last 30 days on this PC.
Get-ChildItem $dest -File | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-30) } | Remove-Item
Write-Host "Done:"
Get-ChildItem $dest -File | Sort-Object LastWriteTime -Descending | Select-Object -First 6 Name, @{n='MB';e={[math]::Round($_.Length / 1MB, 1)}}, LastWriteTime | Format-Table
Read-Host "Press Enter to close"
