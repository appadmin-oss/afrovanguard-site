<?php
/**
 * lib/AvPrompts.php — the AI's instructions, as editable templates.
 *
 * Before this class, every prompt in the app was a heredoc buried in the method
 * that used it: the minutes prompt inside Meetings::structure(), the planner
 * prompt inside Collab::aiTasksFromGoal(). Changing how the AI behaves meant
 * editing PHP and deploying, and the two prompts had already drifted into
 * describing Afrovanguard differently.
 *
 * Here each prompt is a named template with declared variables, a documented
 * default, and an optional override stored in the database. Templates opt into
 * two context blocks that are assembled at render time:
 *
 *   rules      — AvRules::asPromptBlock(),     leadership's live thresholds
 *   knowledge  — AvKnowledge::asPromptBlock(), leadership's written doctrine
 *
 * That is what makes the assistant dynamic rather than hardcoded. A threshold
 * edited in Studio, or a paragraph of doctrine rewritten, changes the next
 * answer the model gives — without a deploy, and without any prompt text being
 * duplicated between call sites.
 *
 * Every path is guarded: an unknown key, an unreadable table or a malformed
 * override falls back to the coded default, which always works.
 */
declare(strict_types=1);

final class AvPrompts
{
    /**
     * Every prompt the app uses.
     *
     *   label    human name (Studio)
     *   group    Studio grouping
     *   help     what this prompt drives
     *   vars     declared {{placeholders}} => description
     *   context  which live blocks to append: rules | knowledge
     *   system   the default template
     */
    public const DEFAULTS = [

        'meeting.minutes' => [
            'label'   => 'Meeting minutes',
            'group'   => 'Meetings',
            'help'    => 'Turns a raw meeting transcript into a summary, highlights, decisions and action items. Runs after every meeting that has a transcript.',
            'vars'    => ['today' => 'Today\'s date (YYYY-MM-DD)', 'max_items' => 'Maximum action items to extract'],
            'context' => ['knowledge'],
            'system'  => <<<'TPL'
You are a meeting-minutes assistant for the Afrovanguard organisation.

Read the raw meeting transcript and return STRICT JSON only — no prose, no markdown fences.

Schema:
{"summary": string (3-5 sentence overview),
 "highlights": string[] (key discussion points),
 "decisions": string[] (decisions made),
 "action_items": [{"task": string, "owner": string, "due": string}]}

Rules:
- Stay faithful to the transcript. Do not invent facts, names, figures or commitments that were not said.
- "owner" is the person's name exactly as spoken, or "" if the transcript does not say who owns it. Never guess an owner.
- "due" is an ISO date (YYYY-MM-DD) only if a date or deadline was actually stated; otherwise "". Today is {{today}}.
- Extract at most {{max_items}} action items. Prefer real commitments over topics that were merely discussed.
- If the transcript is too fragmentary to summarise honestly, say so in "summary" rather than inventing a narrative.
TPL,
        ],

        'goal.tasks' => [
            'label'   => 'Goal → tasks',
            'group'   => 'Collaboration',
            'help'    => 'Breaks a team goal into concrete tasks with deadlines and priorities.',
            'vars'    => ['today' => 'Today\'s date (YYYY-MM-DD)', 'max' => 'Maximum tasks to return'],
            'context' => ['knowledge'],
            'system'  => <<<'TPL'
You are an operations planner for Afrovanguard, a Pan-African nonprofit. You turn a team GOAL into a short list of concrete, actionable tasks the team can divide up and complete.

Rules:
- Return BETWEEN 3 AND {{max}} tasks. Each must be a single, clearly-scoped action a member could pick up and finish — start the title with a verb (e.g. "Draft…", "Contact…", "Design…").
- Order them in the sensible sequence to do the work.
- Set a realistic "days" value: the number of days FROM TODAY the task should be done by (spread them out; earlier tasks get smaller numbers). Today is {{today}}.
- priority is one of: high, normal, low.
- Do NOT invent specific external facts, names, or figures. Keep titles under 120 characters.

Respond with ONLY a JSON array, no prose, no code fences. Shape:
[{"title":"...","priority":"high|normal|low","days":<integer 1-60>}]
TPL,
        ],

        'meeting.agenda' => [
            'label'   => 'Meeting agenda',
            'group'   => 'Meetings',
            'help'    => 'Drafts an agenda from the previous meeting\'s minutes and the attendees\' open commitments. The chair approves or edits it — it is never published automatically.',
            'vars'    => ['today' => 'Today\'s date', 'title' => 'Meeting title', 'duration' => 'Scheduled length in minutes'],
            'context' => ['rules', 'knowledge'],
            'system'  => <<<'TPL'
You draft meeting agendas for Afrovanguard. Today is {{today}}. The meeting is "{{title}}" and is scheduled for {{duration}} minutes.

You will be given the previous meeting's minutes and the open commitments of the people attending.

Return STRICT JSON only:
{"items": [{"topic": string, "minutes": integer, "why": string}], "note": string}

Rules:
- Open commitments that are due or overdue come FIRST. Nothing that was committed to should quietly fall off an agenda.
- Carry forward decisions from last time that need review.
- The total of all "minutes" must not exceed {{duration}}. Leave a few minutes spare.
- "why" is one short sentence explaining why the item is on the agenda — usually which commitment or decision it follows from.
- Do not invent topics. If there is not enough material to fill the time, return fewer items and say so in "note".
- This is a DRAFT for the chair to approve. Never phrase it as final.
TPL,
        ],

        'mentorship.nudge' => [
            'label'   => 'Mentorship nudge',
            'group'   => 'Mentorship',
            'help'    => 'Writes the message sent when a mentorship session is missed or a pairing has gone quiet. The escalation tone rule controls how this reads.',
            'vars'    => ['name' => 'Who is being written to', 'other' => 'The other person in the pairing', 'missed' => 'What was missed', 'days' => 'Days since the last held session', 'step' => 'Which escalation step this is'],
            'context' => ['rules', 'knowledge'],
            'system'  => <<<'TPL'
You write short, human messages for Afrovanguard's mentorship accountability system.

This is escalation step {{step}}. You are writing to {{name}} about their mentorship with {{other}}. What was missed: {{missed}}. It has been {{days}} days since their last held session.

Return STRICT JSON only: {"subject": string, "body": string}

Rules:
- Follow the escalation tone set by leadership in the operating rules above. It is authoritative.
- Under 120 words. Plain language, no corporate phrasing, no guilt.
- Ask whether something is in the way before suggesting anything. Assume a good reason exists.
- Offer one concrete next step — usually rescheduling.
- Never speculate about why the session was missed, never mention other members, and never imply a judgement about character or commitment.
- Do not mention that this message was generated automatically or that an escalation step exists.
TPL,
        ],

        'level.recommendation' => [
            'label'   => 'Level recommendation',
            'group'   => 'Levels',
            'help'    => 'Assesses a member against the level criteria and recommends — never applies — an advancement, with the evidence behind it.',
            'vars'    => ['name' => 'The member', 'level' => 'Their current level', 'next' => 'The next level'],
            'context' => ['rules', 'knowledge'],
            'system'  => <<<'TPL'
You assess Afrovanguard members against the leadership ladder and produce a RECOMMENDATION for human leadership to decide on.

The member is {{name}}, currently at level {{level}}. The level under consideration is {{next}}.

Return STRICT JSON only:
{"recommend": "advance"|"hold"|"insufficient_data",
 "evidence": string[],
 "gaps": string[],
 "note": string}

Rules:
- Apply the thresholds in the operating rules above exactly. Never substitute your own.
- "evidence" is specific and countable: sessions held, active mentees, commitments kept, mentees who are themselves mentoring. Each item is one plain sentence a leader can verify.
- "gaps" is what is missing for the next level, stated just as concretely.
- Measure multiplication quality, never headcount. Registered mentees who are not active are not evidence of leadership.
- Never output a character score, a rating, or a single number summarising the person.
- Return "insufficient_data" rather than guessing when the record is too thin.
- You are recommending. You are not deciding, and you must not write as though the promotion has happened.
TPL,
        ],

        'leadership.brief' => [
            'label'   => 'Leadership brief',
            'group'   => 'Leadership',
            'help'    => 'The periodic exception brief: what needs a human this week, and nothing that does not.',
            'vars'    => ['period' => 'The period covered', 'today' => 'Today\'s date'],
            'context' => ['rules', 'knowledge'],
            'system'  => <<<'TPL'
You write Afrovanguard's leadership brief for {{period}}, ending {{today}}.

You will be given the raw accountability data for the period.

Return STRICT JSON only:
{"headline": string, "needs_attention": [{"who": string, "what": string, "why": string}], "good_news": string[], "note": string}

Rules:
- This is an EXCEPTION brief. Report what needs a human decision, not everything that happened. A quiet week should produce a short brief.
- Every "needs_attention" item names the evidence in "why" so a leader can disagree with it.
- Include "good_news": consistent pairings, kept commitments, members who voluntarily reported a miss. Reporting your own miss is a positive character signal and should be recorded as one.
- Never include a member's stated reason for missing a commitment or meeting. Those disclosures are confidential and must not appear in a brief.
- Never rank members, and never reduce anyone to a score.
- If the data is too thin to say anything useful, say that plainly in "note" instead of padding.
TPL,
        ],
    ];

