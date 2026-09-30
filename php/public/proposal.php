<?php

declare(strict_types=1);

use Assembly\Data\Store;
use Assembly\Domain\AgendaItem;
use Assembly\Domain\Amendment;
use Assembly\Domain\Proposal;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add-amendment') {
        $kind = (string) ($_POST['kind'] ?? '');
        $clauseRaw = (string) ($_POST['clause'] ?? '');
        $clauseId = $clauseRaw === 'top' ? null : $clauseRaw;
        $newText = trim((string) ($_POST['new_text'] ?? ''));
        $clauseExists = $clauseId === null || array_filter(
            $proposal->latest()->clauses,
            static fn ($c): bool => $c->id === $clauseId,
        ) !== [];
        $textOk = $kind === Amendment::STRIKE || $newText !== '';
        // Null clause id is only meaningful as "insert at the top".
        $clauseOk = $clauseId !== null || $kind === Amendment::INSERT_AFTER;
        if (in_array($kind, Amendment::KINDS, true) && $clauseExists && $clauseOk && $textOk) {
            $proposal->amendments[] = new Amendment(
                Store::newId(),
                $proposal->latest()->number,
                $clauseId,
                $kind,
                $kind === Amendment::STRIKE ? null : $newText,
                Amendment::PROPOSED,
                display_name(),
                Store::nowUtc(),
            );
            $store->save('proposals', $proposal->toArray());
        }
    } elseif ($action === 'accept-amendment' || $action === 'reject-amendment') {
        $amendment = $proposal->amendment((string) ($_POST['amendment'] ?? ''));
        if ($amendment !== null && $amendment->status === Amendment::PROPOSED) {
            if ($action === 'accept-amendment') {
                $proposal->acceptAmendment($amendment, display_name());
            } else {
                $amendment->status = Amendment::REJECTED;
            }
            $store->save('proposals', $proposal->toArray());
        }
    } elseif ($action === 'submit-to-session') {
        $sessionData = $store->load('sessions', (string) ($_POST['session'] ?? ''));
        if ($sessionData !== null) {
            $session = Session::fromArray($sessionData);
            $session->agendaItems[] = AgendaItem::create($proposal->title, $proposal->id);
            $store->save('sessions', $session->toArray());
            $proposal->status = Proposal::READY_FOR_SESSION;
            $store->save('proposals', $proposal->toArray());
        }
    }

    redirect_back($back);
}

$latest = $proposal->latest();

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

$sessions = array_map(Session::fromArray(...), $store->list('sessions'));

/** Rendered preview of an amendment against the latest version. */
$amendmentPreview = static function (Amendment $a) use ($latest): string {
    $target = null;
    foreach ($latest->clauses as $clause) {
        if ($clause->id === $a->clauseId) {
            $target = $clause;
            break;
        }
    }
    if ($a->kind === Amendment::INSERT_AFTER) {
        return '<ins>' . h((string) $a->newText) . '</ins>';
    }
    if ($target === null) {
        return '<em>Target clause no longer exists in the latest version.</em>';
    }
    if ($a->kind === Amendment::STRIKE) {
        return '<del>' . h($target->text) . '</del>';
    }

    return render_diff($target->text, (string) $a->newText);
};

$pageTitle = 'Assembly sandbox — ' . $proposal->title;
require __DIR__ . '/_header.php';
?>
<p><a href="proposals.php">← All proposals</a> ·
   <a href="json.php?type=proposals&id=<?= h($proposal->id) ?>">view JSON</a></p>

<h1><?= h($proposal->title) ?> <span class="badge"><?= h(Proposal::statusLabel($proposal->status)) ?></span></h1>
<p class="meta">Created by <?= h($proposal->createdByName) ?> at <?= h(fmt_time($proposal->createdAtUtc)) ?> UTC → local.</p>

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
    <h2>Pending amendments (<?= count($proposal->pendingAmendments()) ?>)</h2>
    <?php foreach ($proposal->pendingAmendments() as $amendment): ?>
        <div class="amendment">
            <p class="meta">
                <strong><?= h(Amendment::kindLabel($amendment->kind)) ?></strong>
                by <?= h($amendment->proposedByName) ?>, <?= h(fmt_time($amendment->createdAtUtc)) ?>
                (against v<?= $amendment->targetVersion ?>)
            </p>
            <p class="diff-view"><?= $amendmentPreview($amendment) ?></p>
            <form method="post" class="inline-form">
                <input type="hidden" name="id" value="<?= h($proposal->id) ?>">
                <input type="hidden" name="amendment" value="<?= h($amendment->id) ?>">
                <input type="hidden" name="_back" value="<?= h($back) ?>">
                <button type="submit" name="action" value="accept-amendment">Accept → new version</button>
                <button type="submit" name="action" value="reject-amendment" class="link-button">Reject</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if ($proposal->pendingAmendments() === []): ?>
        <p class="meta">None.</p>
    <?php endif; ?>

    <h3>Add amendment (against v<?= $latest->number ?>)</h3>
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
        <button type="submit">Add amendment</button>
    </form>
</section>

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
