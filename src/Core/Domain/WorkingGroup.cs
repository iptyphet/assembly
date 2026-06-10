namespace AssemblyApp.Core.Domain;

public enum JoinPolicy
{
    Open = 0,
    Approval = 1,
    Appointed = 2,
}

public sealed class GroupMember
{
    public string UserId { get; set; } = "";
    public string DisplayName { get; set; } = "";
    public bool IsLead { get; set; }
    public bool Pending { get; set; }
    public DateTimeOffset JoinedAtUtc { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class WorkingGroup
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string Name { get; set; } = "";
    public string Description { get; set; } = "";
    public JoinPolicy JoinPolicy { get; set; } = JoinPolicy.Open;
    public List<GroupMember> Members { get; set; } = new();
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    public GroupMember? FindMember( string userId )
        => this.Members.FirstOrDefault( m => m.UserId == userId );

    public bool IsLeadOrAdmin( AppUser user )
        => user.IsAdmin
        || this.FindMember( user.Id ) is { IsLead: true, Pending: false };
}
