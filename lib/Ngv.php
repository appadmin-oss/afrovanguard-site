<?php
/**
 * lib/Ngv.php — NextGen Vanguard (NGV) programme page content model.
 *
 * NGV is Afrovanguard Academy's flagship transformation programme. Its public
 * landing page (/academy/ngv/) is 100% DB-driven: every headline, section,
 * track, phase, plan, testimonial, FAQ and contact detail below is a DEFAULT
 * that an admin can override in the Studio → nothing on the page is hard-coded.
 *
 * Storage mirrors AuthPolicy / brand_theme: a single JSON document in the
 * portable `app_meta` key/value store, read through merge-over-defaults so a
 * missing / partial / malformed record always yields a complete, safe page.
 *
 * The "plans" (programme options) are a first-class, admin-editable list: each
 * plan can be toggled on/off and every field edited, and the whole plans block
 * (like every other section) has its own on/off switch.
 */
declare(strict_types=1);

final class Ngv
{
    private const KEY = 'ngv_content';
    private const PREV_KEY = 'ngv_content_prev'; // one-step backup for undo/restore
    private const MAX_LIST = 60;                 // cap items per list (runaway/abuse guard)

    /** Where somebody applies. The one address every "Apply" on the site means. */
    public const APPLY_URL = '/academy/ngv/register.php';
    private static ?array $cache = null;

