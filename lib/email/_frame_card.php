<?php
/** Afrovanguard Academy email frame (card, left-aligned). Used when a template sets $frame = 'card'. */
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
  .av-px{padding-left:24px!important;padding-right:24px!important}
  .av-h1{font-size:29px!important;line-height:33px!important}
}
@media (prefers-color-scheme:dark){
  .av-bg{background:#141922!important}
  .av-card{background:#1C2330!important;border-color:#2E3644!important}
  .av-stone{background:#262E3B!important}
  .av-ink{color:#F2F0EA!important}
  .av-muted{color:#C3C6CE!important}
  .av-line{border-color:#3A4252!important}
  .av-art{border-radius:10px}
}
@media (prefers-reduced-motion:no-preference){
  .av-rise{animation:avRise .6s .1s cubic-bezier(.2,.7,.2,1) both}
  .av-grow{animation:avGrow 1.2s .3s cubic-bezier(.2,.7,.2,1) both;transform-origin:left center}
  .av-btn{transition:opacity .15s}
  .av-btn:hover{opacity:.86}
}
@keyframes avRise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes avGrow{from{transform:scaleX(0)}to{transform:scaleX(1)}}
</style>
</head>
<body class="av-bg" style="margin:0;padding:0;background:#F5F3EF;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F5F3EF;opacity:0;"><?= e($preheader) ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="av-bg" bgcolor="#F5F3EF" style="background:#F5F3EF;">
<tr><td align="center" style="padding:32px 12px 40px;">
<!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" class="av-wrap" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:560px;">
<tr><td class="av-card" bgcolor="#FFFFFF" style="background:#FFFFFF;border:1px solid #E8E6E1;border-radius:14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td class="av-px" style="padding:32px 40px 0;text-align:left;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="28" valign="middle"><a href="<?= e($v['base_url']) ?>/academy/"><img src="<?= e($v['logo_url']) ?>" width="28" height="28" alt="" style="display:block;width:28px;height:28px;border:0;"></a></td>
<td valign="middle" style="padding-left:9px;"><a href="<?= e($v['base_url']) ?>/academy/" style="text-decoration:none;"><span class="av-ink av-serif" style="font-family:'Cormorant Garamond',Cormorant,Georgia,'Times New Roman',serif;font-size:24px;line-height:26px;font-weight:600;color:#111827;">Academy</span></a></td>
</tr></table>
</td></tr>
<?= $body ?>
</table>
</td></tr>
<tr><td align="center" class="av-muted" style="padding:26px 24px 0;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5F636D;text-align:center;">For any questions, visit our <a href="<?= e($v['base_url']) ?>/contact/" style="color:#8A6406;text-decoration:underline;">help centre</a>.</td></tr>
<tr><td align="center" style="padding:22px 24px 0;text-align:center;">
<div class="av-ink" style="font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:11px;line-height:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#111827;">Afrovanguard</div>
<div style="padding-top:6px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;"><a href="<?= e($v['base_url']) ?>/academy/" class="av-muted" style="color:#5F636D;text-decoration:none;">Academy</a>&nbsp;·&nbsp;<a href="<?= e($v['base_url']) ?>/diary/" class="av-muted" style="color:#5F636D;text-decoration:none;">Diary</a>&nbsp;·&nbsp;<a href="<?= e($v['base_url']) ?>/events/" class="av-muted" style="color:#5F636D;text-decoration:none;">Events</a>&nbsp;·&nbsp;<a href="<?= e($v['preferences_url']) ?>" class="av-muted" style="color:#5F636D;text-decoration:none;">Email preferences</a></div>
<div class="av-muted" style="padding-top:6px;font-family:'Source Sans 3','Source Sans Pro',Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;color:#5F636D;"><?= e($v['postal_address']) ?></div>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
