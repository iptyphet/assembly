<?php

declare(strict_types=1);

use Assembly\Data\Store;
use Assembly\Domain\AgendaItem;
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

    if ($action === 'add-agenda-item') {
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
            $store->save('sessions', $session->toArray());
        }
    } elseif ($action === 'add-speaker') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? '');
        if ($item !== null && $name !== '' && in_array($kind, [SpeakerEntry::SPEECH, SpeakerEntry::REPLY], true)) {
            $item->speakers[] = new SpeakerEntry(Store::newId(), $name, $kind, Store::nowUtc(), false);
            $store->save('sessions', $session->toArray());
        }
    } elseif ($action === 'now-speaking') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        if ($item !== null && $item->speaker((string) ($_POST['speaker'] ?? '')) !== null) {
            $item->nowSpeakingEntryId = (string) $_POST['speaker'];
            $store->save('sessions', $session->toArray());
        }
    } elseif ($action === 'mark-done') {
        $item = $session->agendaItem((string) ($_POST['item'] ?? ''));
        $speaker = $item?->speaker((string) ($_POST['speaker'] ?? ''));
        if ($item !== null && $speaker !== null) {
            $speaker->done = true;
            if ($item->nowSpeakingEntryId === $speaker->id) {
                $item->nowSpeakingEntryId = null;
            }
            $store->save('sessions', $session->toArray());
        }
    }

    redirect_back($back);
}

$proposals = array_map(Proposal::fromArray(...), $store->list('proposals'));

$pageTitle = 'Assembly sandbox — ' . $session->title;
require __DIR__ . '/_header.php';
?>
<p><a href="sessions.php">← All sessions</a> ·
   <a href="json.php?type=sessions&id=<?= h($session->id) ?>">view JSON</a></p>

<h1><?= h($session->title) ?></h1>
<p class="meta">Scheduled: <?= h(fmt_time($session->scheduledForUtc)) ?: 'not scheduled' ?> (<?= h((string) $config['timezone']) ?>)</p>

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

<?php foreach ($session->agendaItems as $item): ?>
    <?php $queue = $item->queue(); ?>
    <section class="card">
        <h2>
            <?= h($item->title) ?>
            <?php if ($item->proposalId !== null): ?>
                <a class="badge" href="proposal.php?id=<?= h($item->proposalId) ?>">proposal ↗</a>
            <?php endif; ?>
        </h2>

        <?php $nowSpeaking = $item->nowSpeaking(); ?>
        <?php if ($nowSpeaking !== null): ?>
            <p class="now-speaking">
                Now speaking: <strong><?= h($nowSpeaking->displayName) ?></strong>
                (<?= $nowSpeaking->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?>)
            </p>
            <form method="post" class="inline-form">
                <input type="hidden" name="id" value="<?= h($session->id) ?>">
                <input type="hidden" name="item" value="<?= h($item->id) ?>">
                <input type="hidden" name="speaker" value="<?= h($nowSpeaking->id) ?>">
                <input type="hidden" name="_back" value="<?= h($back) ?>">
                <button type="submit" name="action" value="mark-done">Mark done</button>
            </form>
        <?php endif; ?>

        <h3>Speakers queue (replies first, then first come first serve)</h3>
        <?php if ($queue === []): ?>
            <p class="meta">Queue is empty.</p>
        <?php else: ?>
            <ol class="speakers-queue">
                <?php foreach ($queue as $speaker): ?>
                    <li class="<?= $speaker->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?>">
                        <span class="badge"><?= $speaker->kind === SpeakerEntry::REPLY ? 'reply' : 'speech' ?></span>
                        <?= h($speaker->displayName) ?>
                        <span class="meta"><?= h(fmt_time($speaker->requestedAtUtc)) ?></span>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="id" value="<?= h($session->id) ?>">
                            <input type="hidden" name="item" value="<?= h($item->id) ?>">
                            <input type="hidden" name="speaker" value="<?= h($speaker->id) ?>">
                            <input type="hidden" name="_back" value="<?= h($back) ?>">
                            <button type="submit" name="action" value="now-speaking" class="link-button">Now speaking</button>
                            <button type="submit" name="action" value="mark-done" class="link-button">Done</button>
                        </form>
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

        <form method="post" class="inline-form">
            <input type="hidden" name="action" value="add-speaker">
            <input type="hidden" name="id" value="<?= h($session->id) ?>">
            <input type="hidden" name="item" value="<?= h($item->id) ?>">
            <input type="hidden" name="_back" value="<?= h($back) ?>">
            <input type="text" name="name" value="<?= h(display_name()) ?>" required>
            <button type="submit" name="kind" value="<?= SpeakerEntry::SPEECH ?>">Add speech</button>
            <button type="submit" name="kind" value="<?= SpeakerEntry::REPLY ?>">Add reply</button>
        </form>
    </section>
<?php endforeach; ?>

<?php if ($session->agendaItems === []): ?>
    <p class="meta">No agenda items yet.</p>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
