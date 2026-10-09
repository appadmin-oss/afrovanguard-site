<?php /* The phone tab bar. Inside .avm, because the phone rules are a
   @container query and a container query only reaches its own descendants —
   as a sibling it stayed display:none at every width. */ ?>
  <nav class="avm-tabbar" aria-label="Sections">
    <a href="?v=today"<?= $v === 'today' ? ' aria-current="page"' : '' ?>><?= Icons::mentorPortal('today') ?>Today</a>
    <a href="?v=mentees"<?= in_array($v, ['mentees', 'case'], true) ? ' aria-current="page"' : '' ?>><?= Icons::mentorPortal('people') ?>Mentees</a>
    <a href="?v=values"<?= $v === 'values' ? ' aria-current="page"' : '' ?>><?= Icons::mentorPortal('star') ?>Values</a>
    <a href="?v=academy"<?= in_array($v, ['academy', 'module'], true) ? ' aria-current="page"' : '' ?>><?= Icons::mentorPortal('cap') ?>Academy</a>
    <a href="#avm-more" data-avm-open="more"<?= in_array($v, ['checkins', 'reflections', 'requests', 'profile', 'support'], true) ? ' aria-current="page"' : '' ?>><?= Icons::mentorPortal('more') ?>More</a>
  </nav>