    /** Canonical defaults, sourced from the NextGen Vanguard brochure + flyer. */
    public static function defaults(): array
    {
        return [
            'enabled' => true, // master switch: false → public page shows a "coming soon" holding state

            'seo' => [
                'title'    => 'NextGen Vanguard — Learn, Earn & Lead | Afrovanguard Academy',
                'desc'     => 'NextGen Vanguard is Afrovanguard Academy\'s elite transformation programme for school leavers, NYSC corpers and IT students in Lagos. Learn future-ready tech, media, business and leadership skills, earn weekly stipends and six global certifications, and land a real internship. Stop scrolling — start earning.',
                'keywords' => 'NextGen Vanguard, Afrovanguard Academy, free tech training Lagos, skills training Alimosho, Egbeda youth programme, NYSC skills Nigeria, school leaver training, learn and earn Nigeria, digital marketing training Lagos, tech internship Lagos, weekly stipend training, global certifications Nigeria, leadership programme for youths, music production training, data analysis training, AI training Nigeria, media and content creation, web development bootcamp Lagos, youth empowerment Nigeria, Okun Alimosho',
                'og_image' => '/assets/site/ngv/ngv-flyer.png',
            ],

            'hero' => [
                'promo_tagline'        => 'Stop scrolling — start earning',
                'eyebrow'              => 'NextGen Vanguard · Afrovanguard Academy',
                'audience'             => 'For young school leavers, NYSC corpers & IT students',
                'title_top'            => 'Future-ready skills for',
                'title_bottom'         => 'Africa\'s next generation',
                'sub'                  => 'An elite Afrovanguard Academy programme that turns raw talent into real skills, income and leadership — with mentorship, weekly stipends and globally recognised certifications.',
                'cta_primary_label'    => 'Apply now',
                'cta_primary_url'      => '/academy/ngv/register.php',
                'cta_secondary_label'  => 'Chat on WhatsApp',
                'cta_secondary_url'    => 'https://wa.me/2349037776318',
                'image'                => '/assets/site/ngv/ngv-flyer.png',
            ],

            // "Why join us" perks (flyer) — num over label
            'perks' => [
                ['num' => 'Six (6)', 'label' => 'Global certifications'],
                ['num' => 'Weekly',  'label' => 'Stipends as you learn'],
                ['num' => 'Real',    'label' => 'Internship placement'],
                ['num' => 'Free',    'label' => 'Internet & workspace access'],
            ],

            // Scrolling skills marquee (flyer: Learn & Earn)
            'marquee' => [
                'Music Production', 'Tech & Data Analysis', 'Digital Marketing',
                'Artificial Intelligence', 'Project Management', 'Media & Content Creation',
                'Web Development', 'Leadership & Entrepreneurship',
            ],

            'stats' => [
                ['num' => '6–12', 'label' => 'Months to transform'],
                ['num' => '6',    'label' => 'Global certifications / year'],
                ['num' => '4',    'label' => 'Passion-aligned tracks'],
                ['num' => '100%', 'label' => 'Hands-on, real projects'],
            ],

            'about' => [
                'title' => 'This is not just another programme — it\'s your turning point',
                'body'  => 'NextGen Vanguard is an elite transformation programme under Afrovanguard Academy, created to equip school leavers and young graduates with future-ready skills, leadership capacity and real-life experience. Through structured learning, guided service and personal development, participants are shaped into impactful leaders across business, media, technology and the arts.',
                'body2' => 'Afrovanguard is a force for good — a movement building a new class of African leaders rooted in faith, diligence, accountability, cultural appreciation and communal spirit. We don\'t give handouts; we forge trailblazers who know how to think, build, serve and thrive — locally and globally.',
            ],

            'tracks_enabled' => true,
            'tracks_title'   => 'Choose your track',
            'tracks_intro'   => 'Pick the lane that matches your fire. Every track adds bonus skills for all vanguards: Google Docs/Sheets/Slides, Excel & desktop publishing, and digital & legal literacy.',
            'tracks' => [
                ['icon' => '💼', 'name' => 'BEC — Business Executive Club', 'desc' => 'Entrepreneurship, branding and strategy for the founders and executives of tomorrow.'],
                ['icon' => '💻', 'name' => 'TECHOME', 'desc' => 'Tech skills, web development and digital fluency — build things people actually use.'],
                ['icon' => '🎨', 'name' => 'Africa GATES', 'desc' => 'Music, art, performance and creative expression — elevate African talent to the world stage.'],
                ['icon' => '🎬', 'name' => 'MEDIAPRO', 'desc' => 'Media, videography, social content and editing — tell true stories that move people.'],
            ],

            'phases_enabled' => true,
            'phases_title'   => 'How the journey works',
            'phases' => [
                ['tag' => 'Phase 1', 'title' => 'Training', 'when' => 'Month 1–6', 'items' => [
                    'Core courses: digital tools, leadership & entrepreneurship',
                    '24-book reading challenge (leadership, finance, law)',
                    'Weekly workshops & panel discussions',
                    'Choose your track: BEC, TECHOME, Africa GATES or MEDIAPRO',
                    'Three certification milestones',
                ]],
                ['tag' => 'Phase 2', 'title' => 'Service & Internship', 'when' => 'Month 6–12', 'items' => [
                    'Practical engagement on real Afrovanguard projects',
                    'Lead community-impact initiatives',
                    'Train peers or assist professionals',
                    'Final showcase & portfolio building',
                    'Three additional certifications',
                ]],
            ],

            'plans_enabled' => true,
            'plans_title'   => 'One programme, one year',
            'plans_intro'   => 'Six months of training, then six months of a guided internship. The programme fee is not a bill while you are on the programme: you work it off through your internship, and it is written off when you finish. No one is turned away for lack; committed applicants who need support can write to their track lead.',
            /* One plan. "Training Only" and "Internship Only" were retired on
               29 Oct 2026 — the programme is the year, and the fee is worked off
               through its second half, which a half-year plan cannot do. */
            'plans' => [
                [
                    'name' => 'Full Programme', 'duration' => '12 months', 'price' => '₦240,000', 'price_note' => 'for the year · worked off through your internship',
                    'desc' => 'The complete transformation — six months of training plus a guided, paid internship.',
                    'features' => ['All core courses & your chosen track', 'Guided internship on real projects', 'Six global certifications', 'Weekly stipends', '24-book leadership reading challenge', 'Portfolio & final showcase'],
                    'cta_label' => 'Start your application', 'cta_url' => '/academy/ngv/register.php',
                    'featured' => true, 'enabled' => true,
                ],
            ],

            'why_enabled' => true,
            'why_title'   => 'Why choose NextGen Vanguard',
            'why' => [
                'Real skills, real results', 'Free internship opportunities', 'Six certifications yearly',
                'Passion-aligned tracks', 'Leadership growth guaranteed', 'Community & career access',
                'Global internship access', 'International certification tracks',
            ],

            /* Fines: the reasons and usual amounts come from the fines desk
               (lib/NgvFines.php), so only whether the section shows is here. */
            'fines_enabled' => true,
            'fees_enabled' => true,
            'fees_title'   => 'Simple, purposeful commitment',
            'fees' => [
                ['name' => 'Membership fee', 'amount' => '₦10,000 / year', 'desc' => 'A symbol of commitment. Covers your ID card & file setup, program starter pack, digital onboarding and induction/graduation prep. Paid on registration day.'],
                ['name' => 'Commitment fee', 'amount' => '₦1,000 / month', 'desc' => 'Not for profit — a leadership-culture tool. Covers resource refreshers, guest-speaker honorariums, community outreach and facility upkeep.'],
            ],
            'fees_note' => 'No one is turned away for lack. If you\'re truly committed and need support, speak to your track lead or send a letter requesting consideration — we\'re here to help you rise, not shame you.',

            'schedule' => [
                'days'    => '5 days a week',
                'time'    => 'Resumption 7:00 AM · Closing 4:00 PM',
                'uniform' => 'Formal office wear, native attire on Cultural Day, NGV T-shirt and sportswear',
                'payment' => 'Payments to: Ambassadors for Community Tech & Cultural Advancements — UBA 1028052271',
            ],

            'testimonials_enabled' => true,
            'testimonials_title'   => 'What they say',
            'testimonials' => [
                ['quote' => 'This programme has significantly transformed me. I now speak confidently, think critically, manage my time properly, and my discipline has stepped up.', 'name' => 'Nwazi Chioma Ruth', 'role' => 'Compliance Officer', 'rating' => '4.9'],
                ['quote' => 'I finished secondary school this year, and since I joined I have gained digital skills and I am learning responsibility and accountability daily.', 'name' => 'Eleyinmi Oluwaseun', 'role' => 'Digital & Communications Lead', 'rating' => '4.9'],
                ['quote' => 'Joining this programme helped me overcome public-speaking fears, improved my self-organisation, and boosted my confidence level.', 'name' => 'Divine-Joy Godwin', 'role' => 'Finance Admin', 'rating' => '4.9'],
            ],

            'faq_enabled' => true,
            'faq_title'   => 'Questions, answered',
            'faq' => [
                ['q' => 'Who is NextGen Vanguard for?', 'a' => 'Young school leavers, NYSC corpers, IT students and young graduates who are ready to gain future-ready skills, earn and lead — especially across Alimosho, Egbeda and greater Lagos.'],
                ['q' => 'How long is the programme?', 'a' => 'A structured journey of up to 12 months: Phase 1 is six months of training, Phase 2 is six months of service and internship. Free training-only and internship-only options are available.'],
                ['q' => 'Do I really earn while I learn?', 'a' => 'Yes. Interns serve on real Afrovanguard projects and receive weekly stipends, alongside internship placement and free internet/workspace access.'],
                ['q' => 'What will it cost me?', 'a' => 'Training-only and internship-only tracks are free (limited slots). The full programme is ₦240,000/year. A ₦10,000/year membership and ₦1,000/month commitment fee support your uniform, materials and community — and no one is turned away for lack.'],
                ['q' => 'Where is it held?', 'a' => 'At CACENTRE Egbeda — 2 Abolude / Oremeji Street, Bakery Bus Stop, Egbeda, Lagos.'],
            ],

            'cta' => [
                'title'        => 'Your future is already waiting — show up for it',
                'text'         => 'Limited slots. Zero excuses. Endless possibilities. The next six months could change the next sixty years of your life. Enrol today.',
                'button_label' => 'Apply now',
                'button_url'   => '/academy/ngv/register.php',
            ],

            'contact' => [
                'phone'     => '+234 903 777 6318',
                'email'     => 'academy@afrovanguard.org.ng',
                'apply_url' => '/academy/ngv/register.php',
            ],

            // Afrovanguard's two offices (editable list). Rendered on the page and
            // used in the Course location schema.
            'offices' => [
                ['name' => 'CACENTRE Egbeda', 'address' => '2 Abolude / Oremeji Street, Bakery Bus Stop, Egbeda, Lagos'],
                ['name' => 'CACENTRE Okun Alimosho', 'address' => 'Okun Alimosho, Alimosho, Lagos'],
            ],

            /* ── NextGen Vanguard 2.0 — the portal's Programme view (design
               "Afrovanguard Portal v4"; lib/NgvJourney.php reads these). The
               levels and schools ship with the programme; the parts that change
               every term (this quarter's project, the chapter roster) start empty
               and are shown only once the office fills them in. The programme's
               brochure (pipeline, the NGV 8, summer, summit) is the public NGV
               page's, not the member's dashboard. */
            'j_levels'   => ['Candidate', 'Member', 'Vanguard', 'Chapter Leader', 'National Vanguard', 'AfroVanguard'],
            'j_schools'  => [
                ['name' => 'School of Self', 'desc' => 'Identity, character, discipline, communication', 'modules' => 6],
                ['name' => 'School of Work', 'desc' => 'CV and portfolio, interviewing, project management', 'modules' => 6],
                ['name' => 'School of Enterprise', 'desc' => 'Sales, finance, business models, investing', 'modules' => 6],
                ['name' => 'School of Technology', 'desc' => 'AI, coding, no-code tools, cybersecurity', 'modules' => 6],
                ['name' => 'School of Culture & Creativity', 'desc' => 'African history, language, music, film, design', 'modules' => 7],
                ['name' => 'School of Community Leadership', 'desc' => 'Organising, advocacy, fundraising, governance', 'modules' => 7],
            ],
            'j_project'      => ['quarter' => '', 'title' => '', 'theme' => '', 'desc' => '', 'step' => 0, 'team' => '', 'budget' => '', 'measure' => ''],
            'j_project_past' => [],
            'j_chapter'      => ['name' => '', 'place' => ''],
            'j_roster'       => [],

            /* ── Registration (lib/NgvRegistration.php) ─────────────────────
               Applying online is the first step; registration is completed in
               person at a CACENTRE, with a guarantor and the signed MoU. Every
               part of that below is the office's to edit — nothing in it is
               hard-coded. */

            /* What the applicant must show they understand before applying.
               Not a test anybody fails: a wrong answer shows the right one. */
            'reg_understand' => [
                ['q' => 'How long is NextGen Vanguard?', 'options' => ['Six months', 'One year: six months of training, then six of internship', 'As long as I like'],
                 'answer' => '2', 'explain' => 'It is one year: six months of training, then a six-month guided internship.'],
                ['q' => 'What happens to the programme fee while you stay on the programme?', 'options' => ['I pay it in monthly instalments', 'I work it off through my internship, and it is written off when I finish', 'Nothing — there is no fee'],
                 'answer' => '2', 'explain' => 'You are given the place. You work the fee off through the internship, and it is written off when you finish. Only if you leave early does what is left become owed.'],
                ['q' => 'Who is your guarantor?', 'options' => ['Anybody whose name I write down', 'An adult who knows me — a parent or guardian, employer, faith leader or community leader — who comes in with me and vouches for my character', 'Somebody who pays my fees'],
                 'answer' => '2', 'explain' => 'Your guarantor vouches for your character. They come to the CACENTRE with you. They are not responsible for your fees.'],
                ['q' => 'How do you finish registering?', 'options' => ['The online form is enough', 'I bring the signed MoU, my documents and my guarantor to the CACENTRE', 'I send a WhatsApp message'],
                 'answer' => '2', 'explain' => 'The form is the first step. You finish in person at the CACENTRE with the signed MoU, your documents and your guarantor.'],
            ],

            /* What to bring, and what the desk checks. `applies`: all | minor | adult | ngg. */
            'reg_checklist' => [
                ['label' => 'The MoU, printed and signed by you and your guarantor', 'desc' => 'Print it from your application page. Sign it before you come.', 'required' => true, 'applies' => 'all'],
                ['label' => 'Your ID', 'desc' => 'National ID (NIN slip), voter’s card, international passport, driver’s licence, or a school or NYSC ID.', 'required' => true, 'applies' => 'all'],
                ['label' => 'Your guarantor, in person, with their ID', 'desc' => 'They come with you and sign in front of the desk.', 'required' => true, 'applies' => 'all'],
                ['label' => 'Your parent or guardian as your guarantor', 'desc' => 'If you are under 18, your guarantor must be your parent or guardian.', 'required' => true, 'applies' => 'minor'],
                ['label' => 'Two passport photographs', 'desc' => 'Recent, plain background.', 'required' => false, 'applies' => 'all'],
                ['label' => 'Your NGG member card', 'desc' => 'If you are coming up from Next Generation Genius.', 'required' => false, 'applies' => 'ngg'],
            ],

            /* The MoU, as printed. `version` is recorded on every registration,
               so a later edit changes what the next person signs only.
               Placeholders: {name} {ref} {dob} {track} {centre} {fee} {basis}
               {training_months} {internship_months} {membership} {commitment}
               {guarantor} {relationship} {date}. */
            'mou' => [
                'version' => 'NGV-MoU-2026.10',
                'title'   => 'Memorandum of Understanding — NextGen Vanguard',
                'body'    => "This memorandum is between Afrovanguard Academy and {name} (reference {ref}), with {guarantor} as guarantor ({relationship}).\n\n"
                           . "1. The programme. NextGen Vanguard runs for one year: {training_months} months of training, then {internship_months} months of a guided internship, at {centre}.\n\n"
                           . "2. Attendance and conduct. I will attend as the programme schedule requires, keep the programme's code of conduct, and accept its policy on lateness, absence and fines.\n\n"
                           . "3. The programme fee. The programme fee is {fee} {basis}. I am given my place: I will not be asked to pay this fee while I remain on the programme. I work it off through my internship, and it is written off when I complete the programme. If I withdraw, or am removed, before completing, the part I have not worked off becomes owed.\n\n"
                           . "4. Other fees. The membership fee ({membership}) is paid on registration day, and the monthly commitment fee ({commitment}) while I am on the programme. No one is turned away for lack: I may ask my track lead for consideration.\n\n"
                           . "5. My guarantor. My guarantor knows me and vouches for my character, and agrees to be contacted about my conduct. My guarantor is not responsible for my fees.\n\n"
                           . "6. My details are used to run the programme and are not sold or shared for marketing.",
            ],

            /* When applicants can come in. Booking is advised; walking in also works. */
            'reg_walkin' => 'You can also walk in, Monday to Friday, 10am to 3pm, with your reference number. Booking means somebody is ready for you.',
            'reg_slots' => [
                ['centre' => 'CACENTRE Egbeda', 'days' => 'Mon, Wed, Fri', 'times' => ['10:00', '12:00', '14:00'], 'capacity' => '6'],
                ['centre' => 'CACENTRE Okun Alimosho', 'days' => 'Tue, Thu', 'times' => ['10:00', '13:00'], 'capacity' => '6'],
            ],
        ];
    }

