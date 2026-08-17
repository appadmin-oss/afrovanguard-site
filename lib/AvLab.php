<?php
/**
 * lib/AvLab.php — a bench for exercising everything the AI can do.
 *
 * Until now the only way to find out whether a prompt edit helped was to wait
 * for a real meeting to end and read the minutes it produced. That is a slow,
 * expensive and destructive feedback loop: you learn a prompt is wrong from the
 * record of a conversation that already happened.
 *
 * This runs each capability on demand, with sample input or real input, and
 * returns three things the Studio shows side by side:
 *
 *   • the SYSTEM PROMPT that was actually rendered — including the live rules
 *     and doctrine blocks, so a rule change is visibly reaching the model;
 *   • the raw model OUTPUT;
 *   • the PARSED result, so a template that produces unparseable JSON is
 *     obvious rather than silently degrading a feature at 3am.
 *
 * Nothing here writes. `goal.tasks` in particular renders and calls the model
 * without creating tasks, because a test that files real work into somebody's
 * queue is not a test. The one exception is deliberate and declared: the chat
 * assistant can file proposals, which is the whole point of it, and those go to
 * the approval queue like any other.
 */
declare(strict_types=1);

final class AvLab
{
    /** Sample inputs, so a capability can be tried with one click. */
    private const SAMPLES = [
        'meeting.minutes' => "Ada: Thanks everyone for joining. First item is the Lagos community centre rollout.\n"
            . "Bode: The venue confirmed for the 14th. I still need the consent forms from the parents.\n"
            . "Ada: How many outstanding?\n"
            . "Bode: About thirty. I'll chase them this week — should have them all by Friday.\n"
            . "Chidi: I can help with the calls if you send me half the list.\n"
            . "Bode: Great, I'll send them tonight.\n"
            . "Ada: Second item — we agreed last time to move the mentor training online. Chidi, where did that land?\n"
            . "Chidi: Done. Recorded and uploaded. Anyone can watch it now.\n"
            . "Ada: Good. So decision stands: training is online-first from now on.\n"
            . "Ada: Last thing, the budget review. I'll take that to the board on the 20th.",

        'session.minutes' => "Mentor: How did the month go?\n"
            . "Mentee: Mixed. I finished the data analysis course — got the certificate last week.\n"
            . "Mentor: That's the one you started in March? Well done, that's a real thing to have finished.\n"
            . "Mentee: Thanks. The job applications haven't gone anywhere though. Eleven sent, two replies.\n"
            . "Mentor: What are you leading with in the applications?\n"
            . "Mentee: Just the CV, mostly.\n"
            . "Mentor: Try a short covering note that names the specific problem you'd solve for them. Can you rework three that way before we next meet?\n"
            . "Mentee: Yes, I can do three.\n"
            . "Mentee: One thing — I've picked up evening shifts, so time is tight. Not sure I can keep up the reading.\n"
            . "Mentor: Then drop the reading for now, keep the applications. We'll pick it back up when the shifts ease.\n"
            . "Mentor: And I'll introduce you to Nneka, she hires for exactly this kind of role. I'll send that this week.",

        'goal.tasks' => 'Run a mentor training weekend for 40 new mentors before the end of the quarter',

        'assistant.console' => 'Which mentorship pairings have gone quiet, and what do the rules say should happen about it?',

        'knowledge.distil' => 'https://en.wikipedia.org/wiki/Mentorship',
    ];

    /**
     * The capabilities the bench can run, with what each needs.
     *
     * `needs` drives the Studio form: 'text' is a big input, 'url' a single line,
     * 'question' a single line, 'none' means just press go.
     */
    public static function capabilities(): array
    {
        $out = [
            [
                'key' => 'meeting.minutes', 'label' => 'Meeting minutes',
                'needs' => 'text', 'placeholder' => 'Paste a meeting transcript…',
                'about' => 'Turns a transcript into summary, highlights, decisions and action items. This is what runs after every recorded meeting.',
            ],
            [
                'key' => 'session.minutes', 'label' => 'Mentorship session minutes',
                'needs' => 'text', 'placeholder' => 'Paste a mentorship session transcript…',
                'about' => 'Progress, obstacles, commitments and next focus for a one-to-one session. Deliberately a record, not an assessment.',
            ],
            [
                'key' => 'goal.tasks', 'label' => 'Goal → tasks',
                'needs' => 'text', 'placeholder' => 'Describe a team goal…',
                'about' => 'Breaks a goal into tasks with deadlines. Running it here does NOT create any tasks.',
            ],
            [
                'key' => 'assistant.console', 'label' => 'Studio assistant (with tools)',
                'needs' => 'question', 'placeholder' => 'Ask about a member, a pairing, the rules…',
                'about' => 'The tool-using assistant. Shows every tool it called and what came back, so you can see what it actually consulted.',
            ],
            [
                'key' => 'knowledge.distil', 'label' => 'Distil a web page into doctrine',
                'needs' => 'url', 'placeholder' => 'https://…',
                'about' => 'Fetches a page and drafts a doctrine entry from it. Drafts only — nothing is added until you approve it.',
            ],
            [
                'key' => 'tool', 'label' => 'Run a single tool',
                'needs' => 'tool', 'placeholder' => '',
                'about' => 'Call one tool directly with your own arguments, to see exactly what the AI sees.',
            ],
        ];
        foreach ($out as &$c) {
            $c['sample'] = (string) (self::SAMPLES[$c['key']] ?? '');
            $c['ready']  = self::readiness($c['key']);
        }
        return $out;
    }

