<?php
/**
 * academy/ngv/_in-studio.php — a staff console page, opened inside the Studio.
 *
 * The NextGen Vanguard console (and the Appeals console) are their own pages,
 * each with a dark bar of links to the others. Opened from the Studio —
 * Studio → People → NextGen Vanguard, one tab per page — that bar would be a
 * second navigation inside the first, so here it gives up its title and its
 * links to the other console pages. It keeps everything else: the page's own
 * buttons (Save, Undo, New appeal) and its status line (role="status"), which
 * is how the page says what it just did. A bar left with nothing to show
 * shrinks to nothing until a message arrives. Opened on its own, the page is
 * unchanged.
 */
declare(strict_types=1);
?>
<script>(function () {
  var inStudio = false;
  try { inStudio = window.top !== window.self && window.top.location.pathname.indexOf('/admin') === 0; } catch (e) { inStudio = false; }
  if (!inStudio) return;
  document.documentElement.classList.add('in-studio');
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.top, .gm-topbar').forEach(function (bar) {
      var shown = Array.prototype.filter.call(bar.children, function (c) {
        if (c.matches('.sp, .msg, h1, .brand, .gm-brand')) return false;
        return getComputedStyle(c).display !== 'none';
      });
      if (!shown.length) bar.classList.add('is-slim');
    });
  });
})();</script>
<style>
.in-studio .top h1, .in-studio .top .brand, .in-studio .gm-topbar .gm-brand,
.in-studio .top > a[href^="/academy/ngv/"]:not([target]) { display: none; }
.in-studio .top, .in-studio .gm-topbar { position: static; }
.in-studio .top.is-slim, .in-studio .gm-topbar.is-slim { min-height: 0; padding: 0; }
.in-studio .top .msg:empty { display: none; }
.in-studio .top.is-slim .msg:not(:empty) { padding: 6px 20px; }
/* The public site's cookie notice: the Studio around it does not show one, and
   nothing is loaded without consent either way (assets/site/ga.js). */
.in-studio .av-cc { display: none; }
</style>
