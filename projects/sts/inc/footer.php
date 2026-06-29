</main>
<?php
/**
 * Closes <main>, then renders the Afrovanguard parent row, footer and
 * back-to-top control. Pages may set $PAGE_FOOT_EXTRA (raw HTML, e.g. a
 * page-specific <script>) before requiring this file.
 */
$PAGE_FOOT_EXTRA = $PAGE_FOOT_EXTRA ?? '';
?>
<div class="parent-row">
  <div class="parent-row-inner">
    <div class="parent-row-lead">
      <span>An initiative of</span>
      <span class="av-mark">Afrovanguard</span>
      <span>· established 2021</span>
    </div>
    <a class="parent-row-cta" href="https://afrovanguard.org.ng" rel="noopener noreferrer" target="_blank">
      Visit Afrovanguard
      <svg aria-hidden="true" fill="none" height="10" viewbox="0 0 10 10" width="10"><path d="M2 8 L8 2 M3.5 2 L8 2 L8 6.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4"></path></svg>
    </a>
  </div>
</div>
<footer class="footer" data-screen-label="Footer">
  <div class="footer-inner">
    <div class="footer-cols">
      <div class="footer-col">
        <div class="brand" style="margin-bottom: 16px;">
          <img alt="" class="sts-logo" height="28" src="/assets/sts-logo.svg" style="width:28px;height:28px;" width="28"/>
          <div class="brand-text"><span class="brand-name">Street-To-Stardom</span></div>
        </div>
        <div style="color: var(--muted); font-size: 13px; line-height: 1.55; max-width: 280px;">
          Education and youth development for under-resourced Lagos communities. Registered NGO. Data published openly.
        </div>
        <div style="margin-top: 20px; display: flex; gap: 14px; font-size: 13px; color: var(--muted);">
          <a href="https://twitter.com" rel="noopener noreferrer" target="_blank">Twitter</a>
          <a href="https://instagram.com" rel="noopener noreferrer" target="_blank">Instagram</a>
          <a href="https://linkedin.com" rel="noopener noreferrer" target="_blank">LinkedIn</a>
          <a href="https://youtube.com" rel="noopener noreferrer" target="_blank">YouTube</a>
        </div>
      </div>
      <div class="footer-col">
        <h4>Programs</h4>
        <ul>
          <li><a href="/programs/next-gen">Next Gen Genius Club</a></li>
          <li><a href="/programs/summer-school">Alimosho Summer School</a></li>
          <li><a href="/programs/lcasp">LCASP</a></li>
          <li><a href="/programs/street-storm">STREET Storm</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Organisation</h4>
        <ul>
          <li><a href="/about">About</a></li>
          <li><a href="/methodology">Methodology</a></li>
          <li><a href="/team">Team &amp; Council</a></li>
          <li><a href="/units">Operating units</a></li>
          <li><a href="/impact">Impact reports</a></li>
          <li><a href="/contact">Contact</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Stay in the loop</h4>
        <div style="color: var(--muted); font-size: 13px; line-height: 1.55;">
          Quarterly field notes from the programs we run. No newsletter spam — opt-in only.
        </div>
        <form @submit.prevent="submit" class="footer-newsletter" data-needs-csrf="" x-data="newsletterForm()">
          <input aria-hidden="true" autocomplete="off" name="website" style="position:absolute;left:-9999px;" tabindex="-1" type="text" value=""/>
          <input name="csrf_token" type="hidden" value=""/>
          <input aria-label="Email" name="email" placeholder="you@example.org" required="" type="email" x-model="email"/>
          <button type="submit" x-bind:disabled="loading" x-text="loading ? 'Subscribing…' : (subscribed ? 'Subscribed ✓' : 'Subscribe')">Subscribe</button>
        </form>
        <div class="footer-newsletter-foot" data-msg="You'll receive 4 emails a year. Unsubscribe with one click." x-data="" x-text="$el.dataset.msg">
          You'll receive 4 emails a year. Unsubscribe with one click.
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <div>© 2026 Street-To-Stardom · An Afrovanguard initiative · IT-186151</div>
      <div class="footer-bottom-links">
        <a href="/privacy">Privacy</a>
        <a href="/safeguarding">Safeguarding</a>
        <a href="/impact">Annual report</a>
        <a href="/impact">Audited financials</a>
      </div>
    </div>
  </div>
</footer>
<button aria-label="Back to top" class="back-to-top" hidden="" type="button">
  <svg aria-hidden="true" fill="none" height="14" viewbox="0 0 14 14" width="14"><path d="M7 11V3M3 7l4-4 4 4" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path></svg>
</button>
<?= $PAGE_FOOT_EXTRA ?>
</body></html>
