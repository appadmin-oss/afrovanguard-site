<?php
/**
 * tests/chioma.test.php — Chioma's tools, and the boundaries around them.
 *
 * Chioma answers to anyone with the URL. Most of what follows is not testing
 * that a feature works; it is testing that a capability she must not have is
 * genuinely absent, and stays absent when someone later adds a tool to AvTools
 * without thinking about the public widget.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ── The boundary: no member data, ever ──────────────────────────────────── */

$names = ChiomaTools::available();

// The staff console's read tier. If any of these ever appears in Chioma's
// registry, an anonymous visitor can ask about a named member's mentorship.
$forbidden = ['member_lookup', 'mentorship_status', 'level_check', 'inactive_pairings',
              'org_stats', 'rules_read', 'prompt_read', 'knowledge_search',
              'propose_knowledge', 'propose_rule', 'propose_prompt'];
$leaked = array_values(array_intersect($names, $forbidden));
ck('chioma: the public registry exposes no staff or member tool', $leaked === []);

// Not merely absent from the list — unrunnable. A model can name any string.
$refusedAll = true;
foreach ($forbidden as $f) {
    $r = ChiomaTools::run($f, ['query' => 'a', 'user_id' => 1]);
    if (!isset($r['error'])) { $refusedAll = false; break; }
}
ck('chioma: naming a staff tool directly is refused, not executed', $refusedAll);

ck('chioma: an unknown tool name is refused', isset(ChiomaTools::run('rm_rf', [])['error']));

/* ── page_read is a site reader, not a file reader ───────────────────────── */

foreach ([
    '../../etc/passwd',
    '/../config.php',
    '/..%2f..%2fconfig.php',
    "/about.html\0.png",
] as $evil) {
    ck('chioma: page_read refuses traversal (' . str_replace("\0", '\\0', $evil) . ')',
       isset(ChiomaTools::run('page_read', ['path' => $evil])['error']));
}
ck('chioma: page_read will not read a php file',
   isset(ChiomaTools::run('page_read', ['path' => '/config.php'])['error']));
ck('chioma: page_read will not read another host',
   isset(ChiomaTools::run('page_read', ['path' => 'https://example.org/secrets'])['error']));

$about = ChiomaTools::run('page_read', ['path' => '/about.html']);
ck('chioma: page_read reads a real site page', !isset($about['error']) && mb_strlen((string) ($about['text'] ?? '')) > 200);
ck('chioma: …and returns text, not markup', strpos((string) ($about['text'] ?? ''), '<div') === false);
ck('chioma: …bounded, so one page cannot fill the context', mb_strlen((string) ($about['text'] ?? '')) <= 6100);

/* ── Acting: staged, never sent ──────────────────────────────────────────── */

ChiomaTools::reset();
ck('chioma: a turn starts with nothing staged', ChiomaTools::staged() === [] && ChiomaTools::sources() === []);

$d = ChiomaTools::run('draft_contact_message', [
    'purpose' => 'volunteer', 'subject' => 'Helping out',
    'message' => 'I would like to volunteer at weekends.', 'name' => 'Ada',
]);
ck('chioma: drafting a contact message stages a form', isset($d['staged']['kind']) && $d['staged']['kind'] === 'contact');
ck('chioma: …the staged form is returned to the caller', count(ChiomaTools::staged()) === 1);
ck('chioma: …and carries what she was told', ($d['staged']['fields']['name'] ?? '') === 'Ada'
   && ($d['staged']['fields']['purpose'] ?? '') === 'volunteer');
ck('chioma: …with no field she was not told', ($d['staged']['fields']['email'] ?? 'x') === '');
ck('chioma: an empty message is refused rather than staged',
   isset(ChiomaTools::run('draft_contact_message', ['purpose' => 'general', 'message' => '  '])['error']));

// process-contact.php maps unknown purposes to "General Enquiry" silently, so a
// bad key would reach the team mislabelled rather than rejected.
$bad = ChiomaTools::run('draft_contact_message', ['purpose' => 'nonsense', 'message' => 'hello there']);
ck('chioma: an unknown purpose falls back to general, never passes through',
   ($bad['staged']['fields']['purpose'] ?? '') === 'general');

ck('chioma: enrolment needs a course that exists',
   isset(ChiomaTools::run('draft_enrolment', ['course' => 'no-such-course-xyz'])['error']));
ck('chioma: enrolment with no course at all is refused',
   isset(ChiomaTools::run('draft_enrolment', [])['error']));

