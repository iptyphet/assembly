# Architecture

> **Two lanes, one repo.** This document describes the production .NET
> lane (`dotnet/`, Blazor on Azure). The repo also contains a `php/`
> lane: a deliberately simpler sandbox for exploring the same domain
> concepts and JSON document structures on plain PHP hosting. It shares
> the document layout and domain vocabulary below, but not the
> one-process model — it has no in-memory state, no mutation gate, no
> realtime. It sketches users/roles, topics, open (roll-call) voting, and
> AI-assisted amendment drafting ("AI proposes, humans dispose") as
> sandbox experiments; secret ballots remain .NET-only.
> See `php/README.md`.

## Goals and constraints

- Serve assemblies/conventions of organizations on a **shoestring budget**.
- **Azure-first** hosting, but trivially forkable to a standalone local
  webserver (a laptop at the venue).
- **Live in-room voting**: open ballots (roll call) and secret ballots,
  real-time tallies, projector view.
- Amendments preserve original text and render as strike-through/insert diffs.

## The one-process model ("what does it mean to have one app running?")

The entire assembly state is loaded into memory at startup (`AppState`) and
served from there. Storage (`IDocumentStore`) is a **durability layer, not a
query layer**: every mutation is written through as a small JSON document,
and at boot all documents are read back. A convention's data is trivially
RAM-sized, so no index or database is needed; if that ever changes, the
escape hatch is an in-memory index rebuilt at startup or a SQLite cache —
not a migration.

Content freshness falls out of the same design. Every browser tab holds a
Blazor Server circuit (a SignalR connection). Pages inherit `LiveComponent`,
which subscribes to `AppState.Changed`; any mutation by any user triggers a
re-render of every subscribed circuit, and Blazor pushes the DOM diff over
the websocket. No polling, no cache invalidation, no stale content — there is
exactly one copy of the truth and everyone is looking at it.

Consequences:

- **Run exactly one instance.** Scaling out would split the in-memory truth
  and the SignalR circuits. The bicep provisions a single B1 instance with
  WebSockets enabled and no autoscale. (A venue laptop is also one instance —
  the same property the budget wants is the one the architecture needs.)
- Mutations are serialized through one `SemaphoreSlim`; at convention scale
  (hundreds of users) contention is negligible.
- A restart reloads everything from storage; in-flight circuits reconnect.

## Storage layout

```
users/{id}.json
proposals/{id}.json          # aggregate: versions + amendments embedded
groups/{id}.json
sessions/{id}.json           # aggregate: agenda items + speakers embedded
ballots/{id}.json
votes/{ballotId}/{handle}.json
system/secret.json           # HMAC key for secret-ballot handles
```

Document-per-aggregate: a proposal embeds its full immutable version chain
and its amendments; a session embeds its agenda and speakers lists. Votes are
individual documents whose **key encodes the uniqueness invariant**
(`ballotId/handle`): one vote per credential per ballot by construction,
re-voting is an idempotent overwrite.

`IDocumentStore` has two implementations: `FileSystemDocumentStore` (local;
backup = copy the folder) and `AzureBlobDocumentStore` (cloud; pennies).
Both are a couple hundred lines; the interface is four methods.

## Domain model

- **Proposal** → immutable `ProposalVersion` chain (text as a list of
  clauses) + `Amendment` list. Accepting an amendment applies it to the
  latest version and publishes a new version; nothing is ever edited in
  place, so history and diffs are always available.
- **Amendment** targets a clause of a specific version: replace / strike /
  insert-after. Rendered with word-level LCS diff (`WordDiff`) as
  `<del>`/`<ins>`.
- **WorkingGroup** with per-group join policy: open / approval / appointed.
- **Session** → agenda items, each with a speakers list. Replies (replikk)
  jump the queue ahead of speeches (innlegg); otherwise FIFO.
- **Ballot** (open or secret, ternary For/Against/Abstain — vote model
  borrowed from iptyphet) → **Vote** documents. Tallies are recomputed from
  votes on every read; no counters to drift.

## Identity and the secret-ballot trust model

Cookie auth with a custom user store (PBKDF2 password hashes); deliberately
not ASP.NET Identity, whose first-party store is EF/SQL. Roles (`Admin`,
`Chair`) are read live from `AppState`, never from claims, so grants apply
without re-login. The first registered user becomes Admin.

Secret ballots: the vote handle is `HMAC-SHA256(serverSecret, ballotId:userId)`.
Deterministic, so re-voting overwrites and double-voting is impossible; the
user→handle link is never stored. This is **convention-grade** anonymity: the
server could recompute the link but does not persist it. The documented
upgrade path for cryptographic unlinkability is client-held Ed25519
credentials issued at QR check-in — see iptyphet's `VoteSigner`/
`Ed25519Crypto`, which are designed to be copied in when needed.

## Relationship to sibling projects

- **simplesubmit** (MIT): the public idea funnel. `Proposal.OriginSuggestionId`
  is the integration point for promoting approved suggestions.
- **iptyphet**: local-first deliberation platform; shares the
  "immutable JSON files on disk are the source of truth" philosophy. Borrowed
  now: ternary vote model, file-layout conventions. Borrow later: Ed25519
  signing for verifiable votes and membership chains.
- **Prior art**: OpenSlides (MIT — heaviest overlap, amendment-diff UX and
  speakers list patterns; it's a multi-service Docker stack, our niche is one
  process + a folder of JSON), Decidim/Consul (AGPL — ideas only), NemoVote
  (commercial — UX reference only).
