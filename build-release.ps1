param(
    [string]$OutputPath = ""
)

$ErrorActionPreference = 'Stop'
$repoPath = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$pluginName = Split-Path -Leaf $repoPath

git -c "safe.directory=$repoPath" -C $repoPath diff --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'Working tree has uncommitted changes; refusing to build.'
}
git -c "safe.directory=$repoPath" -C $repoPath diff --cached --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'Index has uncommitted changes; refusing to build.'
}

$untracked = git -c "safe.directory=$repoPath" -C $repoPath ls-files --others --exclude-standard
if ($untracked) {
    throw 'Working tree has untracked files; refusing to build.'
}

$markers = git -c "safe.directory=$repoPath" -C $repoPath grep -n -E '^(<<<<<<<|=======|>>>>>>>)' -- '*.php'
if ($LASTEXITCODE -eq 0 -and $markers) {
    throw "Merge marker detected:`n$markers"
}
if ($LASTEXITCODE -gt 1) {
    throw 'Unable to check merge markers.'
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    $lightningRoot = Join-Path $env:APPDATA 'Local\lightning-services'
    $phpCommand = Get-ChildItem -LiteralPath $lightningRoot -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1
}
if (-not $phpCommand) {
    throw 'PHP CLI was not found.'
}

$phpExecutable = if ($phpCommand.Source) { $phpCommand.Source } else { $phpCommand.FullName }
$phpFiles = git -c "safe.directory=$repoPath" -C $repoPath ls-files '*.php'
foreach ($phpFile in $phpFiles) {
    & $phpExecutable -l (Join-Path $repoPath $phpFile)
    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint failed: $phpFile"
    }
}

$commit = git -c "safe.directory=$repoPath" -C $repoPath rev-parse --short=12 HEAD
if (-not $OutputPath) {
    $OutputPath = Join-Path (Split-Path -Parent $repoPath) "$pluginName-$commit.zip"
}
$resolvedOutput = [System.IO.Path]::GetFullPath($OutputPath)

git -c "safe.directory=$repoPath" -C $repoPath archive --format=zip --prefix="$pluginName/" --output=$resolvedOutput HEAD
if ($LASTEXITCODE -ne 0) {
    throw 'git archive failed.'
}

Write-Output "Created $resolvedOutput from commit $commit"
