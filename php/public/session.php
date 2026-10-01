<?php

declare(strict_types=1);

use Assembly\Data\Store;
use Assembly\Domain\AgendaItem;
use Assembly\Domain\Amendment;
use Assembly\Domain\Ballot;
use Assembly\Domain\Proposal;
use Assembly\Domain\Session;
use Assembly\Domain\SpeakerEntry;

require __DIR__ . '/_init.php';

$id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
if (!preg_match('/^[a-z0-9-]+$/', $id)) {
    http_response_code(400);
    exit('Invalid session id');
}
$data = $store->load('sessions', $id);
if ($data === null) {
    http_response_code(404);
    exit('Unknown session');
}
$session = Session::fromArray($data);
$back = 'session.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $me = current_user($store);
    $owner = owns_session($store, $session);
    $saveSession = function () use ($store, $session): void {
        $store->save('sessions', $session->toArray());
    };

    // Owner-only steering actions.
    if (in_array($action, [
        'set-original-proposal', 'add-agenda-item', 'now-speaking', 'mark-done',
        'move-speaker', 'set-minutes', 'start-session', 'close-session',
        'open-ballot', 'close-ballot',
    ], true) && !$owner) {
        forbid();
    }

    if ($action === 'set-original-proposal') {
        $raw = (string) ($_POST['proposal'] ?? '');
        if ($raw === '') {
            $session->originalProposalId = null;
            $saveSession();
        } elseif ($store->load('proposals', $raw) !== null) {
            $session->originalProposalId = $raw;
            $saveSession();
        }
    } elseif ($action === 'add-agenda-item' && $session->status !== Session::CLOSED) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $proposalRaw = (string) ($_POST['proposal'] ?? '');
        $proposalId = null;
        if ($proposalRaw !== '') {
            $proposalData = $store->load('proposals', $proposalRaw);
            if ($proposalData !== null) {
                $proposalId = $proposalRaw;
                if ($title === '') {
                    $title = (string) $proposalData['title'];
                }
            }
        }
        if ($title !== '') {
            $session->agendaItems[] = AgendaItem::create($title, $proposalId);
            $saveSession();
        }
    } elseif ($action === 'add-speaker' && $session->status !== Session::CLOSED) {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? '');
        if ($item !== null && in_array($kind, [SpeakerEntry::SPEECH, SpeakerEntry::REPLY], true)) {
            $item->speakers[] = new SpeakerEntry(
                Store::newId(),
                $me->id,
                $me->name,
                $kind,
                Store::nowUtc(),
                false,
                null,
                null,
            );
            $saveSession();
        }
    } elseif ($action === 'now-speaking') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        if ($item !== null && $item->speaker((string) ($_POST['speaker'] ?? '')) !== null) {
            $item->nowSpeakingEntryId = (string) $_POST['speaker'];
            $saveSession();
        }
    } elseif ($action === 'mark-done') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $speaker = $item?->speaker((string) ($_POST['speaker'] ?? ''));
        if ($item !== null && $speaker !== null) {
            $speaker->done = true;
            if ($item->nowSpeakingEntryId === $speaker->id) {
                $item->nowSpeakingEntryId = null;
            }
            $saveSession();
        }
    } elseif ($action === 'move-speaker') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $speakerId = (string) ($_POST['speaker'] ?? '');
        $direction = (string) ($_POST['direction'] ?? '');
        if ($item !== null && in_array($direction, ['up', 'down'], true)) {
            // First rearrange pins the default order so the swap is stable.
            $queue = $item->queue();
            if (array_filter($queue, static fn (SpeakerEntry $s): bool => $s->manualOrder !== null) === []) {
                $item->snapshotQueueOrder();
                $queue = $item->queue();
            }
            $index = null;
            foreach ($queue as $i => $entry) {
                if ($entry->id === $speakerId) {
                    $index = $i;
                    break;
                }
            }
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index !== null && isset($queue[$swapWith])) {
                $a = $queue[$index];
                $b = $queue[$swapWith];
                [$a->manualOrder, $b->manualOrder] = [$b->manualOrder, $a->manualOrder];
                $saveSession();
            }
        }
    } elseif ($action === 'set-minutes') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $speaker = $item?->speaker((string) ($_POST['speaker'] ?? ''));
        $minutesRaw = trim((string) ($_POST['minutes'] ?? ''));
        if ($speaker !== null && ($minutesRaw === '' || ctype_digit($minutesRaw))) {
            $speaker->minutes = $minutesRaw === '' ? null : (int) $minutesRaw;
            $saveSession();
        }
    } elseif ($action === 'start-session' && $session->status === Session::SCHEDULED) {
        $session->status = Session::LIVE;
        $saveSession();
    } elseif ($action === 'close-session' && $session->status === Session::LIVE) {
        $session->status = Session::CLOSED;
        $saveSession();
    } elseif ($action === 'open-ballot' && $session->status === Session::LIVE) {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        if ($item !== null && $item->proposalId !== null) {
            $existingOpen = array_filter(
                $store->list('ballots'),
                static fn (array $b): bool => $b['agendaItemId'] === $item->id && $b['status'] === Ballot::OPEN,
            );
            if ($existingOpen === []) {
                $title = trim((string) ($_POST['title'] ?? ''));
                if ($title === '') {
                    $title = 'Vote: ' . $item->title;
                }
                $ballot = Ballot::create($session->id, $item->id, $item->proposalId, $title, $me->id);
                $store->save('ballots', $ballot->toArray());
            }
        }
    } elseif ($action === 'close-ballot') {
        $ballotData = $store->load('ballots', (string) ($_POST['ballot'] ?? ''));
        if ($ballotData !== null && $ballotData['sessionId'] === $session->id && $ballotData['status'] === Ballot::OPEN) {
            $ballot = Ballot::fromArray($ballotData);
            $ballot->status = Ballot::CLOSED;
            $ballot->closedAtUtc = Store::nowUtc();
            // Amendment ballots decide the amendment on close: for > against
            // applies the patch as a new immutable version, otherwise the
            // amendment is rejected and the proposal stays untouched.
            // Abstains don't count either way.
            if ($ballot->amendmentId !== null) {
                $proposalData = $store->load('proposals', $ballot->proposalId);
                if ($proposalData !== null) {
                    $proposal = Proposal::fromArray($proposalData);
                    $amendment = $proposal->amendment($ballot->amendmentId);
                    if ($amendment !== null && $amendment->status === Amendment::PROPOSED) {
                        $tally = Ballot::tally($store->list('votes/' . $ballot->id));
                        if ($tally[Ballot::FOR] > $tally[Ballot::AGAINST]) {
                            $proposal->acceptAmendment($amendment, $me);
                        } else {
                            $amendment->status = Amendment::REJECTED;
                        }
                        $store->save('proposals', $proposal->toArray());
                    }
                }
            }
            $store->save('ballots', $ballot->toArray());
        }
    } elseif ($action === 'vote') {
        $ballotData = $store->load('ballots', (string) ($_POST['ballot'] ?? ''));
        $choice = (string) ($_POST['choice'] ?? '');
        if ($ballotData !== null && $ballotData['status'] === Ballot::OPEN && in_array($choice, Ballot::CHOICES, true)) {
            $store->save('votes/' . $ballotData['id'], Ballot::vote((string) $ballotData['id'], $me, $choice));
        }
    }

    redirect_back($back);
}

