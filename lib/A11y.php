<?php
/**
 * lib/A11y.php — the visitor's own display settings (the "Accessibility" panel).
 *
 * These are conveniences on top of pages that already aim at WCAG 2.2 AA. They
 * change how this site looks for one person; they do not make any page
 * "compliant", and nothing here says they do. The visitor's own device —
 * screen reader, magnifier, OS contrast, zoom, reduce motion — keeps working.
 *
 * Where a setting lives:
 *   · the cookie av_a11y (and localStorage av.a11y, written together by
 *     assets/site/a11y.js) — read here so <html> carries the classes before the
 *     first paint, and so they apply with JavaScript off;
 *   · for a signed-in member, also their account (Prefs 'a11y', written by
 *     portal/prefs.php), so the settings follow them to another device.
 *
 * The value is a few allow-listed words joined by dots, e.g. "t150.spacing.hc-auto";
 * "0" means "standard, on purpose". Anything not on the list is dropped; a value
 * that is not plainly those characters is ignored whole.
 *
 * Styles: assets/site/a11y.css · behaviour: assets/site/a11y.js · Listen: assets/site/listen.js
 */
declare(strict_types=1);

final class A11y
{
    public const COOKIE = 'av_a11y';
    public const PREF   = 'a11y';

    /** Text sizes offered (percent). 100 is the default and has no class. */
    public const TEXT = [100, 125, 150, 175, 200];
    /** Contrast choices: '' (the site's own), or high contrast light, dark, or matching the device. */
    public const CONTRAST = ['', 'hc-auto', 'hc-light', 'hc-dark'];
    /** On/off settings. */
    public const FLAGS = ['spacing', 'font', 'links', 'focus', 'still', 'targets'];

    /** The boot script, inline in every <head> before any stylesheet: the stored
     *  setting onto <html> before first paint. The cookie wins (the server read the
     *  same one); otherwise localStorage, which then restores the cookie. Classes
     *  the server put there are replaced, so a cached page never shows somebody
     *  else's. Same allow-list as parse(). */
    public const BOOT = "<script>(function(d){var v=null,m=document.cookie.match(/(?:^|; )av_a11y=([a-z0-9.-]{1,80})(?:;|$)/);if(m){v=m[1]}else{try{v=localStorage.getItem('av.a11y')}catch(e){}if(v&&/^[a-z0-9.-]{1,80}$/.test(v)){document.cookie='av_a11y='+v+';path=/;max-age=31536000;samesite=lax'}}var c=d.className.replace(/(^|\\s)a11y-\\S+/g,'');String(v||'').split('.').forEach(function(t){if(/^(t125|t150|t175|t200|spacing|font|links|focus|still|targets|hc-auto|hc-light|hc-dark)$/.test(t))c+=' a11y-'+t+(t.indexOf('hc-')?'':' a11y-hc')});d.className=c.replace(/^\\s+/,'')})(document.documentElement);</script>";

    private static bool $headDone = false;
    private static ?array $memo = null;

    /**
     * A raw cookie/pref value → the settings it allows.
     * @return array{text:int, contrast:string, flags:list<string>}
     */
    public static function parse(?string $raw): array
    {
        $out = ['text' => 100, 'contrast' => '', 'flags' => []];
        $raw = (string) $raw;
        if ($raw === '' || strlen($raw) > 80 || !preg_match('/^[a-z0-9.-]+$/', $raw)) return $out;
        foreach (explode('.', $raw) as $t) {
            if (preg_match('/^t(\d{3})$/', $t, $m) && in_array((int) $m[1], self::TEXT, true)) $out['text'] = (int) $m[1];
            elseif (in_array($t, self::CONTRAST, true) && $t !== '') $out['contrast'] = $t;
            elseif (in_array($t, self::FLAGS, true) && !in_array($t, $out['flags'], true)) $out['flags'][] = $t;
        }
        // A fixed order, so the same settings always make the same value and classes.
        $out['flags'] = array_values(array_intersect(self::FLAGS, $out['flags']));
        return $out;
    }

    /** Settings → the stored value ("0" when everything is standard). */
    public static function serialize(array $s): string
    {
        $t = [];
        if (in_array((int) ($s['text'] ?? 100), self::TEXT, true) && (int) $s['text'] !== 100) $t[] = 't' . (int) $s['text'];
        foreach (self::FLAGS as $f) if (in_array($f, (array) ($s['flags'] ?? []), true)) $t[] = $f;
        if (in_array((string) ($s['contrast'] ?? ''), self::CONTRAST, true) && (string) $s['contrast'] !== '') $t[] = (string) $s['contrast'];
        return $t ? implode('.', $t) : '0';
    }

