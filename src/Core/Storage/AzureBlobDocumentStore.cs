using Azure;
using Azure.Storage.Blobs;
using Azure.Storage.Blobs.Models;

namespace AssemblyApp.Core.Storage;

/// <summary>
/// Stores documents as blobs in a single container. Used for the
/// Azure-hosted deployment; pennies at convention scale.
/// </summary>
public sealed class AzureBlobDocumentStore : IDocumentStore
{
    private readonly BlobContainerClient container;

    public AzureBlobDocumentStore( string connectionString, string containerName )
    {
        this.container = new BlobContainerClient( connectionString, containerName );
        this.container.CreateIfNotExists();
    }

    public async Task<StoredDocument?> ReadAsync( string key, CancellationToken ct = default )
    {
        try
        {
            var response = await this.container
                .GetBlobClient( key )
                .DownloadContentAsync( ct );

            return new StoredDocument
            (
                response.Value.Content.ToString(),
                response.Value.Details.ETag.ToString( "H" )
            );
        }
        catch( RequestFailedException e ) when( e.Status == 404 )
        {
            return null;
        }
    }

    public async Task<string> WriteAsync
    (
        string key,
        string json,
        string? etag = null,
        CancellationToken ct = default
    )
    {
        var options = new BlobUploadOptions();

        if( etag != null )
            options.Conditions = new BlobRequestConditions
            {
                IfMatch = new ETag( etag ),
            };

        try
        {
            var response = await this.container
                .GetBlobClient( key )
                .UploadAsync( BinaryData.FromString( json ), options, ct );

            return response.Value.ETag.ToString( "H" );
        }
        catch( RequestFailedException e ) when( e.Status == 412 )
        {
            throw new StoreConcurrencyException( key );
        }
    }

    public async Task<IReadOnlyList<string>> ListKeysAsync( string prefix, CancellationToken ct = default )
    {
        var keys = new List<string>();

        await foreach( var blob in this.container.GetBlobsAsync( prefix: prefix, cancellationToken: ct ) )
            keys.Add( blob.Name );

        return keys;
    }

    public async Task DeleteAsync( string key, CancellationToken ct = default )
        => await this.container
            .GetBlobClient( key )
            .DeleteIfExistsAsync( cancellationToken: ct );
}
