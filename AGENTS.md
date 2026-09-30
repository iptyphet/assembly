# Agent instructions — assembly

Deliberative assembly platform. Read `README.md` and
`docs/architecture.md` first — they are the authoritative description of
the system and are accurate as of this writing. Keep them in sync when
you change what they describe.

## Commands

- Run locally: `dotnet run --project src/Web` (first registered user
  becomes Admin; local state in `src/Web/data/`, gitignored)
- Build: `dotnet build Assembly.slnx`
- No test project exists; verify by building and exercising the running
  app.
- Deploy: `scripts/provision.ps1` (once), `scripts/deploy.ps1`

## Invariants — do not break these without explicit instruction

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