    /** The full, merged content document (defaults ← stored overrides). */
    public static function get(): array
    {
        if (self::$cache !== null) return self::$cache;
        $stored = [];
        try {
            $raw = Database::metaGet(self::KEY);
            if ($raw) { $d = json_decode($raw, true); if (is_array($d)) $stored = $d; }
        } catch (Throwable $e) { /* DB not ready → defaults */ }
        return self::$cache = self::mendApplyLinks(self::merge(self::defaults(), $stored));
    }

    /**
     * An "Apply" that does not reach the application form.
     *
     * Before the form existed, applying was `bit.ly/ngv`, which redirects to a
     * WhatsApp group invite. The form shipped and the shipped defaults pointed at
     * it, but the live page's stored copy still carried the old link — so every
     * "Apply now" on the page put people in a chat: no record of who applied, no
     * consent, no staff queue, and every member's phone number shown to every
     * other. An apply link is the form; a chat is a chat (`cta_secondary_url` is
     * the one for that, and is left alone). Mended on the way OUT, so it holds
     * for a copy saved before this and for one an editor saves with the old link
     * pasted back in.
     */
    public static function mendApplyUrl(string $url): string
    {
        return preg_match('~^\s*(https?://)?(www\.)?(bit\.ly/ngv(/|\?|#|\s*$)|chat\.whatsapp\.com/)~i', $url) ? self::APPLY_URL : $url;
    }

