using AssemblyApp.Core.Domain;
using AssemblyApp.Core.State;
using Microsoft.AspNetCore.Components;

namespace AssemblyApp.Web.Services;

/// <summary>
/// Base class for interactive pages. Subscribes to AppState.Changed so any
/// mutation by any user re-renders this circuit — that is the whole
/// "live updates" mechanism of the app.
/// </summary>
public abstract class LiveComponent : ComponentBase, IDisposable
{
    [Inject] protected AppState State { get; set; } = default!;
    [Inject] protected CurrentUserService CurrentUserService { get; set; } = default!;

    protected AppUser? CurrentUser { get; private set; }
    protected string? Error { get; set; }

    protected override async Task OnInitializedAsync()
    {
        this.CurrentUser = await this.CurrentUserService.GetAsync();
        this.State.Changed += this.OnStateChanged;
    }

    private void OnStateChanged()
        => _ = this.InvokeAsync( this.StateHasChanged );

    /// <summary>Runs a mutation, surfacing domain errors to the page.</summary>
    protected async Task Run( Func<Task> action )
    {
        try
        {
            this.Error = null;
            await action();
        }
        catch( Exception e )
        {
            this.Error = e.Message;
        }
    }

    public void Dispose()
        => this.State.Changed -= this.OnStateChanged;
}
