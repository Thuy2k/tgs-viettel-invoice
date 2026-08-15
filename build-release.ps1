$ErrorActionPreference = 'Stop'

$pluginRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginName = Split-Path -Leaf $pluginRoot
$parentDir = Split-Path -Parent $pluginRoot
$stageRoot = Join-Path $parentDir ('__release_stage_' + $pluginName)
$stageDir = Join-Path $stageRoot $pluginName
$zipPath = Join-Path $parentDir ($pluginName + '-release.zip')
$mainPluginFile = $pluginName + '.php'

$excludeDirs = @(
    '.git',
    '.github',
    '.agents',
    '.idea',
    '.vscode',
    'node_modules',
    'tests'
)

$excludeFiles = @(
    'build-release.ps1',
    'debug.log',
    'ketquane.text',
    'ketquaserver.text',
    'ketquaupload.text',
    'PLAN_*.md',
    '*.zip',
    '*.bak',
    '*.broken',
    '*.log',
    '*.tmp',
    '*.temp',
    '.DS_Store',
    'Thumbs.db'
)

function Remove-StageDirectory {
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
}

try {
    Remove-StageDirectory
    New-Item -ItemType Directory -Path $stageDir | Out-Null

    $robocopyArgs = @(
        $pluginRoot,
        $stageDir,
        '/E',
        '/XJ',
        '/R:2',
        '/W:1',
        '/XD'
    ) + $excludeDirs + @(
        '/XF'
    ) + $excludeFiles

    & robocopy @robocopyArgs | Out-Null
    $robocopyExitCode = $LASTEXITCODE

    if ($robocopyExitCode -gt 7) {
        throw "robocopy failed with exit code $robocopyExitCode"
    }

    if (!(Test-Path -LiteralPath (Join-Path $stageDir $mainPluginFile) -PathType Leaf)) {
        throw "Release is missing the main plugin file: $mainPluginFile"
    }

    $forbiddenStageItems = Get-ChildItem -LiteralPath $stageDir -Force -Recurse | Where-Object {
        $_.Name -in @('.git', '.github', '.agents', '.idea', '.vscode', '.DS_Store', 'Thumbs.db') -or
        $_.Name -match '\.(zip|bak|broken|log|tmp|temp)$'
    }

    if ($forbiddenStageItems) {
        $paths = ($forbiddenStageItems.FullName -join ', ')
        throw "Forbidden files found in release staging directory: $paths"
    }

    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    & tar.exe -a -cf $zipPath -C $stageRoot $pluginName
    if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $zipPath -PathType Leaf)) {
        throw 'Release zip was not created.'
    }

    $archiveEntries = @(tar.exe -tf $zipPath)
    if ($LASTEXITCODE -ne 0) {
        throw 'Release zip could not be verified.'
    }

    $expectedMainEntry = "$pluginName/$mainPluginFile"
    if ($archiveEntries -notcontains $expectedMainEntry) {
        throw "Release zip is missing: $expectedMainEntry"
    }

    $unexpectedRootEntries = @($archiveEntries | Where-Object {
        $_ -ne "$pluginName/" -and !($_ -like "$pluginName/*")
    })
    $forbiddenArchiveEntries = @($archiveEntries | Where-Object {
        $_ -match '(^|/)(\.git|\.github|\.agents|\.idea|\.vscode)(/|$)' -or
        $_ -match '(^|/)(\.DS_Store|Thumbs\.db)$' -or
        $_ -match '\.(zip|bak|broken|log|tmp|temp)$'
    })

    if ($unexpectedRootEntries.Count -gt 0) {
        throw "Files outside the plugin directory found in release zip: $($unexpectedRootEntries -join ', ')"
    }

    if ($forbiddenArchiveEntries.Count -gt 0) {
        throw "Forbidden files found in release zip: $($forbiddenArchiveEntries -join ', ')"
    }

    $zip = Get-Item -LiteralPath $zipPath
    $hash = Get-FileHash -LiteralPath $zipPath -Algorithm SHA256

    Write-Host 'Created safe release package:'
    Write-Host $zip.FullName
    Write-Host "Files: $($archiveEntries.Count)"
    Write-Host "Size: $($zip.Length) bytes"
    Write-Host "SHA256: $($hash.Hash)"
}
finally {
    Remove-StageDirectory
}
