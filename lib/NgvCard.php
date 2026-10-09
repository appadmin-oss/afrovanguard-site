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

    /**
     * The QR for a URL, as inline SVG. Level Q: a printed card gets scuffed in a wallet.
     *
     * $quiet is the margin in modules. 4 everywhere the code stands alone. The
     * member card passes 0 because its design draws the code edge to edge in
     * a white box whose padding, border and the light card around it are the
     * quiet zone — a second one inside the box shrank every module by a fifth.
     */
    public static function qrSvg(string $url, int $quiet = 4): string
    {
        $o = new QROptions([
            'outputInterface' => QRMarkupSVG::class, 'eccLevel' => EccLevel::Q, 'addQuietzone' => $quiet > 0, 'quietzoneSize' => max(0, $quiet),
            'outputBase64' => false, 'svgAddXmlHeader' => false, 'drawLightModules' => false, 'connectPaths' => true,
        ]);
        return (new QRCode($o))->render($url);
    }
}
