<?php
/**
 * lib/AvPrompts.php — AI system prompts as data, not heredocs.
 *
 * Every AI call in the app used to carry its instructions inline: the meeting
 * minutes schema in Meetings::structure(), the task planner in
 * Collab::aiTasksFromGoal(). That works until someone who is not a developer
 * needs to change how the assistant behaves — a different minutes format, a
 * firmer escalation tone, an extra rule about not naming individuals.
 *
 * A template here is:
 *
 *   • a documented DEFAULT in code, so a fresh install works with no setup and
 *     an operator can always get back to something sane;
 *   • overridable in the Studio, stored in av_prompts;
 *   • rendered with {{placeholders}} filled from caller-supplied variables, plus
 *     the live rules and knowledge blocks appended automatically.
 *
 * The automatic appending is the point. A prompt author cannot forget to include
 * Afrovanguard's cadence or its "never score character" rule, because the
 * renderer adds them. Editing a template changes the *task* instructions; the
 * constitution comes from AvRules either way.
 *
 * Contract fields on each template:
 *   text     the default template body
 *   vars     placeholder names the caller must supply
 *   scope    which AvKnowledge scope to append ('' appends none)
 *   rules    whether to append the AvRules block
 *   label    what the Studio calls it
 */
declare(strict_types=1);

final class AvPrompts
{
    private static bool $ready = false;
    /** @var array<string,string>|null memoised overrides */
    private static ?array $memo = null;

