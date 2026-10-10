<?php
/**
 * tests/avdy.test.php — what the portal Diary notebook (Portal v3) needs from
 * the diary backend: the draft state for public entries, sanitised tab bodies,
 * and reading a shared notebook's entries.
 * Run via tests/run.php (provides ck(), reset_users()).
 */
declare(strict_types=1);
reset_users();
$dyJ = new DiaryJournal();
$dyDb = Database::pdo();
$dySt = fn(int $id) => (string) $dyDb->query('SELECT status FROM diary_entries WHERE id = ' . $id)->fetchColumn();

/* Draft → submit → withdraw */
$r = $dyJ->create(1, 'public', 'Draft one', '<p>Words</p>', date('Y-m-d'), 'default', true);
ck('avdy: a public draft is created outside the review queue', $r['ok'] && $r['status'] === 'draft');
$dyId = (int) $r['id'];
ck('avdy: a draft is not in the moderation queue', !in_array($dyId, array_map(fn($e) => (int) $e['id'], $dyJ->pendingPublic()), true));
$dyJ->updateOwn(1, $dyId, ['body' => '<p>More words</p>']);
ck('avdy: editing a draft keeps it a draft', $dySt($dyId) === 'draft');
$dyJ->updateOwn(1, $dyId, ['submit' => true]);
ck('avdy: submitting sends it to review', $dySt($dyId) === 'pending');
$dyJ->updateOwn(1, $dyId, ['draft' => true]);
ck('avdy: withdrawing returns it to draft', $dySt($dyId) === 'draft');
$dyJ->updateOwn(1, $dyId, ['kind' => 'private']);
ck('avdy: making it private logs it', $dySt($dyId) === 'logged');
$dyJ->updateOwn(1, $dyId, ['kind' => 'public']);
ck('avdy: without a flag, making an entry public still sends it to review (older callers)', $dySt($dyId) === 'pending');
$dyJ->updateOwn(1, $dyId, ['font' => 'caveat']);
ck('avdy: a font change leaves a pending entry pending', $dySt($dyId) === 'pending');
ck('avdy: a legacy create without the flag still goes to review', $dyJ->create(1, 'event', 'E', 'x', date('Y-m-d'))['status'] === 'pending');

/* Tabs are sanitised */
$dyT = new DiaryTabs();
$dyTab = $dyT->add(1, $dyId, 'Two', '<p>ok</p><script>alert(1)</script>');
$dyAll = $dyT->all($dyId);
ck('avdy: an added tab body is sanitised', $dyTab['ok'] && !str_contains($dyAll[1]['body'], '<script'));
$dyT->save(1, $dyId, (int) $dyTab['id'], null, '<p onclick="x()">hi</p><img src=x onerror=alert(1)>');
$dyAll = $dyT->all($dyId);
ck('avdy: a saved tab body is sanitised', !str_contains($dyAll[1]['body'], 'onclick') && !str_contains($dyAll[1]['body'], 'onerror'));
$dyT->save(1, $dyId, 0, null, '<p>first</p><script>bad()</script>');
ck('avdy: the first tab (the entry body) is sanitised too', !str_contains((string) $dyDb->query('SELECT body FROM diary_entries WHERE id = ' . $dyId)->fetchColumn(), '<script'));

/* A shared notebook's entries */
$dyNb = new DiaryNotebooks();
$dyNid = (int) $dyNb->create(1, 'Field notes')['id'];
$dyNb->moveEntry(1, $dyId, $dyNid);
ck('avdy: a stranger cannot list a notebook', $dyNb->entries(2, $dyNid) === null);
$dyNb->share(1, $dyNid, 'b@x.co', 'viewer');
$dyRows = $dyNb->entries(2, $dyNid);
ck('avdy: a member it is shared with can list it', is_array($dyRows) && count($dyRows) === 1);
ck('avdy: the reader sees the author and may not edit', $dyRows[0]['author'] === 'Ada' && $dyRows[0]['mine'] === false);
ck('avdy: the owner sees their own entry as theirs', ($dyNb->entries(1, $dyNid)[0]['mine'] ?? null) === true);

/* Search carries what the editor needs */
$dyS = (new DiaryOrganise())->search(1, []);
ck('avdy: search rows carry font and review note', isset($dyS[0]['font'], $dyS[0]['review_note'], $dyS[0]['first_tab_title']));

/* Deleting an entry takes its tags, tabs and shares with it */
$dyDel = (int) $dyJ->create(1, 'private', 'Gone', '<p>x</p>', date('Y-m-d'))['id'];
(new DiaryOrganise())->setTags(1, $dyDel, ['vanish']);
$dyT->add(1, $dyDel, 'T2', 'y');
$dyJ->deleteOwn(1, $dyDel);
ck('avdy: a deleted entry\'s tags stop counting', !in_array('vanish', array_column((new DiaryOrganise())->vocabulary(1), 'tag'), true));
ck('avdy: a deleted entry\'s tabs go too', (int) $dyDb->query('SELECT COUNT(*) FROM diary_entry_tabs WHERE entry_id = ' . $dyDel)->fetchColumn() === 0);
