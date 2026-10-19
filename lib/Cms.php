<?php
/**
 * lib/Cms.php — the Studio's content store: one place for everything the site
 * lets staff edit, with the same life for each of them.
 *
 *   Document   a thing that is edited and published as one: the homepage hero
 *              ('hero'), a page ('page:about.html'), the site details ('site').
 *   Draft      the change being prepared. Nothing on the site moves until it is
 *              published. Saving again replaces the draft.
 *   Revision   an append-only log row: every save, submission, send-back,
 *              schedule and publish, with who and when. A publish carries a full
 *              snapshot of what went live, so any version can be compared with
 *              any other and put back.
 *   State      live (nothing waiting) · draft · review (an editor asked for it to
 *              go live) · scheduled (it goes live by itself at a time).
 *
 * What a document IS stays with its own class — SiteHome renders the hero,
 * SitePages rewrites a page, SiteSettings writes the footer — and this class
 * calls them through four small adapters (live, clean, same, write). A new kind
 * of content is a new adapter, not a new store.
 *
 * Shared hosting: no worker. Scheduled publishes run from tasks/cron.php and,
 * as a backstop, whenever the Studio lists content (runDue()).
 */
declare(strict_types=1);

final class Cms
{
    public const STATES = ['live', 'draft', 'review', 'scheduled'];
    /** Consecutive saves by one person within this many seconds are one revision. */
    public const COALESCE = 600;
    public const TIMELINE_MAX = 60;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $db = Database::pdo();
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS cms_docs (
            ref VARCHAR(190) PRIMARY KEY,
            state VARCHAR(12) NOT NULL DEFAULT 'live',
            draft TEXT,
            note TEXT,
            submitted_by VARCHAR(190) NOT NULL DEFAULT '',
            submitted_at VARCHAR(32) NOT NULL DEFAULT '',
            scheduled_at VARCHAR(32) NOT NULL DEFAULT '',
            scheduled_by VARCHAR(190) NOT NULL DEFAULT '',
            updated_by VARCHAR(190) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS cms_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref VARCHAR(190) NOT NULL,
            action VARCHAR(16) NOT NULL,
            data TEXT,
            note TEXT,
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL
        )");
        Database::ensureIndex($db, 'idx_cms_rev_ref', 'cms_revisions', 'ref, id');
        Database::ensureIndex($db, 'idx_cms_docs_state', 'cms_docs', 'state, scheduled_at');
        self::importLegacy();
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function json(mixed $v): string { return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

    /* ── The documents ────────────────────────────────────────────────── */

    /** Every document the Studio edits, in the order it lists them. */
    public static function refs(): array
    {
        $out = ['hero'];
        foreach (array_keys(SitePages::PAGES) as $f) if (is_file(SitePages::path($f))) $out[] = 'page:' . $f;
        $out[] = 'site';
        return $out;
    }

    public static function type(string $ref): ?string
    {
        if ($ref === 'hero' || $ref === 'site') return $ref;
        if (str_starts_with($ref, 'page:') && SitePages::known(substr($ref, 5))) return 'page';
        return null;
    }

    public static function known(string $ref): bool { return self::type($ref) !== null; }

    public static function title(string $ref): string
    {
        return match (self::type($ref)) {
            'hero' => 'Homepage hero',
            'site' => 'Site details',
            'page' => SitePages::PAGES[substr($ref, 5)],
            default => $ref,
        };
    }

    /** Where a visitor sees it. */
    public static function href(string $ref): string
    {
        return match (self::type($ref)) { 'page' => SitePages::href(substr($ref, 5)), default => '/' };
    }

    public static function previewUrl(string $ref): string
    {
        return match (self::type($ref)) {
            'hero' => '/admin/preview.php',
            'page' => '/admin/preview.php?page=' . rawurlencode(substr($ref, 5)),
            default => '/admin/preview.php?site=1',
        };
    }

    /* ── Adapters: what each kind of document is ──────────────────────── */

    /** A full snapshot of what is live now. */
    public static function liveOf(string $ref): ?array
    {
        self::ensure();
        switch (self::type($ref)) {
            case 'page': return SitePages::snapshot(substr($ref, 5));
            case 'hero': return self::lastPublished($ref) ?? SiteHome::defaults();
            case 'site': return self::lastPublished($ref);
        }
        return null;
    }

    /** Validate input as a draft. A page's draft is only what differs from the live page. */
    private static function clean(string $ref, array $in): array
    {
        switch (self::type($ref)) {
            case 'hero':
                $c = SiteHome::clean($in);
                return $c['ok'] ? ['ok' => true, 'data' => $c['doc']] : $c;
            case 'site':
                return SiteSettings::clean($in);
            case 'page':
                $file = substr($ref, 5);
                $c = SitePages::clean($file, $in);
                return $c['ok'] ? ['ok' => true, 'data' => SitePages::changes($file, $c['draft'])] : $c;
        }
        return ['ok' => false, 'error' => 'That is not something the Studio edits.'];
    }

    /** True when $draft would change nothing. */
    private static function same(string $ref, array $draft): bool
    {
        return match (self::type($ref)) {
            'page' => $draft === [],
            'site' => $draft === (self::liveOf($ref) ?? SiteSettings::defaults()),
            default => $draft === self::liveOf($ref),
        };
    }

    /** Put $data live. */
    private static function write(string $ref, array $data): array
    {
        switch (self::type($ref)) {
            case 'hero':
                return SiteHome::writeIndex(SiteHome::heroHtml($data));
            case 'page':
                return SitePages::write(substr($ref, 5), $data);
            case 'site':
                $r = SiteSettings::apply($data);
                SiteSettings::forget();
                return $r;
        }
        return ['ok' => false, 'error' => 'That is not something the Studio edits.'];
    }

    /** A page's draft is a delta; the rest are whole documents. What the draft makes of the live document: */
    public static function draftView(string $ref): ?array
    {
        $d = self::draft($ref);
        if ($d === null) return null;
        return self::type($ref) === 'page' ? SitePages::overlay(self::liveOf($ref) ?? [], $d) : $d;
    }

    /* ── Reading ──────────────────────────────────────────────────────── */

    public static function doc(string $ref): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM cms_docs WHERE ref = ?');
        $st->execute([$ref]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return $r + ['ref' => $ref, 'state' => 'live', 'draft' => null, 'note' => '', 'submitted_by' => '', 'submitted_at' => '',
                     'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => '', 'updated_at' => ''];
    }

    public static function draft(string $ref): ?array
    {
        $d = self::doc($ref)['draft'];
        $j = $d ? json_decode((string) $d, true) : null;
        return is_array($j) ? $j : null;
    }

    public static function state(string $ref): string { return self::doc($ref)['state'] ?: 'live'; }

    private static function lastPublished(string $ref): ?array
    {
        $st = Database::pdo()->prepare("SELECT data FROM cms_revisions WHERE ref = ? AND action IN ('publish', 'baseline') ORDER BY id DESC LIMIT 1");
        $st->execute([$ref]);
        $j = json_decode((string) $st->fetchColumn(), true);
        return is_array($j) ? $j : null;
    }

    /** Published versions, newest first: [{id, action, by, at}]; the first is what is live. */
    public static function versions(string $ref): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT id, action, created_by, created_at, note FROM cms_revisions WHERE ref = ? AND action IN ('publish', 'baseline') ORDER BY id DESC");
        $st->execute([$ref]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'action' => $r['action'], 'by' => $r['created_by'], 'at' => $r['created_at'], 'note' => (string) $r['note']],
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** The versions before the live one, newest first (what "put back" offers). */
    public static function history(string $ref): array { return array_slice(self::versions($ref), 1); }

    /** Everything that happened to a document, newest first. */
    public static function timeline(string $ref, int $limit = self::TIMELINE_MAX): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id, action, note, created_by, created_at, data IS NOT NULL AS has_data FROM cms_revisions WHERE ref = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $st->execute([$ref]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'action' => $r['action'], 'note' => (string) $r['note'], 'by' => $r['created_by'], 'at' => $r['created_at'], 'data' => (bool) $r['has_data']],
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function revision(string $ref, int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM cms_revisions WHERE ref = ? AND id = ?');
        $st->execute([$ref, $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['data'] = $r['data'] !== null ? json_decode((string) $r['data'], true) : null;
        return $r;
    }

    /** Everything the Studio's overview lists. Runs anything due first. */
    public static function overview(): array
    {
        self::runDue();
        $out = [];
        foreach (self::refs() as $ref) {
            $d = self::doc($ref);
            $draft = self::draft($ref);
            $out[] = ['ref' => $ref, 'type' => self::type($ref), 'title' => self::title($ref), 'href' => self::href($ref),
                'state' => $d['state'] ?: 'live', 'changes' => $draft === null ? 0 : (self::type($ref) === 'page' ? SitePages::count($draft) : count(self::diff(self::liveOf($ref) ?? [], $draft, $ref))),
                'updated_by' => $d['updated_by'], 'updated_at' => $d['updated_at'], 'scheduled_at' => $d['scheduled_at'],
                'submitted_by' => $d['submitted_by'], 'note' => (string) $d['note'], 'published_at' => self::versions($ref)[0]['at'] ?? ''];
        }
        return $out;
    }

    /* ── The workflow ─────────────────────────────────────────────────── */

    private static function log(string $ref, string $action, ?array $data, string $by, string $note = ''): int
    {
        $db = Database::pdo();
        $db->prepare('INSERT INTO cms_revisions (ref, action, data, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$ref, $action, $data === null ? null : self::json($data), mb_substr($note, 0, 500), mb_substr($by, 0, 190), self::now()]);
        return (int) $db->lastInsertId();
    }

    private static function put(string $ref, array $f): void
    {
        $db = Database::pdo();
        $f['updated_at'] = $f['updated_at'] ?? self::now();
        $has = $db->prepare('SELECT 1 FROM cms_docs WHERE ref = ?'); $has->execute([$ref]);
        if ($has->fetchColumn()) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($f)));
            $db->prepare("UPDATE cms_docs SET $set WHERE ref = ?")->execute([...array_values($f), $ref]);
        } else {
            $f['ref'] = $ref;
            $cols = implode(', ', array_keys($f)); $ph = implode(', ', array_fill(0, count($f), '?'));
            $db->prepare("INSERT INTO cms_docs ($cols) VALUES ($ph)")->execute(array_values($f));
        }
    }

    /**
     * Save the draft. A draft that would change nothing clears itself. Saving
     * takes a document out of review or off the schedule: what was approved is
     * not what is there any more.
     */
    public static function save(string $ref, array $in, string $by): array
    {
        if (!self::known($ref)) return ['ok' => false, 'error' => 'That is not something the Studio edits.'];
        self::ensure();
        $c = self::clean($ref, $in);
        if (!$c['ok']) return $c;
        $data = $c['data'];
        if (self::same($ref, $data)) {
            self::put($ref, ['draft' => null, 'state' => 'live', 'note' => '', 'scheduled_at' => '', 'scheduled_by' => '', 'submitted_by' => '', 'submitted_at' => '', 'updated_by' => $by]);
            return ['ok' => true, 'state' => 'live', 'data' => $data, 'changes' => 0];
        }
        $was = self::doc($ref);
        self::put($ref, ['draft' => self::json($data), 'state' => 'draft', 'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => $by]);
        // One revision per sitting: a run of saves by one person is one row.
        $db = Database::pdo();
        $st = $db->prepare('SELECT id, action, created_by, created_at FROM cms_revisions WHERE ref = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$ref]);
        $last = $st->fetch(PDO::FETCH_ASSOC);
        if ($last && $last['action'] === 'save' && $last['created_by'] === mb_substr($by, 0, 190) && strtotime($last['created_at'] . ' UTC') > time() - self::COALESCE) {
            $db->prepare('UPDATE cms_revisions SET data = ?, created_at = ? WHERE id = ?')->execute([self::json($data), self::now(), $last['id']]);
        } else {
            self::log($ref, 'save', $data, $by, in_array($was['state'], ['review', 'scheduled'], true) ? 'Edited after it was ' . ($was['state'] === 'review' ? 'sent for review' : 'scheduled') . '; it needs approving again.' : '');
        }
        return ['ok' => true, 'state' => 'draft', 'data' => $data, 'changes' => self::type($ref) === 'page' ? SitePages::count($data) : count(self::diff(self::liveOf($ref) ?? [], $data, $ref))];
    }

    public static function discard(string $ref, string $by): array
    {
        if (!self::known($ref)) return ['ok' => false, 'error' => 'That is not something the Studio edits.'];
        if (self::draft($ref) === null) return ['ok' => true];
        self::put($ref, ['draft' => null, 'state' => 'live', 'note' => '', 'scheduled_at' => '', 'scheduled_by' => '', 'submitted_by' => '', 'submitted_at' => '', 'updated_by' => $by]);
        self::log($ref, 'discard', null, $by);
        return ['ok' => true];
    }

    /** An editor asks for the draft to go live. */
    public static function submit(string $ref, string $by, string $note = ''): array
    {
        if (self::draft($ref) === null) return ['ok' => false, 'error' => 'There is no draft to send for review. Make a change and save it first.'];
        $note = mb_substr(trim($note), 0, 500);
        self::put($ref, ['state' => 'review', 'note' => $note, 'submitted_by' => $by, 'submitted_at' => self::now(), 'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => $by]);
        self::log($ref, 'submit', null, $by, $note);
        return ['ok' => true, 'state' => 'review'];
    }

    /** A reviewer sends it back with a reason. The draft stays. */
    public static function reject(string $ref, string $by, string $note): array
    {
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') return ['ok' => false, 'error' => 'Say what needs changing, so the editor knows.'];
        if (self::state($ref) !== 'review') return ['ok' => false, 'error' => 'That is not waiting for review.'];
        self::put($ref, ['state' => 'draft', 'note' => $note, 'updated_by' => $by]);
        self::log($ref, 'reject', null, $by, $note);
        return ['ok' => true, 'state' => 'draft'];
    }

    /**
     * Put the draft live. The first publish of a document records what was live
     * before it ('baseline'), so even the first change can be put back.
     */
    public static function publish(string $ref, string $by, string $note = ''): array
    {
        if (!self::known($ref)) return ['ok' => false, 'error' => 'That is not something the Studio edits.'];
        self::ensure();
        $draft = self::draft($ref);
        if ($draft === null) return ['ok' => false, 'error' => 'Nothing to publish: there is no draft.'];
        $c = self::clean($ref, self::type($ref) === 'page' ? $draft : $draft);
        if (!$c['ok']) return $c;
        if (self::same($ref, $c['data'])) {
            self::put($ref, ['draft' => null, 'state' => 'live', 'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => $by]);
            return ['ok' => false, 'error' => 'Nothing to publish: the draft matches what is live.'];
        }
        if (!self::versions($ref)) self::log($ref, 'baseline', self::liveOf($ref) ?? (self::type($ref) === 'site' ? SiteSettings::defaults() : []), 'the site as it was');
        $w = self::write($ref, $c['data']);
        if (!$w['ok']) return $w;
        $snap = self::type($ref) === 'page' ? SitePages::snapshot(substr($ref, 5)) : $c['data'];
        $id = self::log($ref, 'publish', $snap, $by, $note);
        self::put($ref, ['draft' => null, 'state' => 'live', 'note' => '', 'submitted_by' => '', 'submitted_at' => '', 'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => $by]);
        if (class_exists('Events')) { try { Events::emit('content.published', ['ref' => $ref, 'by' => $by, 'revision' => $id]); } catch (Throwable $e) { /* the publish stands */ } }
        return ['ok' => true, 'revision' => $id] + array_intersect_key($w, ['pages' => 1]);
    }

    /** Publish the draft by itself at $at (site time, 'Y-m-d H:i' or ISO). */
    public static function schedule(string $ref, string $at, string $by): array
    {
        if (self::draft($ref) === null) return ['ok' => false, 'error' => 'There is no draft to schedule.'];
        // A time with no zone is the site's time (Lagos), as the Studio's picker sends it.
        try { $t = (new DateTimeImmutable($at, new DateTimeZone('Africa/Lagos')))->getTimestamp(); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'That date and time could not be read.']; }
        if ($t <= time() + 30) return ['ok' => false, 'error' => 'Choose a time in the future, or publish now.'];
        if ($t > time() + 366 * 86400) return ['ok' => false, 'error' => 'Schedule within the next year.'];
        $utc = gmdate('Y-m-d H:i:s', $t);
        self::put($ref, ['state' => 'scheduled', 'scheduled_at' => $utc, 'scheduled_by' => $by, 'updated_by' => $by]);
        self::log($ref, 'schedule', null, $by, $utc . ' UTC');
        return ['ok' => true, 'state' => 'scheduled', 'scheduled_at' => $utc];
    }

    public static function unschedule(string $ref, string $by): array
    {
        if (self::state($ref) !== 'scheduled') return ['ok' => false, 'error' => 'That is not scheduled.'];
        self::put($ref, ['state' => 'draft', 'scheduled_at' => '', 'scheduled_by' => '', 'updated_by' => $by]);
        self::log($ref, 'unschedule', null, $by);
        return ['ok' => true, 'state' => 'draft'];
    }

    /** Publish whatever is due. Called by tasks/cron.php and by overview(). */
    public static function runDue(?int $now = null): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT ref, scheduled_by FROM cms_docs WHERE state = 'scheduled' AND scheduled_at <> '' AND scheduled_at <= ?");
        $st->execute([gmdate('Y-m-d H:i:s', $now ?? time())]);
        $done = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $res = self::publish($r['ref'], $r['scheduled_by'] . ' (scheduled)', 'Published on schedule.');
            if (!$res['ok']) {
                // Leave it visible as a draft with the reason, rather than retrying for ever.
                self::put($r['ref'], ['state' => 'draft', 'scheduled_at' => '', 'note' => 'The scheduled publish failed: ' . ($res['error'] ?? 'unknown error')]);
                self::log($r['ref'], 'failed', null, 'schedule', $res['error'] ?? '');
            }
            $done[$r['ref']] = $res['ok'];
        }
        return $done;
    }

    /** Make an earlier version (or an earlier saved draft) the draft. */
    public static function restore(string $ref, int $revisionId, string $by): array
    {
        $r = self::revision($ref, $revisionId);
        if (!$r || !is_array($r['data'])) return ['ok' => false, 'error' => 'That version is not in the history.'];
        $in = self::type($ref) === 'page' && $r['action'] === 'save' ? SitePages::overlay(self::liveOf($ref) ?? [], $r['data']) : $r['data'];
        $res = self::save($ref, $in, $by);
        if ($res['ok']) self::log($ref, 'restore', null, $by, 'From the version of ' . $r['created_at'] . ' UTC');
        return $res;
    }

    /* ── Comparing ────────────────────────────────────────────────────── */

    /**
     * Rows [{key, label, before, after}] where $a and $b differ. Nested values
     * are flattened ("slides.2.title"); labels say what a person calls them.
     */
    public static function diff(array $a, array $b, string $ref): array
    {
        $fa = self::flat($a); $fb = self::flat($b);
        $rows = [];
        foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $k) {
            $x = $fa[$k] ?? ''; $y = $fb[$k] ?? '';
            if ($x === $y) continue;
            $rows[] = ['key' => $k, 'label' => self::label($ref, $k), 'before' => $x, 'after' => $y];
        }
        return $rows;
    }

    /** Compare two versions: each side is a revision id, 'live' or 'draft'. */
    public static function compare(string $ref, string $a, string $b): array
    {
        $side = function (string $s) use ($ref): ?array {
            if ($s === 'live') return self::liveOf($ref) ?? [];
            if ($s === 'draft') return self::draftView($ref);
            $r = self::revision($ref, (int) $s);
            if (!$r || !is_array($r['data'])) return null;
            return self::type($ref) === 'page' && $r['action'] === 'save' ? SitePages::overlay(self::liveOf($ref) ?? [], $r['data']) : $r['data'];
        };
        $x = $side($a); $y = $side($b);
        if ($x === null || $y === null) return ['ok' => false, 'error' => 'One of those versions is not in the history.'];
        return ['ok' => true, 'rows' => self::diff($x, $y, $ref)];
    }

    private static function flat(array $a, string $pre = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $pre === '' ? (string) $k : $pre . '.' . $k;
            if (is_array($v)) $out += self::flat($v, $key);
            else $out[$key] = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
        }
        return $out;
    }

    private static function label(string $ref, string $key): string
    {
        $p = explode('.', $key);
        $names = ['eyebrow' => 'small line', 'title' => 'headline', 'sub' => 'sentence', 'href' => 'link', 'image' => 'picture', 'alt' => 'picture description',
                  'hidden' => 'hidden', 'src' => 'video', 'poster' => 'poster', 'mode' => 'Hero type', 'interval' => 'Seconds per slide',
                  'email' => 'Contact email', 'place' => 'Place', 'country' => 'Country', 'copyright' => 'Copyright line',
                  'value' => 'words', 'width' => 'width', 'height' => 'height', 'srcset' => 'picture sizes', 'sizes' => 'picture sizes', 'description' => 'description'];
        switch (self::type($ref)) {
            case 'hero':
                if ($p[0] === 'slides' && isset($p[1], $p[2])) return 'Slide ' . ((int) $p[1] + 1) . ': ' . ($names[$p[2]] ?? $p[2]);
                if ($p[0] === 'video' && isset($p[1])) return 'Video: ' . ($names[$p[1]] ?? $p[1]);
                return $names[$p[0]] ?? $key;
            case 'site':
                if ($p[0] === 'social' && isset($p[1])) return (SiteSettings::SOCIALS[$p[1]][0] ?? $p[1]) . ' link';
                return $names[$p[0]] ?? $key;
            case 'page':
                if ($p[0] === 'seo') return 'Search & sharing: ' . (['title' => 'page title', 'description' => 'description', 'image' => 'share picture'][$p[1] ?? ''] ?? ($p[1] ?? ''));
                static $fields = [];
                $file = substr($ref, 5);
                if (!isset($fields[$file])) { $fields[$file] = []; foreach (SitePages::fields($file) as $f) $fields[$file][$f['key']] = $f; }
                $f = $fields[$file][$p[0]] ?? null;
                $kind = $f ? (['h1' => 'Main heading', 'h2' => 'Heading', 'h3' => 'Sub-heading', 'h4' => 'Small heading', 'p' => 'Paragraph', 'li' => 'List item', 'a' => 'Link', 'img' => 'Picture'][$f['tag']] ?? 'Text') : 'Text';
                return ($f ? $f['group'] . ' · ' : '') . $kind . (isset($p[1]) && $p[1] !== 'value' ? ' (' . ($names[$p[1]] ?? $p[1]) . ')' : '');
        }
        return $key;
    }

    /* ── Housekeeping ─────────────────────────────────────────────────── */

    /** Forget a document entirely (tests, and a page taken off the list). */
    public static function forget(string $ref): void
    {
        self::ensure();
        Database::pdo()->prepare('DELETE FROM cms_docs WHERE ref = ?')->execute([$ref]);
        Database::pdo()->prepare('DELETE FROM cms_revisions WHERE ref = ?')->execute([$ref]);
        if ($ref === 'site') SiteSettings::forget();
    }

    /** Bring over what the first version of the editors kept in app_meta, once. */
    private static function importLegacy(): void
    {
        try {
            if (Database::metaGet('cms_legacy_imported') === '1') return;
            Database::metaSet('cms_legacy_imported', '1');
            $get = static function (string $k): ?array { $j = json_decode((string) Database::metaGet($k), true); return is_array($j) && $j ? $j : null; };
            if ($live = $get('site_home')) self::log('hero', 'baseline', $live, 'the site as it was');
            if (($d = $get('site_home_draft')) && $d !== ($live ?? SiteHome::defaults())) self::put('hero', ['draft' => self::json($d), 'state' => 'draft', 'updated_by' => 'imported']);
            foreach (array_keys(SitePages::PAGES) as $f) {
                $s = $get('site_page:' . $f);
                if ($s && !empty($s['draft'])) self::put('page:' . $f, ['draft' => self::json($s['draft']), 'state' => 'draft', 'updated_by' => 'imported']);
            }
        } catch (Throwable $e) { error_log('[cms] legacy import: ' . $e->getMessage()); }
    }
}