    private static bool $ensured = false;
    private static ?array $overrides = null;

    private static function db(): PDO { return Database::pdo(); }

    /** Idempotently provision the template table. */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            Database::execSchema(self::db(), "
                CREATE TABLE IF NOT EXISTS av_prompts (
                    prompt_key TEXT PRIMARY KEY,
                    system TEXT NOT NULL DEFAULT '',
                    updated_by TEXT NOT NULL DEFAULT '',
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
            ");
        } catch (\Throwable $e) { error_log('[prompts] ensure: ' . $e->getMessage()); }
    }

    public static function flush(): void { self::$overrides = null; }

    private static function overrides(): array
    {
        if (self::$overrides !== null) return self::$overrides;
        self::$overrides = [];
        self::ensure();
        try {
            foreach (self::db()->query('SELECT prompt_key, system FROM av_prompts') as $r) {
                self::$overrides[(string) $r['prompt_key']] = (string) $r['system'];
            }
        } catch (\Throwable $e) { error_log('[prompts] load: ' . $e->getMessage()); }
        return self::$overrides;
    }

    public static function defined(string $key): bool { return isset(self::DEFAULTS[$key]); }

    /** The raw template for a key: the override if one is stored, else the default. */
    public static function template(string $key): string
    {
        if (!self::defined($key)) return '';
        $ov = self::overrides();
        $t  = trim((string) ($ov[$key] ?? ''));
        return $t !== '' ? $t : (string) self::DEFAULTS[$key]['system'];
    }

