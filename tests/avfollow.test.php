<?php
/**
 * tests/avfollow.test.php — following a Diary author (lib/DiaryFollows.php).
 */
declare(strict_types=1);
$fw = new DiaryFollows(); DiaryFollows::ensure();
Database::pdo()->exec('DELETE FROM diary_follows'); Database::pdo()->exec('DELETE FROM diary_follow_sent');
$r = $fw->follow('ada-obi', 'Reader@Example.com');
ck('follow: a reader follows with an email', $r['ok'] && $r['following'] && $r['count'] === 1);
ck('follow: the same email twice is one follow', $fw->follow('ada-obi', 'reader@example.com')['count'] === 1);
ck('follow: a bad email is refused', !$fw->follow('ada-obi', 'nope')['ok']);
ck('follow: an unknown author is refused', !$fw->follow('', 'a@b.co')['ok']);
ck('follow: isFollowing is case-blind on the email', $fw->isFollowing('ada-obi', 'READER@example.com'));
$tok = (string) Database::pdo()->query("SELECT token FROM diary_follows WHERE email = 'reader@example.com'")->fetchColumn();
ck('follow: each follow has an unfollow token', (bool) preg_match('/^[a-f0-9]{40}$/', $tok));
ck('follow: a bad token stops nothing', $fw->stopByToken('zz') === '' && $fw->count('ada-obi') === 1);
ck('follow: the email link stops the follow', $fw->stopByToken($tok) === 'ada-obi' && $fw->count('ada-obi') === 0);
$fw->follow('ada-obi', 'b@x.co', 2);
ck('follow: a member can unfollow', $fw->unfollow('ada-obi', 'b@x.co')['following'] === false && $fw->count('ada-obi') === 0);

/* One email per new entry, however many saves follow */
$fRepo = new DiaryRepository();
$fSlug = $fRepo->save([
    'slug' => 'follow-fixture', 'title' => 'Follow fixture', 'dek' => 'A fixture.',
    'category' => 'Dispatch', 'authors_html' => 'Ada Obi', 'published' => '1 Oct 2026',
    'published_at' => '2026-10-01', 'read_minutes' => 1, 'gradient' => 'g-gold',
    'mc_title' => 'Follow fixture', 'cover_url' => '', 'og_image' => '', 'body_html' => '<p>x</p>',
    'featured' => 0, 'status' => 'published', 'format' => 'standard',
]);
$fClaims = fn() => (int) Database::pdo()->query("SELECT COUNT(*) FROM diary_follow_sent WHERE article_slug = " . Database::pdo()->quote($fSlug))->fetchColumn();
ck('follow: publishing claims the entry for its one mailing', $fClaims() === 1);
ck('follow: a second save does not mail again', $fw->notifyPublished($fSlug) === 0 && $fClaims() === 1);
ck('follow: an unknown entry mails nobody', $fw->notifyPublished('no-such-entry') === 0);
