<?php
/**
 * tests/avog.test.php — the per-item Open Graph cards (lib/AvOg.php) and the
 * static share images they fall back to.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/AvOg.php';

foreach (['og-default.png' => [1200, 630], 'og-square.png' => [600, 600], 'default-image.png' => [1200, 800]] as $f => [$w, $h]) {
    $sz = @getimagesize(AV_ROOT . '/assets/og/' . $f);
    ck("og: assets/og/$f is {$w}×{$h}", $sz && $sz[0] === $w && $sz[1] === $h && filesize(AV_ROOT . '/assets/og/' . $f) < 300000);
}
$sz = @getimagesize(AV_ROOT . '/assets/fliers/cimc-flier-4x5.png');
ck('flier: CIMC 4:5 PNG is 1080×1350, with its PDF', $sz && $sz[0] === 1080 && $sz[1] === 1350 && is_file(AV_ROOT . '/assets/fliers/cimc-flier-4x5.pdf'));

if (AvImage::available()) {
    $px = static fn($im, $x, $y) => imagecolorat($im, $x, $y);
    $rgb = static fn(array $c) => $c[0] << 16 | $c[1] << 8 | $c[2];

    $im = AvOg::diary(['title' => str_repeat('A very long Diary title that keeps going ', 6), 'category' => 'Education', 'ref' => 'AVD-2609-0002', 'date' => 'Sep 21, 2026', 'minutes' => 8]);
    ck('og 1b: 1200×630, gold rule over sand', imagesx($im) === 1200 && imagesy($im) === 630
        && $px($im, 600, 4) === $rgb(AvImage::token('--av-gold')) && $px($im, 4, 620) === $rgb(AvImage::token('--av-sand')));
    imagedestroy($im);

    $im = AvOg::appeal(['title' => 'Twelve laptops for Techome', 'kicker' => 'Appeal', 'figure' => '₦285,000 raised of ₦3,000,000 · 10%', 'percent' => 10, 'met' => false, 'url' => 'afrovanguard.org.ng/give', 'photo' => null]);
    ck('og 1c: ink ground without a photo', $px($im, 1190, 620) === $rgb(AvImage::token('--av-ink')));
    imagedestroy($im);

    $im = AvOg::course(['title' => 'Community Influencers Masterclass', 'pill' => 'Free · 6 weeks']);
    ck('og 1d: paper ground inside a gold frame', $px($im, 10, 10) === $rgb(AvImage::token('--av-paper')) && $px($im, 28, 300) === $rgb(AvImage::token('--av-og-frame')));
    imagedestroy($im);
}

foreach (['diary/og.php', 'give/og.php', 'academy/og.php'] as $f) {
    $src = (string) file_get_contents(AV_ROOT . '/' . $f);
    ck("og: $f draws with AvOg and falls back to the new site card", str_contains($src, 'AvOg::') && str_contains($src, '/assets/og/og-default.png'));
}
