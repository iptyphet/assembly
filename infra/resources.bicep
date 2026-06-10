@description('Region for all resources.')
param location string

@description('Resource name prefix: system-env-region-component (no resource-type suffix).')
param namePrefix string

@description('Storage account name (3-24 lowercase alphanumeric, no separators).')
param storageName string

@description('App Service plan SKU.')
param appServiceSku string

resource log 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: '${namePrefix}-log'
  location: location
  properties: {
    sku: {
      name: 'PerGB2018'
    }
    retentionInDays: 30
  }
}

resource appi 'Microsoft.Insights/components@2020-02-02' = {
  name: '${namePrefix}-appi'
  location: location
  kind: 'web'
  properties: {
    Application_Type: 'web'
    WorkspaceResourceId: log.id
  }
}

resource storage 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: storageName
  location: location
  kind: 'StorageV2'
  sku: {
    name: 'Standard_LRS'
  }
  properties: {
    accessTier: 'Hot'
    allowBlobPublicAccess: false
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
  }
}

var storageConnection = 'DefaultEndpointsProtocol=https;AccountName=${storage.name};AccountKey=${storage.listKeys().keys[0].value};EndpointSuffix=${environment().suffixes.storage}'

resource plan 'Microsoft.Web/serverfarms@2024-04-01' = {
  name: '${namePrefix}-asp'
  location: location
  kind: 'linux'
  sku: {
    name: appServiceSku
  }
  properties: {
    reserved: true
  }
}

// Single instance by design: AppState holds the assembly in memory and the
// Blazor Server circuits live on this one process. Do not scale out.
resource app 'Microsoft.Web/sites@2024-04-01' = {
  name: '${namePrefix}-app'
  location: location
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      linuxFxVersion: 'DOTNETCORE|10.0'
      webSocketsEnabled: true
      alwaysOn: appServiceSku != 'F1'
      http20Enabled: true
      minTlsVersion: '1.2'
      appSettings: [
        {
          name: 'APPLICATIONINSIGHTS_CONNECTION_STRING'
          value: appi.properties.ConnectionString
        }
        {
          name: 'ASPNETCORE_FORWARDEDHEADERS_ENABLED'
          value: 'true'
        }
        {
          name: 'Storage__Kind'
          value: 'AzureBlob'
        }
        {
          name: 'Storage__ConnectionString'
          value: storageConnection
        }
        {
          name: 'Storage__Container'
          value: 'assembly-data'
        }
      ]
    }
  }
}

output webAppName string = app.name
output webAppDefaultHostname string = app.properties.defaultHostName
output storageAccountName string = storage.name
