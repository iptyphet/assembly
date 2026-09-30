using System.Security.Claims;
using AssemblyApp.Core.Domain;
using AssemblyApp.Core.State;
using Microsoft.AspNetCore.Components.Authorization;

namespace AssemblyApp.Web.Services;

/// <summary>
/// Resolves the signed-in cookie principal to the live AppUser. Roles are
/// always read from AppState, never from claims, so grants take effect
/// without re-login.
/// </summary>
public sealed class CurrentUserService
{
    private readonly AuthenticationStateProvider provider;
    private readonly AppState state;

    public CurrentUserService( AuthenticationStateProvider provider, AppState state )
    {
        this.provider = provider;
        this.state = state;
    }

    public async Task<AppUser?> GetAsync()
    {
        var auth = await this.provider.GetAuthenticationStateAsync();
        var id = auth.User.FindFirstValue( ClaimTypes.NameIdentifier );

        return id == null
            ? null
            : this.state.GetUser( id );
    }
}
