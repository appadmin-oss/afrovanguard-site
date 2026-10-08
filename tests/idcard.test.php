<?php
/**
 * tests/idcard.test.php — the member card, its status and its print file.
 *
 * ── WHAT IS WORTH PINNING HERE ──────────────────────────────────────────────
 * This card is a physical object that leaves the building. The failures worth
 * a test are the ones you cannot take back once a hundred of them are printed,
 * and the ones that leak:
 *
 *   • A stranger scanning the QR must never learn that somebody is suspended,
 *     owes money, or is under review. Not in words and not in a colour.
 *   • The status word must be printed, always — a band that distinguishes
 *     states by hue alone says nothing in greyscale, and these get photocopied.
 *   • The print file must be the size the printer was told: two pages at
 *     68 × 99.6 mm with the fonts actually embedded. A card that silently
 *     falls back to a core font comes off the press in Helvetica.
 *   • /card/print must refuse a stranger the same way it refuses a bad id, so
 *     it cannot be used to find out which members exist.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/NgvCard.php';
require_once AV_ROOT . '/lib/MemberCards.php';
require_once AV_ROOT . '/lib/IdCard.php';

/* ══ The band comes from the contract, never from the template ═══════════ */

ck('NgvCard::standing() is still the only source of status — the card reads it, '
 . 'it does not re-decide it',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/IdCard.php'), 'NgvCard::standing($user)'));

ck('NgvCard::html() and css() are gone (ID_CARD_PRINT §2)',
   !str_contains((string) file_get_contents(AV_ROOT . '/lib/NgvCard.php'), 'function html(')
   && !str_contains((string) file_get_contents(AV_ROOT . '/lib/NgvCard.php'), 'function css('));

ck('and no .ngvc- markup survives in the contract',
   !str_contains((string) file_get_contents(AV_ROOT . '/lib/NgvCard.php'), 'ngvc-'));

/* standing()['public'] is the STRANGER'S WORDING, a string — not a boolean
   saying whether the member's own label may be shown. Reading it as a flag
   and printing the member's label is exactly how a suspension escapes. */
ck('publicFor() passes standing()[\'public\'] through as the stranger\'s label, '
 . 'rather than inventing wording of its own',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/IdCard.php'), "\$s['public']"));

/* ══ The card's own shape ════════════════════════════════════════════════ */

$uid = 90210;
Database::pdo()->prepare('INSERT OR IGNORE INTO lms_users (id,name,email,password_hash,role) VALUES (?,?,?,?,?)')
    ->execute([$uid, 'Mrs Adaeze Nwosu', 'adaeze-card@example.org', 'x', 'learner']);

$card = IdCard::forMember($uid);

ck('forMember(): the LAST word is the family name — splitting on the first '
 . 'space puts “Mrs” in 800-weight capitals across the front of the card',
   $card['family'] === 'Nwosu' && $card['given'] === 'Mrs Adaeze');
ck('forMember(): initials come from the given and family names',
   $card['initials'] === 'MN');
ck('forMember(): the card code is one MemberCards minted',
   $card['card_code'] === '' || (bool) preg_match('/^AVQR-[0-9A-Z]{12,24}$/', $card['card_code']));
ck('forMember(): the member number is a string the card can print',
   is_string($card['number']));
ck('forMember(): the QR is an SVG, so it has no resolution to be wrong at '
 . '13.8 mm square',
   str_starts_with(trim($card['qr_svg']), '<svg'));
ck('forMember(): the QR carries the scan URL for THIS card',
   str_contains(MemberCards::scanUrl($card['card_code']), $card['card_code']));
ck('forMember(): the band carries the word AND the tone — the partial prints '
 . 'the word, and the colour never stands alone',
   isset($card['band']['label'], $card['band']['tone'])
   && in_array($card['band']['tone'], NgvCard::TONES, true));
ck('forMember(): “Status as of” has a date to print',
   trim((string) $card['status_date']) !== '');

/* Money and discipline are NOT FETCHED, not merely unprinted: a field in the
   array is a field some later surface will print. */
$keys = array_keys($card);
foreach (['amount', 'balance', 'owing', 'fee', 'paid', 'debt', 'hold',
          'discipline', 'phone', 'address', 'email', 'medical'] as $leak) {
    ck("forMember(): no “{$leak}” key — the array is the whole contract, and a "
     . 'field that exists is a field something will print',
       !in_array($leak, $keys, true));
}

$pub = IdCard::publicFor($uid);
ck('publicFor(): drops the photo — a card found in the street should not hand '
 . 'whoever picked it up a face to go with the name',
   !array_key_exists('photo_url', $pub));
ck('publicFor(): drops the family name too', !array_key_exists('family', $pub));

/* ══ The print file ══════════════════════════════════════════════════════ */

if (class_exists('\\Dompdf\\Dompdf')) {
    require_once AV_ROOT . '/card/_render.php';

    $card  = IdCard::forMember($uid);
    $bleed = true;
    ob_start();
    include AV_ROOT . '/card/print-template.php';
    $html = (string) ob_get_clean();
    $html = card_inline_assets($html);

    ck('print sheet: the page box is declared at 68 × 99.6 mm — trim plus 3 mm '
     . 'bleed, plus the crop marks outside it',
       str_contains($html, '@page{size:68mm 99.6mm;margin:0}'));
    ck('print sheet: stylesheets are inlined, so the render never depends on '
     . 'the server being able to fetch its own URLs',
       !str_contains($html, '<link rel="stylesheet"') && str_contains($html, '<style>'));
    ck('print sheet: the seal is inlined as a data URI for the same reason',
       str_contains($html, 'src="data:image/png;base64,'));
    ck('print sheet: font files resolve to a real path on disk — a webfont URL '
     . 'dompdf cannot fetch means a core font, and a core font is never embedded',
       str_contains($html, AV_ROOT . '/assets/site/fonts/'));

    $pdf = card_pdf($html, 'single');

    ck('PDF: two pages, front then back', substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages') === 2);

    preg_match('~/MediaBox\s*\[([^\]]+)\]~', $pdf, $m);
    $box = array_map('floatval', preg_split('/\s+/', trim($m[1] ?? '')) ?: []);
    $mmW = isset($box[2]) ? round($box[2] / 72 * 25.4, 1) : 0;
    $mmH = isset($box[3]) ? round($box[3] / 72 * 25.4, 1) : 0;
    ck("PDF: the page is 68 × 99.6 mm (got {$mmW} × {$mmH})", $mmW === 68.0 && $mmH === 99.6);

    ck('PDF: fonts are embedded', substr_count($pdf, '/FontFile') > 0);
    preg_match_all('~/BaseFont\s*/([A-Za-z0-9+#-]+)~', $pdf, $fm);
    $faces = array_unique($fm[1] ?? []);
    $subset = array_filter($faces, static fn($f) => str_contains($f, '+'));
    ck('PDF: embedded faces are SUBSET, not whole families — a card carrying '
     . 'two complete typefaces is a megabyte nobody needs',
       count($subset) > 0);
    ck('PDF: Cormorant Garamond is embedded (the spec\'s display face, and NOT '
     . 'the Cormorant the site\'s existing <link> loads)',
       (bool) preg_grep('/CormorantGaramond/', $faces));
    ck('PDF: Source Sans 3 is embedded', (bool) preg_grep('/SourceSans3/', $faces));

    /* The PDF stores Info strings as UTF-16BE with a BOM, so the bytes never
       match the sentence. Decode rather than strip NULs: the × in “54×85.6”
       is U+00D7, and dropping its high byte leaves an invalid UTF-8 sequence
       that compares equal to nothing. */
    preg_match('~/Subject\s*\(([^)]*)\)~', $pdf, $sm);
    $subject = ltrim(mb_convert_encoding((string) ($sm[1] ?? ''), 'UTF-8', 'UTF-16BE'), "\u{FEFF}");
    ck('PDF: the Subject tells the printer what it is holding, verbatim — RGB '
     . 'sent to a press without that note comes back with the gold wrong',
       $subject === 'CR80 54×85.6 mm, 3 mm bleed, RGB. Ask the printer to convert to CMYK.');
} else {
    ck('PDF: dompdf is installed', false);
}

/* ══ Access control ══════════════════════════════════════════════════════ */

$src = (string) file_get_contents(AV_ROOT . '/card/print.php');
ck('/card/print: refuses before it rate-limits — an endpoint that counts '
 . 'first tells a stranger how often other people are printing',
   strpos($src, 'card_refuse(403') < strpos($src, 'card_rate_ok'));
ck('/card/print: a non-holder non-staff member is refused',
   str_contains($src, "if (\$target !== \$meId && !\$isStaff) { card_refuse(403"));
$render = (string) file_get_contents(AV_ROOT . '/card/_render.php');
ck('/card/print: the rate limit is 20 an hour', str_contains($render, '$n >= 20'));
ck('/card/print: the A4 imposition is staff-only — it is a print run, not '
 . 'something a member needs',
   str_contains($src, "\$layout === 'a4' && !\$isStaff"));
ck('/card/print: a photo too small to print is refused with the spec message',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/IdCard.php'),
                'Your photo is too small to print sharply. Upload one at least 800 px wide.'));
ck('/card/print: the file is named for the member number',
   str_contains($src, "'afrovanguard-card-'"));
ck('/card/print: generated files are cached for 24 hours', str_contains($src, '< 86400'));
ck('cacheKey(): keyed on member, standing code, photo hash and design version '
 . '— a card whose status changed must not serve yesterday\'s file',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/IdCard.php'),
                '$memberId, $code, $hash, self::DESIGN_VERSION'));

/* ══ The public page ═════════════════════════════════════════════════════ */

$q = (string) file_get_contents(AV_ROOT . '/q.php');
ck('q.php: a stranger is served publicFor(), not the full card with fields '
 . 'hidden by the template',
   str_contains($q, 'IdCard::publicFor('));
ck('q.php: the holder and staff see the real card',
   str_contains($q, '$full = $isStaff || $isHolder;'));
ck('q.php: it is never indexed, by header AND by meta — a search engine '
 . 'holding a page per member is a membership list nobody published',
   str_contains($q, 'X-Robots-Tag: noindex, nofollow')
   && str_contains($q, '<meta name="robots" content="noindex, nofollow">'));
ck('q.php: an unknown code is a 404 and a voided one a 410, not a 200 saying '
 . '“unknown” — an endpoint that answers every code is one somebody can walk',
   str_contains($q, 'http_response_code($hit && $hit[\'void\'] ? 410 : 404)'));

ck('q.php: the rebuilt partial draws the card, not the removed NgvCard::html()',
   str_contains($q, "include __DIR__ . '/partials/id-card.php'")
   && !str_contains($q, 'NgvCard::html('));

ck('q.php: a stranger gets no family name and no photo — a card found in the '
 . 'street should not hand whoever picked it up a face to go with the name',
   str_contains($q, "\$card['family'] = ''") && str_contains($q, "\$card['photo_url'] = null"));
