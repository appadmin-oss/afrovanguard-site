<?php
/**
 * tests/_grading.php — fixtures shared by the teaching-team and grading tests.
 *
 * Not a test file (no .test.php suffix): the grading suites require it. Every
 * helper builds REAL rows through the same code the pages use where there is
 * such code, and through plain INSERTs only for things no page creates (users).
 */
declare(strict_types=1);

if (!function_exists('gt_user')) {
    /** A fresh account with a unique address; returns its id. */
    function gt_user(string $name, string $role = 'learner'): int
    {
        static $n = 0; $n++;
        $email = strtolower(preg_replace('/[^a-z]/i', '', $name)) . '.' . getmypid() . '.' . $n . '@grading.test';
        Database::pdo()->prepare("INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,'x',?,'active',1)")
            ->execute([$name, $email, $role]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** A published course, created the way the Studio creates one; returns its id. */
    function gt_course(string $title): int
    {
        $ac = new AcademyRepository();
        $slug = $ac->save([
            'title' => $title, 'summary' => 's', 'body_html' => '<p>b</p>', 'category' => 'Programme',
            'level' => 'All levels', 'format' => 'Online', 'duration' => '4 weeks', 'price' => 'Free',
            'location' => 'Lagos', 'gradient' => 'g-gold', 'outcomes' => '', 'cta_url' => '',
            'access_type' => 'open', 'price_ngn' => 0, 'pass_code' => '', 'featured' => false,
            'status' => 'published', 'sort' => 0,
        ]);
        return (int) ($ac->bySlug($slug, true)['id'] ?? 0);
    }

    /** Enrol a learner the way joining does. */
    function gt_enrol(int $uid, int $courseId): void { (new LmsRepository())->enrol($uid, $courseId); }

    /** A lesson with a two-question quiz in its own module; returns the lesson row. */
    function gt_quiz_lesson(int $courseId, string $slug, int $pass = 50): array
    {
        $lms = new LmsRepository();
        $mid = $lms->addModule($courseId, 'Module ' . $slug);
        $id = $lms->saveLesson(['module_id' => $mid, 'slug' => $slug, 'title' => 'Quiz ' . $slug, 'body_html' => '<p>q</p>', 'video_url' => '',
            'quiz_json' => json_encode(['pass' => $pass, 'questions' => [
                ['q' => 'One?', 'options' => ['a', 'b'], 'answer' => 0],
                ['q' => 'Two?', 'options' => ['a', 'b'], 'answer' => 1],
            ]])]);
        return $lms->lessonById($id);
    }

    /** A rubric of two criteria worth 10 each (20 points). */
    function gt_rubric(): string
    {
        return "Argument | 10 | 0: missing; 5: thin; 10: clear and evidenced\nEvidence | 10 | 0: none; 10: sourced";
    }

    /** Sign a user in for a page render (LmsAuth memoises per request). Pass 0 to sign out. */
    function gt_sign_in(int $uid): void
    {
        $pdo = Database::pdo();
        if ($uid > 0) {
            $tok = substr(hash('sha256', 'gt' . $uid . microtime()), 0, 40);
            $pdo->prepare('INSERT INTO lms_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')
                ->execute([$uid, hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 3600)]);
            $_COOKIE[LmsAuth::COOKIE] = $tok;
        } else {
            unset($_COOKIE[LmsAuth::COOKIE]);
        }
        (new ReflectionProperty('LmsAuth', 'checked'))->setValue(null, false);
        (new ReflectionProperty('LmsAuth', 'cache'))->setValue(null, null);
    }
}
