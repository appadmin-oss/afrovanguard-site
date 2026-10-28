<?php
/** Afrovanguard email frame. Included by av_email_render(). Do not edit per email. */
?><!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?= e($subject) ?></title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,div,a,span{font-family:Arial,Helvetica,sans-serif!important}.av-serif,h1{font-family:Georgia,serif!important}</style><![endif]-->
<!--[if !mso]><!--><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&amp;family=Source+Sans+3:wght@400;600;700&amp;display=swap" rel="stylesheet"><!--<![endif]-->
<style>
body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
a{color:#8A6406}
a:hover{color:#111827}
@media only screen and (max-width:600px){
  .av-wrap{width:100%!important}
  .av-px{padding-left:20px!important;padding-right:20px!important}
  .av-h1{font-size:30px!important;line-height:34px!important}
  .av-col{display:block!important;width:100%!important;text-align:left!important}
  .av-digit{padding:0 2px!important}
}
@media (prefers-color-scheme:dark){
  .av-bg{background:#141922!important}
  .av-stone{background:#232A36!important}
  .av-ink{color:#F2F0EA!important}
  .av-muted{color:#C3C6CE!important}
  .av-line{border-color:#3A4252!important}
  .av-art{border-radius:12px}
}
@media (prefers-reduced-motion:no-preference){
  .av-rise{animation:avRise .6s .1s cubic-bezier(.2,.7,.2,1) both}
  .av-rise2{animation:avRise .6s .2s cubic-bezier(.2,.7,.2,1) both}
  .av-pop{animation:avPop .6s cubic-bezier(.3,1.6,.5,1) both}
  .av-pulse{animation:avPulse 1.8s ease-out infinite}
  .av-code td.av-digit{animation:avRise .45s .25s cubic-bezier(.2,.7,.2,1) both}
  .av-code td.av-digit:nth-child(2){animation-delay:.32s}
  .av-code td.av-digit:nth-child(3){animation-delay:.39s}
  .av-code td.av-digit:nth-child(4){animation-delay:.46s}
  .av-code td.av-digit:nth-child(5){animation-delay:.53s}
  .av-code td.av-digit:nth-child(6){animation-delay:.6s}
  .av-btn{transition:opacity .15s}
  .av-btn:hover{opacity:.86}
}
@keyframes avRise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes avPop{from{opacity:0;transform:scale(.5)}to{opacity:1;transform:scale(1)}}
@keyframes avPulse{0%{box-shadow:0 0 0 0 rgba(243,180,22,.55)}70%{box-shadow:0 0 0 10px rgba(243,180,22,0)}100%{box-shadow:0 0 0 0 rgba(243,180,22,0)}}
</style>
</head>
<body class="av-bg" style="margin:0;padding:0;background:#FFFFFF;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#FFFFFF;opacity:0;"><?= e($preheader) ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="av-bg" bgcolor="#FFFFFF" style="background:#FFFFFF;">
<tr><td align="center" style="padding:36px 12px 40px;">
<!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" class="av-wrap" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:560px;">
<tr><td align="center" style="padding:0 20px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
<td width="30" valign="middle"><a href="<?= e($v['base_url']) ?>"><img src="<?= e($v['logo_url']) ?>" width="30" height="30" alt="" style="display:block;width:30px;height:30px;border:0;"></a></td>
<td valign="middle" style="padding-left:9px;"><a href="<?= e($v['base_url']) ?>" class="av-ink av-serif" style="font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:22px;line-height:26px;font-weight:600;color:#111827;text-decoration:none;">Afrovanguard</a></td>
</tr></table>
</td></tr>
<tr><td style="padding-top:12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?= $body ?>
</table>
</td></tr>
<tr><td class="av-px" style="padding:44px 20px 0;"><div class="av-line" style="border-top:1px solid #E8E6E1;font-size:0;line-height:0;height:1px;">&nbsp;</div></td></tr>
<tr><td class="av-px" style="padding:22px 20px 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="av-col" valign="top" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;color:#5F636D;text-align:left;"><span class="av-ink" style="color:#111827;font-weight:700;">Afrovanguard</span><br><span class="av-muted"><?= e($v['postal_address']) ?></span></td>
<td class="av-col" valign="top" align="right" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;text-align:right;"><a href="<?= e($v['base_url']) ?>/diary/" class="av-muted" style="color:#5F636D;text-decoration:none;">Diary</a>&nbsp;&nbsp;·&nbsp;&nbsp;<a href="<?= e($v['base_url']) ?>/events/" class="av-muted" style="color:#5F636D;text-decoration:none;">Events</a>&nbsp;&nbsp;·&nbsp;&nbsp;<a href="<?= e($v['base_url']) ?>/donate/" class="av-muted" style="color:#5F636D;text-decoration:none;">Donate</a></td>
</tr></table>
</td></tr>
<tr><td class="av-px av-muted" style="padding:16px 20px 0;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;color:#5F636D;text-align:left;">You’re receiving this service email because you have an Afrovanguard account.<br><a href="<?= e($v['base_url']) ?>/contact/" style="color:#5F636D;text-decoration:underline;">Help</a>&nbsp;·&nbsp;<a href="<?= e($v['base_url']) ?>/privacy/" style="color:#5F636D;text-decoration:underline;">Privacy</a>&nbsp;·&nbsp;<a href="<?= e($v['preferences_url']) ?>" style="color:#5F636D;text-decoration:underline;">Email preferences</a></td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
