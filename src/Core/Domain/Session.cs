using System.Text.Json.Serialization;

namespace AssemblyApp.Core.Domain;

public enum SpeakerKind
{
    Speech = 0,
    Reply = 1,
}

public sealed class SpeakerEntry
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string UserId { get; set; } = "";
    public string DisplayName { get; set; } = "";
    public SpeakerKind Kind { get; set; } = SpeakerKind.Speech;
    public DateTimeOffset RequestedAtUtc { get; set; } = DateTimeOffset.UtcNow;
    public bool Done { get; set; }
}

public sealed class AgendaItem
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string Title { get; set; } = "";
    public string? ProposalId { get; set; }
    public bool SpeakersOpen { get; set; } = true;
    public List<SpeakerEntry> Speakers { get; set; } = new();
    public string? NowSpeakingEntryId { get; set; }

    [JsonIgnore]
    public SpeakerEntry? NowSpeaking
        => this.Speakers.FirstOrDefault( s => s.Id == this.NowSpeakingEntryId );

    /// <summary>
    /// Pending queue in floor order: replies jump ahead of speeches
    /// (Nordic innlegg/replikk convention), otherwise first come first serve.
    /// </summary>
    [JsonIgnore]
    public IReadOnlyList<SpeakerEntry> Queue
        => this.Speakers
            .Where( s => !s.Done && s.Id != this.NowSpeakingEntryId )
            .OrderBy( s => s.Kind == SpeakerKind.Reply ? 0 : 1 )
            .ThenBy( s => s.RequestedAtUtc )
            .ToList();
}

public sealed class Session
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string Title { get; set; } = "";
    public DateTimeOffset? ScheduledForUtc { get; set; }
    public List<AgendaItem> AgendaItems { get; set; } = new();
    public string? CurrentAgendaItemId { get; set; }
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    [JsonIgnore]
    public AgendaItem? CurrentAgendaItem
        => this.AgendaItems.FirstOrDefault( a => a.Id == this.CurrentAgendaItemId );
}
