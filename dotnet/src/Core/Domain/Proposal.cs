using System.Text.Json.Serialization;

namespace AssemblyApp.Core.Domain;

public enum ProposalStatus
{
    Draft = 0,
    InWorkingGroup = 1,
    ReadyForSession = 2,
    Adopted = 3,
    Rejected = 4,
    Withdrawn = 5,
}

public sealed record Clause( string Id, string Text );

public sealed record ProposalVersion
(
    int Number,
    IReadOnlyList<Clause> Clauses,
    string CreatedByUserId,
    string CreatedByName,
    DateTimeOffset CreatedAtUtc,
    string? Note
);

public sealed class Proposal
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string Title { get; set; } = "";
    public ProposalStatus Status { get; set; } = ProposalStatus.Draft;
    public string? OriginSuggestionId { get; set; }
    public string? WorkingGroupId { get; set; }
    public List<ProposalVersion> Versions { get; set; } = new();
    public List<Amendment> Amendments { get; set; } = new();
    public string CreatedByUserId { get; set; } = "";
    public string CreatedByName { get; set; } = "";
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    [JsonIgnore]
    public ProposalVersion Latest => this.Versions[^1];
}
