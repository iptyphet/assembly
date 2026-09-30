namespace AssemblyApp.Core.Storage;

public sealed record StoredDocument( string Json, string ETag );

public sealed class StoreConcurrencyException : Exception
{
    public StoreConcurrencyException( string key )
        : base( $"Concurrent modification of document '{key}'." )
    {
    }
}

/// <summary>
/// Minimal JSON document store. Keys are forward-slash paths like
/// "proposals/abc123.json". Storage is a durability layer only — the
/// application serves all reads from memory (see AppState).
/// </summary>
public interface IDocumentStore
{
    Task<StoredDocument?> ReadAsync( string key, CancellationToken ct = default );

    /// <summary>Writes a document. Pass the last seen etag to detect lost updates. Returns the new etag.</summary>
    Task<string> WriteAsync( string key, string json, string? etag = null, CancellationToken ct = default );

    Task<IReadOnlyList<string>> ListKeysAsync( string prefix, CancellationToken ct = default );

    Task DeleteAsync( string key, CancellationToken ct = default );
}
