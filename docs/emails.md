# Transactional email

Every transactional email the site sends is rendered by the drop-in module in
`lib/email/`. That module arrived as a design handoff and is treated as
read-only: the markup, colours, spacing and copy in `lib/email/templates/*.php`
belong to the designer, not to this codebase. The one deliberate exception is
recorded at the bottom of this page.

## How a message is sent

```php
Mailer::sendTemplate($to, 'donation_receipt', $vars, $opt);
```

`Mailer::sendTemplate()` (`lib/Mailer.php`) calls `av_email_render()` from
`lib/email/_layout.php`, which returns `['subject', 'html', 'text']`, and hands
all three to the normal multi-road transport (smtp → gas → resend → relay →
host). The plain-text part comes from the renderer, never from a `strip_tags()`
guess. A template name the module does not know throws, and `sendTemplate()`
turns that into `false` plus a logged `Mailer::$lastError` rather than sending
a half-rendered message.

Two frames exist: the Academy templates sit on the card frame
(`_frame_card.php`), everything else on the centre frame (`_frame.php`).

## The eighteen templates and what fires them

| Template | Fired by |
| --- | --- |
| `sign_in_code` | `Otp::issue()` |
| `welcome` | `Notify` on account creation |
| `password_reset` | `LmsAuth::requestPasswordReset()` |
| `donation_receipt` | `process-donation.php` on a confirmed payment |
| `dues_receipt` | `Membership::grant()`, only when the amount is above zero |
| `event_ticket` | `Summit` registration, carrying the `AVT-` ticket code |
| `diary_reply` | `DiaryRepository::setCommentStatus(…, 'published')` |
| `portal_message` | `MentorPortal::sendMessage()`, throttled to one per 30 min, skipped in quiet hours |
| `mentorship_closing` | `MentorPortal::closeStep('schedule', true)`, once per pairing ever (`mentorships.close_told_at`) |
| `mentor_application_received` | `Mentorship` application, pending applications only |
| `mentor_approved` | `Mentorship` on the transition into approved, not on every save |
| `mentee_matched` | `Mentorship` pairing |
| `academy_enrolled` | `Notify` on enrolment |
| `academy_quiz_passed` | `academy/api.php`, suppressed when the course completes in the same step |
| `academy_certificate` | `Notify`, carrying the certificate serial |
| `academy_lesson_available` | `LmsDrip::cronTick()` |
| `academy_continue` | `LmsDrip::cronTick()` |
| `academy_live_class` | `LmsDrip::cronTick()` |

The three drip templates run off `tasks/cron.php`; everything else is sent in
the request that caused it.

Recipients can mute diary reply notifications without an account through
`diary/api.php?action=reply.mute&t=<token>`.

## Illustrations and the Outlook fallback

`assets/site/email/*.gif` holds sixteen 200x150 animated illustrations, each 36
frames, shown at 100x75. Twelve templates use one; the Academy card templates
use none.

**This is the one place this repo departs from the handoff.** The handoff says
not to replace the GIFs with static images and accepts that Outlook for Windows
shows the first frame. Frame 1 of these animations is the blank start of a
draw-on sequence — `heart`, `bubbles` and `check` measure 0.0% ink — so an
Outlook reader of a donation receipt, a diary reply or a mentor approval saw an
empty 100x75 hole where the picture should be. Each GIF therefore ships a PNG of
its **last** frame beside it, served to Outlook alone:

```html
<!--[if !mso]><!--><img … src="…/heart.gif" …><!--<![endif]-->
<!--[if mso]><img … src="…/heart.png" …><![endif]-->
```

Every other client still receives the untouched GIF at the same size, in the
same box. Nothing else in the templates changed. Regenerate the PNGs with the
last frame of each GIF (`convert x.gif -coalesce -delete 0--2 x.png`) if the
illustrations are ever redrawn.

`tests/emailtemplates.test.php` holds the fallback to that contract: every
referenced GIF must be hidden from Outlook, must have a PNG beside it at the
same dimensions, and that PNG must carry real ink rather than being another
blank frame.
