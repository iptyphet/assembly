using System.Text.Json.Serialization;

namespace AssemblyApp.Core.Domain;

public static class Roles
{
    public const string Admin = "Admin";
    public const string Chair = "Chair";
}

public sealed class AppUser
{
    public string Id { get; set; } = Guid.NewGuid().ToString( "N" );
    public string UserName { get; set; } = "";
    public string DisplayName { get; set; } = "";
    public string PasswordHash { get; set; } = "";
    public List<string> Roles { get; set; } = new();
    public DateTimeOffset CreatedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    [JsonIgnore]
    public bool IsAdmin => this.Roles.Contains( Domain.Roles.Admin );

    [JsonIgnore]
    public bool IsChair => this.Roles.Contains( Domain.Roles.Chair ) || this.IsAdmin;
}
