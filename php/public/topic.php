<?php

declare(strict_types=1);

use Assembly\Domain\Session;
use Assembly\Domain\Topic;

require __DIR__ . '/_init.php';

$id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
if (!preg_match('/^[a-z0-9-]+$/', $id)) {
    http_response_code(400);
    exit('Invalid topic id');
}
$data = $store->load('topics', $id);
if ($data === null) {
    http_response_code(404);
    exit('Unknown topic');
}
$topic = Topic::fromArray($data);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create-session') {
    if (!is_chair($store)) {
        forbid();
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $whenLocal = trim((string) ($_POST['scheduled_for'] ?? ''));
    $scheduledForUtc = null;
    if ($whenLocal !== '') {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $whenLocal, new \DateTimeZone((string) $config['timezone']));
        if ($dt !== false) {
            $scheduledForUtc = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
    }
    if ($title !== '') {
        $session = Session::create($title, $scheduledForUtc, $topic->id, current_user($store)->id);
        $store->save('sessions', $session->toArray());
    }
    redirect_back('topic.php?id=' . $topic->id);
}

$sessions = array_values(array_filter(
    array_map(Session::fromArray(...), $store->list('sessions')),
    static fn (Session $s): bool => $s->topicId === $topic->id,
));
usort($sessions, static fn (Session $a, Session $b): int => strcmp((string) $a->scheduledForUtc, (string) $b->scheduledForUtc));
$users = [];
foreach (all_users($store) as $user) {
    $users[$user->id] = $user;
}

$pageTitle = 'Assembly sandbox — ' . $topic->title;
require __DIR__ . '/_header.php';
?>
<p><a href="topics.php">← All topics</a> ·
   <a href="json.php?type=topics&id=<?= h($topic->id) ?>">view JSON</a></p>

<h1><?= h($topic->title) ?></h1>
<p><?= h($topic->description) ?></p>
<p class="meta">Created by <?= h($users[$topic->createdByUserId]->name ?? $topic->createdByUserId) ?>, <?= h(fmt_time($topic->createdAtUtc)) ?></p>

<?php if (is_chair($store)): ?>
<form method="post" class="card">
    <input type="hidden" name="action" value="create-session">
    <input type="hidden" name="id" value="<?= h($topic->id) ?>">
    <input type="hidden" name="_back" value="topic.php?id=<?= h($topic->id) ?>">
    <h2>New session under this topic</h2>
    <label>Title
        <input type="text" name="title" required>
    </label>
    <label>Scheduled for (<?= h((string) $config['timezone']) ?>)
        <input type="datetime-local" name="scheduled_for">
    </label>
    <button type="submit">Create session</button>
</form>
<?php endif; ?>

<div class="card-list">
<?php foreach ($sessions as $session): ?>
    <div class="card">
        <h2>
            <a href="session.php?id=<?= h($session->id) ?>"><?= h($session->title) ?></a>
            <span class="badge status-<?= h($session->status) ?>"><?= h(Session::statusLabel($session->status)) ?></span>
        </h2>
        <p class="meta">
            <?= h(fmt_time($session->scheduledForUtc)) ?> ·
            chair: <?= h($users[$session->ownerUserId]->name ?? '—') ?> ·
            <?= count($session->agendaItems) ?> agenda item(s) ·
            <a href="json.php?type=sessions&id=<?= h($session->id) ?>">view JSON</a>
        </p>
    </div>
<?php endforeach; ?>
</div>
<?php if ($sessions === []): ?>
    <p class="meta">No sessions under this topic yet.</p>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
