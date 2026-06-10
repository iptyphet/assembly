using System.Security.Claims;
using AssemblyApp.Core.State;
using AssemblyApp.Core.Storage;
using AssemblyApp.Web.Components;
using AssemblyApp.Web.Services;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Mvc;

var builder = WebApplication.CreateBuilder( args );

builder.Services
    .AddRazorComponents()
    .AddInteractiveServerComponents();

builder.Services
    .AddAuthentication( CookieAuthenticationDefaults.AuthenticationScheme )
    .AddCookie
    (
        options =>
        {
            options.LoginPath = "/login";
            options.AccessDeniedPath = "/login";
            options.ExpireTimeSpan = TimeSpan.FromDays( 30 );
            options.SlidingExpiration = true;
        }
    );

builder.Services.AddAuthorization();
builder.Services.AddCascadingAuthenticationState();
builder.Services.AddScoped<CurrentUserService>();

builder.Services.AddSingleton<IDocumentStore>
(
    _ => CreateDocumentStore( builder.Configuration )
);

builder.Services.AddSingleton<AppState>();

var app = builder.Build();

if( !app.Environment.IsDevelopment() )
{
    app.UseExceptionHandler( "/Error", createScopeForErrors: true );
    app.UseHsts();
}

app.UseStatusCodePagesWithReExecute( "/not-found", createScopeForStatusCodePages: true );
app.UseHttpsRedirection();

app.UseAuthentication();
app.UseAuthorization();
app.UseAntiforgery();

app.MapStaticAssets();

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

MapAuthEndpoints( app );

// Load the entire assembly state into memory before serving traffic.
await app.Services
    .GetRequiredService<AppState>()
    .InitializeAsync();

app.Run();

static IDocumentStore CreateDocumentStore( IConfiguration configuration )
{
    var kind = configuration["Storage:Kind"] ?? "FileSystem";

    if( kind == "AzureBlob" )
        return new AzureBlobDocumentStore
        (
            configuration["Storage:ConnectionString"]
                ?? throw new InvalidOperationException( "Storage:ConnectionString is required for AzureBlob storage." ),
            configuration["Storage:Container"] ?? "assembly-data"
        );

    return new FileSystemDocumentStore( configuration["Storage:Root"] ?? "data" );
}

static void MapAuthEndpoints( WebApplication app )
{
    app.MapPost
    (
        "/auth/register",
        async
        (
            [FromForm] string username,
            [FromForm] string displayname,
            [FromForm] string password,
            AppState state,
            HttpContext http
        ) =>
        {
            try
            {
                var user = await state.RegisterUserAsync( username, displayname, password );
                await SignInAsync( http, user.Id, user.DisplayName );
                return Results.Redirect( "/" );
            }
            catch( InvalidOperationException e )
            {
                return Results.Redirect( $"/register?error={Uri.EscapeDataString( e.Message )}" );
            }
        }
    ).DisableAntiforgery();

    app.MapPost
    (
        "/auth/login",
        async
        (
            [FromForm] string username,
            [FromForm] string password,
            AppState state,
            HttpContext http
        ) =>
        {
            var user = state.ValidateLogin( username, password );

            if( user == null )
                return Results.Redirect( "/login?error=Invalid%20username%20or%20password" );

            await SignInAsync( http, user.Id, user.DisplayName );
            return Results.Redirect( "/" );
        }
    ).DisableAntiforgery();

    app.MapPost
    (
        "/auth/logout",
        async ( HttpContext http ) =>
        {
            await http.SignOutAsync( CookieAuthenticationDefaults.AuthenticationScheme );
            return Results.Redirect( "/" );
        }
    ).DisableAntiforgery();
}

static async Task SignInAsync( HttpContext http, string userId, string displayName )
{
    var identity = new ClaimsIdentity
    (
        [
            new Claim( ClaimTypes.NameIdentifier, userId ),
            new Claim( ClaimTypes.Name, displayName ),
        ],
        CookieAuthenticationDefaults.AuthenticationScheme
    );

    await http.SignInAsync
    (
        CookieAuthenticationDefaults.AuthenticationScheme,
        new ClaimsPrincipal( identity )
    );
}
