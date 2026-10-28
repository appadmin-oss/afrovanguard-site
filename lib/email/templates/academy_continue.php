<?php
/**
 * Template: academy_continue  (Academy · card frame)
 * Vars: first_name, course_title, progress_pct (int), lessons_done, lessons_total, next_lesson_title, lesson_url
 */
$frame     = 'card';
$subject   = av_t('Pick up where you left off in {course_title}', $v);
$preheader = av_t('Next up: {next_lesson_title}.', $v);
?>
<tr><td class="av-px" style="padding:20px 40px 0;text-align:left;"><h1 class="av-h1 av-rise av-ink av-serif" style="margin:0;font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:34px;line-height:38px;font-weight:600;color:#111827;mso-line-height-rule:exactly;">Pick up where you left off</h1></td></tr>
<tr><td class="av-px" style="padding:16px 40px 0;text-align:left;"><div class="av-ink" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;mso-line-height-rule:exactly;"><?= e($v['first_name']) ?>, you’re making progress in <strong><?= e($v['course_title']) ?></strong>. Next up: <?= e($v['next_lesson_title']) ?>.</div></td></tr>
<tr><td class="av-px" style="padding:22px 40px 0;text-align:left;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:18px;color:#5F636D;padding-bottom:8px;font-variant-numeric:tabular-nums;"><strong class="av-ink" style="color:#111827;"><?= (int) $v['progress_pct'] ?>% complete</strong> · <?= e($v['lessons_done']) ?> of <?= e($v['lessons_total']) ?> lessons</div><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="av-grow" width="<?= max(1, (int) $v['progress_pct']) ?>%" height="8" bgcolor="#F3B416" style="height:8px;background:#F3B416;border-radius:999px 0 0 999px;font-size:0;line-height:0;">&nbsp;</td><td class="av-stone" height="8" bgcolor="#E8E6E1" style="height:8px;background:#E8E6E1;border-radius:0 999px 999px 0;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>
<tr><td class="av-px" style="padding:26px 40px 0;text-align:left;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#111827" style="background:#111827;border-radius:8px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?= e($v['lesson_url']) ?>" style="height:44px;v-text-anchor:middle;width:230px;" arcsize="18%" stroke="f" fillcolor="#111827"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Continue the course</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a class="av-btn" href="<?= e($v['lesson_url']) ?>" style="display:inline-block;padding:12px 22px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">Continue the course</a><!--<![endif]-->
</td></tr></table></td></tr>
<tr><td class="av-px" style="padding:24px 40px 0;text-align:left;"><div class="av-muted" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;">Reminders like this come weekly while a course is in progress. <a href="<?= e($v['preferences_url']) ?>" style="color:#8A6406;text-decoration:underline;">Change how often</a>.</div></td></tr>
<tr><td style="height:40px;font-size:0;line-height:0;">&nbsp;</td></tr>