ChiomaTools::reset();
ck('chioma: reset clears what the previous turn staged', ChiomaTools::staged() === []);

/* ── Rendering her replies ───────────────────────────────────────────────── */

ck('chioma md: a script tag never survives',
   stripos(ChiomaMarkdown::render('<script>alert(1)</script>'), '<script') === false);
ck('chioma md: an event-handler image never survives',
   stripos(ChiomaMarkdown::render('<img src=x onerror=alert(1)>'), 'onerror') === false);
ck('chioma md: a javascript: link never survives',
   stripos(ChiomaMarkdown::render('[go](javascript:alert(1))'), 'javascript:') === false);
ck('chioma md: a data: link never survives',
   stripos(ChiomaMarkdown::render('[go](data:text/html;base64,PHM+)'), 'data:text/html') === false);

$md = ChiomaMarkdown::render("Try **this**:\n\n- [Academy](/academy/)\n- see https://example.org/x");
ck('chioma md: real markdown is rendered', strpos($md, '<strong>') !== false && strpos($md, '<li>') !== false);
ck('chioma md: an internal link stays internal', strpos($md, 'href="/academy/"') !== false);
ck('chioma md: an external link gets noopener', strpos($md, 'rel="noopener noreferrer"') !== false);
ck('chioma md: …and opens in a new tab', strpos($md, 'target="_blank"') !== false);
ck('chioma md: an internal link is NOT given target=_blank',
   !preg_match('~href="/academy/"[^>]*target~', $md));
ck('chioma md: multibyte text survives the DOM pass',
   strpos(ChiomaMarkdown::render('Sí — émoji 🌍 naïve'), '🌍') !== false);
ck('chioma md: empty in, empty out', ChiomaMarkdown::render('   ') === '');

/* ── The shared site index ───────────────────────────────────────────────── */

ck('sitesearch: a one-character query returns nothing rather than everything',
   SiteSearch::query('a') === []);
$pages = SiteSearch::query('donate');
ck('sitesearch: a known page is found', count($pages) > 0);
ck('sitesearch: the best hit for "donate" is the donate page',
   ($pages[0]['url'] ?? '') === '/donate.html');
ck('sitesearch: a type filter excludes other types',
   array_filter(SiteSearch::query('academy', 12, 'Page'), static fn($r) => $r['type'] !== 'Page') === []);
ck('sitesearch: clip does not exceed its bound', mb_strlen(SiteSearch::clip(str_repeat('word ', 200), 40)) <= 40);
ck('sitesearch: a limit is honoured', count(SiteSearch::query('a b c the and', 3)) <= 3);

/* ── The tool the loop is actually given ─────────────────────────────────── */

ck('chioma: site_search is offered', in_array('site_search', $names, true));
ck('chioma: course_list is offered', in_array('course_list', $names, true));
ck('chioma: the action tools are offered', in_array('draft_contact_message', $names, true)
   && in_array('draft_enrolment', $names, true));
ck('chioma: every offered tool has a spec the provider can read',
   count(ChiomaTools::specs($names)) === count($names));
$spec = ChiomaTools::specs(['site_search'])[0] ?? [];
ck('chioma: a spec carries name, description and schema',
   isset($spec['name'], $spec['description'], $spec['input_schema']['type']));
ck('chioma: the action tools are marked as actions',
   ChiomaTools::isAction('draft_contact_message') && !ChiomaTools::isAction('site_search'));

// Without a search key the web tools cannot run, and a model told it has a
// capability it lacks will keep retrying it.
if (!AvWeb::available('web_search')) {
    ck('chioma: an unusable web tool is not offered at all', !in_array('web_search', $names, true));
}

/* ══ A tool call the model WROTE instead of making (reported: the visitor saw
   "draft_contact_message(message=...)" and no form) ═════════════════════════ */
