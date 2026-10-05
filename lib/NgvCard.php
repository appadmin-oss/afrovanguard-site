<?php
/**
 * lib/NgvCard.php — the NGV ID card, and the one answer to "what is this
 * member's standing?".
 *
 * ONE STANDING. The answer used to be spread over five places, each right
 * about its own corner: the account's status (lms_users), the programme's
 * (ngv_participants), the gate's refusal (GateAttendance::whyNot), probation,
 * and what is owed (NgvLedger, Membership). A card that printed one of them
 * would disagree with a desk reading another. standing() reads them all, in
 * order of severity, and everything that shows a status — the card, the page
 * a phone opens when it scans the card, the portal — shows this one.
 *
 * ONE CARD. html() is the card wherever it appears: the member's portal, and
 * the page behind its QR. A second drawing of it would drift within a season.
 *
 * The QR is MemberCards::scanUrl(): SITE_URL/q/AVQR-…. A phone camera opens
 * q.php (the holder's page); the CACENTRE gate reads the AVQR- code out of the
 * same URL and checks them in. That is the two-way scan NGG's cards have.
 *
 * A PRINTED status goes stale; the QR does not. So a printed card says the
 * date its status was true, and that scanning gives the live one.
 */
declare(strict_types=1);

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;

final class NgvCard
{
    /** Tones a standing is drawn in. The word is always shown; the colour never stands alone. */
    public const TONES = ['ok', 'warn', 'bad', 'neutral'];

    /**
     * Where this member stands, most severe reason first.
     *
     *   code     a fixed word (active, suspended, withdrawn, blocked, …)
     *   label    what the card prints
     *   tone     ok | warn | bad | neutral
     *   detail   one sentence for the member and for staff (may name money)
     *   public   what a stranger who scans the card is told — never money or
     *            discipline, only whether this is a current member
     *   gate     whether the CACENTRE gate lets them in (GateAttendance::whyNot)
     */
    public static function standing(array $u): array
    {
        $id = (int) $u['id'];
        $p = self::participant($id);
        $pstat = $p ? (string) $p['status'] : '';
        $why = class_exists('GateAttendance') ? GateAttendance::whyNot($u) : null;
        $s = static fn(string $code, string $label, string $tone, string $detail, string $public) =>
            ['code' => $code, 'label' => $label, 'tone' => $tone, 'detail' => $detail, 'public' => $public, 'gate' => $why === null, 'gateWhy' => (string) $why];

        if ((string) ($u['status'] ?? 'active') !== 'active') {
            return $s('suspended', 'Suspended', 'bad', 'This account is suspended. Ask at the NGV office.', 'Not active');
        }
        if ($pstat === 'withdrawn') return $s('withdrawn', 'Withdrawn', 'bad', 'Withdrawn from the NextGen Vanguard programme.', 'Not active');
        if (!$p && class_exists('GatePass') && !in_array(strtolower((string) ($u['role'] ?? 'learner')), GatePass::roles(), true)) {
            return $s('not_member', 'Not a member', 'neutral', 'This account is not an Afrovanguard member.', 'Not a member');
        }
        if ($pstat === 'completed') return $s('completed', 'Programme completed', 'neutral', 'Completed the NextGen Vanguard programme.', 'Alumnus · programme completed');
        if ($pstat === 'applicant') return $s('applicant', 'Applicant', 'neutral', 'Applied to the programme; not yet enrolled.', 'Applicant');
        if ($pstat === 'paused') return $s('paused', 'Paused', 'warn', 'Their place on the programme is paused.', 'Member · paused');

        // Active from here. What follows is between the member and the office.
        if ($why !== null) return $s('blocked', 'Gate blocked', 'bad', $why, 'Active member');
        if ($p && class_exists('GateAttendance') && ($prob = GateAttendance::probationWhy($id)) !== null) {
            return $s('probation', 'On probation', 'warn', $prob . '.', 'Active member');
        }
        $owed = self::owed($id, $p !== null);
        if ($owed > 0) return $s('owing', 'Dues owing', 'warn', '₦' . number_format($owed) . ' is due now. Pay it in the portal under Dues.', 'Active member');
        if (!$p && class_exists('Membership')) {
            try {
                $m = Membership::summary($id);
                if ((string) $m['state'] === 'lapsed') return $s('lapsed', 'Membership lapsed', 'warn', 'Membership ran out' . ($m['paid_through'] ? ' on ' . substr((string) $m['paid_through'], 0, 10) : '') . '. Renew it in the portal.', 'Membership lapsed');
            } catch (Throwable $e) { /* no memberships table: nothing to say */ }
        }
        return $s('active', 'Active', 'ok', 'In good standing.', 'Active member');
    }

    /** What is payable now on the NGV ledger. 0 where the ledger is off or unreadable. */
    private static function owed(int $id, bool $isParticipant): int
    {
        if (!$isParticipant || !class_exists('NgvLedger')) return 0;
        try { return NgvLedger::enabled() ? max(0, (int) (NgvLedger::balance($id)['payable'] ?? 0)) : 0; }
        catch (Throwable $e) { error_log('[ngvcard] owed: ' . $e->getMessage()); return 0; }
    }

