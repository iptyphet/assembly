<?php

declare(strict_types=1);

use Assembly\Domain\Proposal;
use Assembly\Domain\Session;
use Assembly\Domain\Topic;

require __DIR__ . '/_init.php';

$proposals = array_map(Proposal::fromArray(...), $store->list('proposals'));
$sessions = array_map(Session::fromArray(...), $store->list('sessions'));
$topics = array_map(Topic::fromArray(...), $store->list('topics'));

usort($proposals, static fn (Proposal $a, Proposal $b): int => strcmp($b->createdAtUtc, $a->createdAtUtc));
usort($sessions, static fn (Session $a, Session $b): int => strcmp((string) $a->scheduledForUtc, (string) $b->scheduledForUtc));

$pageTitle = 'Assembly sandbox';
require __DIR__ . '/_header.php';
?>
<h1>Overview</h1>
<div class="card-grid">
    <div class="card">
        <h2><a href="proposals.php">Proposals</a></h2>
        <p class="count"><?= count($proposals) ?></p>
        <ul>
            <?php foreach (array_slice($proposals, 0, 5) as $proposal): ?>
                <li>
                    <a href="proposal.php?id=<?= h($proposal->id) ?>"><?= h($proposal->title) ?></a>
                    <span class="badge"><?= h(Proposal::statusLabel($proposal->status)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card">
        <h2><a href="sessions.php">Sessions</a></h2>
        <p class="count"><?= count($sessions) ?></p>
        <ul>
            <?php foreach (array_slice($sessions, 0, 5) as $session): ?>
                <li>
                    <a href="session.php?id=<?= h($session->id) ?>"><?= h($session->title) ?></a>
                    <span class="meta"><?= h(fmt_time($session->scheduledForUtc)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card">
        <h2><a href="topics.php">Topics</a></h2>
        <p class="count"><?= count($topics) ?></p>
        <ul>
            <?php foreach (array_slice($topics, 0, 5) as $topic): ?>
                <li><a href="topic.php?id=<?= h($topic->id) ?>"><?= h($topic->title) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