    /** Plans withdrawn from the programme. A stored copy of the page from before still lists them. */
    public const RETIRED_PLANS = ['training only', 'internship only'];

    private static function mendApplyLinks(array $doc): array
    {
        /* Content the programme has withdrawn, mended on the way out like the
           apply links: a stored page saved before still carries it, and a
           visitor must not be offered a plan that no longer exists, nor a
           uniform NGV does not issue. */
        if (is_array($doc['plans'] ?? null)) {
            $doc['plans'] = array_values(array_filter($doc['plans'], static fn($pl) =>
                !is_array($pl) || !in_array(mb_strtolower(trim((string) ($pl['name'] ?? ''))), self::RETIRED_PLANS, true)));
        }
        if (is_array($doc['fees'] ?? null)) {
            foreach ($doc['fees'] as $i => $f) {
                if (is_array($f) && isset($f['desc']) && is_string($f['desc'])) {
                    $doc['fees'][$i]['desc'] = trim((string) preg_replace('/\byour uniform package,\s*|\buniform package,\s*|\buniform,\s*/i', '', $f['desc']));
                }
            }
        }
        foreach ([['hero', 'cta_primary_url'], ['cta', 'button_url'], ['contact', 'apply_url']] as [$sec, $key]) {
            if (isset($doc[$sec][$key]) && is_string($doc[$sec][$key])) $doc[$sec][$key] = self::mendApplyUrl($doc[$sec][$key]);
        }
        if (is_array($doc['plans'] ?? null)) {
            foreach ($doc['plans'] as $i => $pl) {
                if (is_array($pl) && isset($pl['cta_url']) && is_string($pl['cta_url'])) $doc['plans'][$i]['cta_url'] = self::mendApplyUrl($pl['cta_url']);
            }
        }
        return $doc;
    }

