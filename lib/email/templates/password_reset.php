<?php
/**
 * Template: password_reset
 * Vars: first_name, reset_url, expires_minutes, request_time, request_location
 */
$subject   = av_t('Reset your Afrovanguard password', $v);
$preheader = av_t('Use the link inside to choose a new password. It expires in {expires_minutes} minutes.', $v);
?>
<tr><td class="av-px" align="center" style="padding:36px 40px 0;text-align:center;"><!--[if !mso]><!--><img class="av-art" src="<?= e($v['art_base']) ?>/lock.gif" width="100" height="75" alt="" style="display:block;margin:0 auto;width:100px;height:75px;border:0;"><!--<![endif]--><!--[if mso]><img class="av-art" src="<?= e($v['art_base']) ?>/lock.png" width="100" height="75" alt="" style="display:block;margin:0 auto;width:100px;height:75px;border:0;"><![endif]--></td></tr>
<tr><td class="av-px" align="center" style="padding:24px 40px 0;text-align:center;"><h1 class="av-h1 av-rise av-ink av-serif" style="margin:0;font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:36px;line-height:40px;font-weight:600;color:#111827;mso-line-height-rule:exactly;">Reset your password</h1></td></tr>
<tr><td class="av-px" align="center" style="padding:14px 48px 0;text-align:center;"><div class="av-ink av-rise2" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;mso-line-height-rule:exactly;"><?= e($v['first_name']) ?>, use the button below to choose a new password. The link expires in <?= e($v['expires_minutes']) ?> minutes and works once.</div></td></tr>
<tr><td class="av-px" align="center" style="padding:28px 40px 0;text-align:center;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td bgcolor="#111827" style="background:#111827;border-radius:8px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?= e($v['reset_url']) ?>" style="height:46px;v-text-anchor:middle;width:240px;" arcsize="18%" stroke="f" fillcolor="#111827"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Choose a new password</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a class="av-btn" href="<?= e($v['reset_url']) ?>" style="display:inline-block;padding:13px 26px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">Choose a new password</a><!--<![endif]-->
</td></tr></table></td></tr>
<tr><td class="av-px" align="center" style="padding:20px 48px 0;text-align:center;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;">Or paste this link into your browser:<br><a href="<?= e($v['reset_url']) ?>" style="color:#8A6406;text-decoration:underline;word-break:break-all;"><?= e($v['reset_url']) ?></a></div></td></tr>
<tr><td class="av-px" align="center" style="padding:16px 48px 0;text-align:center;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;">Didn’t ask for this? Ignore this email and your password won’t change. Requested <?= e($v['request_time']) ?> from <?= e($v['request_location']) ?>.</div></td></tr>
<tr><td style="height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>
