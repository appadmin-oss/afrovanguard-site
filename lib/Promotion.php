<?php
/**
 * lib/Promotion.php — AI-assisted promotion review (report §20, §17, §23).
 *
 *   "Promotion should be automated as a RECOMMENDATION, not blindly automated.
 *    The AI can say: 'Recommended for Level A.' Because: two active mentees
 *    maintained for X months, 85% meeting consistency, 90% commitment
 *    completion, both mentees demonstrating progress. Leadership can then
 *    approve the advancement." (§20)
 *
 * Levels::recommend() already computes the verdict against leadership's rules,
 * and mem_save already changes a level with an audit trail. Neither is
 * duplicated here — a second promote path is how two of them drift apart. What
 * was missing is the middle: the written case a leader reads BEFORE deciding,
 * and a queue so nobody has to go hunting for who is ready.
 *
 * TWO ASSESSMENTS, DELIBERATELY NOT MERGED.
 *
 *   The ENGINE decides eligibility. Its thresholds are leadership's
 *   constitution (§27), so a model does not get to overrule them in either
 *   direction. Its verdict is recorded verbatim.
 *
 *   The MODEL reads multiplication QUALITY — the judgement §17 exists to
 *   demand. "Person A has 10 mentees, 3 unmet in two months, 4 with no goals,
 *   2 unresponsive. Person B has 4, all meeting weekly and all now mentoring.
 *   Person B may represent significantly stronger leadership." No threshold
 *   expresses that; it is exactly what a human wants a second read on.
 *
 * WHERE THEY DISAGREE, THE DISAGREEMENT IS THE OUTPUT. It is not averaged, and
 * the model never flips the verdict. A leader about to promote someone the
 * engine cleared and the model doubted should see that sentence before they
 * click, and the reverse — engine says no, model sees a strong case — is a
 * prompt to look at the rule, not to bypass it.
 *
 * Nothing here promotes anyone. §23: observe, record, analyse, recommend,
 * escalate. Interpret, discern, counsel, decide, correct, promote — human.
 */
declare(strict_types=1);

