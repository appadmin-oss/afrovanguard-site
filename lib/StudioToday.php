<?php
/**
 * lib/StudioToday.php — what is waiting on somebody, for the Studio's Overview.
 *
 * The Overview counted content (entries, courses, members). That answers "how
 * big are we", not "what do I need to do". This is the other question: every
 * queue in the Studio that is waiting on a person, in one list, each with the
 * screen that resolves it — the way NGG's Control Room and every donor system
 * worth the name open on work, not on totals.
 *
 *   · Only what the viewer may act on: an item is shown when the viewer's role
 *     may open the screen it points to (lib/StudioSections.php), so an editor
 *     is never shown money or member requests.
 *   · A queue that could not be counted is SAID, never shown as zero: a silent
 *     "nothing waiting" is the bug that hides an outage.
 *   · Cheap: counts only, each read guarded on its own.
 *   · Not the email set-up: the Overview's own banner already says it, right above.
 */
declare(strict_types=1);

final class StudioToday
{
    /**
     * @return array{items: list<array{key:string,n:int,label:string,sub:string,go:string,tab:string,tone:string}>, errors: list<array{key:string,label:string}>, total:int}
     */
    public static function for(string $role): array
    {
        require_once __DIR__ . '/StudioSections.php';
        $min = StudioSections::minRoles();
        $rank = AdminRoles::RANK;
        $mine = (int) ($rank[$role] ?? 0);
        $items = []; $errors = [];
        foreach (self::sources() as $s) {
            if ($mine < (int) ($rank[$min[$s['go']] ?? 'superadmin'] ?? 99)) continue;
            if (isset($s['role']) && $mine < (int) ($rank[$s['role']] ?? 99)) continue;
            try { $n = (int) ($s['count'])(); }
            catch (Throwable $e) {
                error_log('[studio-today] ' . $s['key'] . ': ' . $e->getMessage());
                $errors[] = ['key' => $s['key'], 'label' => $s['label'], 'go' => $s['go'], 'tab' => $s['tab'] ?? ''];
                continue;
            }
            if ($n <= 0) continue;
            $items[] = ['key' => $s['key'], 'n' => $n, 'label' => $s['label'], 'sub' => $s['sub'], 'go' => $s['go'], 'tab' => $s['tab'] ?? '', 'tone' => $s['tone'] ?? 'act'];
        }
        return ['items' => $items, 'errors' => $errors, 'total' => array_sum(array_column($items, 'n'))];
    }

    /** Each queue: what it is, where it is worked, and how many wait. */
    private static function sources(): array
    {
        return [
            ['key' => 'gifts', 'label' => 'Bank transfers to verify', 'sub' => 'Recorded by one admin; another checks the statement and counts it.',
             'go' => 'gifts', 'tone' => 'money', 'count' => static fn() => BankGifts::pendingCount()],
            ['key' => 'offline', 'label' => 'Offline payments to check', 'sub' => 'Dues and NGV payments by transfer, cash or POS, held until checked.',
             'go' => 'members', 'tab' => 'offline', 'tone' => 'money', 'count' => static function (): int {
                 $c = OfflinePayments::counts(); return (int) ($c['held'] ?? 0) + (int) ($c['checked'] ?? 0); }],
            ['key' => 'approvals', 'label' => 'Requests waiting for a second admin', 'sub' => 'Suspensions and ended dues another admin asked for.',
             'go' => 'members', 'tab' => 'approvals', 'count' => static fn() => Approvals::pendingCount()],
            ['key' => 'requests', 'label' => 'Member requests', 'sub' => 'What members have asked the office to change.',
             'go' => 'members', 'tab' => 'approvals', 'count' => static fn() => MemberRequests::pendingCount()],
            ['key' => 'verify', 'label' => 'Members to verify', 'sub' => 'New accounts waiting for somebody to confirm who they are.',
             'go' => 'members', 'tab' => 'verify', 'count' => static fn() => count(MemberVerification::toVerify(500))],
            ['key' => 'review', 'label' => 'Site changes waiting to be published', 'sub' => 'Sent for review by an editor; an admin publishes or sends back.',
             'go' => 'site', 'role' => 'admin', 'count' => static function (): int {
                 return count(array_filter(Cms::overview(), static fn($d) => (string) ($d['state'] ?? '') === 'review')); }],
            ['key' => 'moderation', 'label' => 'Posts and comments to moderate', 'sub' => 'What members and readers wrote, waiting to go public.',
             'go' => 'moderation', 'count' => static function (): int {
                 $n = (new DiaryRepository())->moderationCount();
                 try { $n += count((new DiaryJournal())->pendingPublic()); } catch (Throwable $e) { /* the journal is optional */ }
                 return $n; }],
            ['key' => 'proposals', 'label' => 'AI suggestions to decide', 'sub' => 'Changes to rules or prompts the assistant proposed; nothing applies until approved.',
             'go' => 'rules', 'count' => static fn() => count(AvTools::proposals('pending', 60))],
        ];
    }
}