    /**
     * Build the finished system prompt: interpolate {{vars}}, then append the
     * live rules and doctrine blocks the template asked for.
     *
     * Any placeholder the caller did not supply is removed rather than passed
     * through, so a stray {{token}} can never reach the model as literal text.
     */
    public static function render(string $key, array $vars = []): string
    {
        if (!self::defined($key)) {
            error_log('[prompts] unknown template: ' . $key);
            return '';
        }
        $out = self::template($key);

        foreach ($vars as $k => $v) {
            $val = is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
            $out = str_replace('{{' . $k . '}}', $val, $out);
        }
        if (strpos($out, '{{') !== false) {
            $left = [];
            if (preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $out, $m)) $left = array_unique($m[1]);
            if ($left) error_log('[prompts] ' . $key . ': unfilled placeholders — ' . implode(', ', $left));
            $out = preg_replace('/\{\{\s*[a-z0-9_.]+\s*\}\}/i', '', $out) ?? $out;
        }

        $ctx = (array) (self::DEFAULTS[$key]['context'] ?? []);
        if (in_array('rules', $ctx, true) && class_exists('AvRules')) {
            $out .= AvRules::asPromptBlock();
        }
        if (in_array('knowledge', $ctx, true) && class_exists('AvKnowledge')) {
            $out .= AvKnowledge::asPromptBlock();
        }
        return $out;
    }

    /**
     * Validate a proposed template. Rejects an empty body and flags placeholders
     * the template does not declare — a typo'd {{owner}} silently renders as
     * nothing, which is exactly the kind of failure that is hard to notice.
     */
    public static function validate(string $key, string $tpl): array
    {
        if (!self::defined($key)) return ['ok' => false, 'error' => 'Unknown prompt.'];
        $tpl = trim($tpl);
        if ($tpl === '') return ['ok' => true, 'value' => '', 'cleared' => true];   // empty = revert to default
        if (mb_strlen($tpl) > 12000) return ['ok' => false, 'error' => 'Too long (12000 characters maximum).'];

        $declared = array_keys((array) (self::DEFAULTS[$key]['vars'] ?? []));
        $used = [];
        if (preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $tpl, $m)) $used = array_unique($m[1]);
        $unknown = array_diff($used, $declared);
        if ($unknown) {
            return ['ok' => false, 'error' => 'Unknown placeholder(s): ' . implode(', ', array_map(fn($u) => '{{' . $u . '}}', $unknown))
                . '. Available here: ' . ($declared ? implode(', ', array_map(fn($d) => '{{' . $d . '}}', $declared)) : 'none') . '.'];
        }
        return ['ok' => true, 'value' => $tpl];
    }

    /** Save an override, or clear it (empty template) to return to the default. */
    public static function set(string $key, string $tpl, string $actor = 'admin'): array
    {
        if (!self::defined($key)) return ['ok' => false, 'error' => 'Unknown prompt.'];
        $v = self::validate($key, $tpl);
        if (!$v['ok']) return $v;
        self::ensure();
        try {
            $db = self::db();
            $db->prepare('DELETE FROM av_prompts WHERE prompt_key = ?')->execute([$key]);
            if ((string) $v['value'] !== '') {
                $db->prepare('INSERT INTO av_prompts (prompt_key, system, updated_by, updated_at) VALUES (?,?,?,' . Database::nowExpr() . ')')
                   ->execute([$key, (string) $v['value'], mb_substr($actor, 0, 120)]);
            }
            self::flush();
            if (class_exists('Events')) Events::emit('prompts.changed', ['key' => $key]);
            return ['ok' => true, 'cleared' => (string) $v['value'] === ''];
        } catch (\Throwable $e) {
            error_log('[prompts] set: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that prompt.'];
        }
    }

    /** The stored override for a key ('' when none), for undo bookkeeping. */
    public static function rawOverride(string $key): string
    {
        return (string) (self::overrides()[$key] ?? '');
    }

    /** Every prompt, grouped for Studio. */
    public static function all(): array
    {
        $ov = self::overrides();
        $groups = [];
        foreach (self::DEFAULTS as $key => $spec) {
            $isOv = isset($ov[$key]) && trim($ov[$key]) !== '';
            $groups[$spec['group']][] = [
                'key'        => $key,
                'label'      => $spec['label'],
                'help'       => $spec['help'],
                'system'     => self::template($key),
                'default'    => (string) $spec['system'],
                'vars'       => (array) ($spec['vars'] ?? []),
                'context'    => array_values((array) ($spec['context'] ?? [])),
                'overridden' => $isOv,
            ];
        }
        $out = [];
        foreach ($groups as $name => $prompts) $out[] = ['group' => $name, 'prompts' => $prompts];
        return $out;
    }

    /** Undo dispatch for the Studio audit trail (AdminAudit::UNDOABLE). */
    public static function applyUndo(string $op, array $args): bool
    {
        if ($op !== 'prompt_set') return false;
        $key = (string) ($args['key'] ?? '');
        $to  = (string) ($args['to'] ?? '');
        if (!self::defined($key)) return false;
        return !empty(self::set($key, $to, 'undo')['ok']);
    }
}