    private const DEFS = [
        'meeting.minutes' => [
            'label' => 'Meeting minutes',
            'scope' => 'meetings',
            'rules' => true,
            'vars'  => [],
            'text'  =>
                "You are a meeting-minutes assistant for the Afrovanguard organisation.\n"
                . "Read the raw meeting transcript and return STRICT JSON only — no prose, no markdown fences.\n"
                . "Schema: {\"summary\": string (3-5 sentence overview), \"highlights\": string[] (key discussion points), "
                . "\"decisions\": string[] (decisions made), \"action_items\": [{\"task\": string, \"owner\": string, \"due_days\": integer|null}] "
                . "(owner \"\" if unassigned; due_days is days from today if the transcript states a deadline, else null)}.\n"
                . "Keep it faithful to the transcript; do not invent facts, deadlines or owners.",
        ],

        'meeting.agenda' => [
            'pending' => 'agenda drafting',
            'label' => 'Meeting agenda proposal',
            'scope' => 'meetings',
            'rules' => true,
            'vars'  => ['title', 'previous_minutes', 'open_commitments', 'participants'],
            'text'  =>
                "You draft meeting agendas for the Afrovanguard organisation.\n"
                . "Propose an agenda for \"{{title}}\" from the material below. Return STRICT JSON only:\n"
                . "{\"items\": [{\"item\": string, \"why\": string, \"minutes\": integer}], \"note\": string}\n"
                . "Rules: carry forward anything unresolved from the previous minutes and any overdue commitment. "
                . "Order the agenda so decisions come before discussion. Keep the total within the meeting's scheduled length. "
                . "Do not invent business that is not evidenced below. This is a DRAFT for the chair to approve or edit.\n\n"
                . "PREVIOUS MINUTES:\n{{previous_minutes}}\n\nOPEN COMMITMENTS:\n{{open_commitments}}\n\nPARTICIPANTS:\n{{participants}}",
        ],

        'goal.tasks' => [
            'label' => 'Goal → task breakdown',
            'scope' => 'commitments',
            'rules' => true,
            'vars'  => ['max', 'today'],
            'text'  =>
                "You are an operations planner for Afrovanguard, a Pan-African nonprofit. You turn a team GOAL into a short list of "
                . "concrete, actionable tasks the team can divide up and complete.\n\n"
                . "Rules:\n"
                . "- Return BETWEEN 3 AND {{max}} tasks. Each must be a single, clearly-scoped action a member could pick up and finish — "
                . "start the title with a verb (e.g. \"Draft…\", \"Contact…\", \"Design…\").\n"
                . "- Order them in the sensible sequence to do the work.\n"
                . "- Set a realistic \"days\" value: the number of days FROM TODAY the task should be done by (spread them out; earlier tasks "
                . "get smaller numbers). Today is {{today}}.\n"
                . "- priority is one of: high, normal, low.\n"
                . "- Do NOT invent specific external facts, names, or figures. Keep titles under 120 characters.\n\n"
                . "Respond with ONLY a JSON array, no prose, no code fences. Shape:\n"
                . "[{\"title\":\"...\",\"priority\":\"high|normal|low\",\"days\":<integer 1-60>}]",
        ],

        'accountability.nudge' => [
            'label' => 'Accountability nudge / escalation wording',
            'scope' => 'mentorship',
            'rules' => true,
            'vars'  => ['step', 'subject', 'context'],
            'text'  =>
                "You write short accountability messages for Afrovanguard mentors and mentees.\n"
                . "This is escalation step {{step}}. Write ONE message to {{subject}} about the situation below.\n"
                . "Rules: two or three sentences. Name the specific thing that has not happened and the concrete next step. "
                . "Never shame, never threaten, never speculate about motive — the purpose is early intervention, not punishment. "
                . "Do not invent facts beyond the context. Return plain text, no JSON, no greeting boilerplate.\n\n"
                . "CONTEXT:\n{{context}}",
        ],

        'leadership.brief' => [
            'pending' => 'the leadership brief',
            'label' => 'Leadership weekly brief',
            'scope' => 'all',
            'rules' => true,
            'vars'  => ['period', 'metrics'],
            'text'  =>
                "You write the Afrovanguard leadership brief — an exception report, not a status dump.\n"
                . "Cover {{period}} using ONLY the figures supplied. Return STRICT JSON:\n"
                . "{\"headline\": string, \"critical\": string[], \"attention\": string[], \"opportunity\": string[], \"growth\": string[]}\n"
                . "Rules: lead with what needs a human decision this week. Every line must trace to a supplied figure — never estimate, "
                . "extrapolate or invent a number. Refer to people by the identifiers given. If a section has nothing, return an empty array.\n\n"
                . "FIGURES:\n{{metrics}}",
        ],

        'promotion.recommendation' => [
            'pending' => 'AI-assisted promotion review',
            'label' => 'Promotion recommendation',
            'scope' => 'levels',
            'rules' => true,
            'vars'  => ['member', 'evidence', 'target_level'],
            'text'  =>
                "You assess readiness for advancement in the Afrovanguard leadership ladder.\n"
                . "Consider whether {{member}} is ready for level {{target_level}}, using ONLY the evidence below. Return STRICT JSON:\n"
                . "{\"recommend\": boolean, \"confidence\": \"low\"|\"medium\"|\"high\", \"reasons\": string[], \"gaps\": string[]}\n"
                . "Rules: judge multiplication QUALITY, not headcount — a member with four thriving mentees outranks one with ten dormant "
                . "ones. Cite the evidence for every reason. If evidence is missing, say so in \"gaps\" and lower the confidence rather than "
                . "assuming. You are producing a recommendation for leadership to weigh, never a decision.",
        ],

        'session.minutes' => [
            'label' => 'Mentorship session minutes',
            'scope' => 'mentorship',
            'rules' => true,
            'vars'  => [],
            'text'  =>
                "You write the record of a one-to-one Afrovanguard mentorship session from its transcript.\n"
                . "This is not a committee meeting. Two people talked, probably about work, study, money, family and character, "
                . "and the record exists so the relationship has continuity — not so anyone can be assessed on it.\n\n"
                . "Return STRICT JSON only — no prose, no markdown fences:\n"
                . "{\"summary\": string (2-4 sentences), \"progress\": string[] (what has moved since last time), "
                . "\"obstacles\": string[] (what is in the way), \"commitments\": [{\"task\": string, \"owner\": \"mentor\"|\"mentee\", \"due_days\": integer|null}], "
                . "\"next_focus\": string}\n\n"
                . "Rules:\n"
                . "- Stay faithful to the transcript. Invent nothing — not a commitment, not a deadline, not a feeling.\n"
                . "- \"owner\" is whichever of the two took the task on. If the transcript does not say, leave the commitment out.\n"
                . "- due_days only when a timeframe was actually agreed; otherwise null.\n"
                . "- Record personal difficulty in \"obstacles\" ONLY as far as it explains what is blocking progress — "
                . "\"is job-hunting and short on time\", not the detail of anyone's circumstances. This record is read by "
                . "people who were not in the room.\n"
                . "- Never evaluate character, commitment or potential. You are writing minutes, not an assessment.\n"
                . "- If the transcript is too thin or too garbled to summarise honestly, say that in \"summary\" and return empty arrays.",
        ],

        'assistant.console' => [
            'label' => 'Studio assistant (tool-using)',
            'scope' => 'all',
            'rules' => true,
            'vars'  => [],
            'text'  =>
                "You are the Afrovanguard accountability assistant, talking to an administrator inside the Studio.\n\n"
                . "You have tools. USE THEM. The administrator can already read the dashboards — what they need from you is the "
                . "answer that requires looking several things up and putting them together. Never answer a factual question about a "
                . "member, a pairing, a rule or a prompt from memory or inference: call the tool and read the real record. "
                . "If a tool fails or returns nothing, say so plainly rather than filling the gap.\n\n"
                . "How to work:\n"
                . "- Look a member up by name first (member_lookup) to get their id, then use it.\n"
                . "- Before suggesting any threshold, read it (rules_read). A rule marked not-enforced changes nothing yet — say so "
                . "rather than recommending a value for it.\n"
                . "- Before proposing doctrine, search what is already taught (knowledge_search). Duplicated doctrine is worse than none.\n"
                . "- When you use the web, fetch the page before relying on it and cite the URL. A search snippet is not a source.\n\n"
                . "Proposing changes:\n"
                . "- propose_knowledge, propose_rule and propose_prompt FILE a proposal for a human to approve. They do not take effect. "
                . "Say so — never tell the administrator a change is live when you have only proposed it.\n"
                . "- Propose one thing at a time, with the evidence for it. A proposal without a reason cannot be judged, and will be rejected.\n"
                . "- Do not propose changes nobody asked for. If you notice something worth changing, say what you noticed and ask.\n\n"
                . "Style: direct and brief. Lead with the answer, then the evidence. Plain sentences, no bullet-point padding, "
                . "no restating the question. If something is uncertain, say which part and why.\n\n"
                . "Boundaries: you observe, analyse, remind and recommend. You do not decide, discipline or promote. "
                . "Never reduce a person to a score. Never repeat a member's stated reason for missing something — those are "
                . "confidential, and the tools deliberately do not give them to you.",
        ],

        'knowledge.distil' => [
            'label' => 'Distil a source into doctrine',
            'scope' => 'assistant',
            'rules' => false,
            'vars'  => ['url', 'title'],
            'text'  =>
                "You turn source material into a single doctrine entry for Afrovanguard's assistants to be taught.\n"
                . "The source is \"{{title}}\" at {{url}}.\n\n"
                . "Return STRICT JSON only:\n"
                . "{\"title\": string, \"body\": string, \"scope\": \"all\"|\"mentorship\"|\"meetings\"|\"levels\"|\"commitments\"|\"assistant\", "
                . "\"priority\": integer, \"worth_adding\": boolean, \"why\": string}\n\n"
                . "Rules:\n"
                . "- Write \"body\" as fact the assistant should know, in Afrovanguard's own voice — not as a summary of an article, "
                . "and not as advice addressed to a reader.\n"
                . "- Keep it under 200 words. A doctrine entry competes for room in every prompt; earn the space.\n"
                . "- Include nothing the source does not actually support.\n"
                . "- Set worth_adding to false when the material is thin, off-topic, promotional, or already common knowledge — "
                . "and explain that in \"why\". Declining is a useful answer, and much better than padding the knowledge base.\n"
                . "- priority: 80+ only for something foundational, 40-60 for useful context, below 30 for detail.",
        ],
    ];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_prompts (
                prompt_key VARCHAR(64) PRIMARY KEY,
                text TEXT NOT NULL DEFAULT '',
                updated_by VARCHAR(191) NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            );");
        } catch (Throwable $e) { error_log('[prompts] ensure: ' . $e->getMessage()); }
    }

    public static function keys(): array { return array_keys(self::DEFS); }

    public static function isKey(string $key): bool { return isset(self::DEFS[$key]); }

    /** @return array<string,string> key => override text */
    private static function overrides(): array
    {
        if (self::$memo !== null) return self::$memo;
        $out = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT prompt_key, text FROM av_prompts') as $r) {
                $out[(string) $r['prompt_key']] = (string) $r['text'];
            }
        } catch (Throwable $e) { error_log('[prompts] load: ' . $e->getMessage()); }
        return self::$memo = $out;
    }

    /** The raw template body — the Studio override if set, else the default. */
    public static function template(string $key): string
    {
        if (!isset(self::DEFS[$key])) return '';
        $ov = self::overrides();
        $t = trim((string) ($ov[$key] ?? ''));
        return $t !== '' ? $t : (string) self::DEFS[$key]['text'];
    }

    public static function defaultText(string $key): string
    {
        return (string) (self::DEFS[$key]['text'] ?? '');
    }

    /**
     * Render a template into a finished system prompt.
     *
     * Placeholders are replaced literally, and any {{var}} the caller did not
     * supply is stripped rather than left in the prompt — a model shown a raw
     * "{{open_commitments}}" will cheerfully hallucinate its contents.
     *
     * @param array<string,string> $vars
     */
    public static function render(string $key, array $vars = []): string
    {
        if (!isset(self::DEFS[$key])) return '';
        $def  = self::DEFS[$key];
        $text = self::template($key);

        foreach ($vars as $k => $v) {
            $text = str_replace('{{' . $k . '}}', (string) $v, $text);
        }
        // Strip unfilled placeholders (including ones an edited template invented).
        $text = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $text) ?? $text;

        if (!empty($def['rules']) && class_exists('AvRules')) {
            $text .= AvRules::asPromptBlock();
        }
        $scope = (string) ($def['scope'] ?? '');
        if ($scope !== '' && class_exists('AvKnowledge')) {
            $text .= AvKnowledge::asPromptBlock($scope);
        }
        return $text;
    }

    /** Which placeholders a template declares (for the Studio and for callers). */
    public static function vars(string $key): array
    {
        return (array) (self::DEFS[$key]['vars'] ?? []);
    }

    /**
     * Save an override. Passing text identical to the default (or empty) clears
     * the override instead of storing a duplicate, so "source: default" stays
     * meaningful in the Studio.
     *
     * @return array{ok:bool, error:string, cleared:bool}
     */
    /**
     * Check a proposed template without storing it.
     *
     * Split out of save() so a caller can find out whether text WOULD be accepted
     * — the AI's propose_prompt tool needs exactly that, and duplicating these
     * checks there would let the two drift apart.
     *
     * @return array{ok:bool, error:string, cleared:bool}
     */
    public static function validate(string $key, string $text): array
    {
        if (!isset(self::DEFS[$key])) return ['ok' => false, 'error' => 'Unknown prompt.', 'cleared' => false];
        $text = trim($text);

        // Empty (or identical to the default) is valid: it means "use the default".
        if ($text === '' || $text === trim(self::defaultText($key))) {
            return ['ok' => true, 'error' => '', 'cleared' => true];
        }
        if (mb_strlen($text) > 8000) {
            return ['ok' => false, 'error' => 'Too long (max 8000 characters).', 'cleared' => false];
        }
        // A template that drops a declared variable will silently produce a prompt
        // with no input — catch it here rather than at 3am in the cron.
        $missing = [];
        foreach (self::vars($key) as $v) {
            if (strpos($text, '{{' . $v . '}}') === false) $missing[] = $v;
        }
        if ($missing) {
            return ['ok' => false, 'cleared' => false,
                    'error' => 'This prompt must still use {{' . implode('}}, {{', $missing) . '}} — without it the AI receives no input to work from.'];
        }
        return ['ok' => true, 'error' => '', 'cleared' => false];
    }

    public static function save(string $key, string $text, string $actor = ''): array
    {
        $v = self::validate($key, $text);
        if (empty($v['ok'])) return $v;
        $text = trim($text);
        if (!empty($v['cleared'])) {
            return ['ok' => self::reset($key), 'error' => '', 'cleared' => true];
        }

        try {
            self::ensure();
            $db = Database::pdo();
            $now = gmdate('c');
            $up = $db->prepare('UPDATE av_prompts SET text = ?, updated_by = ?, updated_at = ? WHERE prompt_key = ?');
            $up->execute([$text, $actor, $now, $key]);
            if ($up->rowCount() === 0) {
                try {
                    $db->prepare('INSERT INTO av_prompts (prompt_key, text, updated_by, updated_at) VALUES (?,?,?,?)')
                       ->execute([$key, $text, $actor, $now]);
                } catch (Throwable $e) {
                    $up->execute([$text, $actor, $now, $key]);   // lost a race; row exists
                }
            }
            self::$memo = null;
            return ['ok' => true, 'error' => '', 'cleared' => false];
        } catch (Throwable $e) {
            error_log('[prompts] save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save.', 'cleared' => false];
        }
    }

    public static function reset(string $key): bool
    {
        if (!isset(self::DEFS[$key])) return false;
        try {
            self::ensure();
            Database::pdo()->prepare('DELETE FROM av_prompts WHERE prompt_key = ?')->execute([$key]);
            self::$memo = null;
            return true;
        } catch (Throwable $e) { error_log('[prompts] reset: ' . $e->getMessage()); return false; }
    }

    /** Every template with provenance, for the Studio editor. */
    /** One template's full picture — for the AI's prompt_read tool and the Studio. */
    public static function describeOne(string $key): array
    {
        foreach (self::describe() as $row) if ($row['key'] === $key) return $row;
        return [];
    }

    public static function describe(): array
    {
        $ov = self::overrides();
        $meta = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT prompt_key, updated_by, updated_at FROM av_prompts') as $r) {
                $meta[(string) $r['prompt_key']] = ['by' => (string) $r['updated_by'], 'at' => (string) $r['updated_at']];
            }
        } catch (Throwable $e) { /* no provenance */ }

        $out = [];
        foreach (self::DEFS as $key => $def) {
            $isOverridden = trim((string) ($ov[$key] ?? '')) !== '';
            $out[] = [
                'key'        => $key,
                'label'      => $def['label'],
                'scope'      => (string) ($def['scope'] ?? ''),
                'appends_rules' => !empty($def['rules']),
                'vars'       => (array) ($def['vars'] ?? []),
                // Named subsystem that will use this template, when nothing calls
                // it yet. Editing an unused prompt has no effect, and the Studio
                // says so rather than letting someone tune it for nothing.
                'pending'    => (string) ($def['pending'] ?? ''),
                'text'       => self::template($key),
                'default'    => (string) $def['text'],
                'source'     => $isOverridden ? 'studio' : 'default',
                'updated_by' => $meta[$key]['by'] ?? '',
                'updated_at' => $meta[$key]['at'] ?? '',
            ];
        }
        return $out;
    }
}
