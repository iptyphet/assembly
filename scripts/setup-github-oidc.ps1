# One-time setup: creates an Azure AD app registration with federated credentials
# so GitHub Actions can authenticate to Azure via OIDC (no long-lived secrets).
# Mirrors simplesubmit/scripts/setup-github-oidc.ps1.
#
# Prerequisites:
#   - az login to the target Azure subscription (the personal one for this project).
#   - Enough AAD permissions to create app registrations and role assignments.
#
# What it does:
#   1. Creates an AAD app + service principal named $AppName.
#   2. Grants it Contributor on the current subscription.
#   3. Adds federated credentials for pushes to main and for pull requests.
#   4. Prints the three GitHub repo secrets you need to configure.

param(
    [string] $GitHubOwner = 'ionflex',
    [string] $GitHubRepo = 'assembly',
    [string] $AppName = 'asmbly-github-oidc'
)

$ErrorActionPreference = 'Stop'

$subscriptionId = az account show --query id -o tsv
$tenantId       = az account show --query tenantId -o tsv

Write-Host "Subscription : $subscriptionId"
Write-Host "Tenant       : $tenantId"

# 1. Create the AAD app (reuse if it already exists)
$appId = az ad app list --display-name $AppName --query "[0].appId" -o tsv
if (-not $appId) {
    $appId = az ad app create --display-name $AppName --query appId -o tsv
    az ad sp create --id $appId | Out-Null
}

Write-Host "App ID       : $appId"

# 2. Grant Contributor on the subscription (idempotent)
az role assignment create `
    --assignee $appId `
    --role Contributor `
    --scope "/subscriptions/$subscriptionId" `
    --only-show-errors 2>$null | Out-Null

# 3. Federated credentials: one for pushes to main, one for pull_requests
$subjects = @(
    @{ Name = 'github-main'; Subject = "repo:${GitHubOwner}/${GitHubRepo}:ref:refs/heads/main" },
    @{ Name = 'github-pr';   Subject = "repo:${GitHubOwner}/${GitHubRepo}:pull_request" }
)

foreach ($s in $subjects) {
    $existing = az ad app federated-credential list --id $appId --query "[?name=='$($s.Name)'] | [0].name" -o tsv
    if ($existing) { continue }

    $body = @{
        name      = $s.Name
        issuer    = 'https://token.actions.githubusercontent.com'
        subject   = $s.Subject
        audiences = @('api://AzureADTokenExchange')
    } | ConvertTo-Json -Compress

    $tempFile = New-TemporaryFile
    Set-Content -Path $tempFile -Value $body -Encoding utf8
    az ad app federated-credential create --id $appId --parameters "@$tempFile" | Out-Null
    Remove-Item $tempFile
}

Write-Host ""
Write-Host "Done. Add these as GitHub repo secrets (Settings -> Secrets and variables -> Actions):"
Write-Host "  AZURE_CLIENT_ID       $appId"
Write-Host "  AZURE_TENANT_ID       $tenantId"
Write-Host "  AZURE_SUBSCRIPTION_ID $subscriptionId"
Write-Host ""
Write-Host "Or via gh:"
Write-Host "  gh secret set AZURE_CLIENT_ID --repo $GitHubOwner/$GitHubRepo --body $appId"
Write-Host "  gh secret set AZURE_TENANT_ID --repo $GitHubOwner/$GitHubRepo --body $tenantId"
Write-Host "  gh secret set AZURE_SUBSCRIPTION_ID --repo $GitHubOwner/$GitHubRepo --body $subscriptionId"
