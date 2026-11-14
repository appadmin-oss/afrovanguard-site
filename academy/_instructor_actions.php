<?php
/**
 * academy/_instructor_actions.php — the POST handlers for instructors being
 * assessed: a learner's feedback on a course (from grades.php), and the staff
 * and instructor forms in the teach console (moderating feedback,
 * observations, reflections, the observation criteria).
 *
 * Same contract as _grading_actions.php: the CSRF token is checked before
 * anything else is read, the acting id is always the signed-in person's, and
 * every permission is the library's (CourseTeam::can through
 * LearnerFeedback / Observations), which refuses with a sentence the page
 * shows. Each returns ['ok' => bool, 'msg' => string, 'to' => query string].
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading_actions.php';

/** A learner gives (or edits) their feedback on the course (grades.php?feedback=1). */
function avin_feedback_action(array $course, int $uid, array $post): array
{
    if (!av_csrf_valid((string) ($post['csrf'] ?? ''))) return ['ok' => false, 'msg' => 'Your session expired — reload the page and try again.', 'to' => 'feedback=1'];
    $d = ['share' => !empty($post['share'])];
    foreach (array_keys(LearnerFeedback::PROMPTS) as $k) $d[$k] = (string) ($post[$k] ?? '');
    $r = LearnerFeedback::save((int) $course['id'], $uid, $d);
    if (empty($r['ok'])) return ['ok' => false, 'msg' => (string) ($r['error'] ?? 'That did not work.'), 'to' => 'feedback=1'];
    return ['ok' => true, 'to' => 'feedback=1', 'msg' => ($r['edited'] ? 'Your feedback is updated.' : 'Thank you — your feedback is saved.')
        . ' You can change it for ' . LearnerFeedback::EDIT_DAYS . ' days. Academy staff read it before the course team does.'];
}

/** The teach console's assessment forms (no course in the URL). */
function avin_action(int $uid, array $post): array
{
    if (!av_csrf_valid((string) ($post['csrf'] ?? ''))) return ['ok' => false, 'msg' => 'Your session expired — reload the page and try again.', 'to' => ''];
    $act = (string) ($post['act'] ?? '');
    $id = (int) ($post['id'] ?? 0);
    $res = ['ok' => false, 'error' => 'Unknown action.']; $ok = ''; $to = '';
    switch ($act) {
        case 'moderate':
            $res = LearnerFeedback::moderate($id, $uid, (string) ($post['decision'] ?? ''), (string) ($post['reason'] ?? ''));
            $ok = ($res['state'] ?? '') === 'approved' ? 'Approved — the course team can read it once ' . LearnerFeedback::MIN_LEARNERS . ' learners have given feedback.' : 'Hidden.';
            $to = 'view=feedback';
            break;
        case 'obs_save':
            $res = Observations::save([
                'id' => $id, 'course_id' => (int) ($post['course_id'] ?? 0), 'instructor_id' => (int) ($post['instructor_id'] ?? 0),
                'observed_on' => (string) ($post['observed_on'] ?? ''), 'target_kind' => (string) ($post['target_kind'] ?? ''),
                // The form offers one picker per kind (target_live, target_lesson); the chosen kind says which counts.
                'target_id' => (int) ($post['target_' . preg_replace('/[^a-z]/', '', (string) ($post['target_kind'] ?? ''))] ?? 0),
                'target_note' => (string) ($post['target_note'] ?? ''), 'scores' => (array) ($post['score'] ?? []),
                'strengths' => (string) ($post['strengths'] ?? ''), 'develop' => (string) ($post['develop'] ?? ''),
                'action' => (string) ($post['action_text'] ?? ''), 'follow_up_on' => (string) ($post['follow_up_on'] ?? ''),
                'final' => !empty($post['final']),
            ], $uid);
            $ok = !empty($res['final']) ? 'Marked final and shared with the instructor.' : 'Draft saved. The instructor cannot see it until you mark it final.';
            $to = !empty($res['ok']) ? 'view=observation&id=' . (int) $res['id']
                : ($id > 0 ? 'view=observation&id=' . $id : 'view=observation&new=1&cid=' . (int) ($post['course_id'] ?? 0) . '&u=' . (int) ($post['instructor_id'] ?? 0));
            break;
        case 'obs_delete': $res = Observations::deleteDraft($id, $uid); $ok = 'Draft deleted.'; $to = 'view=observations'; break;
        case 'reflect':    $res = Observations::reflect($id, $uid, (string) ($post['reflection'] ?? '')); $ok = 'Your reflection is saved.'; $to = 'view=observation&id=' . $id; break;
        case 'criteria':   $res = Observations::setCriteria((string) ($post['criteria'] ?? ''), $uid); $ok = 'Criteria saved. New observations are scored against them.'; $to = 'view=observations'; break;
    }
    return !empty($res['ok']) ? ['ok' => true, 'msg' => $ok, 'to' => $to] : ['ok' => false, 'msg' => (string) ($res['error'] ?? 'That did not work.'), 'to' => $to];
}
