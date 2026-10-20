<?php
/**
 * Template: portal_message
 * Vars: sender_name, sent_at, message_url, safeguarding_email
 */
$subject   = av_t('New message from {sender_name}', $v);
$preheader = av_t('{sender_name} sent you a message in the Afrovanguard portal.', $v);
?>
<tr><td class="av-px" align="center" style="padding:36px 40px 0;text-align:center;"><img class="av-art" src="<?= e($v['art_base']) ?>/envelope.gif" width="100" height="75" alt="" style="display:block;margin:0 auto;width:100px;height:75px;border:0;"></td></tr>
<tr><td class="av-px" align="center" style="padding:24px 40px 0;text-align:center;"><h1 class="av-h1 av-rise av-ink av-serif" style="margin:0;font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:36px;line-height:40px;font-weight:600;color:#111827;mso-line-height-rule:exactly;">New message from <?= e($v['sender_name']) ?></h1></td></tr>
<tr><td class="av-px" align="center" style="padding:14px 48px 0;text-align:center;"><div class="av-ink av-rise2" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;mso-line-height-rule:exactly;">Sent <?= e($v['sent_at']) ?>. For your safety, messages are only shown in the portal.</div></td></tr>
<tr><td class="av-px" align="center" style="padding:28px 40px 0;text-align:center;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td bgcolor="#111827" style="background:#111827;border-radius:8px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?= e($v['message_url']) ?>" style="height:46px;v-text-anchor:middle;width:240px;" arcsize="18%" stroke="f" fillcolor="#111827"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Read message</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a class="av-btn" href="<?= e($v['message_url']) ?>" style="display:inline-block;padding:13px 26px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">Read message</a><!--<![endif]-->
</td></tr></table></td></tr>
<tr><td class="av-px" align="center" style="padding:28px 48px 0;text-align:center;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;">Messages between mentors and mentees can be seen by our safeguarding lead. If something worries you, write to <a href="mailto:<?= e($v['safeguarding_email']) ?>" style="color:#8A6406;text-decoration:underline;"><?= e($v['safeguarding_email']) ?></a>.</div></td></tr>
<tr><td style="height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>
