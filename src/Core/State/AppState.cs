using System.Collections.Concurrent;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using AssemblyApp.Core.Auth;
using AssemblyApp.Core.Domain;
using AssemblyApp.Core.Storage;

namespace AssemblyApp.Core.State;

/// <summary>
/// Single source of truth for the whole assembly. All state lives in memory;
/// the document store is a write-through durability layer. One instance per
/// server process; mutations are serialized through a single gate.
/// </summary>
public sealed class AppState
{
    private readonly IDocumentStore store;
    private readonly SemaphoreSlim gate = new( 1, 1 );

    private readonly ConcurrentDictionary<string, AppUser> users = new();
    private readonly ConcurrentDictionary<string, Proposal> proposals = new();
    private readonly ConcurrentDictionary<string, WorkingGroup> groups = new();
    private readonly ConcurrentDictionary<string, Session> sessions = new();
    private readonly ConcurrentDictionary<string, Ballot> ballots = new();
    private readonly ConcurrentDictionary<string, ConcurrentDictionary<string, Vote>> votes = new();

    private byte[] secret = [];

    private static readonly JsonSerializerOptions JsonOptions = new( JsonSerializerDefaults.Web )
    {
        WriteIndented = true,
        Converters = { new JsonStringEnumConverter() },
    };

    public AppState( IDocumentStore store )
    {
        this.store = store;
    }

    /// <summary>Raised after every committed mutation. UI components subscribe to re-render.</summary>
    public event Action? Changed;

    private void NotifyChanged()
        => this.Changed?.Invoke();

    // ---------------------------------------------------------------- loading

    public async Task InitializeAsync( CancellationToken ct = default )
    {
        await this.LoadAllAsync( "users/", this.users, ( AppUser u ) => u.Id, ct );
        await this.LoadAllAsync( "proposals/", this.proposals, ( Proposal p ) => p.Id, ct );
        await this.LoadAllAsync( "groups/", this.groups, ( WorkingGroup g ) => g.Id, ct );
        await this.LoadAllAsync( "sessions/", this.sessions, ( Session s ) => s.Id, ct );
        await this.LoadAllAsync( "ballots/", this.ballots, ( Ballot b ) => b.Id, ct );
        await this.LoadVotesAsync( ct );
        await this.LoadOrCreateSecretAsync( ct );
    }

    private async Task LoadAllAsync<T>
    (
        string prefix,
        ConcurrentDictionary<string, T> target,
        Func<T, string> keyOf,
        CancellationToken ct
    )
    {
        foreach( var key in await this.store.ListKeysAsync( prefix, ct ) )
        {
            var doc = await this.store.ReadAsync( key, ct );

            if( doc == null )
                continue;

            var item = JsonSerializer.Deserialize<T>( doc.Json, JsonOptions );

            if( item != null )
                target[keyOf( item )] = item;
        }
    }

    private async Task LoadVotesAsync( CancellationToken ct )
    {
        foreach( var key in await this.store.ListKeysAsync( "votes/", ct ) )
        {
            var doc = await this.store.ReadAsync( key, ct );

            if( doc == null )
                continue;

            var vote = JsonSerializer.Deserialize<Vote>( doc.Json, JsonOptions );

            if( vote != null )
                this.VotesFor( vote.BallotId )[vote.VoterHandle] = vote;
        }
    }

    private async Task LoadOrCreateSecretAsync( CancellationToken ct )
    {
        var doc = await this.store.ReadAsync( "system/secret.json", ct );

        if( doc != null )
        {
            var stored = JsonSerializer.Deserialize<SecretDocument>( doc.Json, JsonOptions );
            this.secret = Convert.FromBase64String( stored!.Value );
            return;
        }

        this.secret = RandomNumberGenerator.GetBytes( 32 );

        await this.store.WriteAsync
        (
            "system/secret.json",
            JsonSerializer.Serialize( new SecretDocument( Convert.ToBase64String( this.secret ) ), JsonOptions ),
            null,
            ct
        );
    }

    private sealed record SecretDocument( string Value );

    // ---------------------------------------------------------------- queries

    public IReadOnlyList<AppUser> Users
        => this.users.Values.OrderBy( u => u.DisplayName ).ToList();