    /** Why a capability cannot run right now ('' when it can). */
    private static function readiness(string $key): string
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return 'AI is switched off (ai.enabled).';
        if ($key === 'tool') return '';
        $haveAi = (class_exists('Gemini') && Gemini::configured()) || (class_exists('AvBot') && AvBot::configured());
        if (!$haveAi) return 'No AI provider configured (set AV_GEMINI_API_KEY or ANTHROPIC_API_KEY).';
        if ($key === 'assistant.console' && !AvAgent::available()) return 'No provider available for tool use.';
        if ($key === 'knowledge.distil' && (!class_exists('AvWeb') || !AvWeb::available('web_fetch'))) {
            return 'Web fetching unavailable: ' . AvWeb::whyUnavailable('web_fetch') . '.';
        }
        return '';
    }

    /**
     * Run one capability.
     *
     * @return array{ok:bool,capability:string,prompt:string,output:string,parsed:mixed,
     *               steps:array,provider:string,ms:int,error:?string}
     */
    public static function run(string $key, array $in, string $actor = 'admin'): array
    {
        $t0 = microtime(true);
        $out = [
            'ok' => false, 'capability' => $key, 'prompt' => '', 'output' => '',
            'parsed' => null, 'steps' => [], 'provider' => '', 'ms' => 0, 'error' => null,
        ];
        $why = self::readiness($key);
        if ($why !== '') { $out['error'] = $why; return $out; }

        try {
            switch ($key) {
                case 'meeting.minutes':   $out = array_merge($out, self::meetingMinutes((string) ($in['text'] ?? ''))); break;
                case 'session.minutes':   $out = array_merge($out, self::sessionMinutes((string) ($in['text'] ?? ''))); break;
                case 'goal.tasks':        $out = array_merge($out, self::goalTasks((string) ($in['text'] ?? ''))); break;
                case 'assistant.console': $out = array_merge($out, self::assistant((string) ($in['text'] ?? ''), $actor)); break;
                case 'knowledge.distil':  $out = array_merge($out, self::distil((string) ($in['text'] ?? ''))); break;
                case 'tool':              $out = array_merge($out, self::tool((string) ($in['tool'] ?? ''), (array) ($in['args'] ?? []), $actor)); break;
                default:                  $out['error'] = 'Unknown capability.';
            }
        } catch (Throwable $e) {
            error_log('[lab] ' . $key . ': ' . $e->getMessage());
            $out['error'] = 'The run failed: ' . $e->getMessage();
        }
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        $out['capability'] = $key;
        return $out;
    }

    /* ── capability runners ───────────────────────────────────────── */

    private static function meetingMinutes(string $text): array
    {
        $text = trim($text) !== '' ? $text : self::SAMPLES['meeting.minutes'];
        $prompt = class_exists('AvPrompts') ? AvPrompts::render('meeting.minutes', []) : '';
        $res = Meetings::structure($text);
        return [
            'ok'     => !empty($res['ok']),
            'prompt' => $prompt,
            'parsed' => $res,
            'output' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            'error'  => empty($res['ok']) ? (string) ($res['error'] ?? 'Failed.') : null,
        ];
    }

    private static function sessionMinutes(string $text): array
    {
        $text = trim($text) !== '' ? $text : self::SAMPLES['session.minutes'];
        $prompt = class_exists('AvPrompts') ? AvPrompts::render('session.minutes', []) : '';
        $res = Mentorship::structureSession($text);
        return [
            'ok'     => !empty($res['ok']),
            'prompt' => $prompt,
            'parsed' => $res,
            'output' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            'error'  => empty($res['ok']) ? (string) ($res['error'] ?? 'Failed.') : null,
        ];
    }

    /**
     * The planner, WITHOUT creating anything.
     *
     * Collab::aiTasksFromGoal() writes real tasks into the pool, which is right
     * in the portal and wrong on a test bench — so the prompt is rendered and the
     * model called directly here.
     */
    private static function goalTasks(string $goal): array
    {
        $goal = trim($goal) !== '' ? $goal : self::SAMPLES['goal.tasks'];
        $max  = 6;
        $prompt = class_exists('AvPrompts')
            ? AvPrompts::render('goal.tasks', ['max' => $max, 'today' => gmdate('Y-m-d')])
            : '';
        $res = self::complete($prompt, 'GOAL: ' . $goal . "\n\nBreak this goal into tasks now.");
        if (empty($res['ok'])) return ['ok' => false, 'prompt' => $prompt, 'error' => (string) ($res['error'] ?? 'Failed.')];

        $txt = (string) $res['text'];
        $parsed = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $txt) ?? $txt), true);
        return [
            'ok'       => true,
            'prompt'   => $prompt,
            'output'   => $txt,
            'parsed'   => $parsed,
            'provider' => (string) ($res['via'] ?? ''),
            'error'    => is_array($parsed) ? null : 'The model replied, but the result was not parseable JSON — the template may need tightening.',
        ];
    }

    private static function assistant(string $question, string $actor): array
    {
        $question = trim($question) !== '' ? $question : self::SAMPLES['assistant.console'];
        $r = AvAgent::run($question, [
            'tiers' => AvAgent::tiersFor('admin'),
            'actor' => $actor,
        ]);
        return [
            'ok'       => !empty($r['ok']),
            'prompt'   => AvAgent::defaultSystem(),
            'output'   => (string) $r['text'],
            'parsed'   => null,
            'steps'    => (array) $r['steps'],
            'provider' => (string) $r['provider'],
            'error'    => $r['error'] ?? null,
        ];
    }

    /** Fetch a page and draft a doctrine entry from it. Drafts only. */
    private static function distil(string $url): array
    {
        $url = trim($url) !== '' ? $url : self::SAMPLES['knowledge.distil'];
        $page = AvWeb::fetch($url);
        if (isset($page['error'])) return ['ok' => false, 'error' => (string) $page['error']];

        $prompt = AvPrompts::render('knowledge.distil', [
            'url'   => (string) $page['url'],
            'title' => (string) ($page['title'] ?: $url),
        ]);
        $res = self::complete($prompt, "SOURCE TEXT:\n\n" . mb_substr((string) $page['text'], 0, 12000));
        if (empty($res['ok'])) return ['ok' => false, 'prompt' => $prompt, 'error' => (string) ($res['error'] ?? 'Failed.')];

        $txt = (string) $res['text'];
        $parsed = self::json($txt);
        return [
            'ok'       => true,
            'prompt'   => $prompt,
            'output'   => $txt,
            'parsed'   => $parsed,
            'provider' => (string) ($res['via'] ?? ''),
            'steps'    => [['tool' => 'web_fetch', 'args' => ['url' => $url], 'ok' => true, 'error' => '',
                            'preview' => mb_substr((string) $page['title'], 0, 200)]],
            'error'    => is_array($parsed) ? null : 'The model replied, but the result was not parseable JSON.',
        ];
    }

    /** Call one tool directly, exactly as the model would see it. */
    private static function tool(string $name, array $args, string $actor): array
    {
        if (!class_exists('AvTools') || !AvTools::defined($name)) {
            return ['ok' => false, 'error' => 'Unknown tool.'];
        }
        $tiers = AvAgent::tiersFor('admin');
        $res = AvTools::run($name, $args, ['actor' => $actor, 'tiers' => $tiers]);
        return [
            'ok'     => !isset($res['error']),
            'parsed' => $res,
            'output' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            'steps'  => [['tool' => $name, 'args' => $args, 'ok' => !isset($res['error']),
                          'error' => (string) ($res['error'] ?? ''), 'preview' => '']],
            'error'  => isset($res['error']) ? (string) $res['error'] : null,
        ];
    }

    /* ── shared ───────────────────────────────────────────────────── */

    /** One-shot completion on whichever provider is configured. */
    private static function complete(string $system, string $user): array
    {
        if (class_exists('Gemini') && Gemini::configured()) {
            $r = Gemini::generate($user, ['system' => $system, 'max_tokens' => 2048, 'temperature' => 0.2]);
            if (!empty($r['ok'])) return ['ok' => true, 'text' => (string) $r['text'], 'via' => 'gemini'];
            $err = (string) ($r['error'] ?? '');
        }
        if (class_exists('AvBot') && AvBot::configured()) {
            $r = AvBot::reply(mb_substr($user, 0, 11000), [], ['system' => $system, 'max_tokens' => 1500]);
            if (!empty($r['ok'])) return ['ok' => true, 'text' => (string) $r['text'], 'via' => 'anthropic'];
            $err = (string) ($r['error'] ?? ($err ?? ''));
        }
        return ['ok' => false, 'error' => $err ?? 'No AI provider is configured.'];
    }

    private static function json(string $s)
    {
        $s = trim(preg_replace('/^```(?:json)?|```$/m', '', $s) ?? $s);
        $a = strpos($s, '{'); $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        return json_decode(substr($s, $a, $b - $a + 1), true);
    }
}
