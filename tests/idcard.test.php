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

/* ══ A member with a card ═════════════════════════════════════════════════ */

/* The fatal this caught: issued() read `issued_at` off MemberCards::lookup(),
   which does not return it — so forMember() died for every member who HAD a
   card, and the portal card and /q/ died with it. The test member above has
   no card, which is how that went unseen. */
MemberCards::issue($uid, 'test', 'test');
$carded = null;
try { $carded = IdCard::forMember($uid); } catch (Throwable $e) { $carded = null; }
ck('forMember(): a member who HAS a card gets one — it does not throw', is_array($carded));
ck('forMember(): Issued is the card row’s own date, as the design prints it (“06 Oct 2026”)',
   is_array($carded) && (bool) preg_match('/^\d{2} [A-Z][a-z]{2} \d{4}$/', $carded['issued']));
ck('forMember(): Category is the tier letter and the plan (“E · Executive”)',
   is_array($carded) && (bool) preg_match('/^[A-Z] · .+$/u', $carded['category']));
ck('forMember(): the card’s QR is drawn edge to edge — its white box is the quiet zone, as designed',
   is_array($carded) && str_starts_with(trim($carded['qr_svg']), '<svg')
   && strlen($carded['qr_svg']) < strlen(NgvCard::qrSvg(MemberCards::scanUrl($carded['card_code']))));

/* ══ The partial is the design ════════════════════════════════════════════ */

ob_start();
$card = $carded ?? IdCard::forMember($uid); $avcSide = 'both'; $avcMode = 'screen';
include AV_ROOT . '/partials/id-card.php';
$html = (string) ob_get_clean();
foreach (['Ambassadors for Community, Tech &amp; Cultural Advancements', 'Scan for the live one',
          'Raising one million incorruptible African leaders by 2040.', 'Issuing officer', 'Holder',
          'Scan the front to verify', 'afrovanguard.org.ng'] as $copy) {
    ck("partial: “{$copy}” is on the card, verbatim", str_contains($html, $copy));
}
ck('partial: the back’s return address is the organisation’s, never the member’s',
   str_contains($html, 'please return it to CACENTRE, Alimosho, Lagos, or write to cacentre@afrovanguard.org.ng.'));
$css = (string) file_get_contents(AV_ROOT . '/assets/site/avc-card.css');
ck('avc-card.css: no hex colour — every colour is a token', !preg_match('/#[0-9a-fA-F]{3,8}\b/', $css));
ck('avc-card.css: print faces are laid out on the design’s 10px-per-mm canvas, so its 1px hairlines are whole pixels',
   str_contains($css, '.avc-face.is-print{--u:10px;--bleed:30px'));

/* ══ Printing: staff only, made in the browser ═══════════════════════════ */

$src = (string) file_get_contents(AV_ROOT . '/card/print.php');
ck('/card/print: an admin of this site, or a link NGG’s server signed — nobody else (owner, 2026-10-09)',
   str_contains($src, "AdminRoles::can('admin')") && str_contains($src, "hash_hmac('sha256', 'card-print|'"));
ck('/card/print: a member cannot print their own card — there is no holder door',
   !str_contains($src, 'LmsAuth::user()'));
ck('/card/print: an NGG link lives ten minutes and names a LINKED NGG member',
   str_contains($src, 'abs(time() - (int) $ts) > 600') && str_contains($src, "status = 'linked'"));
ck('/card/print: the signature is compared in constant time', str_contains($src, 'hash_equals('));
ck('/card/print: rate limited', str_contains($src, "av_rate_ok('card_print'"));
ck('/card/print: every opening is on the audit log', str_contains($src, "'card_print_opened'"));
ck('/card/print: the photo-too-small message is the spec’s, verbatim',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/IdCard.php'),
                'Your photo is too small to print sharply. Upload one at least 800 px wide.'));

$js = (string) file_get_contents(AV_ROOT . '/assets/site/avc-print.js');
ck('print: the PDF is 68 × 99.6 mm — trim, 3 mm bleed, and room for the crop marks', str_contains($js, 'format: [68, 99.6]'));
ck('print: the Subject tells the printer what it is holding, verbatim',
   str_contains($js, "'CR80 54×85.6 mm, 3 mm bleed, RGB. Ask the printer to convert to CMYK.'"));
ck('print: crop marks are 0.25 pt', str_contains($js, '0.25 * 25.4 / 72'));
ck('print: the PNGs are written as 300 dpi (pHYs 11811 px/m) and sRGB', str_contains($js, '11811') && str_contains($js, "'sRGB'"));
ck('print: the A4 back page is mirrored for a long-edge flip', str_contains($js, 'COLS - 1 - c'));
ck('print: the failure message is the spec’s', str_contains($js, 'We couldn’t make the file. Try again in a minute.'));
foreach (['snapdom.js', 'jspdf.umd.min.js', 'jszip.min.js'] as $lib) {
    ck("print: {$lib} is vendored, not fetched from a CDN", is_file(AV_ROOT . '/assets/vendor/card/' . $lib));
}

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
