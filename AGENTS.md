# Agent instructions — assembly

Deliberative assembly platform, two lanes sharing one repo:

- `dotnet/` — production platform (Blazor Web App, Azure). Read
  `README.md` and `docs/architecture.md` first — they are the
  authoritative description of this lane and are accurate as of this
  writing. Keep them in sync when you change what they describe.
- `php/` — dependency-free PHP sandbox for exploring the domain's data
  structures (see `php/README.md`). The strict invariants below are
  .NET-lane rules; the PHP lane deliberately has no mutation gate, no
  locking, and no realtime. It does sketch users/roles, topics, open
  (roll-call) voting, and AI-assisted amendment drafting ("AI proposes,
  humans dispose") as sandbox experiments — secret ballots remain
  .NET-only.

## Commands

- Run .NET lane locally: `dotnet run --project dotnet/src/Web` (first
  registered user becomes Admin; local state in `dotnet/src/Web/data/`,
  gitignored)
- Build .NET lane: `dotnet build dotnet/Assembly.slnx`
- Run PHP lane locally: `php -S localhost:8000 -t public` from `php/`
- No test project exists in either lane; verify by building/linting and
  exercising the running app.
- Deploy (.NET lane): `dotnet/scripts/provision.ps1` (once),
  `dotnet/scripts/deploy.ps1`

## Invariants — do not break these without explicit instruction

These apply to the **.NET lane** (`dotnet/`):

- **Single instance by design.** All truth lives in-memory in
  `AppState`; storage is write-through durability, never a query layer.
  Do not add a database, EF Core, Redis, or a second instance.
- Every mutation goes through the single `AppState` gate
  (`SemaphoreSlim`), is written through to `IDocumentStore`, and raises
  `AppState.Changed`. Live re-rendering depends on all three steps —
  never mutate state outside the gate, and never add polling.
- Roles (`Admin`, `Chair`) are read live from `AppState`, never from
  claims. Do not introduce ASP.NET Identity.
- Document-per-aggregate storage layout (see architecture.md). Vote
  uniqueness is enforced by the document key
  (`votes/{ballotId}/{handle}.json`) — preserve that.
- Secret-ballot handles are `HMAC-SHA256(serverSecret, ballotId:userId)`;
  the user→handle link must never be persisted.

## Style

- C# follows the Allman-parens style referenced in the global
  `~/.kimi-code/AGENTS.md`.
- PHP follows the sibling-project idiom (ionflex/canban-calendar,
  ionflex/deprogram-me): plain PHP 8+, `declare(strict_types=1)`, no
  framework/Composer/build step, PRG pattern, `h()` for escaping.
