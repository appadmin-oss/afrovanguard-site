/**
 * Afrovanguard — Onboarding Module v2.0
 * =========================================================
 * First-visit welcome flow with role-based personalisation.
 * Uses sessionStorage (cleared on tab close) — shows once
 * per session, respects prefers-reduced-motion.
 *
 * Steps:
 *   0 — Cinematic welcome with LCASP programme highlight
 *   1 — Role selection (Volunteer / Donor / Parent / Partner / Curious)
 *   2 — Personalised CTA cards based on selected role
 *
 * No external dependencies. Pure vanilla JS + injected CSS.
 */
(function () {
  'use strict';

  /* ── Guard: skip if already seen this session ─────────────────── */
  const STORAGE_KEY  = 'av_onboarded_v2';
  const DELAY_MS     = 1200; // ms before overlay appears
  const reduced      = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (sessionStorage.getItem(STORAGE_KEY)) return;

  /* ── Role definitions — data-driven ──────────────────────────── */
  const ROLE_DATA = {
    volunteer: {
      badge:  '🤝',
      title:  'Ready to serve?',
      sub:    'Here\'s how to join Afrovanguard\'s volunteer family and start making a difference in Alimosho today.',
      ctas: [
        {
          icon: '📋',
          label: 'Join as Volunteer',
          desc: 'Apply to serve at School Storm, Summer School, or the Ogidi Omo Expo as one of our 50 programme volunteers.',
          href: 'https://cacentre.afrovanguard.org.ng/volunteer',
          primary: true,
          tag: 'LCASP 2026 Open'
        },
        {
          icon: '📅',
          label: 'School Storm — Apr 28',
          desc: '3-month outreach · 30,000 secondary school children · 20 volunteer slots remaining.',
          href: 'https://cacentre.afrovanguard.org.ng/school-storm/',
          primary: false,
          tag: 'Starts in 6 days'
        },
        {
          icon: '🌞',
          label: 'Summer School — Jul 28',
          desc: '6 centres · 6 weeks · 600 children across Egbeda, Ayobo, Idimu, Ikotun, Mosan & Ijaiye.',
          href: 'https://cacentre.afrovanguard.org.ng/alimosho-summer-school/',
          primary: false,
          tag: 'Jul 28 – Sep 5'
        }
      ]
    },
    donor: {
      badge:  '💛',
      title:  'Every naira raises a leader.',
      sub:    'Your donation goes directly into programmes. Zero admin cut. 100% to children and communities.',
      ctas: [
        {
          icon: '₦',
          label: 'Donate to LCASP 2026',
          desc: 'Help us reach our ₦40.3M campaign goal — funding 600 children, 30 instructors, and 6 community centres.',
          href: 'donate.html',
          primary: true,
          tag: '₦40.3M Goal · 2026'
        },
        {
          icon: '🏦',
          label: 'Corporate Sponsorship',
          desc: 'CSR funding · equipment donations · mentorship programmes. Partner with us at scale.',
          href: 'https://afrovanguard.org.ng/contact/',
          primary: false,
          tag: 'Partnership'
        },
        {
          icon: '📊',
          label: 'See Budget Breakdown',
          desc: 'Capital (71.4%) · Operations (16.2%) · Concert & Exhibition (11.9%) · Admin (0.5%). Fully transparent.',
          href: 'https://bit.ly/lcasp',
          primary: false,
          tag: 'Full Transparency'
        }
      ]
    },
    parent: {
      badge:  '📚',
      title:  'Your child\'s transformation starts here.',
      sub:    'Afrovanguard programmes are free, structured, and built to produce civic-minded, culturally proud leaders.',
      ctas: [
        {
          icon: '🏫',
          label: 'School Storm — Register Now',
          desc: 'Commences April 28, 2026. Free civic & leadership enrichment for secondary school students across Lagos.',
          href: 'https://cacentre.afrovanguard.org.ng/school-storm/',
          primary: true,
          tag: '🔥 Open Now'
        },
        {
          icon: '🌞',
          label: 'Alimosho Summer School 2026',
          desc: 'Ages 10–18 · Art, Tech & Leadership · 6 weeks · 6 centres: Egbeda · Ayobo · Idimu · Ikotun · Mosan · Ijaiye',
          href: 'https://cacentre.afrovanguard.org.ng/alimosho-summer-school/',
          primary: false,
          tag: 'Jul 28 – Sep 5 · Free'
        },
        {
          icon: '⛺',
          label: 'BootCamp — Aug 31, 2026',
          desc: 'Value incubation camp · 4 days · 100 selected children · leadership simulations & real-world challenges.',
          href: 'https://cacentre.afrovanguard.org.ng/',
          primary: false,
          tag: 'Aug 31 – Sep 3'
        }
      ]
    },
    partner: {
      badge:  '🏛️',
      title:  'Shape the next generation with us.',
      sub:    'LCASP invites government, private sector, and media to co-own this historic civic transformation across Lagos.',
      ctas: [
        {
          icon: '🤝',
          label: 'Partner with Afrovanguard',
          desc: 'Ministry of Education, Ministry of Youth, or private CSR — integrate LCASP as a state-backed or sponsored initiative.',
          href: 'https://afrovanguard.org.ng/contact/',
          primary: true,
          tag: 'Strategic Partnership'
        },
        {
          icon: '📰',
          label: 'Media & Press Access',
          desc: 'Cover School Storm, Summer School, BootCamp, or the Ogidi Omo Grand Expo on September 5, 2026.',
          href: 'https://afrovanguard.org.ng/contact/',
          primary: false,
          tag: 'Press'
        },
        {
          icon: '📄',
          label: 'Download LCASP Proposal',
          desc: 'Full project document: scope, objectives, budget analysis, model of change & stakeholder roles.',
          href: 'https://bit.ly/lcasp',
          primary: false,
          tag: 'bit.ly/lcasp'
        }
      ]
    },
    curious: {
      badge:  '🌍',
      title:  'Welcome to the Movement.',
      sub:    'Afrovanguard is Nigeria\'s foremost youth leadership NGO. Here are the best places to start exploring.',
      ctas: [
        {
          icon: '🏠',
          label: 'Our Story',
          desc: '8+ years · 5,000+ lives · 1 community — Alimosho. Meet the team and the mission driving Africa\'s leadership revolution.',
          href: 'https://afrovanguard.org.ng/about/',
          primary: true,
          tag: 'Est. 2018'
        },
        {
          icon: '🎓',
          label: 'Explore Our Programmes',
          desc: 'School Storm · Summer School · Techome · Africa GATES · Street-To-Stardom · Career Hub · Next Generation Genius',
          href: 'https://cacentre.afrovanguard.org.ng',
          primary: false,
          tag: '8 Programmes'
        },
        {
          icon: '📸',
          label: 'Stories Behind the Impact',
          desc: 'Real photos from our programmes, events, and community work across Alimosho, Lagos.',
          href: '#gallery-section',
          primary: false,
          tag: 'Gallery'
        }
      ]
    }
  };

  /* ── Inject CSS ───────────────────────────────────────────────── */
  const CSS = `
    /* ── Onboarding overlay ───────────────────────────────────── */
    .av-ob {
      position: fixed; inset: 0; z-index: 9000;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
      opacity: 0; pointer-events: none;
      transition: opacity ${reduced ? '0' : '0.45'}s ease;
    }
    .av-ob.is-visible { opacity: 1; pointer-events: auto; }

    /* Dark scrim behind everything */
    .av-ob::before {
      content: ''; position: absolute; inset: 0;
      background: rgba(6, 9, 16, 0.96);
      backdrop-filter: blur(10px);
    }

    /* ── Step dots ────────────────────────────────────────────── */
    .av-ob-steps {
      position: absolute; top: 28px; left: 50%; transform: translateX(-50%);
      display: flex; gap: 10px; z-index: 10;
    }
    .av-ob-step {
      width: 8px; height: 8px; border-radius: 50%;
      background: rgba(255,255,255,0.18);
      transition: all 0.3s ease;
    }
    .av-ob-step.active {
      background: #f3b416; width: 24px; border-radius: 4px;
    }

    /* ── Panel base ───────────────────────────────────────────── */
    .av-ob-panel {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none;
      transform: translateY(${reduced ? '0' : '20px'});
      transition: opacity ${reduced ? '0' : '0.4'}s ease,
                  transform ${reduced ? '0' : '0.4'}s cubic-bezier(0.16,1,0.3,1);
      z-index: 2;
    }
    .av-ob-panel.active {
      opacity: 1; pointer-events: auto; transform: translateY(0);
    }
    .av-ob-panel.is-leaving {
      opacity: 0; transform: translateY(${reduced ? '0' : '-14px'});
      transition-duration: ${reduced ? '0' : '0.25'}s;
    }

    /* ── STEP 0: Welcome panel ────────────────────────────────── */
    .av-ob-bg-img {
      position: absolute; inset: 0;
      background-image: url('https://images.unsplash.com/photo-1529390079861-591de354faf5?auto=format&fit=crop&w=1400&q=60');
      background-size: cover; background-position: center;
      opacity: 0.08;
    }
    .av-ob-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(6,9,16,0.92) 0%, rgba(6,9,16,0.65) 100%);
    }
    .av-ob-grain {
      position: absolute; inset: 0; opacity: 0.03; pointer-events: none; z-index: 1;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)'/%3E%3C/svg%3E");
      background-size: 180px 180px; mix-blend-mode: overlay;
    }

    .av-ob-content {
      position: relative; z-index: 5;
      max-width: 560px; width: 90%;
      padding: 0 24px; text-align: left;
    }
    .av-ob-content--centered { text-align: center; }

    /* Logo */
    .av-ob-logo {
      display: inline-flex; gap: 0;
      font-family: 'Montserrat', sans-serif;
      font-size: clamp(15px, 2vw, 20px); font-weight: 800;
      letter-spacing: -0.01em; margin-bottom: 28px;
    }
    .av-ob-logo-afro { color: #fff; }
    .av-ob-logo-van  { color: #f3b416; }

    /* Rule */
    .av-ob-rule {
      width: 40px; height: 2px; background: #f3b416; margin-bottom: 24px;
    }

    /* Headline */
    .av-ob-headline {
      font-family: 'Cormorant', Georgia, serif;
      font-size: clamp(36px, 6vw + 1rem, 68px);
      font-weight: 700; color: #fff; line-height: 1.05;
      letter-spacing: -0.015em; margin-bottom: 18px;
    }
    .av-ob-headline em { font-style: italic; color: #f3b416; font-weight: 400; }

    .av-ob-sub {
      font-size: clamp(14px, 1.1vw + 0.3rem, 16px);
      color: rgba(255,255,255,0.6); line-height: 1.75;
      margin-bottom: 24px; max-width: 46ch;
    }

    .av-ob-lcasp-pill {
      display: inline-flex; align-items: center; gap: 7px;
      font-size: 11px; font-weight: 700; letter-spacing: 0.08em;
      text-transform: uppercase; color: #22c55e;
      background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.25);
      padding: 6px 14px; border-radius: 9999px; margin-bottom: 32px;
    }

    .av-ob-welcome-btns {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    }

    /* Buttons */
    .av-ob-btn {
      display: inline-flex; align-items: center; gap: 8px;
      font-family: 'Montserrat', sans-serif; font-size: 14px; font-weight: 700;
      letter-spacing: 0.02em; padding: 15px 28px; border-radius: 9999px;
      border: 2px solid transparent; cursor: pointer; white-space: nowrap;
      transition: all 0.2s ease; text-decoration: none;
    }
    .av-ob-btn--primary {
      background: #f3b416; color: #111827; border-color: #f3b416;
      box-shadow: 0 6px 28px rgba(243,180,22,0.35);
    }
    .av-ob-btn--primary:hover {
      background: #d49a0e; transform: translateY(-2px);
      box-shadow: 0 10px 40px rgba(243,180,22,0.45);
    }
    .av-ob-btn--ghost {
      background: transparent; color: rgba(255,255,255,0.55);
      border-color: rgba(255,255,255,0.15); font-size: 13px;
    }
    .av-ob-btn--ghost:hover {
      color: #fff; border-color: rgba(255,255,255,0.4);
    }

    /* ── STEP 1: Role cards ───────────────────────────────────── */
    .av-ob-eyebrow {
      font-size: 10px; font-weight: 700; letter-spacing: 0.18em;
      text-transform: uppercase; color: #f3b416; margin-bottom: 10px;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .av-ob-eyebrow::before { content:''; width:16px; height:1.5px; background:#f3b416; }
    .av-ob-eyebrow::after  { content:''; width:16px; height:1.5px; background:#f3b416; }

    .av-ob-panel-title {
      font-family: 'Cormorant', Georgia, serif;
      font-size: clamp(26px, 3.5vw, 44px); font-weight: 700;
      color: #fff; letter-spacing: 0.02em; margin-bottom: 8px; line-height: 1.1;
    }
    .av-ob-panel-sub {
      font-size: 14px; color: rgba(255,255,255,0.5);
      line-height: 1.7; margin-bottom: 28px; max-width: 44ch; margin-left: auto; margin-right: auto;
    }

    .av-ob-roles {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 10px; margin-bottom: 20px;
    }

    .av-ob-role-card {
      display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
      text-align: left;
      background: rgba(255,255,255,0.04);
      border: 1.5px solid rgba(255,255,255,0.08);
      border-radius: 16px; padding: 18px 16px;
      cursor: pointer; position: relative; overflow: hidden;
      transition: all 0.2s ease; color: inherit;
      font-family: 'Montserrat', sans-serif;
    }
    .av-ob-role-card:hover {
      background: rgba(243,180,22,0.08);
      border-color: rgba(243,180,22,0.35);
      transform: translateY(-2px);
    }
    .av-ob-role-card--wide { grid-column: span 2; flex-direction: row; align-items: center; gap: 12px; }
    .av-ob-role-card--wide .av-ob-role-desc { flex: 1; }

    .av-ob-role-icon { font-size: 22px; margin-bottom: 6px; line-height: 1; }
    .av-ob-role-card--wide .av-ob-role-icon { margin-bottom: 0; }

    .av-ob-role-title { font-size: 14px; font-weight: 700; color: #fff; letter-spacing: 0.02em; }
    .av-ob-role-desc  { font-size: 11.5px; color: rgba(255,255,255,0.45); line-height: 1.4; }
    .av-ob-role-arrow {
      position: absolute; top: 14px; right: 14px;
      font-size: 14px; color: #f3b416; opacity: 0;
      transition: opacity 0.2s ease, transform 0.2s ease;
    }
    .av-ob-role-card:hover .av-ob-role-arrow { opacity: 1; transform: translateX(3px); }
    .av-ob-role-card--wide .av-ob-role-arrow { position: static; opacity: 0.5; }

    .av-ob-back-link {
      background: none; border: none; cursor: pointer;
      font-family: 'Montserrat', sans-serif; font-size: 12px; font-weight: 600;
      color: rgba(255,255,255,0.35); padding: 8px 0;
      transition: color 0.2s; letter-spacing: 0.03em;
    }
    .av-ob-back-link:hover { color: rgba(255,255,255,0.7); }

    /* ── STEP 2: Personalised CTA cards ──────────────────────── */
    .av-ob-cta-badge {
      font-size: 40px; margin-bottom: 16px; line-height: 1;
      display: block; text-align: center;
    }

    .av-ob-cta-cards {
      display: flex; flex-direction: column; gap: 10px;
      margin-bottom: 24px; text-align: left;
    }

    .av-ob-cta-card {
      display: flex; align-items: flex-start; gap: 14px;
      background: rgba(255,255,255,0.04);
      border: 1.5px solid rgba(255,255,255,0.07);
      border-radius: 14px; padding: 16px 18px;
      text-decoration: none; color: inherit;
      transition: all 0.2s ease; position: relative;
    }
    .av-ob-cta-card:hover {
      background: rgba(243,180,22,0.07);
      border-color: rgba(243,180,22,0.3);
      transform: translateX(4px);
    }
    .av-ob-cta-card--primary {
      border-color: rgba(243,180,22,0.3);
      background: rgba(243,180,22,0.06);
    }

    .av-ob-cta-card-icon {
      font-size: 22px; flex-shrink: 0; margin-top: 2px; line-height: 1;
      width: 36px; height: 36px; background: rgba(255,255,255,0.06);
      border-radius: 10px; display: flex; align-items: center; justify-content: center;
    }

    .av-ob-cta-card-body { flex: 1; min-width: 0; }
    .av-ob-cta-card-label {
      font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 3px;
      display: flex; align-items: center; gap: 8px;
    }
    .av-ob-cta-card-tag {
      font-size: 9px; font-weight: 800; letter-spacing: 0.1em;
      text-transform: uppercase; color: #f3b416;
      background: rgba(243,180,22,0.12); border: 1px solid rgba(243,180,22,0.2);
      padding: 2px 7px; border-radius: 99px; white-space: nowrap;
    }
    .av-ob-cta-card-desc {
      font-size: 12px; color: rgba(255,255,255,0.45); line-height: 1.55;
    }
    .av-ob-cta-card-arrow {
      color: rgba(255,255,255,0.25); font-size: 16px; flex-shrink: 0; align-self: center;
      transition: color 0.2s, transform 0.2s;
    }
    .av-ob-cta-card:hover .av-ob-cta-card-arrow {
      color: #f3b416; transform: translateX(3px);
    }

    .av-ob-cta-footer { text-align: center; }

    /* ── Close/skip button ────────────────────────────────────── */
    .av-ob-x {
      position: absolute; top: 24px; right: 24px; z-index: 20;
      background: rgba(255,255,255,0.07); border: 1.5px solid rgba(255,255,255,0.1);
      color: rgba(255,255,255,0.5); border-radius: 50%;
      width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 18px; font-weight: 300;
      transition: all 0.2s ease; line-height: 1;
    }
    .av-ob-x:hover { background: rgba(255,255,255,0.12); color: #fff; }

    /* ── Progress bar ─────────────────────────────────────────── */
    .av-ob-progress {
      position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
      background: rgba(255,255,255,0.05); z-index: 10;
    }
    .av-ob-progress-bar {
      height: 100%; background: linear-gradient(90deg, #f3b416, #d49a0e);
      transition: width ${reduced ? '0' : '0.45'}s cubic-bezier(0.16,1,0.3,1);
      width: 0%;
    }

    /* ── Responsive ───────────────────────────────────────────── */
    @media (max-width: 600px) {
      .av-ob-roles { grid-template-columns: 1fr; }
      .av-ob-role-card--wide { grid-column: span 1; }
      .av-ob-headline { font-size: clamp(30px, 9vw, 44px); }
      .av-ob-content { padding: 0 20px; }
      .av-ob-welcome-btns { flex-direction: column; align-items: flex-start; }
    }
    @media (max-width: 400px) {
      .av-ob-panel-title { font-size: 26px; }
      .av-ob-cta-card { padding: 14px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .av-ob, .av-ob-panel, .av-ob-progress-bar { transition: none !important; }
    }
  `;

  const styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.head.appendChild(styleEl);

  /* ── Inject HTML ─────────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id = 'av-onboard';
  overlay.className = 'av-ob';
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-label', 'Welcome to Afrovanguard');
  overlay.setAttribute('inert', '');

  overlay.innerHTML = `
    <!-- Close X -->
    <button class="av-ob-x" id="ob-x" aria-label="Close welcome overlay">×</button>

    <!-- Step dots -->
    <div class="av-ob-steps" aria-hidden="true">
      <span class="av-ob-step active" data-step="0"></span>
      <span class="av-ob-step" data-step="1"></span>
      <span class="av-ob-step" data-step="2"></span>
    </div>

    <!-- Progress bar -->
    <div class="av-ob-progress" aria-hidden="true">
      <div class="av-ob-progress-bar" id="ob-progress"></div>
    </div>

    <!-- ── STEP 0: Cinematic Welcome ── -->
    <div class="av-ob-panel av-ob-panel--welcome active" data-panel="0" aria-label="Welcome screen">
      <div class="av-ob-grain" aria-hidden="true"></div>
      <div class="av-ob-bg-img" aria-hidden="true"></div>
      <div class="av-ob-overlay" aria-hidden="true"></div>
      <div class="av-ob-content">
        <div class="av-ob-logo" aria-label="Afrovanguard">
          <span class="av-ob-logo-afro">AFRO</span><span class="av-ob-logo-van">VANGUARD</span>
        </div>
        <div class="av-ob-rule" aria-hidden="true"></div>
        <h1 class="av-ob-headline">
          One Million<br><em>Incorruptible</em><br>Leaders by 2040
        </h1>
        <p class="av-ob-sub">
          Correcting corruption from the grassroots — civic education, cultural pride, and
          community-led leadership across Lagos State.
        </p>
        <div class="av-ob-lcasp-pill" aria-label="Active programme — LCASP School Storm begins April 28">
          <svg width="9" height="9" viewBox="0 0 10 10" fill="#22c55e" aria-hidden="true"><circle cx="5" cy="5" r="5"/></svg>
          LCASP 2026 — School Storm begins April 28
        </div>
        <div class="av-ob-welcome-btns">
          <button class="av-ob-btn av-ob-btn--primary" id="ob-start" aria-label="Continue">
            I'm Interested
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </button>
          <button class="av-ob-btn av-ob-btn--ghost" id="ob-skip-0" aria-label="Skip and go to homepage">
            Skip for now
          </button>
        </div>
      </div>
    </div>

    <!-- ── STEP 1: Role Selection ── -->
    <div class="av-ob-panel av-ob-panel--roles" data-panel="1" aria-label="Choose your role">
      <div class="av-ob-content av-ob-content--centered" style="max-width:640px">
        <p class="av-ob-eyebrow">Quick question</p>
        <h2 class="av-ob-panel-title">What brings you here?</h2>
        <p class="av-ob-panel-sub">We'll point you to the right place — instantly.</p>
        <div class="av-ob-roles" role="group" aria-label="Select your role">
          <button class="av-ob-role-card" data-role="volunteer" aria-label="I want to volunteer">
            <span class="av-ob-role-icon" aria-hidden="true">🤝</span>
            <span class="av-ob-role-title">Volunteer</span>
            <span class="av-ob-role-desc">I want to serve and mentor children</span>
            <span class="av-ob-role-arrow" aria-hidden="true">→</span>
          </button>
          <button class="av-ob-role-card" data-role="donor" aria-label="I want to donate or sponsor">
            <span class="av-ob-role-icon" aria-hidden="true">💛</span>
            <span class="av-ob-role-title">Donor / Sponsor</span>
            <span class="av-ob-role-desc">I want to fund the mission</span>
            <span class="av-ob-role-arrow" aria-hidden="true">→</span>
          </button>
          <button class="av-ob-role-card" data-role="parent" aria-label="I am a parent or student">
            <span class="av-ob-role-icon" aria-hidden="true">📚</span>
            <span class="av-ob-role-title">Parent / Student</span>
            <span class="av-ob-role-desc">I want to enrol or learn more</span>
            <span class="av-ob-role-arrow" aria-hidden="true">→</span>
          </button>
          <button class="av-ob-role-card" data-role="partner" aria-label="I represent an organisation">
            <span class="av-ob-role-icon" aria-hidden="true">🏛️</span>
            <span class="av-ob-role-title">Organisation / Media</span>
            <span class="av-ob-role-desc">Partnership, press, or government</span>
            <span class="av-ob-role-arrow" aria-hidden="true">→</span>
          </button>
          <button class="av-ob-role-card av-ob-role-card--wide" data-role="curious" aria-label="I am just exploring">
            <span class="av-ob-role-icon" aria-hidden="true">👀</span>
            <span class="av-ob-role-title">Just exploring</span>
            <span class="av-ob-role-desc">Show me everything</span>
            <span class="av-ob-role-arrow" aria-hidden="true">→</span>
          </button>
        </div>
        <button class="av-ob-back-link" id="ob-back-1" aria-label="Go back to welcome screen">← Back</button>
      </div>
    </div>

    <!-- ── STEP 2: Personalised CTA ── -->
    <div class="av-ob-panel av-ob-panel--cta" data-panel="2" aria-label="Your personalised next steps">
      <div class="av-ob-content av-ob-content--centered" style="max-width:540px">
        <div class="av-ob-cta-badge" id="ob-cta-badge" aria-hidden="true">🎯</div>
        <h2 class="av-ob-panel-title" id="ob-cta-title">Here's where to start</h2>
        <p class="av-ob-panel-sub" id="ob-cta-sub">We've found the right path for you.</p>
        <div class="av-ob-cta-cards" id="ob-cta-cards" role="list" aria-label="Recommended next steps"></div>
        <div class="av-ob-cta-footer">
          <button class="av-ob-btn av-ob-btn--ghost" id="ob-skip-2">
            Explore the full site
          </button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  /* ── State ────────────────────────────────────────────────────── */
  let currentStep = 0;
  let selectedRole = null;

  /* ── DOM refs ─────────────────────────────────────────────────── */
  const panels    = overlay.querySelectorAll('.av-ob-panel');
  const stepDots  = overlay.querySelectorAll('.av-ob-step');
  const progressBar = overlay.querySelector('#ob-progress');

  /* ── Show overlay ─────────────────────────────────────────────── */
  function show() {
    overlay.removeAttribute('inert');
    overlay.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
    // Focus first interactive element
    const firstBtn = overlay.querySelector('button, [href]');
    if (firstBtn) setTimeout(() => firstBtn.focus(), reduced ? 0 : 500);
  }

  function dismiss() {
    overlay.classList.remove('is-visible');
    overlay.setAttribute('inert', '');
    document.body.style.overflow = '';
    sessionStorage.setItem(STORAGE_KEY, '1');
  }

  /* ── Step navigation ──────────────────────────────────────────── */
  function goToStep(next) {
    if (next === currentStep) return;
    const leaving = panels[currentStep];
    const entering = panels[next];
    if (!leaving || !entering) return;

    leaving.classList.add('is-leaving');
    leaving.classList.remove('active');
    setTimeout(() => leaving.classList.remove('is-leaving'), reduced ? 0 : 300);

    entering.classList.add('active');
    currentStep = next;
    updateDots();
    updateProgress();

    // Focus first focusable element in new panel
    const focusTarget = entering.querySelector('button, [href], input');
    if (focusTarget) setTimeout(() => focusTarget.focus(), reduced ? 0 : 350);
  }

  function updateDots() {
    stepDots.forEach((dot, i) => {
      dot.classList.toggle('active', i === currentStep);
    });
  }

  function updateProgress() {
    const pct = Math.round((currentStep / (panels.length - 1)) * 100);
    if (progressBar) progressBar.style.width = pct + '%';
  }

  /* ── Render CTA cards for selected role ───────────────────────── */
  function renderCTAs(role) {
    const data = ROLE_DATA[role] || ROLE_DATA.curious;
    const badgeEl = overlay.querySelector('#ob-cta-badge');
    const titleEl = overlay.querySelector('#ob-cta-title');
    const subEl   = overlay.querySelector('#ob-cta-sub');
    const cardsEl = overlay.querySelector('#ob-cta-cards');

    if (badgeEl) badgeEl.textContent = data.badge;
    if (titleEl) titleEl.textContent = data.title;
    if (subEl)   subEl.textContent   = data.sub;

    if (!cardsEl) return;
    cardsEl.innerHTML = data.ctas.map(cta => `
      <a href="${cta.href}" class="av-ob-cta-card${cta.primary ? ' av-ob-cta-card--primary' : ''}"
         role="listitem" aria-label="${cta.label}"
         ${cta.href.startsWith('http') ? 'target="_blank" rel="noopener"' : ''}>
        <span class="av-ob-cta-card-icon" aria-hidden="true">${cta.icon}</span>
        <span class="av-ob-cta-card-body">
          <span class="av-ob-cta-card-label">
            ${escH(cta.label)}
            <span class="av-ob-cta-card-tag">${escH(cta.tag)}</span>
          </span>
          <span class="av-ob-cta-card-desc">${escH(cta.desc)}</span>
        </span>
        <span class="av-ob-cta-card-arrow" aria-hidden="true">→</span>
      </a>
    `).join('');

    // Clicking a CTA dismisses the overlay after navigation
    cardsEl.querySelectorAll('.av-ob-cta-card').forEach(card => {
      card.addEventListener('click', () => {
        setTimeout(dismiss, 100);
      });
    });
  }

  function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  /* ── Event listeners ──────────────────────────────────────────── */
  // Step 0: "I'm Interested"
  const startBtn = overlay.querySelector('#ob-start');
  if (startBtn) startBtn.addEventListener('click', () => goToStep(1));

  // Skip buttons
  ['ob-skip-0', 'ob-skip-2', 'ob-x'].forEach(id => {
    const el = overlay.querySelector('#' + id);
    if (el) el.addEventListener('click', dismiss);
  });

  // Back buttons
  const back1 = overlay.querySelector('#ob-back-1');
  if (back1) back1.addEventListener('click', () => goToStep(0));

  // Role selection
  overlay.querySelectorAll('.av-ob-role-card[data-role]').forEach(card => {
    card.addEventListener('click', () => {
      selectedRole = card.dataset.role;
      renderCTAs(selectedRole);
      goToStep(2);
    });
  });

  // Escape key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && overlay.classList.contains('is-visible')) {
      dismiss();
    }
  });

  // Click outside panels to dismiss
  overlay.addEventListener('click', e => {
    if (e.target === overlay) dismiss();
  });

  /* ── Focus trap ───────────────────────────────────────────────── */
  overlay.addEventListener('keydown', e => {
    if (e.key !== 'Tab' || !overlay.classList.contains('is-visible')) return;
    const focusable = Array.from(overlay.querySelectorAll(
      'button:not([disabled]):not([inert]), a[href], input, [tabindex]:not([tabindex="-1"])'
    )).filter(el => !el.closest('[inert]'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last  = focusable[focusable.length - 1];
    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* ── Launch after delay ───────────────────────────────────────── */
  const launch = () => {
    // Wait for fonts to be ready for best visual result
    if ('fonts' in document) {
      document.fonts.ready.then(() => setTimeout(show, DELAY_MS));
    } else {
      setTimeout(show, DELAY_MS);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', launch);
  } else {
    launch();
  }

})();
