param(
    [string]$Origin = 'https://www.danielsolared.com',
    [string]$PrivateDirectory = '',
    [string]$Php = 'php'
)
$ErrorActionPreference = 'Stop'
$seoEmail = Read-Host 'SEO login email'
$seoPassword = Read-Host 'Password (at least 14 characters)' -AsSecureString
$seoCredential = [System.Management.Automation.PSCredential]::new($seoEmail, $seoPassword)
$seoPreviousEncoding = $OutputEncoding
try {
    $OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    $seoSetup = @{email=$seoEmail; password=$seoCredential.GetNetworkCredential().Password; origin=$Origin}
    if ($PrivateDirectory) { $seoSetup.private = $PrivateDirectory }
    $seoSetup | ConvertTo-Json -Compress | & $Php (Join-Path $PSScriptRoot 'configure.php')
    if ($LASTEXITCODE -ne 0) { throw 'SEO configuration failed.' }
} finally {
    $OutputEncoding = $seoPreviousEncoding
    $seoSetup = $null
    $seoCredential = $null
    $seoPassword.Dispose()
}
