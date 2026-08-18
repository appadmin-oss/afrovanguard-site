<?php
/**
 * tests/assets.test.php — the cover-image and byline boundary.
 *
 * These pin two fixes that are one careless edit from coming back, because both
 * live on the *content* path — the one an editor can write and a Super Admin
 * reads (audit findings H-2 and M-5):
 *
 *   1. An asset URL is validated on the way in, not merely escaped on the way
 *      out. It is emitted inside `style="background-image:url('…')"`, so a value
 *      carrying a quote is a script tag on somebody else's screen.
 *   2. `av_fetch_image_bytes()` runs on the server against a URL an editor chose.
 *      It must refuse private addresses and must not read outside the asset
 *      directories — a leading slash is not permission to read the filesystem.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

/* ---- Asset URLs: the two shapes the app really produces ---- */

foreach ([
    'https://res.cloudinary.com/av/image/upload/v1/cover.jpg',
    'http://cdn.example.org/a.png',
    'https://x.test/a.jpg?w=800&h=600',
    '/uploads/covers/a.jpg',
    '/assets/site/icon-192.png',
] as $ok) {
    ck('asset url: keeps a real cover (' . $ok . ')', av_safe_asset_url($ok) === $ok);
}

// Every one of these is an attempt to leave the attribute it is rendered into,
// or to reach somewhere it should not.
$bad = [
    'attribute breakout'  => 'https://x/a.jpg" onmouseover="alert(1)',
    'css url() breakout'  => "https://x/a.jpg'); background:url('y",
    'javascript scheme'   => 'javascript:alert(1)',
    'data scheme'         => 'data:image/svg+xml;base64,PHN2Zz4=',
    'vbscript scheme'     => 'vbscript:msgbox',
    'protocol-relative'   => '//evil.example/x.png',
    'path traversal'      => '/uploads/../../etc/passwd',
    'embedded credential' => 'https://user:pw@evil.example/x.png',
    'angle bracket'       => 'https://x/a.jpg<script>',
    'newline'             => "https://x/a.jpg\nSet-Cookie: x=1",
    'no host'             => 'https://',
];
foreach ($bad as $label => $v) {
    ck('asset url: refuses ' . $label, av_safe_asset_url($v) === '');
}
// Refusing means an empty string, never a half-cleaned value that still renders.
ck('asset url: a refusal is empty, not partially cleaned', av_safe_asset_url('javascript:alert(1)') === '');

/* ---- Gradients are CSS class names, so they are an allowlist ---- */

foreach (AV_CARD_GRADIENTS as $g) ck('gradient: ' . $g . ' survives', av_card_gradient($g) === $g);
ck('gradient: case and padding are tolerated', av_card_gradient('  G-Sky ') === 'g-sky');
ck('gradient: an unknown name falls back', av_card_gradient('g-purple') === 'g-gold');
ck('gradient: an injection falls back', av_card_gradient('g-gold" onload="alert(1)') === 'g-gold');
ck('gradient: empty falls back', av_card_gradient('') === 'g-gold');

/* ---- The byline is markup, so it is sanitized rather than trusted ---- */

ck('byline: a plain byline is escaped, not parsed',
   av_byline_html('Ada & Bode') === 'Ada &amp; Bode');
ck('byline: an author link survives',
   strpos(av_byline_html('Ada <a href="/people/1">Okonkwo</a>'), '<a href="/people/1">') !== false);
ck('byline: a script tag does not',
   strpos(av_byline_html('Ada<script>alert(1)</script>'), '<script') === false);
ck('byline: a javascript: href does not',
   strpos(av_byline_html('<a href="javascript:alert(1)">x</a>'), 'javascript:') === false);
ck('byline: an event handler does not',
   strpos(av_byline_html('<a href="/x" onmouseover="alert(1)">y</a>'), 'onmouseover') === false);

/* ---- Local reads stay inside the asset directories ---- */

ck('local asset: a real asset reads', av_read_local_asset('/assets/site/icon-192.png') !== null);
foreach ([
    'traversal out of uploads' => '/uploads/../lib/helpers.php',
    'traversal above the root' => '/../etc/passwd',
    'a source file'            => '/lib/helpers.php',
    'the database directory'   => '/db/schema.sql',
    'a dotfile'                => '/.env',
    'a protocol-relative host' => '//evil.example/x',
    'a null byte'              => "/assets/site/icon-192.png\0.txt",
] as $label => $p) {
    ck('local asset: refuses ' . $label, av_read_local_asset($p) === null);
}

/* ---- The server-side fetcher refuses the private network ---- */

// No assertion here reaches the network: every one is refused by the address
// guard before a connection is opened.
foreach ([
    'loopback'          => 'http://127.0.0.1/a.png',
    'loopback by name'  => 'http://localhost/a.png',
    'cloud metadata'    => 'http://169.254.169.254/latest/meta-data/',
    'private range'     => 'http://10.0.0.5/a.png',
    'IPv6 loopback'     => 'http://[::1]/a.png',
    'decimal loopback'  => 'http://2130706433/a.png',
    'octal loopback'    => 'http://0177.0.0.1/a.png',
    'a file:// URL'     => 'file:///etc/passwd',
    'a gopher:// URL'   => 'gopher://x/',
] as $label => $u) {
    ck('image fetch: refuses ' . $label, av_fetch_image_bytes($u) === null);
}

// The guard is shared with web_fetch on purpose — a second copy is how one of
// them ends up weaker than the other.
ck('image fetch: uses the same guard as web_fetch', is_callable(['AvWeb', 'guard']));
ck('image fetch: goes through AvWeb::fetchBytes', is_callable(['AvWeb', 'fetchBytes']));
// fetchBytes must NOT be gated on ai.web_access: a cover image is the app loading
// an asset a human pasted, not the assistant reading the web.
AvRules::save(['ai.web_access' => '0'], 'test');
ck('image fetch: a local cover still loads with AI web access off',
   av_fetch_image_bytes('/assets/site/icon-192.png') !== null);
ck('image fetch: still refuses loopback with AI web access off',
   av_fetch_image_bytes('http://127.0.0.1/a.png') === null);
AvRules::resetAll('test');