    public IReadOnlyList<Proposal> Proposals
        => this.proposals.Values.OrderByDescending( p => p.CreatedAtUtc ).ToList();

    public IReadOnlyList<WorkingGroup> Groups
        => this.groups.Values.OrderBy( g => g.Name ).ToList();

    public IReadOnlyList<Session> Sessions
        => this.sessions.Values.OrderByDescending( s => s.CreatedAtUtc ).ToList();

    public AppUser? GetUser( string id )
        => this.users.GetValueOrDefault( id );

    public Proposal? GetProposal( string id )
        => this.proposals.GetValueOrDefault( id );

    public WorkingGroup? GetGroup( string id )
        => this.groups.GetValueOrDefault( id );

    public Session? GetSession( string id )
        => this.sessions.GetValueOrDefault( id );

    public Ballot? GetBallot( string id )
        => this.ballots.GetValueOrDefault( id );

    public IReadOnlyList<Ballot> BallotsForSession( string sessionId )
        => this.ballots.Values
            .Where( b => b.SessionId == sessionId )
            .OrderBy( b => b.CreatedAtUtc )
            .ToList();

    public IReadOnlyList<Proposal> ProposalsForGroup( string groupId )
        => this.proposals.Values
            .Where( p => p.WorkingGroupId == groupId )
            .OrderBy( p => p.Title )
            .ToList();

    public VoteTally TallyOf( string ballotId )
    {
        var cast = this.VotesFor( ballotId ).Values;

        return new VoteTally
        (
            cast.Count( v => v.Value == VoteValue.For ),
            cast.Count( v => v.Value == VoteValue.Against ),
            cast.Count( v => v.Value == VoteValue.Abstain )
        );
    }

    public bool HasVoted( string ballotId, AppUser user )
    {
        var ballot = this.GetBallot( ballotId );

        if( ballot == null )
            return false;

        return this
            .VotesFor( ballotId )
            .ContainsKey( this.HandleFor( ballot, user ) );
    }

    /// <summary>Roll-call display for open ballots.</summary>
    public IReadOnlyList<Vote> RollCallOf( string ballotId )
        => this.VotesFor( ballotId ).Values
            .OrderBy( v => v.VoterDisplayName )
            .ToList();

    // ---------------------------------------------------------------- users

    public async Task<AppUser> RegisterUserAsync( string userName, string displayName, string password )
    {
        await this.gate.WaitAsync();

        try
        {
            userName = userName.Trim().ToLowerInvariant();

            if( userName.Length < 2 )
                throw new InvalidOperationException( "Username must be at least 2 characters." );

            if( password.Length < 6 )
                throw new InvalidOperationException( "Password must be at least 6 characters." );

            if( this.users.Values.Any( u => u.UserName == userName ) )
                throw new InvalidOperationException( "Username is taken." );

            var user = new AppUser
            {
                UserName = userName,
                DisplayName = string.IsNullOrWhiteSpace( displayName ) ? userName : displayName.Trim(),
                PasswordHash = PasswordHasher.Hash( password ),
            };

            if( this.users.IsEmpty )
                user.Roles.Add( Roles.Admin );

            this.users[user.Id] = user;
            await this.SaveAsync( $"users/{user.Id}.json", user );
            this.NotifyChanged();

            return user;
        }
        finally
        {
            this.gate.Release();
        }
    }

    public AppUser? ValidateLogin( string userName, string password )
    {
        var user = this.users.Values
            .FirstOrDefault( u => u.UserName == userName.Trim().ToLowerInvariant() );

        if( user == null )
            return null;

        return PasswordHasher.Verify( password, user.PasswordHash )
            ? user
            : null;
    }

