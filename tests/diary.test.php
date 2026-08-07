<?php
/**
 * tests/diary.test.php — Notebooks, entry tabs, and the finding half.
 *
 * The assertions worth reading are the ones about what a share does NOT grant
 * and what a delete does NOT destroy. Those are the decisions somebody will be
 * tempted to "simplify" later, and each one is a way to lose a member's writing
 * or show it to the wrong person.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();
DiaryNotebooks::ensure();
DiaryTabs::ensure();
DiaryOrganise::ensure();
foreach (['diary_entry_tabs', 'diary_tags', 'diary_notebook_members', 'diary_notebooks', 'diary_entries'] as $t) {
    try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
}

$nb  = new DiaryNotebooks();
$tab = new DiaryTabs();
$org = new DiaryOrganise();
$jr  = new DiaryJournal();

/** An entry straight into the table, so these tests do not depend on moderation. */
$mkEntry = function (int $author, string $title, string $body = 'Body text', string $date = '2026-05-01') use ($db): int {
    $db->prepare('INSERT INTO diary_entries (author_id,kind,title,body,entry_date,status,created_at,updated_at)
                  VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$author, 'private', $title, $body, $date, 'logged', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
    return (int) $db->lastInsertId();
};

/* ══ NOTEBOOKS ═══════════════════════════════════════════════════════════ */

$r = $nb->create(1, 'Mentoring', 'Sessions with the cohort', 'green');
ck('notebooks: create returns an id', ($r['ok'] ?? false) && ($r['id'] ?? 0) > 0);
$nbId = (int) $r['id'];
ck('notebooks: a nameless notebook is refused', ($nb->create(1, '   ')['ok'] ?? true) === false);
ck('notebooks: an unknown colour falls back to ink',
   ($nb->create(1, 'Colour test', '', 'chartreuse')['notebook']['colour'] ?? '') === 'ink');

ck('notebooks: the owner sees their own', count($nb->forUser(1)) === 2);
ck('notebooks: another member does not', count($nb->forUser(2)) === 0);
ck('notebooks: owner role is owner', ($nb->one(1, $nbId)['role'] ?? '') === 'owner');
ck('notebooks: a stranger cannot open it', $nb->one(2, $nbId) === null);

ck('notebooks: only the owner renames', !$nb->update(2, $nbId, ['name' => 'Hijacked']));
ck('notebooks: the owner renames', $nb->update(1, $nbId, ['name' => 'Mentoring 2026']));
ck('notebooks: the rename stuck', ($nb->one(1, $nbId)['name'] ?? '') === 'Mentoring 2026');
ck('notebooks: an empty rename is refused', !$nb->update(1, $nbId, ['name' => '  ']));

/* ── Filing entries ─────────────────────────────────────────────────────── */
$e1 = $mkEntry(1, 'First session');
$e2 = $mkEntry(1, 'Second session');
$eOther = $mkEntry(2, 'Not mine');

ck('notebooks: author files their entry', $nb->moveEntry(1, $e1, $nbId));
ck('notebooks: you cannot file somebody else\'s entry', !$nb->moveEntry(1, $eOther, $nbId));
ck('notebooks: the count reflects it', ($nb->forUser(1)[0]['entries'] ?? 0) === 1);
ck('notebooks: an entry can go back to the diary', $nb->moveEntry(1, $e1, 0));
$nb->moveEntry(1, $e1, $nbId);
$nb->moveEntry(1, $e2, $nbId);
ck('notebooks: moveMany files a batch', $nb->moveMany(1, [$e1, $e2], 0) === 2);
$nb->moveMany(1, [$e1, $e2], $nbId);

/* ── Sharing ────────────────────────────────────────────────────────────── */
ck('notebooks: sharing needs a real member',
   ($nb->share(1, $nbId, 'nobody@nowhere.test')['ok'] ?? true) === false);
ck('notebooks: cannot share with yourself',
   ($nb->share(1, $nbId, 'a@x.co')['ok'] ?? true) === false);

$s = $nb->share(1, $nbId, 'b@x.co', 'viewer');
ck('notebooks: share succeeds', ($s['ok'] ?? false) === true);
ck('notebooks: the recipient now sees it', count($nb->forUser(2)) === 1);
ck('notebooks: and it is flagged as not theirs', ($nb->forUser(2)[0]['mine'] ?? true) === false);
ck('notebooks: a viewer can read', $nb->canRead(2, $nbId));
// The distinction the whole role model exists for.
ck('notebooks: a viewer cannot write', !$nb->canWrite(2, $nbId));
ck('notebooks: a viewer cannot file an entry into it', !$nb->moveEntry(2, $eOther, $nbId));

$s2 = $nb->share(1, $nbId, 'b@x.co', 'contributor');
ck('notebooks: re-sharing changes the role rather than failing', ($s2['ok'] ?? false) === true);
ck('notebooks: a contributor can write', $nb->canWrite(2, $nbId));
ck('notebooks: and can now file their own entry', $nb->moveEntry(2, $eOther, $nbId));
ck('notebooks: sharing is not duplicated on re-share', count($nb->members(1, $nbId)) === 1);

// A share never grants administration.
ck('notebooks: a contributor still cannot rename it', !$nb->update(2, $nbId, ['name' => 'Mine now']));
ck('notebooks: a contributor still cannot delete it', !$nb->delete(2, $nbId));

ck('notebooks: the member list is owner-only', $nb->members(2, $nbId) === []);
ck('notebooks: the owner revokes', $nb->unshare(1, $nbId, 2));
ck('notebooks: access is gone', !$nb->canRead(2, $nbId));
// Revoking does NOT claw back the contributor's own entry.
$stillTheirs = $db->prepare('SELECT author_id FROM diary_entries WHERE id = ?');
$stillTheirs->execute([$eOther]);
ck('notebooks: a revoked contributor keeps authorship of what they wrote',
   (int) $stillTheirs->fetchColumn() === 2);

/* ── Link sharing ───────────────────────────────────────────────────────── */
$tok = $nb->linkToken(1, $nbId);
ck('notebooks: a link token is minted', is_string($tok) && strlen((string) $tok) >= 24);
ck('notebooks: the same token is returned, not a new one', $nb->linkToken(1, $nbId) === $tok);
ck('notebooks: a stranger cannot mint one', $nb->linkToken(2, $nbId) === null);

$view = $nb->byLinkToken((string) $tok);
ck('notebooks: the link resolves', is_array($view) && ($view['notebook']['name'] ?? '') === 'Mentoring 2026');
ck('notebooks: the link carries entries', count($view['entries'] ?? []) >= 2);
ck('notebooks: the link never exposes the member list', !isset($view['members']));
ck('notebooks: a rubbish token resolves to nothing', $nb->byLinkToken('not-a-token') === null);
ck('notebooks: revoking kills the link', $nb->revokeLink(1, $nbId) && $nb->byLinkToken((string) $tok) === null);

/* ── Deleting a notebook keeps the writing ──────────────────────────────── */
$before = (int) $db->query('SELECT COUNT(*) FROM diary_entries')->fetchColumn();
ck('notebooks: the owner deletes', $nb->delete(1, $nbId));
$after = (int) $db->query('SELECT COUNT(*) FROM diary_entries')->fetchColumn();
ck('notebooks: THE ENTRIES SURVIVE', $before === $after);
$back = $db->prepare('SELECT notebook_id FROM diary_entries WHERE id = ?');
$back->execute([$e1]);
ck('notebooks: they return to the diary', (int) $back->fetchColumn() === 0);

/* ══ TABS ════════════════════════════════════════════════════════════════ */

$e3 = $mkEntry(1, 'Week 12 review', 'What happened on Monday.');
$tabs = $tab->all($e3);
ck('tabs: an untouched entry already has exactly one tab', count($tabs) === 1);
ck('tabs: and that tab IS the entry body', ($tabs[0]['body'] ?? '') === 'What happened on Monday.');
ck('tabs: the first tab is id 0', ($tabs[0]['id'] ?? -1) === 0 && ($tabs[0]['is_first'] ?? false) === true);

$t1 = $tab->add(1, $e3, 'Tuesday', 'What happened on Tuesday.');
ck('tabs: add succeeds', ($t1['ok'] ?? false) === true);
ck('tabs: a stranger cannot add one', ($tab->add(2, $e3, 'Nope')['ok'] ?? true) === false);
$t2 = $tab->add(1, $e3, 'Wednesday', 'Wed.');
ck('tabs: the entry now has three', count($tab->all($e3)) === 3);
ck('tabs: countsFor batches', ($tab->countsFor([$e3, $e1])[$e3] ?? 0) === 3);

// Saving tab 0 writes the ENTRY body — the caller never learns where a tab lives.
ck('tabs: saving tab 0 edits the entry itself', $tab->save(1, $e3, 0, 'Monday', 'Monday, revised.'));
$row = $db->prepare('SELECT body, first_tab_title FROM diary_entries WHERE id = ?');
$row->execute([$e3]);
$got = $row->fetch(PDO::FETCH_ASSOC);
ck('tabs: the entry body changed', ($got['body'] ?? '') === 'Monday, revised.');
ck('tabs: the first tab has a name now', ($got['first_tab_title'] ?? '') === 'Monday');
ck('tabs: and it reads back through all()', ($tab->all($e3)[0]['title'] ?? '') === 'Monday');

ck('tabs: saving a later tab works', $tab->save(1, $e3, (int) $t1['id'], null, 'Tuesday, revised.'));
ck('tabs: a stranger cannot save', !$tab->save(2, $e3, (int) $t1['id'], null, 'hax'));

// THE GUARD THAT MATTERS: the first tab cannot be deleted, because deleting it
// would be deleting the entry through a side door.
ck('tabs: the first tab cannot be deleted', !$tab->remove(1, $e3, 0));
ck('tabs: a later tab can be', $tab->remove(1, $e3, (int) $t2['id']));
ck('tabs: two left', count($tab->all($e3)) === 2);

$t3 = $tab->add(1, $e3, 'Thursday', 'Thu.');
ck('tabs: reorder puts them where asked',
   $tab->reorder(1, $e3, [(int) $t3['id'], (int) $t1['id']]));
$order = array_column($tab->all($e3), 'title');
ck('tabs: the first tab stays first regardless', $order[0] === 'Monday');
ck('tabs: the reorder took', $order[1] === 'Thursday' && $order[2] === 'Tuesday');

/* ══ ORGANISATION ════════════════════════════════════════════════════════ */

// Normalisation is the single line that keeps a tag list usable.
ck('tags: case is folded', DiaryOrganise::normaliseTag('Mentoring') === 'mentoring');
ck('tags: spaces become hyphens', DiaryOrganise::normaliseTag('one to one') === 'one-to-one');
ck('tags: punctuation is stripped', DiaryOrganise::normaliseTag('  Mentoring!  ') === 'mentoring');
ck('tags: an empty tag stays empty', DiaryOrganise::normaliseTag('  !!  ') === '');

$set = $org->setTags(1, $e3, ['Mentoring', 'mentoring ', 'Week Review', '!!']);
ck('tags: duplicates collapse after normalising', count($set) === 2);
ck('tags: they read back', $org->tagsFor($e3) === ['mentoring', 'week-review']);
ck('tags: a stranger cannot tag your entry', $org->setTags(2, $e3, ['hax']) === []);

$org->setTags(1, $e1, ['mentoring']);
$vocab = $org->vocabulary(1);
ck('tags: the vocabulary counts usage', ($vocab[0]['tag'] ?? '') === 'mentoring' && ($vocab[0]['n'] ?? 0) === 2);

ck('tags: rename sweeps every entry', $org->renameTag(1, 'mentoring', 'coaching') === 2);
ck('tags: the old tag is gone', $org->tagsFor($e1) === ['coaching']);

// Pinning is capped so it keeps meaning something.
ck('pin: pinning works', ($org->pin(1, $e3, true)['ok'] ?? false) === true);
$extra = [];
for ($i = 0; $i < DiaryOrganise::MAX_PINNED; $i++) $extra[] = $mkEntry(1, 'Filler ' . $i);
$okCount = 0;
foreach ($extra as $id) if (($org->pin(1, $id, true)['ok'] ?? false)) $okCount++;
ck('pin: the cap holds', $okCount === DiaryOrganise::MAX_PINNED - 1);
foreach ($extra as $id) $org->pin(1, $id, false);

ck('archive: archiving works', $org->archive(1, $e1, true));
ck('archive: archived entries leave the default list',
   !in_array($e1, array_column($org->search(1), 'id'), true));
ck('archive: and appear when asked for',
   in_array($e1, array_column($org->search(1, ['archived' => true]), 'id'), true));

// Archiving must unpin, or "out of the way" leaves it at the top.
$org->pin(1, $e2, true);
$org->archive(1, $e2, true);
$p = $db->prepare('SELECT pinned FROM diary_entries WHERE id = ?');
$p->execute([$e2]);
ck('archive: archiving unpins', (int) $p->fetchColumn() === 0);
$org->archive(1, $e2, false);

/* ── Search ─────────────────────────────────────────────────────────────── */
ck('search: finds by title', count($org->search(1, ['q' => 'Week 12'])) === 1);
ck('search: finds by body', count($org->search(1, ['q' => 'Monday, revised'])) === 1);
// An entry whose answer is on a later tab must still be findable.
ck('search: finds text that only exists on a later tab',
   ($org->search(1, ['q' => 'Tuesday, revised'])[0]['id'] ?? 0) === $e3);
ck('search: a wildcard is not a wildcard', count($org->search(1, ['q' => '%'])) === 0);
ck('search: filters by tag', ($org->search(1, ['tag' => 'Coaching'])[0]['id'] ?? 0) === $e3
   || count($org->search(1, ['tag' => 'coaching'])) >= 1);
ck('search: results carry their tags and tab count',
   ($org->search(1, ['q' => 'Week 12'])[0]['tab_count'] ?? 0) === 3);
ck('search: pinned float to the top', ($org->search(1)[0]['pinned'] ?? false) === true);
ck('search: never returns another member\'s entries',
   !in_array($eOther, array_column($org->search(1), 'id'), true));

$counts = $org->counts(1);
ck('counts: pinned is counted', ($counts['pinned'] ?? 0) === 1);
ck('counts: archived is counted', ($counts['archived'] ?? 0) === 1);
ck('counts: unfiled is counted', ($counts['unfiled'] ?? 0) >= 1);

/* ══ CROSS-CUTTING READ PERMISSION ═══════════════════════════════════════ */
//
// av_diary_may_read() is what the tabs endpoint trusts, so it gets its own
// assertions rather than being covered by implication.

$nb2 = $nb->create(1, 'Shared reading', '', 'gold');
$nbId2 = (int) $nb2['id'];
$eShared = $mkEntry(1, 'Inside a shared notebook', 'Contents.');
$nb->moveEntry(1, $eShared, $nbId2);
$ePrivate = $mkEntry(1, 'Not in any notebook', 'Private contents.');

ck('read: the author can read their own', av_diary_may_read(1, $ePrivate));
ck('read: a stranger cannot', !av_diary_may_read(2, $ePrivate));
ck('read: a stranger cannot read one inside an unshared notebook', !av_diary_may_read(2, $eShared));

$nb->share(1, $nbId2, 'b@x.co', 'viewer');
// THE POINT OF NOTEBOOK SHARING: access reaches entries filed before the share…
ck('read: a notebook viewer can read what is already in it', av_diary_may_read(2, $eShared));
// …and entries that did not exist when it was granted.
$eLater = $mkEntry(1, 'Written after the share', 'Later contents.');
$nb->moveEntry(1, $eLater, $nbId2);
ck('read: and entries added AFTER the share was granted', av_diary_may_read(2, $eLater));
ck('read: but still not the unfiled one', !av_diary_may_read(2, $ePrivate));

$nb->unshare(1, $nbId2, 2);
ck('read: revoking closes it again', !av_diary_may_read(2, $eShared));
ck('read: a nonexistent entry is not readable', !av_diary_may_read(1, 999999));
