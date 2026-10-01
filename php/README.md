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

On first run — and whenever a new aggregate type appears — `data/` is
seeded **per subdirectory** from `data-example/`: any of
`users/topics/proposals/sessions/ballots/votes` missing from `data/` is
copied over independently, without touching existing documents. `data/`
and `config.php` are gitignored.

## Configuration

Optional: copy `config.example.php` to `config.php` and adjust.

- `password` — empty string = **no login gate at all** (local play).
  Set one to get a session-based login page (a site password, not
  per-user auth).
- `data_dir` — where the JSON documents live.
- `timezone` — display timezone (stored timestamps are always UTC ISO-8601).

## Users and roles

Identity is **impersonation**: the header dropdown switches which user the
session acts as. No passwords, no registration — users are documents in
`users/`, managed on the admin-only `users.php` page. Roles mirror the
.NET lane's vocabulary:

- **attendant** — create proposals, submit them to sessions, apply for
  speaker slots, vote on open ballots.
- **chair** — + create topics and sessions. The chair who creates a
  session is its **owner**.
- **session owner (or admin)** — set the session's original proposal,
  manage the agenda, start/close the session, rearrange the speakers
  queue, assign speaking time, open/close ballots.
- **admin** — + user management.

All checks are server-side in the POST handlers (rejected with 403);
buttons are hidden when the current role may not use them.

## Topics

A topic (`topics/{id}.json`) groups related sessions — title plus a
free-text description. Chairs create them; each session optionally belongs
to one. The sessions list groups by topic, and the session owner pins an
**original proposal** — "the proposal under discussion" — at the top of
the session page. Attendants can still submit further proposals as agenda
items.

## Voting

Open ballots only — an open ballot is a **roll call**, the tally shows who
voted what. The session owner opens a ballot on an agenda item with a
linked proposal (only while the session is `live`) and closes it when
done. Choices are `for` / `against` / `abstain` (the ternary model of the
.NET lane). Votes are individual documents keyed
`votes/{ballotId}/{userId}.json`, so one vote per user per ballot holds
**by key construction** and re-voting is an idempotent overwrite. Tallies
are recomputed from the vote documents on every render — no counters to
drift. Secret ballots remain out of scope.

## AI-assisted amendment drafting

"AI proposes, humans dispose." On a proposal page you can ask the AI for a
patch in plain words; the answer is a list of clause ops (replace /
strike / insert-after) that becomes an **amendment draft** — never a
direct edit. Amendments carry an append-only `revisions` list, so honing
("nah, more like this…") adds a revision and the drafting history stays
inspectable. The lifecycle:

```
draft → (hone → draft)* → proposed (frozen, ballot created)
      → accepted (patch applied as a new immutable version)
      → rejected (proposal untouched; amendment kept with its diff + tally)
```

Freezing ("Propose for vote") creates an open ballot referencing the
amendment under an agenda item linked to the proposal. When the session
owner closes that ballot, the outcome applies automatically: `for >
against` accepts (abstains don't count either way).

The proposer and chairs/admins can hone and freeze; everyone can view
drafts. Every op is validated server-side (`Ai\PatchValidator`): unknown
clause ids or malformed operations are rejected with a flash error and
nothing is saved.

Config (`config.php`):

```php
'ai' => [
    'provider' => 'mock',        // or 'anthropic'
    'api_key' => '',             // needed for anthropic
    'model' => 'claude-haiku-4-5',
],
```

- `mock` (default) — deterministic canned patch, zero keys, exercises the
  whole flow offline.
- `anthropic` — plain-curl Messages API client with forced tool use for
  structured ops output. Claude Haiku 4.5 is the default model (~$1/$5 per
  million tokens; a patch is ~2–4k tokens in / <1k out, well under a cent
  per call). Sonnet 5 is a one-line upgrade if wording quality
  disappoints.

## Data layout

Document-per-aggregate, mirroring the .NET lane's storage layout:

```
data/
├── users/{id}.json       # id, name, role
├── topics/{id}.json      # id, title, description
├── proposals/{id}.json   # aggregate: immutable version chain + amendments embedded
├── sessions/{id}.json    # aggregate: agenda items + speakers embedded
├── ballots/{id}.json     # open ballot on an agenda item's proposal
└── votes/{ballotId}/{userId}.json   # one vote per user per ballot, by key
```

Writes are atomic (temp file + rename) and pretty-printed. Every detail
page has a **view JSON** link — inspecting the document structure is the
point of the sandbox.

## Deploy to fifle.net

`.github/workflows/php-deploy.yml` deploys pushes touching `php/**` to
**https://www.fifle.net/assembly** (domeneshop): rsync to
`~/apps/assembly/`, with `~/www/assembly` symlinked to `public/`.
`config.php` and `data/` live only on the server and are never clobbered.

Required GitHub secrets on this repo:

- `DEPLOY_KEY` — private SSH key authorized for `fifle@login.domeneshop.no`
- `ASSEMBLY_PASSWORD` (optional) — when set, the site password in the
  server-side `config.php` is updated on every deploy

## What's here

- Users and impersonation: switch who you act as from the header; roles
  (admin / chair / attendant) gate actions server-side.
- Topics grouping sessions, with a per-session original proposal pinned
  as "the proposal under discussion".
- Proposals: immutable version chains (clauses), per-clause amendments
  (replace / strike / insert-after) with word-level `<del>`/`<ins>` diff
  previews (`src/Text/WordDiff.php`, a straight port of the .NET
  `Core/Text/WordDiff.cs`), accept-amendment → new version, diff between
  any two versions. Nothing is ever edited in place.
- Sessions: scheduled date/time, list view grouped by topic plus a month
  calendar grid; owner starts (→ live) and closes the session.
- Speakers lists per agenda item: attendants apply for speeches and
  replies; the default queue is replies first, then FIFO (Nordic
  innlegg/replikk convention, mirroring `AgendaItem.Queue` in the C#).
  The owner can rearrange with up/down buttons (first move snapshots the
  order into `manualOrder`), assign minutes per entry, and run
  now-speaking / mark-done.
- Open voting: owner opens/closes ballots on agenda items with linked
  proposals while the session is live; everyone votes for / against /
  abstain; tallies and per-user votes render live.
- Submit a proposal to a session → linked agenda item + status change.

## What's deliberately missing vs the .NET lane

- **No mutation gate / locking.** The .NET lane serializes every mutation
  through `AppState`'s `SemaphoreSlim`; this sandbox just reads and writes
  files. Single-user local play only.
- **No realtime.** No `AppState.Changed`, no live re-render, no polling —
  reload the page.
- **No secret ballots** or vote anonymity of any kind — every ballot here
  is a roll call. The .NET lane's HMAC-handle model is not ported.
- **No real auth.** Impersonation is a dropdown, the optional site
  password is a shared session flag; nothing like the .NET lane's
  identity model.
- No second instance, no database — same "folder of JSON is the truth"
  philosophy, minus the in-memory copy.