    public async Task SetRoleAsync( string userId, string role, bool granted )
    {
        await this.gate.WaitAsync();

        try
        {
            var user = this.RequireUser( userId );

            if( granted && !user.Roles.Contains( role ) )
                user.Roles.Add( role );

            if( !granted )
                user.Roles.Remove( role );

            await this.SaveAsync( $"users/{user.Id}.json", user );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    // ---------------------------------------------------------------- proposals

    public async Task<Proposal> CreateProposalAsync( string title, string text, AppUser author )
    {
        await this.gate.WaitAsync();

        try
        {
            if( string.IsNullOrWhiteSpace( title ) )
                throw new InvalidOperationException( "Title is required." );

            var clauses = ParseClauses( text );

            if( clauses.Count == 0 )
                throw new InvalidOperationException( "Proposal text is required." );

            var proposal = new Proposal
            {
                Title = title.Trim(),
                CreatedByUserId = author.Id,
                CreatedByName = author.DisplayName,
            };

            proposal.Versions.Add
            (
                new ProposalVersion
                (
                    1,
                    clauses,
                    author.Id,
                    author.DisplayName,
                    DateTimeOffset.UtcNow,
                    "Initial version"
                )
            );

            this.proposals[proposal.Id] = proposal;
            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();

            return proposal;
        }
        finally
        {
            this.gate.Release();
        }
    }

    /// <summary>Working-group tuning: publish a new version with fresh text.</summary>
    public async Task AddVersionAsync( string proposalId, string text, string? note, AppUser author )
    {
        await this.gate.WaitAsync();

        try
        {
            var proposal = this.RequireProposal( proposalId );
            var clauses = ParseClauses( text );

            if( clauses.Count == 0 )
                throw new InvalidOperationException( "Proposal text is required." );

            proposal.Versions.Add
            (
                new ProposalVersion
                (
                    proposal.Latest.Number + 1,
                    clauses,
                    author.Id,
                    author.DisplayName,
                    DateTimeOffset.UtcNow,
                    note
                )
            );

            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task<Amendment> ProposeAmendmentAsync
    (
        string proposalId,
        string? clauseId,
        AmendmentKind kind,
        string? newText,
        AppUser author
    )
    {
        await this.gate.WaitAsync();

        try
        {
            var proposal = this.RequireProposal( proposalId );

            if
            (
                kind != AmendmentKind.StrikeClause
                && string.IsNullOrWhiteSpace( newText )
            )
                throw new InvalidOperationException( "Amendment text is required." );

            if
            (
                kind != AmendmentKind.InsertClauseAfter
                && proposal.Latest.Clauses.All( c => c.Id != clauseId )
            )
                throw new InvalidOperationException( "Clause not found in the current version." );

            var amendment = new Amendment
            {
                TargetVersion = proposal.Latest.Number,
                ClauseId = clauseId,
                Kind = kind,
                NewText = newText?.Trim(),
                ProposedByUserId = author.Id,
                ProposedByName = author.DisplayName,
            };

            proposal.Amendments.Add( amendment );
            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();

            return amendment;
        }
        finally
        {
            this.gate.Release();
        }
    }

    /// <summary>Accepting an amendment applies it to the latest version and publishes a new one.</summary>
    public async Task SetAmendmentStatusAsync
    (
        string proposalId,
        string amendmentId,
        AmendmentStatus status,
        AppUser actor
    )
    {
        await this.gate.WaitAsync();

        try
        {
            var proposal = this.RequireProposal( proposalId );

            var amendment = proposal.Amendments.FirstOrDefault( a => a.Id == amendmentId )
                ?? throw new InvalidOperationException( "Amendment not found." );

            if( amendment.Status != AmendmentStatus.Proposed )
                throw new InvalidOperationException( "Amendment is already settled." );

            if( status == AmendmentStatus.Accepted )
            {
                var clauses = ApplyAmendment( proposal.Latest.Clauses, amendment );

                proposal.Versions.Add
                (
                    new ProposalVersion
                    (
                        proposal.Latest.Number + 1,
                        clauses,
                        actor.Id,
                        actor.DisplayName,
                        DateTimeOffset.UtcNow,
                        $"Amendment by {amendment.ProposedByName} accepted"
                    )
                );
            }

            amendment.Status = status;
            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task SetProposalStatusAsync( string proposalId, ProposalStatus status )
    {
        await this.gate.WaitAsync();

        try
        {
            var proposal = this.RequireProposal( proposalId );
            proposal.Status = status;
            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task AssignToGroupAsync( string proposalId, string? groupId )
    {
        await this.gate.WaitAsync();

        try
        {
            var proposal = this.RequireProposal( proposalId );

            if
            (
                groupId != null
                && !this.groups.ContainsKey( groupId )
            )
                throw new InvalidOperationException( "Working group not found." );

            proposal.WorkingGroupId = groupId;

            if
            (
                groupId != null
                && proposal.Status == ProposalStatus.Draft
            )
                proposal.Status = ProposalStatus.InWorkingGroup;

            await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    private static List<Clause> ParseClauses( string text )
        => text
            .Replace( "\r\n", "\n" )
            .Split( '\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries )
            .Select( line => new Clause( Guid.NewGuid().ToString( "N" ), line ) )
            .ToList();

    private static List<Clause> ApplyAmendment( IReadOnlyList<Clause> clauses, Amendment amendment )
    {
        var result = clauses.ToList();
        var index = result.FindIndex( c => c.Id == amendment.ClauseId );

        switch( amendment.Kind )
        {
            case AmendmentKind.ReplaceClause:
                if( index < 0 )
                    throw new InvalidOperationException( "Clause no longer exists in the current version." );

                result[index] = result[index] with { Text = amendment.NewText! };
                break;

            case AmendmentKind.StrikeClause:
                if( index < 0 )
                    throw new InvalidOperationException( "Clause no longer exists in the current version." );

                result.RemoveAt( index );
                break;

            case AmendmentKind.InsertClauseAfter:
                var clause = new Clause( Guid.NewGuid().ToString( "N" ), amendment.NewText! );
                result.Insert( index < 0 ? result.Count : index + 1, clause );
                break;
        }

        return result;
    }

    // ---------------------------------------------------------------- groups

    public async Task<WorkingGroup> CreateGroupAsync
    (
        string name,
        string description,
        JoinPolicy policy,
        AppUser creator
    )
    {
        await this.gate.WaitAsync();

        try
        {
            if( string.IsNullOrWhiteSpace( name ) )
                throw new InvalidOperationException( "Group name is required." );

            var group = new WorkingGroup
            {
                Name = name.Trim(),
                Description = description.Trim(),
                JoinPolicy = policy,
            };

            group.Members.Add
            (
                new GroupMember
                {
                    UserId = creator.Id,
                    DisplayName = creator.DisplayName,
                    IsLead = true,
                }
            );

            this.groups[group.Id] = group;
            await this.SaveAsync( $"groups/{group.Id}.json", group );
            this.NotifyChanged();

            return group;
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task JoinGroupAsync( string groupId, AppUser user )
    {
        await this.gate.WaitAsync();

        try
        {
            var group = this.RequireGroup( groupId );

            if( group.JoinPolicy == JoinPolicy.Appointed )
                throw new InvalidOperationException( "Members of this group are appointed." );

            if( group.FindMember( user.Id ) != null )
                throw new InvalidOperationException( "Already a member (or awaiting approval)." );

            group.Members.Add
            (
                new GroupMember
                {
                    UserId = user.Id,
                    DisplayName = user.DisplayName,
                    Pending = group.JoinPolicy == JoinPolicy.Approval,
                }
            );

            await this.SaveAsync( $"groups/{group.Id}.json", group );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task ApproveMemberAsync( string groupId, string userId, AppUser actor )
    {
        await this.gate.WaitAsync();

        try
        {
            var group = this.RequireGroup( groupId );

            if( !group.IsLeadOrAdmin( actor ) )
                throw new InvalidOperationException( "Only a group lead or admin can approve members." );

            var member = group.FindMember( userId )
                ?? throw new InvalidOperationException( "No such membership request." );

            member.Pending = false;
            await this.SaveAsync( $"groups/{group.Id}.json", group );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task AppointMemberAsync( string groupId, string userId, AppUser actor )
    {
        await this.gate.WaitAsync();

        try
        {
            var group = this.RequireGroup( groupId );

            if( !group.IsLeadOrAdmin( actor ) )
                throw new InvalidOperationException( "Only a group lead or admin can appoint members." );

            var user = this.RequireUser( userId );

            if( group.FindMember( user.Id ) != null )
                throw new InvalidOperationException( "Already a member." );

            group.Members.Add
            (
                new GroupMember
                {
                    UserId = user.Id,
                    DisplayName = user.DisplayName,
                }
            );

            await this.SaveAsync( $"groups/{group.Id}.json", group );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    // ---------------------------------------------------------------- sessions

    public async Task<Session> CreateSessionAsync( string title, DateTimeOffset? scheduledForUtc )
    {
        await this.gate.WaitAsync();

        try
        {
            if( string.IsNullOrWhiteSpace( title ) )
                throw new InvalidOperationException( "Session title is required." );

            var session = new Session
            {
                Title = title.Trim(),
                ScheduledForUtc = scheduledForUtc,
            };

            this.sessions[session.Id] = session;
            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();

            return session;
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task AddAgendaItemAsync( string sessionId, string title, string? proposalId )
    {
        await this.gate.WaitAsync();

        try
        {
            var session = this.RequireSession( sessionId );

            if( string.IsNullOrWhiteSpace( title ) )
                throw new InvalidOperationException( "Agenda item title is required." );

            session.AgendaItems.Add
            (
                new AgendaItem
                {
                    Title = title.Trim(),
                    ProposalId = proposalId,
                }
            );

            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task SetCurrentAgendaItemAsync( string sessionId, string? agendaItemId )
    {
        await this.gate.WaitAsync();

        try
        {
            var session = this.RequireSession( sessionId );
            session.CurrentAgendaItemId = agendaItemId;
            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task RequestSpeechAsync( string sessionId, string agendaItemId, AppUser user, SpeakerKind kind )
    {
        await this.gate.WaitAsync();

        try
        {
            var (session, item) = this.RequireAgendaItem( sessionId, agendaItemId );

            if( !item.SpeakersOpen )
                throw new InvalidOperationException( "The speakers list is closed." );

            if
            (
                item.Speakers.Any
                (
                    s => s.UserId == user.Id
                    && !s.Done
                    && s.Id != item.NowSpeakingEntryId
                )
            )
                throw new InvalidOperationException( "You are already on the speakers list." );

            item.Speakers.Add
            (
                new SpeakerEntry
                {
                    UserId = user.Id,
                    DisplayName = user.DisplayName,
                    Kind = kind,
                }
            );

            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task WithdrawSpeechAsync( string sessionId, string agendaItemId, AppUser user )
    {
        await this.gate.WaitAsync();

        try
        {
            var (session, item) = this.RequireAgendaItem( sessionId, agendaItemId );

            item.Speakers.RemoveAll
            (
                s => s.UserId == user.Id
                && !s.Done
                && s.Id != item.NowSpeakingEntryId
            );

            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    /// <summary>Chair action: finish the current speaker and call the next from the queue.</summary>
    public async Task NextSpeakerAsync( string sessionId, string agendaItemId )
    {
        await this.gate.WaitAsync();

        try
        {
            var (session, item) = this.RequireAgendaItem( sessionId, agendaItemId );

            if( item.NowSpeaking is { } current )
                current.Done = true;

            item.NowSpeakingEntryId = item.Queue.FirstOrDefault()?.Id;
            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task SetSpeakersOpenAsync( string sessionId, string agendaItemId, bool open )
    {
        await this.gate.WaitAsync();

        try
        {
            var (session, item) = this.RequireAgendaItem( sessionId, agendaItemId );
            item.SpeakersOpen = open;
            await this.SaveAsync( $"sessions/{session.Id}.json", session );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    // ---------------------------------------------------------------- ballots

    public async Task<Ballot> CreateBallotAsync
    (
        string sessionId,
        string? agendaItemId,
        string? proposalId,
        string title,
        BallotMode mode
    )
    {
        await this.gate.WaitAsync();

        try
        {
            this.RequireSession( sessionId );

            if( string.IsNullOrWhiteSpace( title ) )
                throw new InvalidOperationException( "Ballot title is required." );

            var ballot = new Ballot
            {
                SessionId = sessionId,
                AgendaItemId = agendaItemId,
                ProposalId = proposalId,
                Title = title.Trim(),
                Mode = mode,
            };

            this.ballots[ballot.Id] = ballot;
            await this.SaveAsync( $"ballots/{ballot.Id}.json", ballot );
            this.NotifyChanged();

            return ballot;
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task OpenBallotAsync( string ballotId )
    {
        await this.gate.WaitAsync();

        try
        {
            var ballot = this.RequireBallot( ballotId );

            if( ballot.State != BallotState.Draft )
                throw new InvalidOperationException( "Ballot is not in draft state." );

            ballot.State = BallotState.Open;
            ballot.OpenedAtUtc = DateTimeOffset.UtcNow;
            await this.SaveAsync( $"ballots/{ballot.Id}.json", ballot );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    public async Task CloseBallotAsync( string ballotId )
    {
        await this.gate.WaitAsync();

        try
        {
            var ballot = this.RequireBallot( ballotId );

            if( ballot.State != BallotState.Open )
                throw new InvalidOperationException( "Ballot is not open." );

            ballot.State = BallotState.Closed;
            ballot.ClosedAtUtc = DateTimeOffset.UtcNow;
            await this.SaveAsync( $"ballots/{ballot.Id}.json", ballot );

            if( ballot.ProposalId is { } proposalId )
                await this.SettleProposalAsync( proposalId, ballot );

            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    private async Task SettleProposalAsync( string proposalId, Ballot ballot )
    {
        var proposal = this.proposals.GetValueOrDefault( proposalId );

        if( proposal == null )
            return;

        var tally = this.TallyOf( ballot.Id );

        proposal.Status = tally.For > tally.Against
            ? ProposalStatus.Adopted
            : ProposalStatus.Rejected;

        await this.SaveAsync( $"proposals/{proposal.Id}.json", proposal );
    }

    public async Task CastVoteAsync( string ballotId, AppUser voter, VoteValue value )
    {
        await this.gate.WaitAsync();

        try
        {
            var ballot = this.RequireBallot( ballotId );

            if( ballot.State != BallotState.Open )
                throw new InvalidOperationException( "Voting is not open." );

            var handle = this.HandleFor( ballot, voter );

            var vote = new Vote
            {
                BallotId = ballotId,
                VoterHandle = handle,
                VoterDisplayName = ballot.Mode == BallotMode.Open ? voter.DisplayName : null,
                Value = value,
            };

            this.VotesFor( ballotId )[handle] = vote;
            await this.SaveAsync( $"votes/{ballotId}/{handle}.json", vote );
            this.NotifyChanged();
        }
        finally
        {
            this.gate.Release();
        }
    }

    /// <summary>
    /// Open ballots: the handle is the user id (roll-call). Secret ballots:
    /// an HMAC of (ballotId, userId) under a server secret — deterministic so
    /// re-voting overwrites, but the user-to-handle link is never stored.
    /// Trust model: convention-grade; the server could recompute the link
    /// but does not persist it. Upgrade path: client-held Ed25519 credentials
    /// issued at QR check-in (see iptyphet's VoteSigner).
    /// </summary>
    private string HandleFor( Ballot ballot, AppUser user )
    {
        if( ballot.Mode == BallotMode.Open )
            return user.Id;

        var payload = Encoding.UTF8.GetBytes( $"{ballot.Id}:{user.Id}" );

        return Convert
            .ToHexString( HMACSHA256.HashData( this.secret, payload ) )
            .ToLowerInvariant();
    }

    // ---------------------------------------------------------------- helpers

    private ConcurrentDictionary<string, Vote> VotesFor( string ballotId )
        => this.votes.GetOrAdd( ballotId, _ => new ConcurrentDictionary<string, Vote>() );

    private async Task SaveAsync<T>( string key, T item )
        => await this.store.WriteAsync( key, JsonSerializer.Serialize( item, JsonOptions ) );

    private AppUser RequireUser( string id )
        => this.users.GetValueOrDefault( id )
            ?? throw new InvalidOperationException( "User not found." );

    private Proposal RequireProposal( string id )
        => this.proposals.GetValueOrDefault( id )
            ?? throw new InvalidOperationException( "Proposal not found." );

    private WorkingGroup RequireGroup( string id )
        => this.groups.GetValueOrDefault( id )
            ?? throw new InvalidOperationException( "Working group not found." );

    private Session RequireSession( string id )
        => this.sessions.GetValueOrDefault( id )
            ?? throw new InvalidOperationException( "Session not found." );

    private Ballot RequireBallot( string id )
        => this.ballots.GetValueOrDefault( id )
            ?? throw new InvalidOperationException( "Ballot not found." );

    private (Session, AgendaItem) RequireAgendaItem( string sessionId, string agendaItemId )
    {
        var session = this.RequireSession( sessionId );

        var item = session.AgendaItems.FirstOrDefault( a => a.Id == agendaItemId )
            ?? throw new InvalidOperationException( "Agenda item not found." );

        return (session, item);
    }
}
