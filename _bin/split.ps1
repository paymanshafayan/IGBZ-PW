<#
.SYNOPSIS
    یک فایل بزرگ را برای آپلود در گیت‌هاب تکه می‌کند.

.DESCRIPTION
    گیت‌هاب فایل بزرگ‌تر از ۱۰۰ مگابایت را در push معمولی رد می‌کند. این اسکریپت فایل را
    به تکه‌های کوچک‌تر می‌شکند و یک فایل امضا (SHA256) هم کنارش می‌گذارد تا سمت لینوکس،
    `hoosha/setup.sh` بعد از سرهم‌کردن مطمئن شود چیزی خراب نشده است.

    خروجی: <نام فایل>.part-001 ، .part-002 ، … و <نام فایل>.sha256

.EXAMPLE
    .\split.ps1 -Path .\electron-v43.4.0-linux-x64.zip

.EXAMPLE
    .\split.ps1 -Path .\bun-linux-x64.zip -PartSizeMB 40
#>

param(
    [Parameter(Mandatory = $true)]
    [string] $Path,

    [int] $PartSizeMB = 45
)

$ErrorActionPreference = 'Stop'

$file = Get-Item -LiteralPath $Path
if (-not $file) { throw "فایل پیدا نشد: $Path" }

$partSize = $PartSizeMB * 1MB
Write-Host ""
Write-Host ("فایل  : {0}" -f $file.Name)
Write-Host ("حجم   : {0:N1} MB" -f ($file.Length / 1MB))
Write-Host ("تکه‌ها : هرکدام حداکثر {0} MB" -f $PartSizeMB)
Write-Host ""

if ($file.Length -le $partSize) {
    Write-Host "این فایل از حد تکه‌کردن کوچک‌تر است — همان یک فایل را مستقیم آپلود کن." -ForegroundColor Yellow
    return
}

# امضای فایل اصلی، برای بررسی سلامت بعد از سرهم‌شدن.
Write-Host "در حال گرفتن امضای SHA256 ..." -NoNewline
$hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLower()
Set-Content -LiteralPath ($file.FullName + '.sha256') -Value $hash -NoNewline -Encoding ascii
Write-Host " انجام شد"
Write-Host ""

$stream = [System.IO.File]::OpenRead($file.FullName)
try {
    $buffer = New-Object byte[] (4 * 1024 * 1024)
    $index = 1

    while ($stream.Position -lt $stream.Length) {
        $partPath = '{0}.part-{1:d3}' -f $file.FullName, $index
        $out = [System.IO.File]::Create($partPath)
        try {
            $written = 0
            while ($written -lt $partSize -and $stream.Position -lt $stream.Length) {
                $toRead = [Math]::Min($buffer.Length, $partSize - $written)
                $read = $stream.Read($buffer, 0, $toRead)
                if ($read -le 0) { break }
                $out.Write($buffer, 0, $read)
                $written += $read
            }
        }
        finally { $out.Dispose() }

        Write-Host ("  ساخته شد: {0}  ({1:N1} MB)" -f (Split-Path $partPath -Leaf), ($written / 1MB))
        $index++
    }
}
finally { $stream.Dispose() }

Write-Host ""
Write-Host "تمام شد." -ForegroundColor Green
Write-Host "حالا همهٔ فایل‌های .part-### و فایل .sha256 را در پوشهٔ _bin/ بگذار و push کن."
Write-Host "خودِ فایل اصلی را آپلود نکن."
Write-Host ""
