<?php
/**
 * lib/CardBundle.php — Afrovanguard member cards, handed to NGG's ID Card Studio.
 *
 * NextGen Genius prints cards in its ID Card Studio: sheets of card stock, a
 * print log, a reprint queue. Afrovanguard cards are printed there too (owner,
 * 2026-10-09), but the card stays ONE renderer — partials/id-card.php. The
 * studio receives that partial's markup and its stylesheet, self-contained
 * (fonts, seal, pattern and photos inlined), lays it out in an isolated
 * same-origin iframe at the design's 10px/mm and rasterises it with snapDOM.
 * Nothing is drawn twice, so the studio's card cannot drift from this one.
 *
 *   list    who has a card: id, name, printed ID, level, kind, photo, version
 *   style   the card's CSS, every asset inlined — once per design version
 *   faces   front and back markup for up to 24 members, photos inlined
 *
 * Served by integrations/ngg-cards.php to a request NGG's server signs.
 * Nothing on a card is money, discipline, medical data or a phone number, and
 * nothing here adds any: the same IdCard::forMember() the portal uses.
 */
declare(strict_types=1);

require_once __DIR__ . '/IdCard.php';

final class CardBundle
{
    public const FACES_MAX = 24;

    /** The faces the card uses, and nothing else (the bundle is sent whole). */
    private const FONTS = [
        ['Cormorant Garamond', 'normal', 600, 'CormorantGaramond-600.ttf'],
        ['Cormorant Garamond', 'italic', 500, 'CormorantGaramond-500-italic.ttf'],
        ['Source Sans 3', 'normal', 300, 'SourceSans3-300.ttf'],
        ['Source Sans 3', 'normal', 400, 'SourceSans3-400.ttf'],
        ['Source Sans 3', 'normal', 600, 'SourceSans3-600.ttf'],
        ['Source Sans 3', 'normal', 700, 'SourceSans3-700.ttf'],
        ['Source Sans 3', 'normal', 800, 'SourceSans3-800.ttf'],
    ];

    /** What changes the drawing: the card's CSS, partial, tokens and fonts. */
    public static function designVersion(): string
    {
        $h = hash_init('sha256');
        foreach (['assets/site/avc-card.css', 'assets/site/av-tokens.css', 'partials/id-card.php', 'assets/site/av-pattern-shapes.svg', 'assets/site/av-seal.png'] as $f) {
            hash_update($h, (string) @file_get_contents(self::root() . '/' . $f));
        }
        return IdCard::DESIGN_VERSION . '-' . substr(hash_final($h), 0, 12);
    }

    /**
     * Everybody with a live card.
     *
     * @return list<array{id:string,name:string,printedId:string,number:string,level:string,kind:string,
     *   cardCode:string,photo:bool,status:string,version:string}>
     */
    public static function list(string $q = ''): array
    {
        MemberCards::ensure();
        $st = Database::pdo()->query("SELECT DISTINCT c.member_id FROM av_member_cards c JOIN lms_users u ON u.id = c.member_id
                                      WHERE c.kind = 'secure' AND c.status = 'active' AND u.status <> 'deleted' ORDER BY c.member_id LIMIT 2000");
        $q = mb_strtolower(trim($q));
        $out = [];
        $design = self::designVersion();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $c = IdCard::forMember((int) $mid);
            $name = trim($c['given'] . ' ' . $c['family']);
            $printed = IdCard::printedId((int) $mid);
            if ($q !== '' && !str_contains(mb_strtolower($name . ' ' . $printed), $q)) continue;
            $out[] = [
                'id' => (string) $mid, 'name' => $name, 'printedId' => $printed, 'number' => $c['number'],
                'level' => $c['tier_letter'], 'kind' => str_contains($printed, '-NGV-') ? 'NGV' : (str_contains($printed, '-AVM-') ? 'AVM' : 'Member'),
                'cardCode' => $c['card_code'], 'photo' => !empty($c['photo_url']), 'status' => (string) ($c['band']['label'] ?? ''),
                'scanUrl' => MemberCards::scanUrl($c['card_code']),
                /* What a cached drawing of this card depends on. The status date
                   is left out: it changes daily, and the band's word does not. */
                'version' => substr(hash('sha256', $design . '|' . json_encode(array_diff_key($c, ['status_date' => 1, 'qr_svg' => 1]))), 0, 16),
            ];
            if (count($out) >= 500) break;
        }
        return $out;
    }

    /** The card's stylesheet with every asset inlined, and the seal for the markup. */
    public static function style(): array
    {
        $root = self::root();
        $css = '';
        foreach (self::FONTS as [$fam, $style, $weight, $file]) {
            $bytes = (string) @file_get_contents($root . '/assets/site/fonts/' . $file);
            if ($bytes === '') continue;
            $css .= "@font-face{font-family:'{$fam}';font-style:{$style};font-weight:{$weight};font-display:block;"
                  . "src:url(data:font/ttf;base64," . base64_encode($bytes) . ") format('truetype')}\n";
        }
        $css .= (string) @file_get_contents($root . '/assets/site/av-tokens.css') . "\n";
        $card = (string) @file_get_contents($root . '/assets/site/avc-card.css');
        $svg = base64_encode((string) @file_get_contents($root . '/assets/site/av-pattern-shapes.svg'));
        $css .= str_replace('url("/assets/site/av-pattern-shapes.svg")', 'url("data:image/svg+xml;base64,' . $svg . '")', $card) . "\n";
        /* The studio lays the face out alone, trimmed: no bleed (it adds its
           own), no corner radius, no shadow — the print face at 10px per mm. */
        $css .= "html,body{margin:0;padding:0;background:transparent}.avc-face.is-print{--bleed:0px}\n";
        return [
            'version' => self::designVersion(),
            'css' => $css,
            'seal' => 'data:image/png;base64,' . base64_encode((string) @file_get_contents($root . '/assets/site/av-seal.png')),
            'mm' => ['w' => 54, 'h' => 85.6], 'pxPerMm' => 10,
        ];
    }

    /**
     * Front and back markup, as the print page draws them. The seal's src is
     * the token __AV_SEAL__ (sent once, by style()); photos are inlined.
     *
     * @param list<string|int> $ids
     * @return array<string,array{front:string,back:string,version:string}>
     */
    public static function faces(array $ids): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::FACES_MAX) as $mid) {
            if ($mid <= 0 || MemberCards::secure($mid) === null) continue;
            $card = IdCard::forMember($mid);
            $card['photo_url'] = self::inlinePhoto($card['photo_url'] ?? null);
            $html = [];
            foreach (['front', 'back'] as $side) {
                $avcMode = 'print'; $avcSide = $side;
                ob_start();
                include self::root() . '/partials/id-card.php';
                $html[$side] = str_replace('src="/assets/site/av-seal.png"', 'src="__AV_SEAL__"', trim((string) ob_get_clean()));
            }
            $out[(string) $mid] = $html;
        }
        return $out;
    }

    /** The photo as a data URI, at most ~600 dpi at the panel; null if unreadable. */
    private static function inlinePhoto(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;
        $bytes = '';
        if (preg_match('~^https://res\.cloudinary\.com/~i', $url)) {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 2]]);
            $bytes = (string) @file_get_contents($url, false, $ctx, 0, 8 * 1048576);
        } else {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && !str_contains($path, '..') && str_starts_with($path, '/uploads/')) {
                $bytes = (string) @file_get_contents(self::root() . $path);
            }
        }
        if ($bytes === '') return null;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private static function root(): string { return dirname(__DIR__); }
}
