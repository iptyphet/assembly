# assembly

Deliberative assembly platform for conventions and congresses on a shoestring
budget: proposals with versioned text, working groups, amendments rendered as
diffs, speakers lists, and live open/secret voting.

The internal counterpart to [simplesubmit](../simplesubmit) (public idea
funnel): ideas graduate into formal proposals here, get tuned by working
groups, and are decided on the assembly floor.

## Run locally

```
dotnet run --project src/Web
```

Open the printed URL and register — the **first registered user becomes
Admin**. State is stored as plain JSON files under `src/Web/data/` (gitignored);
backing up the assembly = copying that folder.

## Architecture in one paragraph

One ASP.NET Core process (Blazor Web App, interactive server rendering,
.NET 10). All state lives in memory in `AppState`; every mutation is
serialized through a single gate, written through to an `IDocumentStore`
(JSON over the file system locally, Azure Blob Storage in the cloud), and
broadcast via the `AppState.Changed` event so every connected browser circuit
re-renders live — that's the whole realtime mechanism, no polling, no cache
invalidation. **Single instance by design**; do not scale out without adding
a SignalR backplane and shared state. See
[docs/architecture.md](docs/architecture.md).

## Deploy to Azure

```
az login
./scripts/provision.ps1     # creates rg, storage, plan, app (B1, ~€12/mo)
./scripts/deploy.ps1        # dotnet publish + zip deploy
```

## License

BSD 3-Clause — see [LICENSE](LICENSE).
