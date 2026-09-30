# Assembly — PHP sandbox lane

A deliberately simple companion to the production .NET lane (`../dotnet/`):
a plain website for entering assembly data and inspecting how it structures
as JSON documents on disk. Same domain vocabulary and document layout, far
fewer guarantees.

## Run

```
php -S localhost:8000 -t public
```

No framework, no Composer, no build step, no JavaScript — pure
server-rendered PRG (POST → redirect → GET). Requires PHP 8+.

On first run, `data/` is seeded from `data-example/` so version diffs,
a pending amendment, and a speakers queue are visible immediately.
`data/` and `config.php` are gitignored.

## Configuration

Optional: copy `config.example.php` to `config.php` and adjust.

- `password` — empty string = **no login gate at all** (local play).
  Set one to get a session-based login page. There are no user accounts.
- `data_dir` — where the JSON documents live.
- `timezone` — display timezone (stored timestamps are always UTC ISO-8601).

Display name is a free-text session value (asked once, editable in the
header), used as version author and speaker name default.

## Data layout

Document-per-aggregate, mirroring the .NET lane's storage layout but with
only two document types (no users/groups/ballots/votes):

```
data/
├── proposals/{id}.json   # aggregate: immutable version chain + amendments embedded
└── sessions/{id}.json    # aggregate: agenda items + speakers embedded
```

Writes are atomic (temp file + rename) and pretty-printed. Every detail
page has a **view JSON** link — inspecting the document structure is the
point of the sandbox.

## What's here

- Proposals: immutable version chains (clauses), per-clause amendments
  (replace / strike / insert-after) with word-level `<del>`/`<ins>` diff
  previews (`src/Text/WordDiff.php`, a straight port of the .NET
  `Core/Text/WordDiff.cs`), accept-amendment → new version, diff between
  any two versions. Nothing is ever edited in place.
- Sessions: scheduled date/time, list view plus a month calendar grid.
- Speakers lists per agenda item: speeches and replies; the queue is
  replies first, then FIFO (Nordic innlegg/replikk convention, mirroring
  `AgendaItem.Queue` in the C#); now-speaking and mark-done buttons.
- Submit a proposal to a session → linked agenda item + status change.

## What's deliberately missing vs the .NET lane

- **No mutation gate / locking.** The .NET lane serializes every mutation
  through `AppState`'s `SemaphoreSlim`; this sandbox just reads and writes
  files. Single-user local play only.
- **No realtime.** No `AppState.Changed`, no live re-render, no polling —
  reload the page.
- **No ballots or voting** of any kind.
- **No users or roles.** At most a shared password and a session display
  name; nothing like the .NET lane's identity model.
- No second instance, no database — same "folder of JSON is the truth"
  philosophy, minus the in-memory copy.
