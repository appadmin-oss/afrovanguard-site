<?php
/**
 * lib/SiteSettings.php — the details every page shares, edited once in the
 * Studio (Content → Site → Site details): the contact email and place in the
 * footer, the social links, the copyright line.
 *
 * The defaults ARE today's footer, so a site where nobody has edited them
 * renders byte-identically (tests/avhchrome.test.php holds index.html, the
 * partial and every static page together). PHP pages read the live values as
 * they render; static pages get the new footer written into them when the
 * details are published (Cms::publish → SiteSettings::apply).
 */
declare(strict_types=1);

final class SiteSettings
{
    /** network => [label, icon slug]. The order is the footer's. */
    public const SOCIALS = [
        'instagram' => ['Instagram', 'instagram'],
        'x'         => ['X', 'x'],
        'facebook'  => ['Facebook', 'facebook'],
        'linkedin'  => ['LinkedIn', 'linkedin'],
        'youtube'   => ['YouTube', 'youtube'],
    ];

    public static function defaults(): array
    {
        return [
            'email'     => 'cacentre@afrovanguard.org.ng',
            'place'     => 'Alimosho, Lagos',
            'country'   => 'Nigeria',
            'copyright' => 'Afrovanguard © 2017–2026',
            'social'    => [
                'instagram' => 'https://www.instagram.com/afrovanguard/',
                'x'         => 'https://twitter.com/afrovanguard',
                'facebook'  => 'https://www.facebook.com/afrovanguard/',
                'linkedin'  => 'https://www.linkedin.com/company/afrovanguard/',
                'youtube'   => 'https://www.youtube.com/@afrovanguard',
            ],
        ];
    }

    private static ?array $live = null;
    /** The site root publishing writes into. Overridable for tests. */
    public static ?string $root = null;

    /** What the site shows now. Without a database (a CLI build), the defaults. */
    public static function live(): array
    {
        if (self::$live !== null) return self::$live;
        $v = null;
        try { if (class_exists('Cms', false) && class_exists('Database', false)) $v = Cms::liveOf('site'); } catch (Throwable $e) { $v = null; }
        return self::$live = is_array($v) ? array_replace_recursive(self::defaults(), $v) : self::defaults();
    }

    public static function forget(): void { self::$live = null; }

    /** The homepage as it would look with $data (the Studio's preview). Nothing is written. */
    public static function previewHtml(array $data, string $html): string
    {
        require_once AV_ROOT . '/partials/avh-chrome.php';
        $old = avh_chrome_html()['foot'];
        $keep = self::$live;
        self::$live = array_replace_recursive(self::defaults(), $data);
        $new = avh_chrome_html()['foot'];
        self::$live = $keep;
        return str_replace($old, $new, $html);
    }

    /** @return array{ok:bool, data?:array, error?:string} */
    public static function clean(array $in): array
    {
        $d = self::defaults();
        $t = static fn($v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), 0, $max);
        $out = [
            'email'     => $t($in['email'] ?? $d['email'], 190),
            'place'     => $t($in['place'] ?? $d['place'], 80),
            'country'   => $t($in['country'] ?? $d['country'], 60),
            'copyright' => $t($in['copyright'] ?? $d['copyright'], 120),
            'social'    => [],
        ];
        if (!filter_var($out['email'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'The contact email does not look like an email address.'];
        if ($out['place'] === '') return ['ok' => false, 'error' => 'Say where Afrovanguard is (it shows in every footer).'];
        if ($out['copyright'] === '') return ['ok' => false, 'error' => 'The copyright line cannot be empty.'];
        foreach (self::SOCIALS as $k => [$label]) {
            $u = trim((string) ($in['social'][$k] ?? ''));
            if ($u !== '' && !(preg_match('#^https://[^\s"<>\\\\]+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL))) {
                return ['ok' => false, 'error' => 'The ' . $label . ' link must be a full https:// address, or left empty to hide it.'];
            }
            $out['social'][$k] = $u;
        }
        return ['ok' => true, 'data' => $out];
    }

    /**
     * Publish: every static page with the shared footer gets the new one, and
     * index.html (which carries the footer without markers) has the old footer
     * swapped for the new. PHP pages need nothing: they read live() as they render.
     */
    public static function apply(array $data, ?string $root = null): array
    {
        $root ??= self::$root ?? AV_ROOT;
        require_once AV_ROOT . '/partials/avh-chrome.php';
        $old = avh_chrome_html()['foot'];
        self::$live = array_replace_recursive(self::defaults(), $data);
        $new = avh_chrome_html()['foot'];
        if ($old === $new) return ['ok' => true, 'pages' => 0];
        $n = 0; $failed = [];
        foreach (avh_chrome_pages($root) as $path) {
            $html = (string) file_get_contents($path);
            $out = str_contains($html, '<!-- avh:foot -->')
                ? avh_chrome_splice($html, ['foot' => $new])
                : (str_contains($html, $old) ? str_replace($old, $new, $html) : $html);
            if ($out === $html) continue;
            $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $out, LOCK_EX) === false || !@rename($tmp, $path)) { @unlink($tmp); $failed[] = substr($path, strlen($root) + 1); continue; }
            $n++;
        }
        if ($failed) return ['ok' => false, 'error' => 'The footer could not be written into: ' . implode(', ', array_slice($failed, 0, 5)) . '. In cPanel → File Manager, set those files to 644, then publish again.', 'pages' => $n];
        return ['ok' => true, 'pages' => $n];
    }
}
