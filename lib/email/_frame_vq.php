<?php
/**
 * VanQuest email frame. Included by av_email_render() when a template sets
 * $frame = 'vq'. VanQuest's own look (vquest.afrovanguard.org.ng): night ground,
 * one orange accent, Geist over a mono for labels, pixel art. Dark is the design,
 * not a dark-mode variant, so every surface carries its colour inline and as
 * bgcolor for the clients that drop one or the other.
 */
?><!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title><?= e($subject) ?></title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,div,a,span,h1{font-family:Arial,Helvetica,sans-serif!important}.vq-mono{font-family:'Courier New',Courier,monospace!important}</style><![endif]-->
<!--[if !mso]><!--><link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600;700&amp;family=Geist+Mono:wght@500;600&amp;display=swap" rel="stylesheet"><!--<![endif]-->
<style>
:root{color-scheme:dark;supported-color-schemes:dark}
body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;background:#0b0a09}
img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;image-rendering:pixelated}
a{color:#ff9f1c}
@media only screen and (max-width:600px){
  .av-wrap{width:100%!important}
  .av-px{padding-left:20px!important;padding-right:20px!important}
  .av-h1{font-size:30px!important;line-height:33px!important}
  .av-col{display:block!important;width:100%!important;text-align:left!important}
  .vq-rung{font-size:11px!important}
  .vq-hide-sm{display:none!important}
}
@media (prefers-reduced-motion:no-preference){
  .av-rise{animation:avRise .6s .1s cubic-bezier(.2,.7,.2,1) both}
  .av-rise2{animation:avRise .6s .2s cubic-bezier(.2,.7,.2,1) both}
  .vq-now{animation:vqPulse 1.8s ease-out infinite}
  .av-btn{transition:opacity .15s}
  .av-btn:hover{opacity:.86}
}
@keyframes avRise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes vqPulse{0%{box-shadow:0 0 0 0 rgba(255,159,28,.55)}70%{box-shadow:0 0 0 8px rgba(255,159,28,0)}100%{box-shadow:0 0 0 0 rgba(255,159,28,0)}}
</style>
</head>
<body style="margin:0;padding:0;background:#0b0a09;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#0b0a09;opacity:0;"><?= e($preheader) ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0b0a09" style="background:#0b0a09;">
<tr><td align="center" style="padding:32px 12px 40px;">
<!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" class="av-wrap" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:560px;">
<tr><td style="padding:0 4px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="32" valign="middle"><a href="<?= e($v['vq_url']) ?>"><img src="<?= e($v['art_base']) ?>/vqmark.png" width="32" height="34" alt="" style="display:block;width:32px;height:34px;border:0;"></a></td>
<td valign="middle" style="padding-left:10px;"><a href="<?= e($v['vq_url']) ?>" style="font-family:Geist,Arial,Helvetica,sans-serif;font-size:19px;line-height:24px;font-weight:600;letter-spacing:-0.02em;color:#f4efe6;text-decoration:none;">VanQuest</a></td>
<td valign="middle" align="right" class="vq-mono vq-hide-sm" style="font-family:'Geist Mono','Courier New',Courier,monospace;font-size:10.5px;line-height:16px;letter-spacing:.12em;color:#8a8073;text-align:right;">AN AFROVANGUARD INITIATIVE</td>
</tr></table>
</td></tr>
<tr><td bgcolor="#15120f" style="background:#15120f;border:1px solid #2c2620;border-radius:20px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?= $body ?>
</table>
</td></tr>
<tr><td class="av-px" style="padding:24px 8px 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="av-col" valign="top" style="font-family:Geist,Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;color:#8a8073;text-align:left;"><span style="color:#f4efe6;font-weight:600;">Afrovanguard</span><br><?= e($v['postal_address']) ?></td>
<td class="av-col" valign="top" align="right" style="font-family:Geist,Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;text-align:right;"><a href="<?= e($v['vq_url']) ?>/" style="color:#8a8073;text-decoration:none;">Story</a>&nbsp;&nbsp;·&nbsp;&nbsp;<a href="<?= e($v['vq_url']) ?>/world/?play" style="color:#8a8073;text-decoration:none;">World</a>&nbsp;&nbsp;·&nbsp;&nbsp;<a href="<?= e($v['base_url']) ?>" style="color:#8a8073;text-decoration:none;">Afrovanguard</a></td>
</tr></table>
</td></tr>
<tr><td class="av-px" style="padding:14px 8px 0;font-family:Geist,Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;color:#8a8073;text-align:left;">You’re receiving this because you joined VanQuest at <?= e(preg_replace('~^https?://~', '', $v['vq_url'])) ?>.<br><a href="<?= e($v['base_url']) ?>/contact/" style="color:#8a8073;text-decoration:underline;">Help</a>&nbsp;·&nbsp;<a href="<?= e($v['base_url']) ?>/privacy/" style="color:#8a8073;text-decoration:underline;">Privacy</a>&nbsp;·&nbsp;<a href="<?= e($v['unsubscribe_url']) ?>" style="color:#8a8073;text-decoration:underline;">Unsubscribe</a></td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
