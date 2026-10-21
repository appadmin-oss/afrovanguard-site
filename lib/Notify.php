<?php
/**
 * lib/Notify.php — Afrovanguard transactional emails (welcome, enrolment,
 * membership, completion). Thin layer over Mailer with branded copy.
 *
 * Every method is best-effort and self-contained: it builds the message,
 * hands it to Mailer, and swallows any failure so the calling request
 * (registration, payment, lesson completion) always succeeds.
 */
declare(strict_types=1);

final class Notify
{
    private static function first(string $name): string
    {
        $p = trim(explode(' ', trim($name))[0] ?? '');
        return $p !== '' ? $p : 'there';
    }

    private static function safe(callable $fn): void
    {
        try { $fn(); } catch (\Throwable $e) { error_log('[notify] ' . $e->getMessage()); }
    }

    /** A warm, personalised birthday email to a team member / volunteer. */
    public static function birthday(array $person): void
    {
        self::safe(function () use ($person) {
            $email = trim((string) ($person['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            if (!class_exists('Mailer')) return;
            $name = self::first((string) ($person['name'] ?? ''));
            $role = trim((string) ($person['role'] ?? ''));
            $roleLine = $role !== ''
                ? 'Your work as <strong>' . htmlspecialchars($role) . '</strong> helps move us toward one million incorruptible African leaders — and today, we celebrate <em>you</em>.'
                : 'Today, the whole movement pauses to celebrate <em>you</em> — thank you for all you pour into this work.';
            $html = Mailer::shell(
                'Happy Birthday, ' . $name . '! 🎉',
                [
                    'From everyone at Afrovanguard — the warmest happy birthday to you. 🎂',
                    $roleLine,
                    'We hope your day is full of joy, rest and the people you love. Here’s to another year of light and leadership.',
                ],
                ['text' => 'See what the movement is building', 'url' => defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '/'],
                'Happy birthday from the whole Afrovanguard family.'
            );
            Mailer::send($email, 'Happy Birthday from Afrovanguard 🎉', $html);
        });
    }

    /** A private birthday note from a site visitor, delivered to the celebrant. */
    public static function birthdayWish(array $person, string $from, string $message): void
    {
        self::safe(function () use ($person, $from, $message) {
            $email = trim((string) ($person['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            if (!class_exists('Mailer')) return;
            $name = self::first((string) ($person['name'] ?? ''));
            $fromClean = htmlspecialchars(trim($from) !== '' ? $from : 'A well-wisher');
            $msgHtml = nl2br(htmlspecialchars($message));
            $html = Mailer::shell(
                'A birthday wish for you, ' . $name . ' 🎂',
                [
                    'Someone from the Afrovanguard community wanted to celebrate you today:',
                    '<em style="display:block;padding:12px 16px;border-left:3px solid #f3b416;background:#fffbf0;border-radius:0 6px 6px 0;">“' . $msgHtml . '”</em>',
                    '— ' . $fromClean,
                ],
                ['text' => 'Visit Afrovanguard', 'url' => defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '/'],
                'A birthday wish from the Afrovanguard community.'
            );
            Mailer::send($email, 'A birthday wish for you 🎂', $html);
        });
    }

    /* modules/EMAILS.md: welcome — sent when membership is confirmed. */
    public static function welcome(array $user): void
    {
        self::safe(function () use ($user) {
            Mailer::sendTemplate((string) $user['email'], 'welcome', [
                'first_name' => self::first((string) $user['name']),
                'member_id'  => (string) ($user['member_ref'] ?? $user['ref'] ?? $user['id'] ?? ''),
                'joined_on'  => date('j M Y', strtotime((string) ($user['created_at'] ?? 'now')) ?: time()),
                'chapter'    => (string) ($user['chapter'] ?? 'Alimosho'),
                'portal_url' => (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/portal/',
            ]);
        });
    }

    /* modules/EMAILS.md: academy_enrolled — card frame, no illustration. */
    public static function enrolled(array $user, array $course): void
    {
        self::safe(function () use ($user, $course) {
            Mailer::sendTemplate((string) $user['email'], 'academy_enrolled', [
                'first_name'    => self::first((string) $user['name']),
                'course_title'  => (string) $course['title'],
                'course_url'    => academy_url($course['slug'] . '/learn/'),
                'course_length' => (string) ($course['length_label'] ?? $course['duration'] ?? 'Self-paced'),
                'lessons_count' => (string) ($course['lessons_count'] ?? $course['lessons'] ?? ''),
                'starts_on'     => (string) ($course['starts_on'] ?? 'Today'),
            ]);
        });
    }

    public static function membership(array $user): void
    {
        self::safe(function () use ($user) {
            $name = self::first((string) $user['name']);
            $html = Mailer::shell(
                'Your membership is active',
                [
                    'Thank you, ' . $name . '. Your membership is active — every members’ programme is now open to you, alongside priority cohorts and your certificates.',
                    'Most of all, thank you for backing the mission to raise one million incorruptible leaders for Africa.',
                ],
                ['text' => 'Browse members’ programmes', 'url' => academy_url('')],
                'Your Afrovanguard membership is active.'
            );
            Mailer::send((string) $user['email'], 'Your Afrovanguard membership is active', $html);
        });
    }

    /**
     * modules/EMAILS.md: academy_quiz_passed — on a pass only.
     *
     * §4 is explicit that there is no email on a fail, which is the right
     * call and worth keeping deliberately: a knowledge check you can retake
     * is not news, and mailing somebody their failure is how people stop
     * taking the checks at all.
     */
    public static function quizPassed(array $user, array $lesson, array $result, string $nextUrl): void
    {
        self::safe(function () use ($user, $lesson, $result, $nextUrl) {
            if (empty($result['passed'])) return;
            Mailer::sendTemplate((string) $user['email'], 'academy_quiz_passed', [
                'first_name' => self::first((string) $user['name']),
                'quiz_title' => (string) ($lesson['title'] ?? 'Knowledge check'),
                'score'      => (int) ($result['score'] ?? 0) . '%',
                'pass_mark'  => (int) ($result['pass'] ?? 70) . '%',
                'next_url'   => $nextUrl,
            ]);
        });
    }

    /* modules/EMAILS.md: academy_certificate — card frame, no illustration.
       The serial is the certificate id the public verify page checks, so the
       email carries the same one rather than a second reference. */
    public static function completed(array $user, array $course, ?string $certUrl): void
    {
        self::safe(function () use ($user, $course, $certUrl) {
            $serial = '';
            try {
                $c = (new LmsRepository())->getCertificate((int) $user['id'], (int) $course['id']);
                $serial = (string) ($c['serial'] ?? '');
            } catch (\Throwable $e) { /* the email still goes without it */ }
            $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            Mailer::sendTemplate((string) $user['email'], 'academy_certificate', [
                'first_name'      => self::first((string) $user['name']),
                'course_title'    => (string) $course['title'],
                'completed_on'    => date('j M Y'),
                'certificate_id'  => $serial,
                'certificate_url' => (string) ($certUrl ?: academy_url($course['slug'] . '/certificate')),
                'verify_url'      => $serial !== '' ? $site . '/academy/verify.php?id=' . rawurlencode($serial)
                                                    : $site . '/academy/verify.php',
            ]);
        });
    }
}
