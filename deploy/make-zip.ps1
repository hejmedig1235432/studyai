# Builds studyai-upload.zip: everything the web host needs, WITHOUT your local database,
# uploads, logs, sessions, .env or dev-only files.
#   powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path $root 'studyai-upload.zip'
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ('studyai-' + [guid]::NewGuid())
New-Item -ItemType Directory -Path $stage | Out-Null

foreach ($dir in 'public', 'src', 'bin', 'migrations') {
    Copy-Item -Recurse (Join-Path $root $dir) (Join-Path $stage $dir)
}
foreach ($file in '.htaccess', '.env.example', 'README.md') {
    Copy-Item (Join-Path $root $file) $stage
}
# Empty storage folder with only its protection file.
New-Item -ItemType Directory -Path (Join-Path $stage 'storage') | Out-Null
Copy-Item (Join-Path $root 'storage\.htaccess') (Join-Path $stage 'storage')
# Dev-only files are not needed on the host.
Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $stage 'public\router.php')

if (Test-Path $out) { Remove-Item -Force $out }
# Build the zip by hand so entry paths use "/" (Compress-Archive on Windows PowerShell 5.1 writes "\",
# which Linux web hosts unpack as strange file names like "src\AI.php").
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
try {
    Get-ChildItem -Path $stage -Recurse -File -Force | ForEach-Object {
        $rel = $_.FullName.Substring($stage.Length).TrimStart('\', '/').Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $rel, 'Optimal') | Out-Null
    }
} finally {
    $zip.Dispose()
}
Remove-Item -Recurse -Force $stage
Write-Host "Created $out"
