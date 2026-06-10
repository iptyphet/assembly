# Provisions the Azure resources for assembly (resource group, storage,
# App Service plan + app, App Insights) via infra/main.bicep.
#
# Prerequisites: az login to the target subscription.

param(
    [string] $Location = 'westeurope',
    [string] $Env = 'p',
    [string] $Sku = 'B1'
)

$ErrorActionPreference = 'Stop'

az deployment sub create `
    --name "assembly-$Env-$(Get-Date -Format yyyyMMddHHmmss)" `
    --location $Location `
    --template-file "$PSScriptRoot/../infra/main.bicep" `
    --parameters env=$Env appServiceSku=$Sku `
    --output table
