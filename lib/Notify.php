<?php
/**
 * lib/Notify.php — Academy transactional emails (welcome, enrolment,
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

    public static function welcome(array $user): void
    {
        self::safe(function () use ($user) {
            $name = self::first((string) $user['name']);
            $html = Mailer::shell(
                'Welcome to the Academy, ' . $name . ' 👋',
                [
                    'Your free Afrovanguard Academy account is ready. You can now enrol in programmes, track your progress lesson by lesson, take knowledge checks and earn verifiable certificates.',
                    'Start with our open programmes — no payment needed — and pick up exactly where you leave off, on any device.',
                ],
                ['text' => 'Explore programmes', 'url' => academy_url('')],
                'Your Afrovanguard Academy account is ready.'
            );
            Mailer::send((string) $user['email'], 'Welcome to Afrovanguard Academy', $html);
        });
    }

    public static function enrolled(array $user, array $course): void
    {
        self::safe(function () use ($user, $course) {
            $name = self::first((string) $user['name']);
            $url  = academy_url($course['slug'] . '/learn/');
            $html = Mailer::shell(
                'You’re enrolled in ' . $course['title'],
                [
                    'Hi ' . $name . ', your place in <strong>' . htmlspecialchars($course['title']) . '</strong> is confirmed. Every lesson, quiz and your certificate are now unlocked.',
                    'Learn at your own pace — your progress is saved automatically as you go.',
                ],
                ['text' => 'Start learning', 'url' => $url],
                'Your enrolment in ' . $course['title'] . ' is confirmed.'
            );
            Mailer::send((string) $user['email'], 'You’re enrolled — ' . $course['title'], $html);
        });
    }

    public static function membership(array $user): void
    {
        self::safe(function () use ($user) {
            $name = self::first((string) $user['name']);
            $html = Mailer::shell(
                'Welcome to Academy membership 🌟',
                [
                    'Thank you, ' . $name . '. Your membership is active — every members’ programme is now open to you, alongside priority cohorts and your certificates.',
                    'Most of all, thank you for backing the mission to raise one million incorruptible leaders for Africa.',
                ],
                ['text' => 'Browse members’ programmes', 'url' => academy_url('')],
                'Your Afrovanguard Academy membership is active.'
            );
            Mailer::send((string) $user['email'], 'Your Academy membership is active', $html);
        });
    }

    public static function completed(array $user, array $course, ?string $certUrl): void
    {
        self::safe(function () use ($user, $course, $certUrl) {
            $name = self::first((string) $user['name']);
            $rows = [
                'Congratulations, ' . $name . '! You’ve completed <strong>' . htmlspecialchars($course['title']) . '</strong> — every lesson done.',
                'Your certificate of completion is ready to view, download and share.',
            ];
            $cta = $certUrl ? ['text' => 'Get your certificate', 'url' => $certUrl] : ['text' => 'Back to the programme', 'url' => academy_url($course['slug'] . '/')];
            $html = Mailer::shell('You completed ' . $course['title'] . ' 🎓', $rows, $cta, 'Your certificate is ready.');
            Mailer::send((string) $user['email'], 'Certificate ready — ' . $course['title'], $html);
        });
    }
}
