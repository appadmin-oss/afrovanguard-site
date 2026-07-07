<?php
/**
 * lib/ContentGuard.php — submission integrity for user-generated text.
 *
 * One dependency-free analyzer used by every surface that accepts free text
 * (diary submissions, academy enrolment, contact form, …):
 *
 *   ContentGuard::analyze($text)        → scores + flags (never blocks)
 *   ContentGuard::gate($text, $opts)    → ok/error for inline enforcement
 *   ContentGuard::trap($input, $field)  → honeypot / timing bot check
 *
 * Scores (0–100):
 *   spam     — link stuffing, spam lexicon, contact-injection, repetition
 *   ai       — likelihood the text is AI-generated (formulaic phrasing, flat
 *              sentence rhythm, no contractions). HEURISTIC: used to FLAG for
 *              human review only — never to auto-reject, because false
 *              positives punish real people who simply write formally.
 *   realism  — does this read like real human prose at all (vowel balance,
 *              common-word rate, keyboard mash, repetition, lorem)?
 *
 * gate() policy: hard-block high spam and very low realism; enforce the
 * configured length caps; pass the AI score through as a flag.
 */
declare(strict_types=1);

final class ContentGuard
{
    /* ── Lexicons ── */
    private const SPAM_WORDS = [
        'viagra', 'cialis', 'casino', 'jackpot', 'lottery', 'win big', 'betting tips', 'fixed odds',
        'forex signal', 'binary option', 'crypto giveaway', 'airdrop', 'pump and dump', 'double your money',
        'investment plan', 'guaranteed returns', 'make money fast', 'work from home and earn', 'passive income daily',
        'loan offer', 'quick loan', 'recover your funds', 'recovery expert', 'hacker for hire', 'hack any',
        'ssd chemical', 'herbal cure', 'enlargement', 'escort', 'hookup', 'xxx', 'porn',
        'click here now', 'limited offer', 'act now', 'free money', 'promo code', 'referral bonus',
        'whatsapp me on', 'message me on telegram', 'dm me for', 'contact me for riches',
    ];
    private const AI_PHRASES = [
        'as an ai', 'as a language model', 'i cannot assist', 'delve into', 'delving into',
        'furthermore', 'moreover', 'in conclusion', 'in summary', 'to summarize',
        'it is important to note', "it's important to note", 'it is worth noting',
        "in today's fast-paced", 'in the realm of', 'in the ever-evolving', 'rich tapestry',
        'navigating the', 'landscape of', 'holistic approach', 'paramount importance',
        'plethora of', 'myriad of', 'embark on a journey', 'unlock the potential', 'unlock the power',
        'game-changer', 'dive deep into', "let's explore", 'comprehensive guide', 'key takeaways',
        'seamlessly integrate', 'robust solution', 'cutting-edge', 'in this article we',
    ];
    private const COMMON_WORDS = [
        'the','and','to','of','a','in','is','it','you','that','he','was','for','on','are','with','as','i','his','they',
        'be','at','one','have','this','from','or','had','by','but','not','what','all','were','we','when','your','can',
        'said','there','use','an','each','which','she','do','how','their','if','will','up','other','about','out','many',
        'then','them','these','so','some','her','would','make','like','him','into','time','has','look','two','more',
        'go','see','no','way','could','people','my','than','first','been','who','its','now','find','long','down','day',
        'did','get','come','made','may','part','our','me','am','us','very','just','also','because','want','need','help',
        // Nigerian everyday register
        'na','dey','wey','abeg','oga','sha','abi','wahala','oya',
    ];