final class Promotion
{
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_promotion_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                from_level VARCHAR(8) NOT NULL DEFAULT '',
                to_level VARCHAR(8) NOT NULL DEFAULT '',
                engine_ok INTEGER NOT NULL DEFAULT 0,
                engine_reasons TEXT NOT NULL DEFAULT '',
                engine_gaps TEXT NOT NULL DEFAULT '',
                metrics TEXT NOT NULL DEFAULT '',
                ai_ok INTEGER NOT NULL DEFAULT 0,
                ai_confidence VARCHAR(8) NOT NULL DEFAULT '',
                ai_reasons TEXT NOT NULL DEFAULT '',
                ai_gaps TEXT NOT NULL DEFAULT '',
                agrees INTEGER NOT NULL DEFAULT 1,
                source VARCHAR(16) NOT NULL DEFAULT '',
                status VARCHAR(12) NOT NULL DEFAULT 'open',
                note TEXT NOT NULL DEFAULT '',
                decided_by VARCHAR(191) NOT NULL DEFAULT '',
                decided_at VARCHAR(32) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_avprom ON av_promotion_reviews(user_id, id);");
        } catch (Throwable $e) { error_log('[promotion] ensure: ' . $e->getMessage()); }
    }

    /* ════════════════════════════════════════════════════════════════
       Who is worth reviewing
       ════════════════════════════════════════════════════════════════ */

    /**
     * Members the engine rates as ready, or as meeting every measurable
     * criterion. Only mentors are assessed: under the O–G model advancement
     * requires active mentees, so nobody else can qualify — the model's own
     * rule, not a shortcut.
     *
     * @return list<array{user_id:int,name:string,level:string,next:string,ready:bool,engine:array}>
     */
    public static function candidates(): array
    {
        self::ensure();
        if (!class_exists('Levels') || !class_exists('Accountability')) return [];

        $needA  = class_exists('AvRules') ? max(1, AvRules::int('levels.active_mentees_for_a')) : 2;
        // "Approaching" needs a floor, or the queue fills with people who are not
        // close to anything. Rather than invent one, borrow the organisation's own
        // declared line between healthy and needing attention. Someone above Amber
        // but below the promotion bar is genuinely nearly there; someone below
        // Amber has a relationship to repair first, and is already on the Health
        // board where that belongs.
        $floorPct = class_exists('AvRules') ? AvRules::int('health.amber_attendance_pct') : 70;

        $out = [];
        $seen = [];
        foreach (Accountability::activePairs() as $p) {
            $uid = (int) $p['mentor_id'];
            if (isset($seen[$uid])) continue;
            $seen[$uid] = true;
            try { $r = Levels::recommend($uid); } catch (Throwable $e) { continue; }
            if (($r['next'] ?? null) === null) continue;                 // top of the ladder

            $m     = (array) ($r['metrics'] ?? []);
            $ready = !empty($r['recommend']);
            // "Approaching the criteria" (§20), judged on the structured metrics
            // and never on the wording of a gap. The active-mentee bar is the
            // ladder's defining criterion (§4, §17), so it is required; everything
            // else is shown as an outstanding gap rather than used to hide the
            // person. A member mentoring well but missing their OWN accountability
            // meetings is precisely who a leader should see, with that written
            // next to their name.
            $near  = !$ready
                  && (int) ($m['active_mentees'] ?? 0) >= $needA
                  && ($m['attendance_pct'] ?? null) !== null
                  && (int) $m['attendance_pct'] >= $floorPct;
            if (!$ready && !$near) continue;

            $out[] = ['user_id' => $uid, 'name' => (string) $p['mentor'],
                      'level' => (string) $r['level'], 'next' => (string) $r['next'],
                      'ready' => $ready, 'engine' => $r];
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       The review
       ════════════════════════════════════════════════════════════════ */

    /**
     * Write the case for (or against) one member's advancement.
     *
     * @return array{ok:bool,review?:array,reason?:string}
     */
    public static function review(int $userId, bool $force = false): array
    {
        self::ensure();
        if ($userId <= 0 || !class_exists('Levels')) return ['ok' => false, 'reason' => 'Levels are unavailable.'];

        $eng = Levels::recommend($userId);
        if (($eng['next'] ?? null) === null) return ['ok' => false, 'reason' => 'Already at the top of the ladder.'];

        // One review per member per target level, unless a human asks again. The
        // evidence moves slowly; re-running it weekly is cost with no reader.
        if (!$force) {
            $last = self::latestFor($userId);
            if ($last && $last['to_level'] === (string) $eng['next'] && $last['status'] !== 'reopened') {
                return ['ok' => false, 'reason' => 'This member already has a review for ' . $eng['next'] . '.', 'review' => $last];
            }
        }

        $ai = self::assess($userId, $eng);

        try {
            Database::pdo()->prepare(
                'INSERT INTO av_promotion_reviews
                   (user_id, from_level, to_level, engine_ok, engine_reasons, engine_gaps, metrics,
                    ai_ok, ai_confidence, ai_reasons, ai_gaps, agrees, source, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId, (string) $eng['level'], (string) $eng['next'],
                !empty($eng['recommend']) ? 1 : 0,
                self::enc($eng['reasons'] ?? []), self::enc($eng['gaps'] ?? []), self::enc($eng['metrics'] ?? []),
                !empty($ai['recommend']) ? 1 : 0, (string) ($ai['confidence'] ?? ''),
                self::enc($ai['reasons'] ?? []), self::enc($ai['gaps'] ?? []),
                (!empty($eng['recommend']) === !empty($ai['recommend'])) ? 1 : 0,
                (string) ($ai['source'] ?? ''), 'open', gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            error_log('[promotion] store: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'Could not file the review.'];
        }
        return ['ok' => true, 'review' => self::byId($id)];
    }

    /**
     * The model's qualitative read. Never authoritative — see the class comment.
     *
     * With no provider this returns the engine's own verdict restated, marked as
     * such, so a review always exists and never pretends a model looked at it.
     */
    private static function assess(int $userId, array $eng): array
    {
        $none = [
            'recommend'  => !empty($eng['recommend']),
            'confidence' => '',
            'reasons'    => [],
            'gaps'       => [],
            'source'     => 'engine-only',
        ];
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return $none;
        if (!class_exists('AvPrompts') || !AvPrompts::isKey('promotion.recommendation')) return $none;

        $name = self::nameOf($userId);
        $sys = AvPrompts::render('promotion.recommendation', [
            'member'       => $name !== '' ? $name : ('member #' . $userId),
            'target_level' => (string) $eng['next'],
            'evidence'     => self::evidenceText($eng),
        ]);
        if (trim($sys) === '') return $none;

        $r = class_exists('AvAgent')
            ? AvAgent::complete($sys, 'Assess readiness now.', ['max_tokens' => 900, 'temperature' => 0.2])
            : ['ok' => false];
        if (empty($r['ok'])) return $none;

        $j = self::json((string) $r['text']);
        if (!is_array($j) || !array_key_exists('recommend', $j)) return $none;

        $list = static function ($v): array {
            $o = [];
            foreach ((array) $v as $x) { $x = trim((string) $x); if ($x !== '') $o[] = mb_substr($x, 0, 400); }
            return array_slice($o, 0, 8);
        };
        $conf = strtolower(trim((string) ($j['confidence'] ?? '')));
        return [
            'recommend'  => (bool) $j['recommend'],
            'confidence' => in_array($conf, ['low', 'medium', 'high'], true) ? $conf : 'low',
            'reasons'    => $list($j['reasons'] ?? []),
            'gaps'       => $list($j['gaps'] ?? []),
            'source'     => (string) ($r['provider'] ?? 'ai'),
        ];
    }

    /** The evidence, as the flat text the prompt is fed. Figures only. */
    public static function evidenceText(array $eng): string
    {
        $m = (array) ($eng['metrics'] ?? []);
        $L = [];
        $L[] = 'Current level: ' . (string) $eng['level'] . '. Under review for: ' . (string) $eng['next'] . '.';
        $L[] = 'Active mentees: ' . (int) ($m['active_mentees'] ?? 0)
             . ' (of whom mentoring others: ' . (int) ($m['multiplying_mentees'] ?? 0) . ')';
        $L[] = 'Descendants across the chain: ' . (int) ($m['descendants'] ?? 0) . ', depth ' . (int) ($m['depth'] ?? 0);
        $L[] = 'Meeting consistency: ' . (($m['attendance_pct'] ?? null) === null ? 'not yet measurable' : (int) $m['attendance_pct'] . '%')
             . ' across ' . (int) ($m['sessions_held'] ?? 0) . ' session(s)';
        $L[] = 'Time at the current level: ' . (($m['days_at_level'] ?? null) === null ? 'unknown — predates tenure tracking' : (int) $m['days_at_level'] . ' days');
        if (($m['commitment_pct'] ?? null) !== null) {
            $L[] = 'Commitments kept: ' . (int) $m['commitment_pct'] . '% of ' . (int) ($m['commitments_settled'] ?? 0) . ' settled';
            // Candour is a positive signal, and saying so stops the model reading
            // a self-reported miss as a failure (§3A).
            $L[] = 'Misses the member reported themselves: ' . (int) ($m['self_reported_misses'] ?? 0)
                 . ' (owning a miss is evidence of accountability, not against it)';
        } elseif (!empty($m['commitment_sample_short'])) {
            $L[] = 'Commitments: too few settled to judge yet (needs ' . (int) ($m['commitment_sample_needed'] ?? 0) . ')';
        }
        $L[] = '';
        $L[] = 'The rules engine\'s verdict: ' . (!empty($eng['recommend']) ? 'MEETS the criteria' : 'does NOT yet meet the criteria');
        foreach ((array) ($eng['reasons'] ?? []) as $x) $L[] = '  met: ' . $x;
        foreach ((array) ($eng['gaps'] ?? []) as $x)    $L[] = '  outstanding: ' . $x;
        return implode("\n", $L);
    }

    /* ════════════════════════════════════════════════════════════════
       The queue leadership works through
       ════════════════════════════════════════════════════════════════ */

    /**
     * Everyone worth a look, with their latest review attached.
     *
     * Ready first, then those approaching. A member already promoted past the
     * reviewed level drops out on their own — eligibility is recomputed live, so
     * there is no second status to keep in step with reality.
     */
    public static function queue(): array
    {
        self::ensure();
        $rows = [];
        foreach (self::candidates() as $c) {
            $rev = self::latestFor($c['user_id']);
            // A review for a level the member has since passed is history, not a
            // pending decision.
            if ($rev && $rev['to_level'] !== $c['next']) $rev = null;
            $rows[] = [
                'user_id' => $c['user_id'],
                'name'    => $c['name'],
                'level'   => $c['level'],
                'next'    => $c['next'],
                'ready'   => $c['ready'],
                'reasons' => array_slice((array) ($c['engine']['reasons'] ?? []), 0, 4),
                'gaps'    => array_slice((array) ($c['engine']['gaps'] ?? []), 0, 4),
                'review'  => $rev,
            ];
        }
        usort($rows, static function ($a, $b) {
            if ($a['ready'] !== $b['ready']) return $a['ready'] ? -1 : 1;
            return strcmp($a['name'], $b['name']);
        });
        return $rows;
    }

    /**
     * Set a review aside with a reason.
     *
     * Deferred is not hidden — the queue still shows it, in its own bucket, with
     * who set it aside and why. A promotion quietly dropped is the failure §21
     * exists to prevent, so "not yet" has to stay visible.
     */
    public static function defer(int $userId, string $note, string $actor): array
    {
        self::ensure();
        $rev = self::latestFor($userId);
        if (!$rev) return ['ok' => false, 'error' => 'No review to set aside.'];
        if ($rev['status'] === 'deferred') return ['ok' => false, 'error' => 'That review is already set aside.'];
        $note = trim($note);
        if ($note === '') return ['ok' => false, 'error' => 'Say why, so the next person reading this knows.'];
        try {
            Database::pdo()->prepare("UPDATE av_promotion_reviews SET status='deferred', note=?, decided_by=?, decided_at=? WHERE id=?")
                ->execute([mb_substr($note, 0, 600), mb_substr($actor, 0, 191), gmdate('Y-m-d H:i:s'), $rev['id']]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save that.']; }
        return ['ok' => true, 'review' => self::byId($rev['id'])];
    }

    /** Put a deferred review back in front of leadership. */
    public static function reopen(int $userId, string $actor): array
    {
        self::ensure();
        $rev = self::latestFor($userId);
        if (!$rev) return ['ok' => false, 'error' => 'No review to reopen.'];
        try {
            Database::pdo()->prepare("UPDATE av_promotion_reviews SET status='reopened', decided_by=?, decided_at=? WHERE id=?")
                ->execute([mb_substr($actor, 0, 191), gmdate('Y-m-d H:i:s'), $rev['id']]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save that.']; }
        return ['ok' => true, 'review' => self::byId($rev['id'])];
    }

    public static function latestFor(int $userId): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_promotion_reviews WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::decode($r) : null;
        } catch (Throwable $e) { return null; }
    }

    public static function byId(int $id): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_promotion_reviews WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::decode($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Review anyone newly ready, and tell leadership.
     *
     * Only members the engine rates READY are reviewed automatically. The ones
     * merely approaching appear in the queue with their gaps and cost nothing —
     * writing a case for someone who does not yet meet the criteria is a model
     * call nobody asked for.
     */
    public static function sweep(): array
    {
        $out = ['reviewed' => 0, 'notified' => 0];
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return $out + ['off' => true];

        foreach (self::candidates() as $c) {
            if (!$c['ready']) continue;
            $r = self::review($c['user_id']);
            if (empty($r['ok'])) continue;
            $out['reviewed']++;
            $out['notified'] += self::tellLeadership($c, $r['review']);
        }
        return $out;
    }

    private static function tellLeadership(array $c, array $rev): int
    {
        if (!class_exists('AdminRoles') || !class_exists('Notifications')) return 0;
        $n = 0;
        try {
            $body = $c['name'] . ' meets the criteria for ' . $c['next'] . '.';
            if (!$rev['agrees']) {
                // The disagreement is the reason to open it, so it leads.
                $body .= ' The AI review disagrees with the rules engine — worth reading before you decide.';
            }
            $find = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
            foreach (AdminRoles::list() as $a) {
                $email = strtolower(trim((string) ($a['email'] ?? '')));
                if ($email === '') continue;
                $find->execute([$email]);
                $uid = (int) ($find->fetchColumn() ?: 0);
                if ($uid <= 0) continue;
                $n += Notifications::push($uid, 'promotion', 'Ready for ' . $c['next'] . ': ' . $c['name'], $body,
                    '/admin/#mentorship', 'promo:' . (int) $rev['id']) > 0 ? 1 : 0;
            }
        } catch (Throwable $e) { error_log('[promotion] notify: ' . $e->getMessage()); }
        return $n;
    }

    /* ── internals ─────────────────────────────────────────────────── */

    private static function nameOf(int $uid): string
    {
        try {
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            return (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return ''; }
    }

    private static function enc($v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($s) ? $s : '[]';
    }

    private static function decode(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'user_id'       => (int) $r['user_id'],
            'from_level'    => (string) $r['from_level'],
            'to_level'      => (string) $r['to_level'],
            'engine_ok'     => (int) $r['engine_ok'] === 1,
            'engine_reasons'=> json_decode((string) $r['engine_reasons'], true) ?: [],
            'engine_gaps'   => json_decode((string) $r['engine_gaps'], true) ?: [],
            'metrics'       => json_decode((string) $r['metrics'], true) ?: [],
            'ai_ok'         => (int) $r['ai_ok'] === 1,
            'ai_confidence' => (string) $r['ai_confidence'],
            'ai_reasons'    => json_decode((string) $r['ai_reasons'], true) ?: [],
            'ai_gaps'       => json_decode((string) $r['ai_gaps'], true) ?: [],
            'agrees'        => (int) $r['agrees'] === 1,
            'source'        => (string) $r['source'],
            'status'        => (string) $r['status'],
            'note'          => (string) $r['note'],
            'decided_by'    => (string) $r['decided_by'],
            'decided_at'    => (string) $r['decided_at'],
            'created_at'    => (string) $r['created_at'],
        ];
    }

    private static function json(string $s)
    {
        $s = trim(preg_replace('/^```(?:json)?|```$/m', '', $s) ?? $s);
        $a = strpos($s, '{'); $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        return json_decode(substr($s, $a, $b - $a + 1), true);
    }
}
