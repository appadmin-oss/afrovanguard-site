<?php
/**
 * tests/googlehub.test.php — Google Workspace is part of the portal, not a
 * page beside it (lib/GoogleHub.php).
 *
 * The portal had a "Workspace" section and /workspace was a second home with
 * its own inbox, calendar, Drive and Chat. Now: the inbox, what is coming up,
 * recent files and the quick actions are on Today; the Google apps are in the
 * Suite; Google Chat is in Team Chat; the connected account is in the side
 * bar; and /workspace, and #workspace, send people to the portal.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ── No Workspace section ────────────────────────────────────────────── */
ck('nav: there is no Workspace section', !isset(PortalNav::SECTIONS['workspace']));
ck('nav: its view and script are gone', !is_file(AV_ROOT . '/portal/views/workspace.php') && !is_file(AV_ROOT . '/portal/workspace-live.js'));
ck('nav: an old #workspace link opens Today', str_contains((string) file_get_contents(AV_ROOT . '/portal/avp.js'), "workspace: 'overview'"));

/* ── /workspace sends people to the portal, with what Google said ────── */
$ws = (string) file_get_contents(AV_ROOT . '/workspace.php');
ck('/workspace: redirects to the portal and draws nothing of its own', str_contains($ws, "header('Location: /portal/'") && !str_contains($ws, 'render_head('));
$cb = (string) file_get_contents(AV_ROOT . '/auth/google.php');
ck('connect: comes back to the portal, saying how it went', str_contains($cb, "'google=connected'") && str_contains($cb, "'google_e=connect_failed'") && str_contains($cb, "'/portal/?google_e=connect_cancelled'"));
ck('connect: an old ?next=/workspace still lands in the portal', str_contains($cb, "if (\$nx === '/workspace' || str_starts_with(\$nx, '/workspace?')) \$nx = '/portal/';"));

/* ── What the portal is told ─────────────────────────────────────────── */
$admin = ['id' => 0, 'email' => 'a@afrovanguard.org.ng', 'role' => 'superadmin', 'name' => 'A'];
$s = GoogleHub::state($admin);
ck('state: says whether members can connect, whether this one has, and the connect link', array_key_exists('oauth', $s) && array_key_exists('connected', $s) && str_starts_with($s['connect'], '/auth/google/connect?next='));
ck('state: the connect link comes back to the portal', rawurldecode(substr($s['connect'], strlen('/auth/google/connect?next='))) === '/portal/');
ck('state: the apps for the Suite, without the in-portal chat', !array_filter($s['apps'], fn($a) => !empty($a['internal'])));
ck('state: an administrator is told what is wrong when Google is not set up', GoogleHub::problem(false, false) !== '' && str_contains(GoogleHub::problem(false, false), 'Rules & AI'));
ck('state: a member is not', GoogleHub::state(['id' => 0, 'email' => 'm@x', 'role' => 'member', 'name' => 'M'])['problem'] === '');
ck('notice: the round-trip’s codes, in words', (GoogleHub::notice(['google' => 'connected'])['tone'] ?? '') === 'ok' && (GoogleHub::notice(['google_e' => 'connect_failed'])['tone'] ?? '') === 'err' && GoogleHub::notice([]) === null);

/* ── Where each part lives now ───────────────────────────────────────── */
$today = (string) file_get_contents(AV_ROOT . '/portal/views/today.php');
ck('Today: the Google card — inbox, coming up, recent files, quick actions', str_contains($today, 'id="avpGoogleCard"') && str_contains($today, 'id="gMail"') && str_contains($today, 'id="gEvents"') && str_contains($today, 'id="gFiles"') && str_contains($today, 'Start a Meet'));
ck('Today: a member not connected is asked to connect', str_contains($today, "\$google['connect']"));
ck('Suite: the Google apps', str_contains((string) file_get_contents(AV_ROOT . '/portal/views/tools.php'), 'id="suiteGoogle"'));
$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('side bar: the connected account, and a way to disconnect', str_contains($idx, 'id="avpGoogle"') && str_contains($idx, '/auth/google/disconnect'));
ck('portal: loads google.js, which exists, and not the old Workspace script', str_contains($idx, "av_asset('/portal/google.js')") && is_file(AV_ROOT . '/portal/google.js') && !str_contains($idx, 'workspace-live.js'));
$js = (string) file_get_contents(AV_ROOT . '/portal/google.js');
ck('google.js: fills the card from the member’s own Google, and says the notice once', str_contains($js, "/portal/workspace.php?action=me") && str_contains($js, "searchParams.delete('google_e')"));
ck('Team Chat: reconnecting Google comes back to Team Chat, not /workspace', !str_contains((string) file_get_contents(AV_ROOT . '/portal/team-chat.js'), 'next=/workspace'));