ChiomaTools::reset();
$chR = Chioma::rescueToolText("That is so lovely to hear!\n\nI've left your name open on the form below.\n\ndraft_contact_message(message=\"I love your works!\")");
ck('chioma: a written-out contact call is removed from the reply', !str_contains($chR['text'], 'draft_contact_message') && str_contains($chR['text'], 'lovely to hear'));
ck('chioma: …and becomes the real staged form', ($chR['rescued'] ?? []) === ['draft_contact_message'] && (ChiomaTools::staged()[0]['fields']['message'] ?? '') === 'I love your works!');
ChiomaTools::reset();
$chR = Chioma::rescueToolText('Sure — `draft_contact_message({"purpose":"volunteer","message":"I want to help"})`');
ck('chioma: the JSON form of a written call is rescued too', (ChiomaTools::staged()[0]['fields']['purpose'] ?? '') === 'volunteer' && !str_contains($chR['text'], '`'));
ChiomaTools::reset();
$chR = Chioma::rescueToolText('Let me look. site_search(query="techome")');
ck('chioma: a written-out read call is removed but not run', $chR['text'] === 'Let me look.' && ChiomaTools::staged() === [] && $chR['rescued'] === []);
ck('chioma: ordinary replies are untouched', Chioma::rescueToolText('Head to the Academy (/academy/).')['text'] === 'Head to the Academy (/academy/).');
ck('chioma: without the tool loop she is not told about tools', !str_contains(Chioma::systemPrompt([], false), 'draft_contact_message') && str_contains(Chioma::systemPrompt([], true), 'draft_contact_message'));

/* ── Who she is talking to (session context) ─────────────────────────────── */

ChiomaTools::setVisitor(null);
ck('chioma: a stranger is not offered my_overview', !in_array('my_overview', ChiomaTools::available(false), true));
ck('chioma: events_upcoming is offered to everyone', in_array('events_upcoming', ChiomaTools::available(false), true));
$mo = ChiomaTools::run('my_overview', []);
ck('chioma: my_overview with nobody signed in reads nothing', isset($mo['error']) && !isset($mo['courses']));
$anon = Chioma::systemPrompt(['path' => '/'], true);
ck('chioma: a stranger is told to sign in for their own record', str_contains($anon, 'not signed in') && str_contains($anon, '/login/'));

ChiomaTools::setVisitor(['id' => 4242, 'name' => 'Adaeze Nwosu', 'email' => 'ada@example.com']);
ck('chioma: a signed-in visitor is offered my_overview', in_array('my_overview', ChiomaTools::available(false), true));
$mine = ChiomaTools::run('my_overview', []);
ck('chioma: my_overview reads the visitor\'s own record', ($mine['name'] ?? '') === 'Adaeze Nwosu' && isset($mine['courses']));
ChiomaTools::reset();
$d = ChiomaTools::run('draft_contact_message', ['purpose' => 'general', 'message' => 'I love your works']);
ck('chioma: a signed-in visitor\'s draft carries their name and email',
   ($d['staged']['fields']['name'] ?? '') === 'Adaeze Nwosu' && ($d['staged']['fields']['email'] ?? '') === 'ada@example.com');
$d2 = ChiomaTools::run('draft_contact_message', ['purpose' => 'general', 'message' => 'x', 'name' => 'Someone Else']);
ck('chioma: a name the visitor gave wins over the account name', ($d2['staged']['fields']['name'] ?? '') === 'Someone Else');
ChiomaTools::reset();

$signed = Chioma::systemPrompt(['path' => '/portal/', 'me' => ['id' => 4242, 'name' => 'Adaeze Nwosu', 'email' => 'ada@example.com', 'kind' => 'a learner']], true);
ck('chioma: she knows who is signed in', str_contains($signed, 'SIGNED IN as Adaeze Nwosu (ada@example.com)'));
ck('chioma: she uses their first name', str_contains($signed, 'Call them Adaeze.'));
ck('chioma: she is told never to ask for a name or email she has', str_contains($signed, 'Never ask for their name or email'));
ck('chioma: she is told the date in Lagos', str_contains($signed, 'in Lagos.'));
ck('chioma: she never discusses another person\'s record', str_contains($signed, 'Never look up, guess at or comment on anybody else'));
$noTools = Chioma::systemPrompt(['me' => ['id' => 1, 'name' => 'A B', 'email' => 'a@b.co']], false);
ck('chioma: without tools she is not told to call my_overview', !str_contains($noTools, 'my_overview'));
ChiomaTools::setVisitor(null);

$ep = (string) file_get_contents(__DIR__ . '/../chioma.php');
ck('chioma endpoint: the visitor comes from the session, not the request', str_contains($ep, 'LmsAuth::user()') && !preg_match('/\$body\[.me.\]/', $ep));
$cs = (string) file_get_contents(__DIR__ . '/../lib/Chioma.php');
ck('chioma: an outside agent webhook never gets the account email', str_contains($cs, "'first_name' =>") && str_contains($cs, 'never their account id or email address'));
$ev = ChiomaTools::run('events_upcoming', []);
ck('chioma: events_upcoming always answers, even with nothing on', is_array($ev) && array_key_exists('events', $ev) && isset($ev['note']));
