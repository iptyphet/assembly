namespace AssemblyApp.Core.Domain;

public enum AmendmentKind
{
    ReplaceClause = 0,
    StrikeClause = 1,
    InsertClauseAfter = 2,
}

public enum AmendmentStatus
{
    Proposed = 0,
    Accepted = 1,
    Rejected = 2,
    Withdrawn = 3,
}

public sealed class Amendment
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );

    /// <summary>Version of the proposal this amendment was written against.</summary>
    public int TargetVersion { get; set; }

    /// <summary>Clause being amended. Null for InsertClauseAfter at the top of the document.</summary>
    public string? ClauseId { get; set; }

    public AmendmentKind Kind { get; set; }

    /// <summary>Replacement or inserted text. Null for StrikeClause.</summary>
    public string? NewText { get; set; }

    public AmendmentStatus Status { get; set; } = AmendmentStatus.Proposed;
    public string ProposedByUserId { get; set; } = "";
    public string ProposedByName { get; set; } = "";
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;
}
