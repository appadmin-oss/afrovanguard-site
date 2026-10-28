<?php /** How a member looking for a mentor sees you. */
$pr = $portal->profile(); ?>
      <div class="avm-two">
        <form class="avm-panel" data-avm-profile>
          <label>Headline <input class="avm-input" type="text" name="headline" maxlength="160" value="<?= e($pr['headline']) ?>" data-pv="headline" placeholder="What you help with, in one line"></label>
          <label>Focus areas <input class="avm-input" type="text" name="focus" maxlength="255" value="<?= e($pr['focus']) ?>" data-pv="focus" placeholder="Leadership, public speaking, study habits"></label>
          <label>About you <textarea class="avm-textarea" name="bio" rows="5" data-pv="bio" placeholder="Who you are and how you work."><?= e($pr['bio']) ?></textarea></label>
          <label>Capacity
            <input class="avm-input" type="number" name="capacity" min="1" max="50" value="<?= (int) $pr['capacity'] ?>">
            <small style="display:block;margin-top:4px;color:var(--av-muted)">You have <?= (int) $pr['active'] ?> active now. Requests are refused above this.</small>
          </label>
          <div style="display:flex;align-items:center;gap:12px">
            <input type="hidden" name="accepting" value="<?= $pr['accepting'] ? 1 : 0 ?>">
            <button type="button" role="switch" aria-label="Accepting requests" data-avm-switch="accepting" aria-checked="<?= $pr['accepting'] ? 'true' : 'false' ?>"
                    style="position:relative;width:42px;height:24px;border:0;border-radius:999px;cursor:pointer;background:<?= $pr['accepting'] ? 'var(--av-green)' : 'var(--av-line-2)' ?>">
              <span style="position:absolute;top:3px;left:<?= $pr['accepting'] ? '21px' : '3px' ?>;width:18px;height:18px;border-radius:50%;background:var(--av-white);transition:left .15s"></span>
            </button>
            <span>Accepting requests</span>
          </div>
          <div><button class="avm-btn avm-btn--ink" type="submit">Save profile</button></div>
        </form>

        <section class="avm-panel" aria-labelledby="avm-pv-h">
          <p class="avm-eyebrow" id="avm-pv-h">How you appear</p>
          <div class="avm-casehead-top">
            <span class="avm-av" aria-hidden="true"><?= e($pr['initials']) ?></span>
            <div><h2 style="margin:0;font-size:17px"><?= e($pr['name']) ?></h2>
              <p style="margin:2px 0 0;font-size:13px;color:var(--av-muted)" data-pv-out="headline"><?= e($pr['headline']) ?></p></div>
          </div>
          <p style="margin:0;font-size:14px;line-height:1.6;color:var(--av-text-2)" data-pv-out="bio"><?= e($pr['bio']) ?></p>
          <p style="margin:0;font-size:13px;color:var(--av-muted)" data-pv-out="focus"><?= e($pr['focus']) ?></p>
        </section>
      </div>
