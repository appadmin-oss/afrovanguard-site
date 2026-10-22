<?php
/**
 * tests/questdeskui.test.php — the Quest desk at /mentorship/compliance/.
 *
 * The promises: one registry of sections, each with its view and no view
 * without an entry; the committee never sees what is a superadmin's (prices,
 * rules, the advisor); every form on the desk posts an op the endpoint
 * handles; a promotion is one level, never one's own, the committee's call;
 * the register is assembled in grouped queries; every view renders; the
 * stylesheet holds to tokens.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8500–8599.
 */
declare(strict_types=1);

$db = Database::pdo();
Conduct::ensure(); QuestPoints::ensure(); QuestRecord::ensure(); QuestLadder::ensure(); QuestMoney::ensure(); Levels::ensure(); Mentorship::ensure();
$db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8500 AND 8599');
$db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8500 AND 8599');
AvRules::resetAll('test');

reset_users();
$now = gmdate('Y-m-d H:i:s');
foreach ([8501 => ['Desk Boss', 'boss.du@afrovanguard.org.ng'], 8502 => ['Desk Committee', 'com.du@x.test'], 8503 => ['Desk Member', 'mem.du@x.test']] as $id => [$n, $e]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, '', 'member', $now]);
}
AdminRoles::add('boss.du@afrovanguard.org.ng', 'superadmin', 'test');
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))->execute([8502, 'test', $now]);
$boss = ['id' => 8501, 'name' => 'Desk Boss', 'email' => 'boss.du@afrovanguard.org.ng'];
$com = ['id' => 8502, 'name' => 'Desk Committee', 'email' => 'com.du@x.test'];

/* ── one registry ── */

$views = array_map(static fn($f) => basename($f, '.php'), glob(AV_ROOT . '/mentorship/compliance/views/*.php') ?: []);
sort($views);
$ids = array_keys(QuestDesk::SECTIONS);
$sorted = $ids; sort($sorted);
ck('questdesk ui: every section has its view, and no view is outside the registry', $views === $sorted);
ck('questdesk ui: the committee is not shown prices, rules or the advisor',
   !isset(QuestDesk::sectionsFor($com)['rules']) && !isset(QuestDesk::sectionsFor($com)['advisor']) && isset(QuestDesk::sectionsFor($com)['catalogue']));
ck('questdesk ui: a superadmin sees every section', array_keys(QuestDesk::sectionsFor($boss)) === $ids);
$shell = (string) file_get_contents(AV_ROOT . '/mentorship/compliance/index.php');
ck('questdesk ui: the shell builds its sidebar from the registry, not a list of its own',
   str_contains($shell, 'QuestDesk::sectionsFor($u)') && !preg_match("~data-view=\"(cases|money|rules)\"~", $shell));

/* ── every form posts an op the endpoint handles ── */

$endpoint = (string) file_get_contents(AV_ROOT . '/mentorship/conduct.php');
$src = $shell;
foreach (glob(AV_ROOT . '/mentorship/compliance/views/*.php') ?: [] as $f) $src .= (string) file_get_contents($f);
preg_match_all('~name="op" value="([a-z_]+)"~', $src, $m);
$ops = array_unique($m[1]);
$missing = array_values(array_filter($ops, static fn($op) => !str_contains($endpoint, "case '" . $op . "':")));
ck('questdesk ui: every op the desk posts has a handler — ' . implode(', ', $missing ?: ['none missing']), count($ops) >= 15 && $missing === []);
ck('questdesk ui: the endpoint checks the origin and the token on every post',
   str_contains($endpoint, 'require_same_origin()') && str_contains($endpoint, "av_csrf_valid((string) (\$_POST['csrf']"));
ck('questdesk ui: a bulk run is capped and names its cap', str_contains($endpoint, '$max = 200') && str_contains($endpoint, "'At most ' . \$max"));

/* ── promotions ── */

Levels::set(8503, 'O', 'test');
ck('questdesk ui: a member off the committee cannot promote', !QuestDesk::promote(8503, 'A', ['id' => 8504, 'email' => 'x@x.test'])['ok']);
ck('questdesk ui: nobody promotes themselves', !QuestDesk::promote(8502, 'A', $com)['ok']);
ck('questdesk ui: a promotion is one level, to the next', !QuestDesk::promote(8503, 'B', $com)['ok'] && Levels::of(8503) === 'O');
ck('questdesk ui: the committee promotes one level', QuestDesk::promote(8503, 'A', $com)['ok'] && Levels::of(8503) === 'A');

/* ── the register ── */

QuestPoints::grant(8503, 160, 'test:du', 'test', '', 0, gmdate('Y-m-d'));
$r = current(array_filter(QuestDesk::roster('Desk Member'), static fn($x) => $x['id'] === 8503));
ck('questdesk ui: the register carries level, points, the month and what the points qualify for',
   $r && $r['level'] === 'A' && $r['points'] === 160 && $r['month'] === 160 && $r['qualifies'] === 'A' && $r['journey'] === 'Visitor');
ck('questdesk ui: search narrows the register', count(QuestDesk::roster('nobody-by-this-name')) === 0);
$rosterSrc = (string) file_get_contents(AV_ROOT . '/lib/QuestDesk.php');
ck('questdesk ui: the register never asks once per member', !preg_match('~foreach \(\$rows as \$r\)[^}]*->(prepare|query)\(~s', $rosterSrc));

/* ── every view renders ── */

$ov = QuestDesk::overview();
$csrf = 'test'; $superior = true; $edit = true; $u = $boss;
$bad = [];
foreach (QuestDesk::sectionsFor($boss) as $sid => $s) {
    $title = $s['title'];
    ob_start();
    try { require AV_ROOT . '/mentorship/compliance/views/' . $sid . '.php'; $html = (string) ob_get_clean(); }
    catch (Throwable $e) { ob_end_clean(); $bad[] = $sid . ': ' . $e->getMessage(); continue; }
    if (!str_contains($html, 'data-view="' . $sid . '"')) $bad[] = $sid . ': no view';
    if (preg_match('/\sstyle\s*=\s*"(?!width:)/', $html)) $bad[] = $sid . ': inline style';
}
ck('questdesk ui: every section renders, as its own view, without inline chrome — ' . implode('; ', $bad ?: ['all clean']), $bad === []);

/* ── the stylesheet ── */

$css = (string) file_get_contents(AV_ROOT . '/mentorship/quest-desk.css');
ck('questdesk ui: the stylesheet is tokens only — no hex colour, no !important', !preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) && !str_contains($css, '!important'));
ck('questdesk ui: narrow tables become cards by their own width (a container query), not the window\'s', str_contains($css, '@container (max-width'));
$js = (string) file_get_contents(AV_ROOT . '/mentorship/compliance/desk.js');
ck('questdesk ui: the script asks with a second press, never a dialog', !preg_match('/\b(alert|confirm|prompt)\s*\(/', $js));

$db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8500 AND 8599');
$db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8500 AND 8599');
$db->exec("DELETE FROM admin_users WHERE email = 'boss.du@afrovanguard.org.ng'");
AvRules::resetAll('test');
