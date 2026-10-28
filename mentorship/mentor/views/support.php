<?php /** Five things a mentor asks for, and the way to report a concern. */ ?>
      <section class="avm-card">
<?php foreach ([
    'I am worried about a mentee’s safety'        => 'Use Report a concern below — it goes to the safeguarding lead, not to the coordinator inbox.',
    'My mentee has stopped replying'              => 'Ask the coordinator to try another route before you close the pairing.',
    'I need to pause or stop mentoring'           => 'Tell the coordinator early so a handover and an explanation reach your mentee.',
    'Something in the portal is wrong'            => 'Describe what you were doing and what happened.',
    'I want to raise my capacity'                 => 'Set it in your profile, and tell the coordinator if you want more matches.',
] as $topic => $line): ?>
        <button type="button" class="avm-todo" data-avm-open="support" data-topic="<?= e($topic) ?>" style="width:100%;text-align:left;border:0;background:none;cursor:pointer">
          <span class="avm-dot avm-dot--gold" aria-hidden="true"></span>
          <span><b><?= e($topic) ?></b><small><?= e($line) ?></small></span>
          <em>Ask</em>
        </button>
<?php endforeach; ?>
      </section>
      <div><a class="avm-btn avm-btn--danger" href="?v=concern">Report a concern</a></div>