$owner = owns_session($store, $session);
$readOnly = $session->status === Session::CLOSED;

$proposals = [];
foreach ($store->list('proposals') as $pid => $pdata) {
    $proposals[$pid] = Proposal::fromArray($pdata);
}
$users = [];
foreach (all_users($store) as $user) {
    $users[$user->id] = $user;
}
$topicData = $session->topicId !== null ? $store->load('topics', $session->topicId) : null;

$ballotsByItem = [];
foreach ($store->list('ballots') as $bdata) {
    if ($bdata['sessionId'] === $session->id) {
        $ballotsByItem[(string) $bdata['agendaItemId']][] = Ballot::fromArray($bdata);
    }
}

$originalProposal = $session->originalProposalId !== null
    ? ($proposals[$session->originalProposalId] ?? null)
    : null;

$pageTitle = 'Assembly sandbox — ' . $session->title;
require __DIR__ . '/_header.php';
?>
<p><a href="sessions.php">← All sessions</a> ·
   <a href="json.php?type=sessions&id=<?= h($session->id) ?>">view JSON</a></p>

<h1><?= h($session->title) ?>
    <span class="badge status-<?= h($session->status) ?>"><?= h(Session::statusLabel($session->status)) ?></span>
</h1>
<p class="meta">
    Scheduled: <?= h(fmt_time($session->scheduledForUtc)) ?: 'not scheduled' ?> (<?= h((string) $config['timezone']) ?>) ·
    chair: <?= h($users[$session->ownerUserId]->name ?? '—') ?>
    <?php if ($topicData !== null): ?>
        · topic: <a href="topic.php?id=<?= h($session->topicId) ?>"><?= h((string) $topicData['title']) ?></a>
    <?php endif; ?>
