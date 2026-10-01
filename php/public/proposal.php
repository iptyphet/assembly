<?php

declare(strict_types=1);

use Assembly\Ai\PatchException;
use Assembly\Ai\PatchValidator;
use Assembly\Data\Store;
use Assembly\Domain\AgendaItem;
use Assembly\Domain\Amendment;
use Assembly\Domain\AmendmentRevision;
use Assembly\Domain\Ballot;
use Assembly\Domain\Proposal;
use Assembly\Domain\ProposalVersion;
use Assembly\Domain\Session;

require __DIR__ . '/_init.php';

$id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
if (!preg_match('/^[a-z0-9-]+$/', $id)) {
    http_response_code(400);
    exit('Invalid proposal id');
}
$data = $store->load('proposals', $id);
if ($data === null) {
    http_response_code(404);
    exit('Unknown proposal');
}
$proposal = Proposal::fromArray($data);
$back = 'proposal.php?id=' . $id;

/**
 * Agenda items linked to this proposal in non-closed sessions — the
 * possible homes for an amendment ballot. Keyed "sessionId:itemId".
 *
 * @return array<string, array{session: Session, item: AgendaItem}>
 */
$votableItems = function () use ($store, $proposal): array {
    $result = [];
    foreach ($store->list('sessions') as $sdata) {
        $session = Session::fromArray($sdata);
        if ($session->status === Session::CLOSED) {
            continue;
        }
        foreach ($session->agendaItems as $item) {
            if ($item->proposalId === $proposal->id) {
                $result[$session->id . ':' . $item->id] = ['session' => $session, 'item' => $item];
            }
        }
    }

    return $result;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $me = current_user($store);
    $latestNow = $proposal->latest();

    if ($action === 'ask-ai' || $action === 'add-amendment') {
        // Both paths create a DRAFT amendment with a first revision — the
        // AI path via the configured provider, the manual path from the
        // form fields. Same machinery, no special AI patch type.
        $ask = trim((string) ($_POST['ask'] ?? ''));
        try {
            if ($action === 'ask-ai') {
                if ($ask === '') {
                    throw new PatchException('Describe the change you want first.');
                }
                $patch = ai_suggest_patch($config, $proposal, $latestNow, $ask);
                $revision = new AmendmentRevision(
                    $ask, $patch['ops'], $patch['summary'], AmendmentRevision::AI, $me->id, Store::nowUtc(),
                );
            } else {
                $kind = (string) ($_POST['kind'] ?? '');
                $clauseRaw = (string) ($_POST['clause'] ?? '');
                $newText = trim((string) ($_POST['new_text'] ?? ''));
                $ops = PatchValidator::validate([[
                    'clauseId' => $clauseRaw === 'top' ? null : $clauseRaw,
                    'operation' => $kind,
                    'text' => $kind === Amendment::STRIKE ? null : $newText,
                ]], $latestNow);
                $revision = new AmendmentRevision(
                    $ask !== '' ? $ask : 'Hand-written patch',
                    $ops,
                    Amendment::kindLabel($kind),
                    AmendmentRevision::HUMAN,
                    $me->id,
                    Store::nowUtc(),
                );
            }
            $proposal->amendments[] = new Amendment(
                Store::newId(),
                $latestNow->number,
                null,
                '',
                null,
                Amendment::DRAFT,
                $me->id,
                $me->name,
                Store::nowUtc(),
                [$revision],
                null,
            );
            $store->save('proposals', $proposal->toArray());
        } catch (PatchException $e) {
            flash('Patch failed: ' . $e->getMessage());
        }
    } elseif ($action === 'hone') {
        $amendment = $proposal->amendment((string) ($_POST['amendment'] ?? ''));
        if ($amendment !== null) {
            if (!$amendment->canHone($me->id, is_chair($store))) {
                forbid();
            }
            $ask = trim((string) ($_POST['ask'] ?? ''));
            try {
                if ($ask === '') {
                    throw new PatchException('Say what to change in the draft.');
                }
                $patch = ai_suggest_patch($config, $proposal, $latestNow, $ask, $amendment->currentOps());
                $amendment->revisions[] = new AmendmentRevision(
                    $ask, $patch['ops'], $patch['summary'], AmendmentRevision::AI, $me->id, Store::nowUtc(),
                );
                $store->save('proposals', $proposal->toArray());
            } catch (PatchException $e) {
                flash('Hone failed: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'propose-for-vote') {
        $amendment = $proposal->amendment((string) ($_POST['amendment'] ?? ''));
        if ($amendment !== null) {
            if (!$amendment->canHone($me->id, is_chair($store))) {
                forbid();
            }
            $target = (string) ($_POST['item'] ?? '');
            $candidates = $votableItems();
            if (!isset($candidates[$target])) {
                flash('Submit the proposal to a session first — the amendment vote needs an agenda item.');
            } else {
                $session = $candidates[$target]['session'];
                $item = $candidates[$target]['item'];
                $ballot = Ballot::create(
                    $session->id,
                    $item->id,
                    $proposal->id,
                    'Amendment: ' . ($amendment->revisions !== []
                        ? $amendment->revisions[0]->summary
                        : Amendment::kindLabel($amendment->kind)),
                    $me->id,
                    $amendment->id,
                );
                $store->save('ballots', $ballot->toArray());
                $amendment->status = Amendment::PROPOSED;
                $amendment->ballotId = $ballot->id;
                $store->save('proposals', $proposal->toArray());
            }
        }
    } elseif ($action === 'accept-amendment' || $action === 'reject-amendment') {
        // Manual decision, only for legacy proposed amendments without a
        // ballot — frozen ballot-linked amendments are decided by the vote.
        $amendment = $proposal->amendment((string) ($_POST['amendment'] ?? ''));
        if ($amendment !== null && $amendment->status === Amendment::PROPOSED && $amendment->ballotId === null) {
            if ($action === 'accept-amendment') {
                $proposal->acceptAmendment($amendment, $me);
            } else {
                $amendment->status = Amendment::REJECTED;
            }
            $store->save('proposals', $proposal->toArray());
        }
    } elseif ($action === 'submit-to-session') {
        $sessionData = $store->load('sessions', (string) ($_POST['session'] ?? ''));
        if ($sessionData !== null) {
            $session = Session::fromArray($sessionData);
            if ($session->status !== Session::CLOSED) {
                $session->agendaItems[] = AgendaItem::create($proposal->title, $proposal->id);
                $store->save('sessions', $session->toArray());
                $proposal->status = Proposal::READY_FOR_SESSION;
                $store->save('proposals', $proposal->toArray());
            }
        }
    }

    redirect_back($back);
}

$latest = $proposal->latest();
$me = current_user($store);
$chair = is_chair($store);
$flashMessage = flash_take();

// Which version to display (defaults to latest), and an optional diff pair.
$viewNumber = (int) ($_GET['v'] ?? $latest->number);
$viewVersion = $proposal->version($viewNumber) ?? $latest;
$diffPair = null;
if (preg_match('/^(\d+),(\d+)$/', (string) ($_GET['diff'] ?? ''), $m)) {
    $a = $proposal->version((int) $m[1]);
    $b = $proposal->version((int) $m[2]);
    if ($a !== null && $b !== null) {
        $diffPair = [$a, $b];
    }
}

/** Whole-version text: clauses joined, so WordDiff always has one sequence. */
$versionText = static fn ($v): string => implode(' ', array_map(
    static fn ($c): string => $c->text,
    $v->clauses,
));

$sessions = array_values(array_filter(
    array_map(Session::fromArray(...), $store->list('sessions')),
    static fn (Session $s): bool => $s->status !== Session::CLOSED,
));

/**
 * Render a patch (list of ops) against the latest version as <del>/<ins>.
 *
 * @param list<array{clauseId: ?string, operation: string, text: ?string}> $ops
 */
$opsPreview = static function (array $ops) use ($latest): string {
    $html = '';
    foreach ($ops as $op) {
        $target = null;
        $targetIndex = null;
        foreach ($latest->clauses as $i => $clause) {
            if ($clause->id === $op['clauseId']) {
                $target = $clause;
                $targetIndex = $i;
                break;
            }
        }
        $where = $targetIndex !== null ? '§' . ($targetIndex + 1) : ($op['clauseId'] === null ? 'top' : '§?');
        $html .= '<p class="meta">' . h(Amendment::kindLabel($op['operation'])) . ' ' . $where . '</p>';
        if ($op['operation'] === Amendment::INSERT_AFTER) {
            $html .= '<p><ins>' . h((string) $op['text']) . '</ins></p>';
        } elseif ($target === null) {
            $html .= '<p><em>Target clause no longer exists in the latest version.</em></p>';
        } elseif ($op['operation'] === Amendment::STRIKE) {
            $html .= '<p><del>' . h($target->text) . '</del></p>';
        } else {
            $html .= '<p>' . render_diff($target->text, (string) $op['text']) . '</p>';
        }
    }

    return $html;
};

/** Render one amendment's current patch preview. */
$amendmentPreview = static fn (Amendment $a): string => $opsPreview($a->currentOps());

/** Session/label lookup for ballot links. */
$sessionTitles = [];
foreach ($store->list('sessions') as $sdata) {
    $sessionTitles[(string) $sdata['id']] = (string) $sdata['title'];
}

$candidates = $votableItems();

$pageTitle = 'Assembly sandbox — ' . $proposal->title;
require __DIR__ . '/_header.php';
?>
<p><a href="proposals.php">← All proposals</a> ·
   <a href="json.php?type=proposals&id=<?= h($proposal->id) ?>">view JSON</a></p>

<h1><?= h($proposal->title) ?> <span class="badge"><?= h(Proposal::statusLabel($proposal->status)) ?></span></h1>
<p class="meta">Created by <?= h($proposal->createdByName) ?> at <?= h(fmt_time($proposal->createdAtUtc)) ?> UTC → local.</p>

<?php if ($flashMessage !== null): ?>
    <p class="error"><?= h($flashMessage) ?></p>
<?php endif; ?>

<section class="card">
    <h2>Version <?= $viewVersion->number ?> <?= $viewVersion->number === $latest->number ? '(latest)' : '' ?></h2>
    <p class="meta">By <?= h($viewVersion->createdByName) ?>, <?= h(fmt_time($viewVersion->createdAtUtc)) ?>
        <?= $viewVersion->note !== null ? '— ' . h($viewVersion->note) : '' ?></p>
    <ol class="clauses">
        <?php foreach ($viewVersion->clauses as $clause): ?>
            <li><?= h($clause->text) ?></li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="card">
    <h2>Version history (<?= count($proposal->versions) ?>)</h2>
    <ul>
        <?php foreach ($proposal->versions as $version): ?>
            <li>
                <a href="proposal.php?id=<?= h($proposal->id) ?>&v=<?= $version->number ?>">v<?= $version->number ?></a>
                — <?= h($version->createdByName) ?>, <?= h(fmt_time($version->createdAtUtc)) ?>
                <?= $version->note !== null ? '— ' . h($version->note) : '' ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($proposal->versions) > 1): ?>
    <form method="get" action="proposal.php" class="inline-form">
        <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
        Diff
        <select name="diff">
            <?php foreach ($proposal->versions as $a): ?>
                <?php foreach ($proposal->versions as $b): ?>
                    <?php if ($a->number < $b->number): ?>
                        <option value="<?= $a->number ?>,<?= $b->number ?>"
                            <?= $diffPair !== null && $diffPair[0]->number === $a->number && $diffPair[1]->number === $b->number ? 'selected' : '' ?>>
                            v<?= $a->number ?> → v<?= $b->number ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>
        <button type="submit">Show diff</button>
    </form>
    <?php endif; ?>
    <?php if ($diffPair !== null): ?>
    <div class="diff-view">
        <h3>v<?= $diffPair[0]->number ?> → v<?= $diffPair[1]->number ?></h3>
        <p><?= render_diff($versionText($diffPair[0]), $versionText($diffPair[1])) ?></p>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>New amendment</h2>
    <p class="meta">Amendments start as drafts you can hone, then freeze for a session vote. AI proposes, humans dispose.</p>
    <form method="post">
        <input type="hidden" name="action" value="ask-ai">
        <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
        <input type="hidden" name="_back" value="<?= h($back) ?>">
        <label>Ask AI for a patch — describe the change in plain words
            <textarea name="ask" rows="2" placeholder="e.g. raise the fee to 300 kroner"></textarea>
        </label>
        <button type="submit">Draft with AI</button>
    </form>
    <h3>…or write it yourself (against v<?= $latest->number ?>)</h3>
    <form method="post">
        <input type="hidden" name="action" value="add-amendment">
        <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
        <input type="hidden" name="_back" value="<?= h($back) ?>">
        <label>Operation
            <select name="kind">
                <option value="<?= Amendment::REPLACE ?>">Replace clause</option>
                <option value="<?= Amendment::STRIKE ?>">Strike clause</option>
                <option value="<?= Amendment::INSERT_AFTER ?>">Insert clause after</option>
            </select>
        </label>
        <label>Clause
            <select name="clause">
                <option value="top">— top of document (insert only) —</option>
                <?php foreach ($latest->clauses as $i => $clause): ?>
                    <option value="<?= h($clause->id) ?>">§<?= $i + 1 ?>: <?= h(mb_strimwidth($clause->text, 0, 60, '…')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>New text (not needed for strike)
            <textarea name="new_text" rows="3"></textarea>
        </label>
        <label>Note / rationale (optional)
            <input type="text" name="ask">
        </label>
        <button type="submit">Draft amendment</button>
    </form>
</section>

<?php $drafts = $proposal->draftAmendments(); ?>
<section class="card">
    <h2>Amendment drafts (<?= count($drafts) ?>)</h2>
    <?php foreach ($drafts as $amendment): ?>
        <div class="amendment">
            <p class="meta">
                Draft by <?= h($amendment->proposedByName) ?>, <?= h(fmt_time($amendment->createdAtUtc)) ?>
                (against v<?= $amendment->targetVersion ?>) · <?= count($amendment->revisions) ?> revision(s)
            </p>
            <div class="diff-view"><?= $amendmentPreview($amendment) ?></div>
            <h4>Revision history</h4>
            <?php foreach ($amendment->revisions as $i => $revision): ?>
                <div class="revision">
                    <p class="meta">
                        r<?= $i + 1 ?> <span class="badge source-<?= h($revision->source) ?>"><?= h($revision->source) ?></span>
                        <?= h(fmt_time($revision->createdAtUtc)) ?> — ask: “<?= h($revision->ask) ?>”
                        — <?= h($revision->summary) ?>
                    </p>
                    <div class="diff-view"><?= $opsPreview($revision->ops) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($amendment->canHone($me->id, $chair)): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="hone">
                    <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
                    <input type="hidden" name="amendment" value="<?= h($amendment->id) ?>">
                    <input type="hidden" name="_back" value="<?= h($back) ?>">
                    <input type="text" name="ask" placeholder="nah, more like this…" required>
                    <button type="submit">Hone with AI</button>
                </form>
                <?php if ($candidates !== []): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="propose-for-vote">
                    <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
                    <input type="hidden" name="amendment" value="<?= h($amendment->id) ?>">
                    <input type="hidden" name="_back" value="<?= h($back) ?>">
                    <select name="item">
                        <?php foreach ($candidates as $key => $candidate): ?>
                            <option value="<?= h($key) ?>">
                                <?= h($candidate['session']->title) ?> — <?= h($candidate['item']->title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Propose for vote (freeze)</button>
                </form>
                <?php else: ?>
                    <p class="meta">Submit the proposal to a session before the draft can go to a vote.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($drafts === []): ?>
        <p class="meta">None.</p>
    <?php endif; ?>
</section>

<?php $pending = $proposal->pendingAmendments(); ?>
<section class="card">
    <h2>Proposed amendments (frozen, <?= count($pending) ?>)</h2>
    <?php foreach ($pending as $amendment): ?>
        <div class="amendment">
            <p class="meta">
                by <?= h($amendment->proposedByName) ?>, <?= h(fmt_time($amendment->createdAtUtc)) ?>
                (against v<?= $amendment->targetVersion ?>)
            </p>
            <div class="diff-view"><?= $amendmentPreview($amendment) ?></div>
            <?php if ($amendment->ballotId !== null): ?>
                <?php $ballotData = $store->load('ballots', $amendment->ballotId); ?>
                <p class="meta">
                    Being voted on
                    <?php if ($ballotData !== null): ?>
                        in <a href="session.php?id=<?= h((string) $ballotData['sessionId']) ?>"><?= h($sessionTitles[(string) $ballotData['sessionId']] ?? 'session') ?></a>
                        (ballot <?= h($ballotData['status']) ?>)
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
                    <input type="hidden" name="amendment" value="<?= h($amendment->id) ?>">
                    <input type="hidden" name="_back" value="<?= h($back) ?>">
                    <button type="submit" name="action" value="accept-amendment">Accept → new version</button>
                    <button type="submit" name="action" value="reject-amendment" class="link-button">Reject</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($pending === []): ?>
        <p class="meta">None.</p>
    <?php endif; ?>
</section>

<?php $decided = $proposal->decidedAmendments(); ?>
<?php if ($decided !== []): ?>
<section class="card">
    <h2>Decided amendments (<?= count($decided) ?>)</h2>
    <p class="meta">The permanent record behind the chain — rejected amendments keep their diff too.</p>
    <?php foreach ($decided as $amendment): ?>
        <div class="amendment">
            <p class="meta">
                <span class="badge amendment-<?= h($amendment->status) ?>"><?= h(Amendment::statusLabel($amendment->status)) ?></span>
                by <?= h($amendment->proposedByName) ?>, <?= h(fmt_time($amendment->createdAtUtc)) ?>
                <?php if ($amendment->ballotId !== null): ?>
                    <?php $ballotData = $store->load('ballots', $amendment->ballotId); ?>
                    <?php if ($ballotData !== null): ?>
                        <?php $tally = Ballot::tally($store->list('votes/' . $amendment->ballotId)); ?>
                        — vote: <?= $tally[Ballot::FOR] ?> for, <?= $tally[Ballot::AGAINST] ?> against, <?= $tally[Ballot::ABSTAIN] ?> abstain
                        (<a href="session.php?id=<?= h((string) $ballotData['sessionId']) ?>">session</a>)
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <div class="diff-view"><?= $amendmentPreview($amendment) ?></div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2>Submit to session</h2>
    <?php if ($sessions === []): ?>
        <p class="meta">No sessions yet — <a href="sessions.php">create one</a>.</p>
    <?php else: ?>
    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="submit-to-session">
        <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
        <input type="hidden" name="_back" value="<?= h($back) ?>">
        <select name="session">
            <?php foreach ($sessions as $session): ?>
                <option value="<?= h($session->id) ?>"><?= h($session->title) ?> (<?= h(fmt_time($session->scheduledForUtc)) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Submit (creates agenda item, marks ready for session)</button>
    </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
