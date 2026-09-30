<?php

declare(strict_types=1);

use Assembly\Domain\Proposal;

require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $clauses = array_values(array_filter(array_map(
        static fn (string $line): string => trim($line),
        explode("\n", (string) ($_POST['clauses'] ?? '')),
    ), static fn (string $line): bool => $line !== ''));
    if ($title !== '' && $clauses !== []) {
        $proposal = Proposal::create($title, $clauses, current_user($store));
        $store->save('proposals', $proposal->toArray());
    }
    redirect_back('proposals.php');
}

$proposals = array_map(Proposal::fromArray(...), $store->list('proposals'));
usort($proposals, static fn (Proposal $a, Proposal $b): int => strcmp($b->createdAtUtc, $a->createdAtUtc));

$pageTitle = 'Assembly sandbox — Proposals';
require __DIR__ . '/_header.php';
?>
<h1>Proposals</h1>

<form method="post" class="card">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="_back" value="proposals.php">
    <h2>New proposal</h2>
    <label>Title
        <input type="text" name="title" required>
    </label>
    <label>Clauses (one per line)
        <textarea name="clauses" rows="5" required></textarea>
    </label>
    <button type="submit">Create proposal</button>
</form>

<div class="card-list">
<?php foreach ($proposals as $proposal): ?>
    <div class="card">
        <h2>
            <a href="proposal.php?id=<?= h($proposal->id) ?>"><?= h($proposal->title) ?></a>
            <span class="badge"><?= h(Proposal::statusLabel($proposal->status)) ?></span>
        </h2>
        <p class="meta">
            v<?= $proposal->latest()->number ?> ·
            <?= count($proposal->pendingAmendments()) ?> pending amendment(s) ·
            by <?= h($proposal->createdByName) ?> ·
            <a href="json.php?type=proposals&id=<?= h($proposal->id) ?>">view JSON</a>
        </p>
    </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
