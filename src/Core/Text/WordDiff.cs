namespace AssemblyApp.Core.Text;

public enum DiffKind
{
    Same = 0,
    Removed = 1,
    Added = 2,
}

public sealed record DiffSegment( DiffKind Kind, string Text );

/// <summary>
/// Word-level diff (longest common subsequence) used to render amendments
/// with strike-through for removed text and highlight for inserted text.
/// </summary>
public static class WordDiff
{
    public static IReadOnlyList<DiffSegment> Compute( string oldText, string newText )
    {
        var oldWords = Tokenize( oldText );
        var newWords = Tokenize( newText );

        var lcs = LcsTable( oldWords, newWords );
        var segments = new List<DiffSegment>();

        Backtrack
        (
            lcs,
            oldWords,
            newWords,
            oldWords.Count,
            newWords.Count,
            segments
        );

        return Coalesce( segments );
    }

    private static List<string> Tokenize( string text )
        => text
            .Split( ' ', StringSplitOptions.RemoveEmptyEntries )
            .ToList();

    private static int[,] LcsTable( List<string> a, List<string> b )
    {
        var table = new int[a.Count + 1, b.Count + 1];

        for( var i = 1; i <= a.Count; i++ )
        for( var j = 1; j <= b.Count; j++ )
            table[i, j] = a[i - 1] == b[j - 1]
                ? table[i - 1, j - 1] + 1
                : Math.Max( table[i - 1, j], table[i, j - 1] );

        return table;
    }

    private static void Backtrack
    (
        int[,] table,
        List<string> a,
        List<string> b,
        int i,
        int j,
        List<DiffSegment> output
    )
    {
        if
        (
            i > 0
            && j > 0
            && a[i - 1] == b[j - 1]
        )
        {
            Backtrack( table, a, b, i - 1, j - 1, output );
            output.Add( new DiffSegment( DiffKind.Same, a[i - 1] ) );
        }
        else if
        (
            j > 0
            &&
            (
                i == 0
                || table[i, j - 1] >= table[i - 1, j]
            )
        )
        {
            Backtrack( table, a, b, i, j - 1, output );
            output.Add( new DiffSegment( DiffKind.Added, b[j - 1] ) );
        }
        else if( i > 0 )
        {
            Backtrack( table, a, b, i - 1, j, output );
            output.Add( new DiffSegment( DiffKind.Removed, a[i - 1] ) );
        }
    }

    private static IReadOnlyList<DiffSegment> Coalesce( List<DiffSegment> segments )
    {
        var result = new List<DiffSegment>();

        foreach( var segment in segments )
        {
            if
            (
                result.Count > 0
                && result[^1].Kind == segment.Kind
            )
                result[^1] = result[^1] with { Text = result[^1].Text + " " + segment.Text };
            else
                result.Add( segment );
        }

        return result;
    }
}
