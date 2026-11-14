<?php
/**
 * lib/PeerReview.php — learners reviewing each other's work.
 *
 * On a peer item each learner, once they have submitted, is given up to
 * `peer_count` other submissions to review with the item's rubric. The system
 * chooses them; a learner never chooses and is never given their own.
 *
 * Allocation is balanced: each request takes the submissions with the fewest
 * reviewers so far. Ties go first to submissions whose authors have been given
 * the fewest reviews to do — an author who has not reviewed yet will review
 * later and cannot review themselves, so covering their work now is what keeps
 * the last person to arrive from being left one review short.
 *
 * A submission's score is the median of its completed reviews once it has
 * `peer_count` of them (GradingWork::recompute). If it is still short when the
 * grace period after the due date ends, it drops into the instructor's marking
 * queue instead, and an instructor may mark any peer submission at any time
 * (an override).
 */
declare(strict_types=1);

final class PeerReview
{
    /**
     * Give this learner their reviews (up to the item's peer_count). Safe to
     * call on every visit: it only tops up, never reassigns.
     * @return array{ok:bool, error?:string, assigned?:int, tasks?:array}
     */
    public static function assign(int $itemId, int $reviewerId): array
    {
        Grading::ensure();
        $item = Grading::item($itemId);
        if (!$item || $item['kind'] !== 'peer' || $item['status'] !== 'published') return ['ok' => false, 'error' => 'This item is not peer reviewed.'];
        if (!self::hasSubmitted($itemId, $reviewerId)) return ['ok' => false, 'code' => 'submit_first', 'error' => 'Submit your own work first — then you will be given others to review.'];
        $w = Grading::window($item);
        if ($w['grace_ends'] !== null && Grading::now() > $w['grace_ends']) return ['ok' => true, 'assigned' => 0, 'tasks' => self::tasks($itemId, $reviewerId)];

        $db = Database::pdo();
        $st = $db->prepare('SELECT COUNT(*) FROM peer_reviews WHERE item_id = ? AND reviewer_id = ?');
        $st->execute([$itemId, $reviewerId]);
        $need = (int) $item['peer_count'] - (int) $st->fetchColumn();
        $assigned = 0;
        if ($need > 0) {
            $cand = $db->prepare(
                "SELECT s.id,
                        (SELECT COUNT(*) FROM peer_reviews r WHERE r.submission_id = s.id) AS received,
                        (SELECT COUNT(*) FROM peer_reviews g WHERE g.item_id = s.item_id AND g.reviewer_id = s.user_id) AS given
                   FROM grade_submissions s
                  WHERE s.item_id = ? AND s.user_id <> ?
                    AND s.attempt = (SELECT MAX(s2.attempt) FROM grade_submissions s2 WHERE s2.item_id = s.item_id AND s2.user_id = s.user_id)
                    AND NOT EXISTS (SELECT 1 FROM peer_reviews x WHERE x.submission_id = s.id AND x.reviewer_id = ?)
                  ORDER BY received, given, s.submitted_at, s.id"
            );
            $cand->execute([$itemId, $reviewerId, $reviewerId]);
            $ins = $db->prepare(Database::insertIgnore('peer_reviews', ['item_id', 'submission_id', 'reviewer_id', 'assigned_at', 'done_at']));
            foreach (array_slice($cand->fetchAll(PDO::FETCH_ASSOC), 0, $need) as $c) {
                $ins->execute([$itemId, (int) $c['id'], $reviewerId, Grading::stamp(), '']);
                $assigned += $ins->rowCount();
            }
        }
        return ['ok' => true, 'assigned' => $assigned, 'tasks' => self::tasks($itemId, $reviewerId)];
    }

    public static function hasSubmitted(int $itemId, int $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM grade_submissions WHERE item_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$itemId, $userId]);
        return (bool) $st->fetchColumn();
    }

    /** This reviewer's reviews on an item, in the order they were given. */
    public static function tasks(int $itemId, int $reviewerId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM peer_reviews WHERE item_id = ? AND reviewer_id = ? ORDER BY id');
        $st->execute([$itemId, $reviewerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function get(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM peer_reviews WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Record a review. Only the assigned reviewer, who must have submitted
     * themselves, and only until the grace period ends.
     */
    public static function review(int $reviewId, int $reviewerId, array $scores, string $feedback): array
    {
        $r = self::get($reviewId);
        if (!$r || (int) $r['reviewer_id'] !== $reviewerId) return ['ok' => false, 'error' => 'That review is not yours.'];
        $item = Grading::item((int) $r['item_id']);
        if (!$item) return ['ok' => false, 'error' => 'No such item.'];
        if (!self::hasSubmitted((int) $item['id'], $reviewerId)) return ['ok' => false, 'error' => 'Submit your own work first.'];
        $sub = GradingWork::submission((int) $r['submission_id']);
        if (!$sub || (int) $sub['user_id'] === $reviewerId) return ['ok' => false, 'error' => 'You cannot review your own work.'];
        $w = Grading::window($item);
        if ($w['grace_ends'] !== null && Grading::now() > $w['grace_ends']) return ['ok' => false, 'error' => 'Peer review for this item has closed.'];
        $sc = Grading::scoreRubric($item['rubric'], array_values($scores));
        if (!$sc['ok']) return $sc;
        Database::pdo()->prepare('UPDATE peer_reviews SET scores_json = ?, points = ?, max_points = ?, feedback = ?, done_at = ? WHERE id = ?')
            ->execute([json_encode($sc['scores']), $sc['points'], $sc['max'], mb_substr(trim($feedback), 0, 5000), Grading::stamp(), $reviewId]);
        GradingWork::recompute((int) $r['submission_id']);
        return ['ok' => true];
    }

    /** Completed reviews of a submission. */
    public static function doneReviews(int $subId): array
    {
        $st = Database::pdo()->prepare("SELECT * FROM peer_reviews WHERE submission_id = ? AND done_at <> '' ORDER BY id");
        $st->execute([$subId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Completed review scores of a submission, as percentages. */
    public static function doneScores(int $subId): array
    {
        $out = [];
        foreach (self::doneReviews($subId) as $r) {
            if ((float) $r['max_points'] > 0) $out[] = (float) $r['points'] / (float) $r['max_points'] * 100;
        }
        return $out;
    }
}
