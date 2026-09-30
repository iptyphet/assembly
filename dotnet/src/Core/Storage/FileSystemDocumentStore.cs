namespace AssemblyApp.Core.Storage;

/// <summary>
/// Stores documents as JSON files under a root directory. Backup of the
/// whole assembly = copy the folder. Used for local/venue hosting.
/// </summary>
public sealed class FileSystemDocumentStore : IDocumentStore
{
    private readonly string root;

    public FileSystemDocumentStore( string root )
    {
        this.root = Path.GetFullPath( root );
        Directory.CreateDirectory( this.root );
    }

    public Task<StoredDocument?> ReadAsync( string key, CancellationToken ct = default )
    {
        var path = this.PathFor( key );

        if( !File.Exists( path ) )
            return Task.FromResult<StoredDocument?>( null );

        var json = File.ReadAllText( path );
        var etag = ETagOf( path );

        return Task.FromResult<StoredDocument?>( new StoredDocument( json, etag ) );
    }

    public Task<string> WriteAsync
    (
        string key,
        string json,
        string? etag = null,
        CancellationToken ct = default
    )
    {
        var path = this.PathFor( key );

        if
        (
            etag != null
            && File.Exists( path )
            && ETagOf( path ) != etag
        )
            throw new StoreConcurrencyException( key );

        Directory.CreateDirectory( Path.GetDirectoryName( path )! );

        var temp = path + ".tmp";
        File.WriteAllText( temp, json );
        File.Move( temp, path, overwrite: true );

        return Task.FromResult( ETagOf( path ) );
    }

    public Task<IReadOnlyList<string>> ListKeysAsync( string prefix, CancellationToken ct = default )
    {
        var dir = this.PathFor( prefix );

        if( !Directory.Exists( dir ) )
            return Task.FromResult<IReadOnlyList<string>>( [] );

        var keys = Directory
            .EnumerateFiles( dir, "*.json", SearchOption.AllDirectories )
            .Select
            (
                f => Path
                    .GetRelativePath( this.root, f )
                    .Replace( Path.DirectorySeparatorChar, '/' )
            )
            .OrderBy( k => k, StringComparer.Ordinal )
            .ToList();

        return Task.FromResult<IReadOnlyList<string>>( keys );
    }

    public Task DeleteAsync( string key, CancellationToken ct = default )
    {
        var path = this.PathFor( key );

        if( File.Exists( path ) )
            File.Delete( path );

        return Task.CompletedTask;
    }

    private string PathFor( string key )
    {
        var relative = key.Replace( '/', Path.DirectorySeparatorChar );
        var full = Path.GetFullPath( Path.Combine( this.root, relative ) );

        if( !full.StartsWith( this.root, StringComparison.Ordinal ) )
            throw new ArgumentException( $"Key escapes store root: '{key}'." );

        return full;
    }

    private static string ETagOf( string path )
        => File
            .GetLastWriteTimeUtc( path )
            .Ticks
            .ToString();
}