    /** Full analysis. Never blocks — returns scores + flags + a moderator summary. */
    public static function analyze(string $text): array
    {
        $text  = trim($text);
        $lower = mb_strtolower($text);
        $len   = mb_strlen($text);
        $words = preg_split('/[^\p{L}\p{N}\']+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wc    = count($words);
        $flags = [];

        /* ── SPAM ─────────────────────────────────────────── */
        $spam = 0;
        $urls = preg_match_all('~https?://|www\.[a-z0-9]~i', $text);
        if ($urls > 1) { $spam += min(54, ($urls - 1) * 18); $flags[] = 'links×' . $urls; }
        $hits = 0;
        foreach (self::SPAM_WORDS as $w) { if (mb_strpos($lower, $w) !== false) $hits++; }
        if ($hits) { $spam += min(60, $hits * 15); $flags[] = 'spam-terms×' . $hits; }
        if (preg_match_all('/[\w.+\-]+@[\w\-]+\.[a-z]{2,}/i', $text) > 1) $spam += 10;
        if (preg_match_all('/\+?\d[\d\s\-]{8,}\d/', $text) > 1) $spam += 10;
        if ($wc >= 20) {
            $freq = array_count_values($words);
            arsort($freq);
            $top = (int) reset($freq);
            if ($top >= 6 && $top / $wc > 0.2) { $spam += 15; $flags[] = 'repetitive'; }
        }
        if (preg_match('/(.)\1{5,}/u', $text)) $spam += 10;
        if ($len > 40) {
            $letters = preg_replace('/[^\p{L}]/u', '', $text) ?: '';
            $upper   = preg_replace('/[^\p{Lu}]/u', '', $text) ?: '';
            if ($letters !== '' && mb_strlen($upper) / max(1, mb_strlen($letters)) > 0.5) { $spam += 10; $flags[] = 'shouting'; }
        }
        $spam = min(100, $spam);

        /* ── AI-LIKELIHOOD (flag only, never a verdict) ───── */
        $ai = 0;
        $phit = 0;
        foreach (self::AI_PHRASES as $p) { if (mb_strpos($lower, $p) !== false) $phit++; }
        if ($phit) $ai += min(55, $phit * 9);
        $sentences = preg_split('/[.!?]+[\s"\')\]]*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sLens = array_values(array_filter(array_map(fn($s) => count(preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []), $sentences)));
        if (count($sLens) >= 4) {
            $mean = array_sum($sLens) / count($sLens);
            $var  = 0.0; foreach ($sLens as $L) { $var += ($L - $mean) ** 2; }
            $cv   = $mean > 0 ? sqrt($var / count($sLens)) / $mean : 1.0;
            if ($cv < 0.35) $ai += 20; elseif ($cv < 0.5) $ai += 10;   // uncannily even rhythm
        }
        if ($len > 300 && !preg_match("/\b\w+'(t|s|re|ll|ve|d|m)\b/i", $text)) $ai += 10;  // zero contractions
        if (preg_match_all('/^\s*(?:[-*•]|\d+[.)])\s+/m', $text) >= 3) $ai += 8;           // listicle shape
        $ai = min(100, $ai);
        if ($ai >= 60) $flags[] = 'ai-likely';

        /* ── REALISM (100 = reads like real prose) ────────── */
        $realism = 100;
        $letters = preg_replace('/[^\p{L}]/u', '', $lower) ?: '';
        $lc = mb_strlen($letters);
        if ($lc > 12) {
            $vowels = preg_match_all('/[aeiouàèéíòóúáãõ]/u', $letters);
            $vr = $vowels / $lc;
            if ($vr < 0.22 || $vr > 0.62) { $realism -= 30; $flags[] = 'odd-letters'; }
        }
        if ($wc >= 8) {
            $common = 0;
            foreach ($words as $w) { if (in_array($w, self::COMMON_WORDS, true)) $common++; }
            $rate = $common / $wc;
            if ($rate < 0.08) { $realism -= 45; $flags[] = 'no-language'; }
            elseif ($rate < 0.15) { $realism -= 25; }
            $avg = array_sum(array_map('mb_strlen', $words)) / $wc;
            if ($avg > 12) $realism -= 20;
            $freq = array_count_values($words);
            arsort($freq);
            if ((int) reset($freq) / $wc > 0.3 && $wc > 20) $realism -= 20;
        }
        foreach (['asdf', 'qwer', 'zxcv', 'hjkl', 'qwerty', 'lorem ipsum'] as $mash) {
            if (mb_strpos($lower, $mash) !== false) { $realism -= ($mash === 'lorem ipsum' ? 60 : 25); $flags[] = 'mash'; break; }
        }
        $realism = max(0, min(100, $realism));

        return [
            'len' => $len, 'words' => $wc,
            'spam' => $spam, 'ai' => $ai, 'realism' => $realism,
            'flags' => array_values(array_unique($flags)),
            'summary' => "spam {$spam} · ai {$ai} · realism {$realism}" . ($flags ? ' · ' . implode(', ', array_unique($flags)) : ''),
        ];
    }

    /**
     * Inline enforcement for a submission field.
     * $opts: label ('message'), min (0), max (5000), spam_block (65),
     *        realism_block (30), allow_links (1).
     * @return array{ok:bool,error:string,analysis:array}
     */
    public static function gate(string $text, array $opts = []): array
    {
        $label = (string) ($opts['label'] ?? 'message');
        $min   = (int) ($opts['min'] ?? 0);
        $max   = (int) ($opts['max'] ?? 5000);
        $a     = self::analyze($text);

        if ($a['len'] < $min) {
            return ['ok' => false, 'error' => 'Your ' . $label . ' is a bit short — please write at least ' . $min . ' characters.', 'analysis' => $a];
        }
        if ($a['len'] > $max) {
            return ['ok' => false, 'error' => 'Your ' . $label . ' is too long — the maximum is ' . number_format($max) . ' characters (yours is ' . number_format($a['len']) . ').', 'analysis' => $a];
        }
        if ($a['spam'] >= (int) ($opts['spam_block'] ?? 65)) {
            return ['ok' => false, 'error' => 'Your ' . $label . ' looks like spam to our filters. Please remove promotional links or wording and try again.', 'analysis' => $a];
        }
        if ($a['words'] >= 6 && $a['realism'] <= (int) ($opts['realism_block'] ?? 30)) {
            return ['ok' => false, 'error' => 'We couldn’t read that as a real ' . $label . ' — please write it in plain words.', 'analysis' => $a];
        }
        return ['ok' => true, 'error' => '', 'analysis' => $a];
    }

    /**
     * Bot trap for public forms: a honeypot field real people never fill
     * (render it visually hidden), plus an optional minimum-seconds check
     * against a form-render timestamp. TRUE = this is a bot; drop silently.
     */
    public static function trap(array $input, string $honeypot = 'website', int $minSeconds = 3): bool
    {
        if (trim((string) ($input[$honeypot] ?? '')) !== '') return true;
        $ts = (int) ($input['form_ts'] ?? 0);
        if ($ts > 1000000000 && (time() - $ts) < $minSeconds) return true;
        return false;
    }
}