</p>

<?php if ($owner && !$readOnly): ?>
    <form method="post" class="inline-form">
        <input type="hidden" name="id" value="<?= h($session->id) ?>">
        <input type="hidden" name="_back" value="<?= h($back) ?>">
        <?php if ($session->status === Session::SCHEDULED): ?>
            <button type="submit" name="action" value="start-session">Start session</button>
        <?php elseif ($session->status === Session::LIVE): ?>
            <button type="submit" name="action" value="close-session">Close session</button>
        <?php endif; ?>
    </form>
<?php endif; ?>

<section class="card original-proposal">
    <h2>Proposal under discussion</h2>
    <?php if ($originalProposal !== null): ?>
        <h3>
            <a href="proposal.php?id=<?= h($originalProposal->id) ?>"><?= h($originalProposal->title) ?></a>
            <span class="badge"><?= h(Proposal::statusLabel($originalProposal->status)) ?></span>
        </h3>
        <ol class="clauses">
            <?php foreach ($originalProposal->latest()->clauses as $clause): ?>
                <li><?= h($clause->text) ?></li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <p class="meta">None set.</p>
    <?php endif; ?>
    <?php if ($owner && !$readOnly): ?>
        <form method="post" class="inline-form">
            <input type="hidden" name="action" value="set-original-proposal">
            <input type="hidden" name="id" value="<?= h($session->id) ?>">
            <input type="hidden" name="_back" value="<?= h($back) ?>">
            <select name="proposal">
                <option value="">— none —</option>
                <?php foreach ($proposals as $proposal): ?>
                    <option value="<?= h($proposal->id) ?>" <?= $proposal->id === $session->originalProposalId ? 'selected' : '' ?>>
                        <?= h($proposal->title) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Set original proposal</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($owner && !$readOnly): ?>
<section class="card">
    <h2>Add agenda item</h2>
    <form method="post">
        <input type="hidden" name="action" value="add-agenda-item">
        <input type="hidden" name="id" value="<?= h($session->id) ?>">
        <input type="hidden" name="_back" value="<?= h($back) ?>">
        <label>Title
            <input type="text" name="title">
        </label>
        <label>Linked proposal (optional — fills the title when empty)
            <select name="proposal">
                <option value="">— none —</option>
                <?php foreach ($proposals as $proposal): ?>
                    <option value="<?= h($proposal->id) ?>"><?= h($proposal->title) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Add agenda item</button>
    </form>
</section>
<?php endif; ?>