    /** Settings → the classes on <html>, e.g. "a11y-t150 a11y-spacing a11y-hc-auto a11y-hc". */
    public static function classes(array $s): string
    {
        $c = [];
        if ((int) $s['text'] !== 100) $c[] = 'a11y-t' . (int) $s['text'];
        foreach ($s['flags'] as $f) $c[] = 'a11y-' . $f;
        if ($s['contrast'] !== '') { $c[] = 'a11y-' . $s['contrast']; $c[] = 'a11y-hc'; }
        return implode(' ', $c);
    }

    /**
     * This request's settings: the cookie, else — for a signed-in member with no
     * cookie on this device yet — their account, which is then written to the
     * cookie so the account is read once per device, not on every page.
     */
    public static function current(): array
    {
        if (self::$memo !== null) return self::$memo;
        $raw = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : null;
        if ($raw === null && !empty($_COOKIE['av_lms']) && class_exists('LmsAuth') && class_exists('Prefs')) {
            try {
                $u = LmsAuth::user();
                if ($u) {
                    $raw = self::valueOf(Prefs::get((int) $u['id'], self::PREF, ''));
                    self::setCookie($raw === '' ? '0' : $raw);
                }
            } catch (Throwable $e) { $raw = null; }
        }
        return self::$memo = self::parse($raw);
    }

    /** The value part of a stored account pref ("t150.spacing@1732450000" → "t150.spacing"). */
    public static function valueOf(string $pref): string
    {
        $v = self::serialize(self::parse(explode('@', $pref, 2)[0]));
        return $v === '0' ? '' : $v;
    }

    /** When the account pref was last changed (unix seconds), 0 if never. */
    public static function stampOf(string $pref): int
    {
        $p = explode('@', $pref, 2);
        return isset($p[1]) && ctype_digit($p[1]) ? (int) $p[1] : 0;
    }

    public static function setCookie(string $value): void
    {
        $value = self::serialize(self::parse($value));
        $_COOKIE[self::COOKIE] = $value;
        if (headers_sent()) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Readable by the page script on purpose (it keeps localStorage in step); it holds nothing private.
        setcookie(self::COOKIE, $value, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax']);
    }

    /**
     * The class attribute for <html>: the page's own classes plus the settings.
     * Returns ' class="…"' (escaped), or '' when there is nothing to say.
     */
    public static function htmlClass(string $existing = ''): string
    {
        $all = trim($existing . ' ' . self::classes(self::current()));
        return $all === '' ? '' : ' class="' . htmlspecialchars($all, ENT_QUOTES) . '"';
    }

    /** The <head> part: the boot script, the stylesheet and the script. Once per page. */
    public static function head(): string
    {
        if (self::$headDone) return '';
        self::$headDone = true;
        return self::BOOT . "\n"
            . '  <link href="' . htmlspecialchars(av_asset('/assets/site/a11y.css'), ENT_QUOTES) . '" rel="stylesheet" />' . "\n"
            . '  <script src="' . htmlspecialchars(av_asset('/assets/site/a11y.js'), ENT_QUOTES) . '" defer></script>' . "\n";
    }

    /** Whether head() has run on this page (avh_nav() adds it to a page whose own head did not). */
    public static function headDone(): bool { return self::$headDone; }

    /** For the test suite: draw a fresh page. */
    public static function resetForTest(): void { self::$headDone = false; self::$memo = null; }

    /**
     * The header control. An <a> to the settings on the statement page, so it
     * works with JavaScript off; a11y.js turns it into a <button> that opens the
     * dialog. Icon (a person in a circle) plus the word, always.
     */
    public static function button(string $extraClass = ''): string
    {
        return '<a class="av-a11y-btn' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '') . '" href="/accessibility/#settings" data-a11y-open>'
            . '<svg class="av-a11y-ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10.25" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="6.9" r="1.6" fill="currentColor"/><path d="M6.8 9.6l5.2 1.1 5.2-1.1M12 10.7v3.4m0 0l-2.6 4.6M12 14.1l2.6 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '<span class="av-a11y-btn-t">Accessibility</span></a>';
    }

    /** The footer link (every footer). Opens the same dialog; without JavaScript, the settings on the statement page. */
    public static function footerLink(): string
    {
        return '<a href="/accessibility/#settings" data-a11y-open data-a11y-link>Accessibility settings</a>';
    }

    /**
     * The Listen control for a page's main text (assets/site/listen.js). Hidden
     * until the script finds the browser can speak; never starts by itself.
     * $target is a CSS selector for the content to read.
     */
    public static function listen(string $target, string $label = 'Listen to this page'): string
    {
        return '<div class="av-listen" data-av-listen="' . htmlspecialchars($target, ENT_QUOTES) . '" data-label="' . htmlspecialchars($label, ENT_QUOTES) . '" hidden></div>'
            . "\n" . '<script src="' . htmlspecialchars(av_asset('/assets/site/listen.js'), ENT_QUOTES) . '" defer></script>';
    }
}
