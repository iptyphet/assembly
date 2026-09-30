# Publishes the web app and deploys it to the Azure App Service created by
# provision.ps1 / infra/main.bicep.
#
# Prerequisites: az login, and the infrastructure provisioned.

param(
    [string] $ResourceGroup = 'iptyphet-p-we-asmbly-rg',
    [string] $AppName = 'iptyphet-p-we-asmbly-app'
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path "$PSScriptRoot/.."
$publishDir = Join-Path $root 'publish'
$zipPath = Join-Path $root 'publish.zip'

dotnet publish "$root/src/Web/AssemblyApp.Web.csproj" -c Release -o $publishDir
if ($LASTEXITCODE -ne 0) { throw 'dotnet publish failed' }

if (Test-Path $zipPath) { Remove-Item $zipPath }
Compress-Archive -Path "$publishDir/*" -DestinationPath $zipPath

az webapp deploy `
    --resource-group $ResourceGroup `
    --name $AppName `
    --src-path $zipPath `
    --type zip

Write-Host "Deployed. https://$AppName.azurewebsites.net"