    /** Validate a (partial or full) patch, persist the merged document, return it. */
    public static function save(array $patch): array
    {
        $before = self::get(); // effective content BEFORE this patch → the restore point
        $merged = self::capLists(self::merge($before, self::clean($patch)));
        try { Database::metaSet(self::PREV_KEY, json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
        Database::metaSet(self::KEY, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$cache = self::mendApplyLinks($merged);
        if (class_exists('Sitemap')) { try { Sitemap::rebuild(); } catch (Throwable $e) {} }
        if (class_exists('Events'))  { try { Events::emit('ngv.updated', ['by' => 'admin']); } catch (Throwable $e) {} }
        return $merged;
    }

    /** Reset to shipped defaults (clears the stored override; keeps a restore point). */
    public static function reset(): array
    {
        try {
            $before = self::get();
            Database::metaSet(self::PREV_KEY, json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            Database::metaSet(self::KEY, '');
        } catch (Throwable $e) {}
        self::$cache = null;
        return self::get();
    }

    /** Is there a previous version to restore to? */
    public static function hasPrevious(): bool
    {
        try { $r = Database::metaGet(self::PREV_KEY); return is_string($r) && $r !== ''; }
        catch (Throwable $e) { return false; }
    }

    /**
     * Restore the previous saved version (one-step undo of the last save/reset).
     * The swap is itself undoable — the version being replaced becomes the new
     * restore point — so an admin can toggle back and forth. Returns null if
     * there is no backup to restore.
     */
    public static function restorePrevious(): ?array
    {
        $raw = null;
        try { $raw = Database::metaGet(self::PREV_KEY); } catch (Throwable $e) {}
        if (!is_string($raw) || $raw === '') return null;
        $prev = json_decode($raw, true);
        if (!is_array($prev)) return null;
        $current = self::get();
        Database::metaSet(self::KEY, json_encode(self::capLists($prev), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        try { Database::metaSet(self::PREV_KEY, json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
        self::$cache = null;
        if (class_exists('Sitemap')) { try { Sitemap::rebuild(); } catch (Throwable $e) {} }
        return self::get();
    }

    /** Cap every list to MAX_LIST items so a bad/abusive save can't balloon the blob. */
    private static function capLists(array $doc): array
    {
        foreach ($doc as $k => $v) {
            if (is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1) && count($v) > self::MAX_LIST) {
                $doc[$k] = array_slice($v, 0, self::MAX_LIST);
            }
        }
        return $doc;
    }

    /* ── Convenience for the view ─────────────────────────────────────── */

    public static function isEnabled(): bool { return (bool) (self::get()['enabled'] ?? true); }

    /** Only the plans an admin has left switched on. */
    public static function activePlans(): array
    {
        return array_values(array_filter(self::get()['plans'] ?? [], fn($p) => !empty($p['enabled'])));
    }

    /** Section on/off: `Ngv::section('plans')` → bool (defaults to true). */
    public static function section(string $name): bool
    {
        return (bool) (self::get()[$name . '_enabled'] ?? true);
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /**
     * Deep-merge stored overrides onto defaults. Scalars replace; associative
     * arrays merge key-by-key; LIST arrays (tracks/plans/…) replace wholesale
     * when the override provides a non-empty list, so an admin can add/remove/
     * reorder items freely. Empty/absent overrides always fall back to defaults.
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (!array_key_exists($k, $base)) { $base[$k] = $v; continue; }
            $b = $base[$k];
            if (is_array($b) && is_array($v) && self::isAssoc($b) && self::isAssoc($v)) {
                $base[$k] = self::merge($b, $v);
            } elseif (is_array($b) && is_array($v)) {
                // list → replace only when the override actually has items
                if ($v !== []) $base[$k] = array_values($v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Coerce an incoming patch into safe types: booleans stay booleans, strings
     * are trimmed, lists are re-indexed. Recursive; never trusts client shapes.
     * Length caps keep a single field from ballooning the stored blob.
     */
    private static function clean($v)
    {
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v;
        if (is_string($v)) return mb_substr(trim($v), 0, 20000);
        if (is_array($v)) {
            $assoc = self::isAssoc($v);
            $out = [];
            foreach ($v as $k => $item) {
                $ck = is_string($k) ? preg_replace('/[^a-zA-Z0-9_]/', '', $k) : $k;
                $out[$ck] = self::clean($item);
            }
            return $assoc ? $out : array_values($out);
        }
        return null;
    }
}
