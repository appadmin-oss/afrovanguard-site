<?php
/**
 * Shared <head> + document open for every Street-To-Stardom page.
 *
 * Pages set these before requiring this file:
 *   $PAGE_TITLE      string  full <title>
 *   $PAGE_DESC       string  meta description (also used for OG/Twitter)
 *   $PAGE_PATH       string  canonical path with trailing slash, e.g. "/about/"
 *   $PAGE_HEAD_EXTRA string  optional raw HTML injected at end of <head>
 *                            (page-specific <style> / JSON-LD)
 *
 * No build step. Pure PHP — drops onto any Apache/LiteSpeed shared host.
 */
if (!isset($STS_ROOT)) {
  $STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
  if ($STS_ROOT === '' || !is_file($STS_ROOT . '/inc/head.php')) {
    $STS_ROOT = __DIR__;
    while (!is_file($STS_ROOT . '/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) {
      $STS_ROOT = dirname($STS_ROOT);
    }
  }
}

$SITE_ORIGIN = 'https://sts.afrovanguard.org.ng';
$PAGE_TITLE  = $PAGE_TITLE ?? 'Street-To-Stardom — Education that meets children where they are';
$PAGE_DESC   = $PAGE_DESC ?? 'Education, mentorship and youth development for under-resourced communities across Lagos. An Afrovanguard initiative.';
$PAGE_PATH   = $PAGE_PATH ?? '/';
$PAGE_HEAD_EXTRA = $PAGE_HEAD_EXTRA ?? '';
$CANON       = $SITE_ORIGIN . $PAGE_PATH;
$OG_IMAGE    = $SITE_ORIGIN . '/assets/og-default.svg';

$t = htmlspecialchars($PAGE_TITLE, ENT_QUOTES);
$d = htmlspecialchars($PAGE_DESC, ENT_QUOTES);
$c = htmlspecialchars($CANON, ENT_QUOTES);
$oi = htmlspecialchars($OG_IMAGE, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"/><meta content="width=device-width, initial-scale=1" name="viewport"/><meta content="IE=edge" http-equiv="X-UA-Compatible"/><title><?= $t ?></title><meta content="<?= $d ?>" name="description"/><link href="<?= $c ?>" rel="canonical"/><meta content="<?= $t ?>" property="og:title"/><meta content="<?= $d ?>" property="og:description"/><meta content="website" property="og:type"/><meta content="<?= $c ?>" property="og:url"/><meta content="<?= $oi ?>" property="og:image"/><meta content="summary_large_image" name="twitter:card"/><meta content="<?= $t ?>" name="twitter:title"/><meta content="<?= $d ?>" name="twitter:description"/><meta content="<?= $oi ?>" name="twitter:image"/><link href="/assets/sts-logo.svg" rel="icon" type="image/svg+xml"/><link href="/assets/sts-logo.svg" rel="apple-touch-icon"/><link href="/manifest.webmanifest" rel="manifest"/><link href="/api/rss.php" rel="alternate" title="STS Field notes" type="application/rss+xml"/><meta content="#0732F7" media="(prefers-color-scheme: light)" name="theme-color"/><meta content="#0B0B12" media="(prefers-color-scheme: dark)" name="theme-color"/><meta content="telephone=no" name="format-detection"/><script>
    (function () {
      try {
        var mode = localStorage.getItem("sts.theme") || "system";
        var resolved = mode === "system"
          ? (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light")
          : mode;
        document.documentElement.setAttribute("data-theme", resolved);
        document.documentElement.setAttribute("data-theme-mode", mode);
        document.documentElement.setAttribute("data-accent", "blue-led");
        document.documentElement.setAttribute("data-density", "default");
      } catch (e) {}
    })();
  </script><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;family=Montserrat:wght@500;600;700&amp;family=JetBrains+Mono:wght@400;500&amp;display=swap" rel="stylesheet"/><link href="/assets/css/app.css" rel="stylesheet"/><link href="/assets/css/sts-enhance.css" rel="stylesheet"/><script src="/assets/js/app.js" type="module"></script><?= $PAGE_HEAD_EXTRA ?></head>
<body data-pathname="<?= htmlspecialchars($PAGE_PATH, ENT_QUOTES) ?>"><a class="skip-link" href="#main">Skip to content</a>
<?php require $STS_ROOT . '/inc/nav.php'; ?>
<main id="main">
