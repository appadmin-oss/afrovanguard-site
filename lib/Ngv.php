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
                'eyebrow'              => 'NextGen Vanguard · Afrovanguard Academy',
                'audience'             => 'Young school leaver, NYSC corper or IT student?',
                'title_top'            => 'Stop Scrolling.',
                'title_bottom'         => 'Start Earning.',
                'sub'                  => 'Join the movement turning Alimosho youths into skilled creators, tech stars and digital bosses. An elite transformation programme equipping you with future-ready skills, leadership and real income — in months, not years.',
                'cta_primary_label'    => 'Apply now',
                'cta_primary_url'      => 'https://bit.ly/ngv',
                'cta_secondary_label'  => 'Talk to us on WhatsApp',
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
            'plans_title'   => 'Programme options',
            'plans_intro'   => 'Start where you are. No one is turned away for lack — committed applicants who need support can write to their track lead.',
            'plans' => [
                [
                    'name' => 'Training Only', 'price' => 'Free', 'price_note' => 'Limited slots',
                    'desc' => 'Full learning access with classes three times a day.',
                    'features' => ['All core courses & your chosen track', 'Three certification milestones', 'Weekly workshops & panels'],
                    'cta_label' => 'Apply now', 'cta_url' => 'https://bit.ly/ngv',
                    'featured' => false, 'enabled' => true,
                ],
                [
                    'name' => 'Full Programme', 'price' => '₦240,000', 'price_note' => 'per year · training + service',
                    'desc' => 'The complete transformation: training plus a guided internship and stipends.',
                    'features' => ['Everything in Training', 'Guided internship on real projects', 'Six global certifications', 'Portfolio & final showcase'],
                    'cta_label' => 'Start your application', 'cta_url' => 'https://bit.ly/ngv',
                    'featured' => true, 'enabled' => true,
                ],
                [
                    'name' => 'Internship Only', 'price' => 'Free', 'price_note' => 'Limited slots',
                    'desc' => 'Serve on Afrovanguard projects and earn stipends while you build experience.',
                    'features' => ['Real project placement', 'Weekly stipends', 'Mentorship & references'],
                    'cta_label' => 'Apply now', 'cta_url' => 'https://bit.ly/ngv',
                    'featured' => false, 'enabled' => true,
                ],
            ],

            'why_enabled' => true,
            'why_title'   => 'Why choose NextGen Vanguard',
            'why' => [
                'Real skills, real results', 'Free internship opportunities', 'Six certifications yearly',
                'Passion-aligned tracks', 'Leadership growth guaranteed', 'Community & career access',
                'Global internship access', 'International certification tracks',
            ],

            'fees_enabled' => true,
            'fees_title'   => 'Simple, purposeful commitment',
            'fees' => [
                ['name' => 'Membership fee', 'amount' => '₦10,000 / year', 'desc' => 'A symbol of commitment. Covers your uniform package, ID card & file setup, program starter pack, digital onboarding and induction/graduation prep.'],
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
                'button_url'   => 'https://bit.ly/ngv',
            ],

            'contact' => [
                'phone'     => '+234 903 777 6318',
                'email'     => 'academy@afrovanguard.org.ng',
                'address'   => 'CACENTRE Egbeda — 2 Abolude / Oremeji Street, Bakery Bus Stop, Egbeda, Lagos',
                'apply_url' => 'https://bit.ly/ngv',
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
        return self::$cache = self::merge(self::defaults(), $stored);
    }

    /** Validate a (partial or full) patch, persist the merged document, return it. */
    public static function save(array $patch): array
    {
        $merged = self::merge(self::get(), self::clean($patch));
        Database::metaSet(self::KEY, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$cache = $merged;
        if (class_exists('Sitemap')) { try { Sitemap::rebuild(); } catch (Throwable $e) {} }
        if (class_exists('Events'))  { try { Events::emit('ngv.updated', ['by' => 'admin']); } catch (Throwable $e) {} }
        return $merged;
    }

    /** Reset to shipped defaults (clears the stored override). */
    public static function reset(): array
    {
        try { Database::metaSet(self::KEY, ''); } catch (Throwable $e) {}
        self::$cache = null;
        return self::get();
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
