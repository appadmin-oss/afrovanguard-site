<?php
/**
 * Template: sign_in_code
 * Vars: code, expires_minutes, request_time, request_location
 */
$subject   = av_t('Your Afrovanguard sign-in code: {code}', $v);
$preheader = av_t('Use this code to sign in. It expires in {expires_minutes} minutes.', $v);
?>
<?php $v['code_digits'] = array_map(fn($d) => ['d' => $d], str_split((string) $v['code'])); ?>
<tr><td class="av-px" align="center" style="padding:36px 40px 0;text-align:center;"><!--[if !mso]><!--><img class="av-art" src="<?= e($v['art_base']) ?>/lock.gif" width="100" height="75" alt="" style="display:block;margin:0 auto;width:100px;height:75px;border:0;"><!--<![endif]--><!--[if mso]><img class="av-art" src="<?= e($v['art_base']) ?>/lock.png" width="100" height="75" alt="" style="display:block;margin:0 auto;width:100px;height:75px;border:0;"><![endif]--></td></tr>
<tr><td class="av-px" align="center" style="padding:24px 40px 0;text-align:center;"><h1 class="av-h1 av-rise av-ink av-serif" style="margin:0;font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:36px;line-height:40px;font-weight:600;color:#111827;mso-line-height-rule:exactly;">Your sign-in code</h1></td></tr>
<tr><td class="av-px" align="center" style="padding:14px 48px 0;text-align:center;"><div class="av-ink av-rise2" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;mso-line-height-rule:exactly;">Enter this code to sign in. It expires in <?= e($v['expires_minutes']) ?> minutes.</div></td></tr>
<tr><td class="av-px" align="center" style="padding:28px 40px 0;text-align:center;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" class="av-code" align="center"><tr><?php foreach ($v['code_digits'] as $it): ?><td class="av-digit" style="padding:0 4px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="av-stone av-ink" width="48" height="60" align="center" valign="middle" bgcolor="#F5F3EF" style="width:48px;height:60px;background:#F5F3EF;border:1px solid #E8E6E1;border-radius:10px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:30px;line-height:60px;font-weight:700;color:#111827;font-variant-numeric:tabular-nums;mso-line-height-rule:exactly;"><?= e($it['d']) ?></td></tr></table></td><?php endforeach; ?></tr></table></td></tr>
<tr><td class="av-px" align="center" style="padding:28px 48px 0;text-align:center;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;">Didn’t ask for this? Ignore this email and your account stays secure. Requested <?= e($v['request_time']) ?> from <?= e($v['request_location']) ?>.</div></td></tr>
<tr><td style="height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>