<?php foreach ($session->agendaItems as $item): ?>
    <?php
    $queue = $item->queue();
    $itemBallots = $ballotsByItem[$item->id] ?? [];
    ?>
    <section class="card">
        <h2>
            <?= h($item->title) ?>
            <?php if ($item->proposalId !== null && isset($proposals[$item->proposalId])): ?>
                <a class="badge" href="proposal.php?id=<?= h($item->proposalId) ?>">proposal ↗</a>
            <?php endif; ?>
        </h2>

        <?php $nowSpeaking = $item->nowSpeaking(); ?>
        <?php if ($nowSpeaking !== null): ?>
            <p class="now-speaking">
                Now speaking: <strong><?= h($nowSpeaking->displayName) ?></strong>
                (<?= $nowSpeaking->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?>)
            </p>
            <?php if ($owner && !$readOnly): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= h($session->id) ?>">
                    <input type="hidden" name="item" value="<?= h($item->id) ?>">
                    <input type="hidden" name="speaker" value="<?= h($nowSpeaking->id) ?>">
                    <input type="hidden" name="_back" value="<?= h($back) ?>">
                    <button type="submit" name="action" value="mark-done">Mark done</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <h3>
            Speakers queue (replies first, then first come first serve)
            <?php if ($item->allocatedMinutes() > 0): ?>
                <span class="meta">— <?= $item->allocatedMinutes() ?> min allocated</span>
            <?php endif; ?>
        </h3>
        <?php if ($queue === []): ?>
            <p class="meta">Queue is empty.</p>
        <?php else: ?>
            <ol class="speakers-queue">
                <?php foreach ($queue as $position => $speaker): ?>
                    <li class="<?= $speaker->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?>">
                        <span class="badge"><?= $speaker->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?></span>
                        <?= h($speaker->displayName) ?>
                        <span class="meta"><?= h(fmt_time($speaker->requestedAtUtc)) ?></span>
                        <?php if ($speaker->minutes !== null): ?>
                            <span class="badge minutes"><?= $speaker->minutes ?> min</span>
                        <?php endif; ?>
                        <?php if ($owner && !$readOnly): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="move-speaker">
                                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                                <input type="hidden" name="item" value="<?= h($item->id) ?>">
                                <input type="hidden" name="speaker" value="<?= h($speaker->id) ?>">
                                <input type="hidden" name="_back" value="<?= h($back) ?>">
                                <?php if ($position > 0): ?>
                                    <button type="submit" name="direction" value="up" class="link-button" title="Move up">↑</button>
                                <?php endif; ?>
                                <?php if ($position < count($queue) - 1): ?>
                                    <button type="submit" name="direction" value="down" class="link-button" title="Move down">↓</button>
                                <?php endif; ?>
                            </form>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                                <input type="hidden" name="item" value="<?= h($item->id) ?>">
                                <input type="hidden" name="speaker" value="<?= h($speaker->id) ?>">
                                <input type="hidden" name="_back" value="<?= h($back) ?>">
                                <button type="submit" name="action" value="now-speaking" class="link-button">Now speaking</button>
                                <button type="submit" name="action" value="mark-done" class="link-button">Done</button>
                            </form>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="set-minutes">
                                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                                <input type="hidden" name="item" value="<?= h($item->id) ?>">
                                <input type="hidden" name="speaker" value="<?= h($speaker->id) ?>">
                                <input type="hidden" name="_back" value="<?= h($back) ?>">
                                <input type="text" name="minutes" inputmode="numeric" pattern="[0-9]*"
                                       value="<?= $speaker->minutes ?? '' ?>" placeholder="min" class="minutes-input">
                                <button type="submit" class="link-button">Set</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php $doneSpeakers = array_values(array_filter($item->speakers, static fn (SpeakerEntry $s): bool => $s->done)); ?>
        <?php if ($doneSpeakers !== []): ?>
            <p class="meta">Done:
                <?= h(implode(', ', array_map(static fn (SpeakerEntry $s): string => $s->displayName, $doneSpeakers))) ?>
            </p>
        <?php endif; ?>

        <?php if (!$readOnly): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="add-speaker">
                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                <input type="hidden" name="item" value="<?= h($item->id) ?>">
                <input type="hidden" name="_back" value="<?= h($back) ?>">
                <span class="meta">Apply as <?= h(current_user($store)->name) ?>:</span>
                <button type="submit" name="kind" value="<?= SpeakerEntry::SPEECH ?>">Request speech</button>
                <button type="submit" name="kind" value="<?= SpeakerEntry::REPLY ?>">Request reply</button>
            </form>
        <?php endif; ?>

        <?php if ($item->proposalId !== null): ?>
            <div class="ballot-area">
                <h3>Ballots</h3>
                <?php foreach ($itemBallots as $ballot): ?>
                    <?php
                    $votes = $store->list('votes/' . $ballot->id);
                    $tally = Ballot::tally($votes);
                    $myVote = (string) ($votes[current_user($store)->id]['choice'] ?? '');
                    ?>
                    <div class="ballot-card <?= $ballot->status === Ballot::OPEN ? 'open' : 'closed' ?>">
                        <p>
                            <strong><?= h($ballot->title) ?></strong>
                            <span class="badge ballot-<?= h($ballot->status) ?>"><?= h($ballot->status) ?></span>
                        </p>
                        <?php if ($ballot->amendmentId !== null): ?>
                            <p class="meta">
                                Amendment vote —
                                <a href="proposal.php?id=<?= h($ballot->proposalId) ?>">amendment on the proposal ↗</a>
                            </p>
                            <?php if ($ballot->status === Ballot::CLOSED): ?>
                                <?php
                                $outcomeProposal = isset($proposals[$ballot->proposalId]) ? $proposals[$ballot->proposalId] : null;
                                $outcome = $outcomeProposal?->amendment($ballot->amendmentId);
                                ?>
                                <?php if ($outcome !== null): ?>
                                    <p class="ballot-outcome">
                                        <?php if ($outcome->status === Amendment::ACCEPTED): ?>
                                            Amendment <strong>accepted</strong> (<?= $tally[Ballot::FOR] ?> for, <?= $tally[Ballot::AGAINST] ?> against) — applied as a new version.
                                        <?php elseif ($outcome->status === Amendment::REJECTED): ?>
                                            Amendment <strong>rejected</strong> (<?= $tally[Ballot::FOR] ?> for, <?= $tally[Ballot::AGAINST] ?> against) — the proposal is unchanged.
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <p class="tally">
                            For: <?= $tally[Ballot::FOR] ?> ·
                            Against: <?= $tally[Ballot::AGAINST] ?> ·
                            Abstain: <?= $tally[Ballot::ABSTAIN] ?>
                        </p>
                        <?php if ($votes !== []): ?>
                            <ul class="vote-list">
                                <?php foreach ($votes as $vote): ?>
                                    <li><?= h((string) $vote['userName']) ?>: <strong><?= h((string) $vote['choice']) ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($ballot->status === Ballot::OPEN && !$readOnly): ?>
                            <form method="post" class="inline-form vote-buttons">
                                <input type="hidden" name="action" value="vote">
                                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                                <input type="hidden" name="ballot" value="<?= h($ballot->id) ?>">
                                <input type="hidden" name="_back" value="<?= h($back) ?>">
                                <?php foreach (Ballot::CHOICES as $choice): ?>
                                    <button type="submit" name="choice" value="<?= h($choice) ?>"
                                            class="vote-<?= h($choice) ?> <?= $myVote === $choice ? 'current' : '' ?>">
                                        <?= h(ucfirst($choice)) ?><?= $myVote === $choice ? ' ✓' : '' ?>
                                    </button>
                                <?php endforeach; ?>
                            </form>
                            <?php if ($owner): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="close-ballot">
                                    <input type="hidden" name="id" value="<?= h($session->id) ?>">
                                    <input type="hidden" name="ballot" value="<?= h($ballot->id) ?>">
                                    <input type="hidden" name="_back" value="<?= h($back) ?>">
                                    <button type="submit">Close ballot</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php
                $hasOpen = array_filter($itemBallots, static fn (Ballot $b): bool => $b->status === Ballot::OPEN) !== [];
                ?>
                <?php if ($owner && !$readOnly && $session->status === Session::LIVE && !$hasOpen): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="open-ballot">
                        <input type="hidden" name="id" value="<?= h($session->id) ?>">
                        <input type="hidden" name="item" value="<?= h($item->id) ?>">
                        <input type="hidden" name="_back" value="<?= h($back) ?>">
                        <input type="text" name="title" placeholder="Ballot title (optional)">
                        <button type="submit">Open ballot</button>
                    </form>
                <?php endif; ?>
                <?php if ($itemBallots === [] && !($owner && !$readOnly && $session->status === Session::LIVE)): ?>
                    <p class="meta">No ballots.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($session->agendaItems === []): ?>
    <p class="meta">No agenda items yet.</p>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
