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
    $whenLocal = trim((string) ($_POST['scheduled_for'] ?? ''));
    $topicRaw = (string) ($_POST['topic'] ?? '');
    $topicId = null;
    if ($topicRaw !== '' && $store->load('topics', $topicRaw) !== null) {
        $topicId = $topicRaw;
    }
    $scheduledForUtc = null;
    if ($whenLocal !== '') {
        // datetime-local is entered in the configured timezone, stored as UTC.
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $whenLocal, new \DateTimeZone((string) $config['timezone']));
        if ($dt !== false) {
            $scheduledForUtc = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
    }
    if ($title !== '') {
        // The chair who creates a session is its owner.
        $session = Session::create($title, $scheduledForUtc, $topicId, current_user($store)->id);
        $store->save('sessions', $session->toArray());
    }
    redirect_back('sessions.php');
}

$sessions = array_map(Session::fromArray(...), $store->list('sessions'));
usort($sessions, static fn (Session $a, Session $b): int => strcmp((string) $a->scheduledForUtc, (string) $b->scheduledForUtc));
$topics = array_map(Topic::fromArray(...), $store->list('topics'));
usort($topics, static fn (Topic $a, Topic $b): int => strcmp($a->title, $b->title));

// Month calendar grid. Sessions are placed by their scheduled date in the
// configured timezone.
$month = (string) ($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = (new \DateTimeImmutable('now'))->format('Y-m');
}
$firstOfMonth = new \DateTimeImmutable($month . '-01');
$prevMonth = $firstOfMonth->modify('-1 month')->format('Y-m');
$nextMonth = $firstOfMonth->modify('+1 month')->format('Y-m');

$byDate = [];
foreach ($sessions as $session) {
    if ($session->scheduledForUtc === null) {
        continue;
    }
    $localDate = (new \DateTimeImmutable($session->scheduledForUtc))
        ->setTimezone(new \DateTimeZone((string) $config['timezone']))
        ->format('Y-m-d');
    $byDate[$localDate][] = $session;
}

$daysInMonth = (int) $firstOfMonth->format('t');
$firstWeekday = (int) $firstOfMonth->format('N'); // 1 = Monday

$pageTitle = 'Assembly sandbox — Sessions';
require __DIR__ . '/_header.php';
?>
<h1>Sessions</h1>

<?php if (is_chair($store)): ?>
<form method="post" class="card">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="_back" value="sessions.php">
    <h2>New session</h2>
    <label>Title
        <input type="text" name="title" required>
    </label>
    <label>Topic
        <select name="topic">
            <option value="">— none —</option>
            <?php foreach ($topics as $topic): ?>
                <option value="<?= h($topic->id) ?>"><?= h($topic->title) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Scheduled for (<?= h((string) $config['timezone']) ?>)
        <input type="datetime-local" name="scheduled_for">
    </label>
    <button type="submit">Create session</button>
</form>
<?php endif; ?>

<section class="card">
    <h2>
        <a href="sessions.php?month=<?= h($prevMonth) ?>">←</a>
        <?= h($firstOfMonth->format('F Y')) ?>
        <a href="sessions.php?month=<?= h($nextMonth) ?>">→</a>
    </h2>
    <table class="calendar">
        <thead>
            <tr>
                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day): ?>
                    <th><?= $day ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $cell = 0;
            $day = 1;
            while ($day <= $daysInMonth):
            ?>
                <tr>
                    <?php for ($col = 1; $col <= 7; $col++): ?>
                        <?php if ($cell < $firstWeekday - 1 || $day > $daysInMonth): ?>
                            <td class="empty"></td>
                        <?php else: ?>
                            <?php
                            $date = sprintf('%s-%02d', $month, $day);
                            $daySessions = $byDate[$date] ?? [];
                            ?>
                            <td>
                                <span class="day-number"><?= $day ?></span>
                                <?php foreach ($daySessions as $session): ?>
                                    <a class="calendar-session" href="session.php?id=<?= h($session->id) ?>">
                                        <?= h($session->title) ?>
                                    </a>
                                <?php endforeach; ?>
                            </td>
                            <?php $day++; ?>
                        <?php endif; ?>
                        <?php $cell++; ?>
                    <?php endfor; ?>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</section>

<?php
$grouped = [];
foreach ($sessions as $session) {
    $grouped[$session->topicId ?? ''][] = $session;
}
$renderSessionCard = static function (Session $session): void {
    ?>
    <div class="card">
        <h2>
            <a href="session.php?id=<?= h($session->id) ?>"><?= h($session->title) ?></a>
            <span class="badge status-<?= h($session->status) ?>"><?= h(Session::statusLabel($session->status)) ?></span>
        </h2>
        <p class="meta">
            <?= h(fmt_time($session->scheduledForUtc)) ?> ·
            <?= count($session->agendaItems) ?> agenda item(s) ·
            <a href="json.php?type=sessions&id=<?= h($session->id) ?>">view JSON</a>
        </p>
    </div>
    <?php
};
?>
<?php foreach ($topics as $topic): ?>
    <?php if (($grouped[$topic->id] ?? []) === []) {
        continue;
    } ?>
    <h2><a href="topic.php?id=<?= h($topic->id) ?>"><?= h($topic->title) ?></a></h2>
    <div class="card-list">
        <?php foreach ($grouped[$topic->id] as $session) {
            $renderSessionCard($session);
        } ?>
    </div>
<?php endforeach; ?>
<?php if (($grouped[''] ?? []) !== []): ?>
    <h2>No topic</h2>
    <div class="card-list">
        <?php foreach ($grouped[''] as $session) {
            $renderSessionCard($session);
        } ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
