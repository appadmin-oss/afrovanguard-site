<?php
/**
 * academy/certificate.php — branded completion certificate (PNG).
 *   /academy/<course>/certificate   (requires sign-in and a PASS: every item
 *   done, every graded item passed, the course's pass mark reached)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_cert.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$ac = new AcademyRepository();
$lms = new LmsRepository();
$course = $slug ? $ac->bySlug($slug) : null;
$user = LmsAuth::user();

if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); return; }
if (!$user) { header('Location: ' . academy_url($slug . '/learn')); return; }
$cert = $lms->issueCertificate((int) $user['id'], (int) $course['id']);
/* Not earned yet: the grades page says exactly what is left, which a 403 does not. */
if (!$cert) { header('Location: ' . academy_url($slug . '/grades')); exit; }

/* A team-graded course's certificate states the grade it was issued on — the
   grade recorded at issue, never today's (lib/Grading.php: certificates are
   never revoked or rewritten by a later regrade). */
$gradeLine = ($cert['grade_pct'] ?? null) !== null ? 'with a final grade of ' . Grading::num((float) $cert['grade_pct']) . '%' : '';

// ?format=txt is the plain-text version (what a host without GD serves anyway).
if (($_GET['format'] ?? '') === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Certificate ' . $cert['serial'] . ' — ' . $user['name'] . ' — ' . $course['title'] . ($gradeLine !== '' ? ' — ' . $gradeLine : '');
    return;
}
avac_cert_png((string) $user['name'], 'course', (string) $course['title'], (string) $cert['issued_at'], (string) $cert['serial'], 'afrovanguard-certificate-' . $slug . '.png', $gradeLine);
