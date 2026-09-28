<?php
/**
 * tests/afg.test.php — Africa GATES lives on its own site now.
 *
 * The page here was a demo. What matters is that every way somebody can
 * still arrive at the old address ends up at the new one, and that nothing
 * on this site sends them to the old address in the first place.
 */
declare(strict_types=1);

$AFG = 'https://afg.afrovanguard.org.ng';

/* ── The old address redirects, by both routes ────────────────────────── */
ck('the demo page is gone — a page that must redirect cannot also be served',
   !is_file(AV_ROOT . '/projects/africa-gates/index.html'));
$stub = (string) @file_get_contents(AV_ROOT . '/projects/africa-gates/index.php');
ck('what is left there is a redirect',
   str_contains($stub, $AFG) && str_contains($stub, '301'));
ck('and it carries the path and the query, so a link somebody already shared '
 . 'lands on its own page over there',
   str_contains($stub, "preg_replace('#^/?projects/africa-gates/?#'")
   && str_contains($stub, "\$qs !== '' ? '?' . \$qs : ''"));

$ht = (string) @file_get_contents(AV_ROOT . '/.htaccess');
ck('.htaccess redirects the whole path',
   str_contains($ht, 'RewriteRule ^projects/africa-gates/?(.*)$ ' . $AFG . '/$1 [R=301,L,QSA,NE]'));
/* projects/africa-gates/ is a real directory. The guard at the top of the
   clean-URL block stops rewriting for anything real, so a rule placed after
   it would redirect the deeper paths and keep serving the page itself. */
ck('and it does so ABOVE the real-file guard, or it would never fire for the '
 . 'page it is there to move',
   strpos($ht, 'RewriteRule ^projects/africa-gates') < strpos($ht, 'RewriteCond %{REQUEST_FILENAME} -f'));
ck('the dev router agrees, so the behaviour is not different on a laptop',
   str_contains((string) @file_get_contents(AV_ROOT . '/router.php'), '^/projects/africa-gates/?(.*)$'));

/* ── Nothing here still links to the old address ──────────────────────── */
$bad = [];
foreach (['*.html', '*.php'] as $glob) {
    foreach (['', 'projects/', 'projects/sts/', 'lib/', 'db/', 'tools/'] as $dir) {
        foreach (glob(AV_ROOT . '/' . $dir . $glob) ?: [] as $file) {
            if (str_ends_with($file, 'projects/africa-gates/index.php')) continue;
            $t = (string) @file_get_contents($file);
            if (preg_match('~href="[^"]*/projects/africa-gates/?"~', $t)
                || str_contains($t, 'cacentre.afrovanguard.org.ng/africa-gates')) {
                $bad[] = substr($file, strlen(AV_ROOT) + 1);
            }
        }
    }
}
ck('no page still links to the old Africa GATES address: ' . implode(', ', $bad), $bad === []);

/* ── The programme links that were quietly 404ing ─────────────────────────
   CACENTRE serves its programmes at /programs/<slug>. The footer pointed
   four of five at the bare slug, and spelt Techome with an h it does not
   have, so every one of those links was a 404 on a page nobody checks. */
$partials = (string) @file_get_contents(AV_ROOT . '/lib/partials.php');
foreach (['street-to-stardom', 'techome', 'mediapro'] as $slug) {
    ck('footer: ' . $slug . ' links to /programs/' . $slug . ', which is what CACENTRE serves',
       str_contains($partials, '/programs/' . $slug));
}
ck('footer: no programme link points at a bare CACENTRE slug any more',
   !preg_match('~cacentre\.afrovanguard\.org\.ng/(street-to-stardom|tech[ho]+me|mediapro|africa-gates)~', $partials));
ck('the other sites are named once rather than typed into every link',
   str_contains($partials, "const AV_CACENTRE_URL") && str_contains($partials, "const AV_AFG_URL")
   && str_contains($partials, 'AV_VOLUNTEER_URL = AV_CACENTRE_URL')
   && str_contains($partials, 'AV_EVENTS_URL = AV_AFG_URL'));

/* ── The generated pages carry it ─────────────────────────────────────── */
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html'] as $page) {
    $t = (string) @file_get_contents(AV_ROOT . '/' . $page);
    ck($page . ': the footer sends Africa GATES to its own site', str_contains($t, $AFG));
}