    public static function participant(int $memberId): ?array
    {
        if (!class_exists('NgvDb')) return null;
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
            $st->execute([$memberId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }

    public static function user(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email, role, status FROM lms_users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The QR for a URL, as inline SVG. Level Q: a printed card gets scuffed in a wallet. */
    public static function qrSvg(string $url): string
    {
        $o = new QROptions([
            'outputInterface' => QRMarkupSVG::class, 'eccLevel' => EccLevel::Q, 'addQuietzone' => true, 'quietzoneSize' => 4,
            'outputBase64' => false, 'svgAddXmlHeader' => false, 'drawLightModules' => false, 'connectPaths' => true,
        ]);
        return (new QRCode($o))->render($url);
    }

    /**
     * The card. $opts:
     *   standing  a standing() result (read here when absent)
     *   public    true on the page a stranger's phone opens: first name only,
     *             and the public standing rather than the member's own
     *   asOf      'Y-m-d' the status was read (shown, because print goes stale)
     */
    public static function html(array $u, array $opts = []): string
    {
        $id = (int) $u['id'];
        $st = $opts['standing'] ?? self::standing($u);
        $public = !empty($opts['public']);
        $p = self::participant($id);
        $ngv = class_exists('GateAttendance') ? GateAttendance::cardFor($id) : null;
        $code = class_exists('MemberCards') ? MemberCards::secure($id) : null;
        $name = trim((string) $u['name']) ?: 'Member';
        if ($public) $name = explode(' ', $name)[0];
        $track = $p ? trim((string) $p['track']) : '';
        $asOf = (string) ($opts['asOf'] ?? (function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')));
        $label = $public ? (string) $st['public'] : (string) $st['label'];
        $tone = in_array($st['tone'], self::TONES, true) ? (string) $st['tone'] : 'neutral';
        if ($public && $st['public'] === 'Active member') $tone = 'ok';
        /* From the name SHOWN: on the public card a surname's initial is still the surname. */
        $initials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $name) ?: [], 0, 2))));
        $e = static fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $qr = $code ? '<div class="ngvc-qr" role="img" aria-label="QR code: scan to open this card\'s page">' . self::qrSvg(MemberCards::scanUrl($code)) . '</div>'
                    : '<div class="ngvc-qr is-none"><span>No gate card yet — ask the NGV office.</span></div>';
        return self::css()
            . '<article class="ngvc" aria-label="NextGen Vanguard ID card">'
            . '<header class="ngvc-top"><span class="ngvc-org">AFROVANGUARD</span><span class="ngvc-prog">NextGen Vanguard</span></header>'
            . '<div class="ngvc-body">'
            .   '<div class="ngvc-avatar" aria-hidden="true">' . $e($initials ?: 'AV') . '</div>'
            .   '<h3 class="ngvc-name">' . $e($name) . '</h3>'
            .   ($track !== '' ? '<p class="ngvc-track">' . $e($track) . '</p>' : '')
            .   ($ngv ? '<p class="ngvc-id"><span>NGV ID</span> ' . $e($ngv) . '</p>' : '')
            .   $qr
            . '</div>'
            . '<footer class="ngvc-status is-' . $e($tone) . '"><b>' . $e($label) . '</b>'
            .   '<span>Status as of ' . $e(date('j M Y', (int) strtotime($asOf . 'T12:00:00'))) . ($code ? ' · scan for the live one' : '') . '</span></footer>'
            . '</article>';
    }

    /** The card's styles, once per page. Inline: the card appears on pages with different stylesheets. */
    private static function css(): string
    {
        static $done = false;
        if ($done) return '';
        $done = true;
        /* ID-1 portrait, 54 × 85.6mm. Status bands are white on a dark fill,
           each at least 6:1, so the word reads in print as well as on glass. */
        return '<style>
.ngvc{--ngvc-ink:#15120e;width:min(100%,324px);aspect-ratio:54/85.6;display:flex;flex-direction:column;background:#fff;color:var(--ngvc-ink);border-radius:14px;overflow:hidden;box-shadow:0 14px 40px -24px rgba(0,0,0,.55);border:1px solid #e3e5ea;font-family:Montserrat,system-ui,sans-serif;margin:0 auto}
.ngvc-top{background:linear-gradient(100deg,#b8101f,#c2410c 55%,#a15c00);color:#fff;padding:12px 16px;display:flex;flex-direction:column;gap:2px}
.ngvc-org{font-weight:800;letter-spacing:.18em;font-size:12px}
.ngvc-prog{font-size:12px;font-weight:600}
.ngvc-body{flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;padding:14px 16px 12px;gap:4px}
.ngvc-avatar{width:56px;height:56px;border-radius:50%;background:#15120e;color:#fff;display:grid;place-items:center;font-weight:800;font-size:20px}
.ngvc-name{margin:6px 0 0;font-size:19px;line-height:1.2;font-weight:800}
.ngvc-track{margin:0;font-size:13px;color:#4a4f5a}
.ngvc-id{margin:2px 0 0;font:700 14px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em}
.ngvc-id span{font:600 11px/1 Montserrat,system-ui,sans-serif;color:#4a4f5a;letter-spacing:.08em;text-transform:uppercase}
.ngvc-qr{width:62%;max-width:200px;margin:auto 0 0}
.ngvc-qr svg{width:100%;height:auto;display:block}
.ngvc-qr svg path,.ngvc-qr svg rect{fill:#000}
.ngvc-qr.is-none{display:grid;place-items:center;aspect-ratio:1;border:1.5px dashed #b5bac4;border-radius:8px;font-size:12px;color:#4a4f5a;padding:8px}
.ngvc-status{padding:9px 14px 11px;color:#fff;text-align:center;display:flex;flex-direction:column;gap:1px}
.ngvc-status b{font-size:15px;letter-spacing:.04em;text-transform:uppercase}
.ngvc-status span{font-size:11px}
.ngvc-status.is-ok{background:#0f6b34}.ngvc-status.is-warn{background:#8a4b00}.ngvc-status.is-bad{background:#a3121f}.ngvc-status.is-neutral{background:#3b4252}
@media print{.ngvc{box-shadow:none;width:54mm;border-radius:3mm;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>';
    }
}
