<?php

declare(strict_types=1);

use Assembly\Domain\Session;
use Assembly\Domain\Topic;

require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!is_chair($store)) {
        forbid();
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    if ($title !== '') {
        $topic = Topic::create($title, $description, current_user($store)->id);
        $store->save('topics', $topic->toArray());
    }
    redirect_back('topics.php');
}

$topics = array_map(Topic::fromArray(...), $store->list('topics'));
usort($topics, static fn (Topic $a, Topic $b): int => strcmp($a->title, $b->title));
$sessions = array_map(Session::fromArray(...), $store->list('sessions'));

$pageTitle = 'Assembly sandbox — Topics';
require __DIR__ . '/_header.php';
?>
<h1>Topics</h1>

<?php if (is_chair($store)): ?>
<form method="post" class="card">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="_back" value="topics.php">
    <h2>New topic</h2>
    <label>Title
        <input type="text" name="title" required>
    </label>
    <label>Description
        <textarea name="description" rows="3"></textarea>
    </label>
    <button type="submit">Create topic</button>
</form>
<?php endif; ?>

<div class="card-list">
<?php foreach ($topics as $topic): ?>
    <?php $count = count(array_filter($sessions, static fn (Session $s): bool => $s->topicId === $topic->id)); ?>
    <div class="card">
        <h2><a href="topic.php?id=<?= h($topic->id) ?>"><?= h($topic->title) ?></a></h2>
        <p class="meta"><?= $count ?> session(s) ·
            <a href="json.php?type=topics&id=<?= h($topic->id) ?>">view JSON</a></p>
        <p><?= h($topic->description) ?></p>
    </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
