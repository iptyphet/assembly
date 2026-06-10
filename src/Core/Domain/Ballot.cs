namespace AssemblyApp.Core.Domain;

public enum BallotMode
{
    /// <summary>Roll-call vote: who voted what is public.</summary>
    Open = 0,

    /// <summary>Secret ballot: votes are keyed by an anonymous handle.</summary>
    Secret = 1,
}

public enum BallotState
{
    Draft = 0,
    Open = 1,
    Closed = 2,
}

/// <summary>Ternary vote model, borrowed from Iptyphet.Core.Voting.</summary>
public enum VoteValue
{
    For = 0,
    Against = 1,
    Abstain = 2,
}

public sealed class Ballot
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string SessionId { get; set; } = "";
    public string? AgendaItemId { get; set; }
    public string? ProposalId { get; set; }
    public string Title { get; set; } = "";
    public BallotMode Mode { get; set; } = BallotMode.Open;
    public BallotState State { get; set; } = BallotState.Draft;
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? OpenedAtUtc { get; set; }
    public DateTimeOffset? ClosedAtUtc { get; set; }
}

public sealed class Vote
{
    public string BallotId { get; set; } = "";

    /// <summary>User id for open ballots; anonymous HMAC handle for secret ballots.</summary>
    public string VoterHandle { get; set; } = "";

    /// <summary>Only set on open (roll-call) ballots.</summary>
    public string? VoterDisplayName { get; set; }

    public VoteValue Value { get; set; }
    public DateTimeOffset CastAtUtc { get; set; } = DateTimeOffset.UtcNow;
}

public sealed record VoteTally( int For, int Against, int Abstain )
{
    public int Total => this.For + this.Against + this.Abstain;
}
