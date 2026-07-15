<?php
/**
 * workspace.php — the Afrovanguard Workspace hub ("the site IS Workspace").
 *
 * A full Google-Workspace-style operating environment served at /workspace:
 * members arrive already signed in through Google SSO (lib/GoogleAuth.php) and
 * work from one place — an app launcher into Gmail / Calendar / Drive / Meet /
 * Chat / Groups, live "coming up" + recent files (real Calendar/Drive data via
 * GoogleWorkspace), embedded Calendar + Drive, org communities and, for admins,
 * the people directory.
 *
 * Everything degrades gracefully: the launcher (deep links) always works; live
 * panels + embeds appear only when Workspace is configured; a non-member sees a
 * connect-your-account panel instead of the org surfaces.
 *
 * Served at /workspace (top-level .php → extension-less route in .htaccess and
 * router.php). Members-only; its own slim app chrome.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/workspace.php';

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/workspace')); exit; }

$isOrg      = LmsAuth::isOrgMember($u);
$isAdmin    = LmsAuth::atLeast($u, 'admin');
$wsConfig   = GoogleWorkspace::configured();
$oauthOn    = GoogleWorkspaceUser::configured();               // per-user connect available?
$meConn     = $oauthOn && GoogleWorkspaceUser::connected((int) $u['id']);
$discCsrf   = $oauthOn ? av_csrf_token() : '';
$domain     = av_workspace_domain();
$surfaces   = av_workspace_surfaces($isAdmin);
$comms      = $isOrg ? av_workspace_communities() : [];
$embeds     = $isOrg ? av_workspace_embeds() : [];
$email      = (string) ($u['email'] ?? '');
$name       = trim((string) ($u['name'] ?? '')) ?: (explode('@', $email)[0] ?: 'Member');
$first      = explode(' ', $name)[0] ?: 'there';
$parts      = preg_split('/\s+/', $name) ?: [];
$initials   = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';
$theme      = (($_COOKIE['av_portal_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';

// Quick-action deep links (org-scoped where Google supports it).
$act = [
    'compose' => av_ws_link('AV_WS_MAIL_URL', "https://mail.google.com/a/$domain") . '#compose',
    'event'   => 'https://calendar.google.com/calendar/u/0/r/eventedit',
    'meet'    => 'https://meet.google.com/new',
    'doc'     => 'https://docs.google.com/document/create',
];

render_head([
    'title'      => 'Workspace — Afrovanguard',
    'desc'       => 'Your Afrovanguard Workspace — Gmail, Calendar, Drive, Meet, Chat and Groups in one place.',
    'canonical'  => rtrim(SITE_URL, '/') . '/workspace',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'ws-page' . ($theme === 'light' ? ' ws-light' : ''),
    'manifest'   => '/manifest.webmanifest',
]);
?>
<style>
  .ws-page{--ws-bg:#0b1220;--ws-surface:#111a2e;--ws-surface-2:#0f1830;--ws-line:#22304d;
    --ws-ink:#eef2fb;--ws-muted:#93a1c0;--ws-accent:var(--afg-accent,#f3b416);--ws-on-accent:#111827;
    background:var(--ws-bg);color:var(--ws-ink);min-height:100vh;
    font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif)}
  .ws-page.ws-light{--ws-bg:#f5f6fa;--ws-surface:#ffffff;--ws-surface-2:#f0f2f8;--ws-line:#e2e6ef;
    --ws-ink:#131a2b;--ws-muted:#5b6987}
  .ws-page *{box-sizing:border-box}
  .ws-wrap{max-width:1160px;margin:0 auto;padding:0 20px}

  /* app bar */
  .ws-bar{position:sticky;top:0;z-index:40;background:var(--ws-surface);
    border-bottom:1px solid var(--ws-line);backdrop-filter:saturate(1.1)}
  .ws-bar-in{display:flex;align-items:center;gap:14px;height:60px}
  .ws-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;font-weight:700}
  .ws-brand .wm-1{color:var(--ws-ink)}.ws-brand .wm-2{color:var(--ws-accent)}
  .ws-brand-tag{font-size:12px;font-weight:600;letter-spacing:.04em;color:var(--ws-muted);
    border-left:1px solid var(--ws-line);padding-left:10px;text-transform:uppercase}
  .ws-search{flex:1;max-width:460px;margin:0 auto;position:relative}
  .ws-search input{width:100%;height:40px;border-radius:999px;border:1px solid var(--ws-line);
    background:var(--ws-surface-2);color:var(--ws-ink);padding:0 16px 0 42px;font-size:14px;outline:none}
  .ws-search input:focus{border-color:var(--ws-accent)}
  .ws-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--ws-muted)}
  .ws-bar-actions{display:flex;align-items:center;gap:6px;margin-left:auto}
  .ws-icon-btn{width:40px;height:40px;border-radius:50%;border:0;background:transparent;color:var(--ws-muted);
    display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative}
  .ws-icon-btn:hover{background:var(--ws-surface-2);color:var(--ws-ink)}
  .ws-avatar{width:36px;height:36px;border-radius:50%;background:var(--ws-accent);color:var(--ws-on-accent);
    font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}

  /* app launcher popover */
  .ws-launcher{position:relative}
  .ws-pop{position:absolute;right:0;top:48px;width:320px;background:var(--ws-surface);
    border:1px solid var(--ws-line);border-radius:16px;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);
    padding:14px;display:none;z-index:50}
  .ws-pop.open{display:block}
  .ws-pop-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
  .ws-pop-grid a{display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 6px;
    border-radius:12px;text-decoration:none;color:var(--ws-ink);text-align:center}
  .ws-pop-grid a:hover{background:var(--ws-surface-2)}
  .ws-pop-grid .ws-app-ico{color:var(--ws-muted)}
  .ws-pop-grid span{font-size:12px}

  /* menu (user) */
  .ws-menu{position:absolute;right:0;top:48px;width:240px;background:var(--ws-surface);
    border:1px solid var(--ws-line);border-radius:14px;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);
    padding:14px;display:none;z-index:50}
  .ws-menu.open{display:block}
  .ws-menu .who{display:flex;align-items:center;gap:10px;padding-bottom:10px;border-bottom:1px solid var(--ws-line);margin-bottom:8px}
  .ws-menu .who .nm{font-weight:600;font-size:14px}.ws-menu .who .em{font-size:12px;color:var(--ws-muted)}
  .ws-menu a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:var(--ws-ink);font-size:14px}
  .ws-menu a:hover{background:var(--ws-surface-2)}

  /* hero */
  .ws-hero{padding:34px 0 10px}
  .ws-hello{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-size:clamp(28px,4vw,40px);
    font-weight:700;margin:0 0 6px;letter-spacing:-.01em;color:var(--ws-ink)}
  .ws-sub{color:var(--ws-muted);margin:0 0 22px;font-size:15px}
  .ws-sub .em{color:var(--ws-ink);font-weight:600}
  .ws-quick{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 6px}
  .ws-q{display:inline-flex;align-items:center;gap:9px;padding:11px 16px;border-radius:12px;
    border:1px solid var(--ws-line);background:var(--ws-surface);color:var(--ws-ink);
    text-decoration:none;font-size:14px;font-weight:600;transition:transform .12s,border-color .12s}
  .ws-q:hover{transform:translateY(-1px);border-color:var(--ws-accent)}
  .ws-q svg{color:var(--ws-accent)}
  .ws-q--meet{background:var(--ws-accent);color:var(--ws-on-accent);border-color:var(--ws-accent)}
  .ws-q--meet svg{color:var(--ws-on-accent)}

  /* section + grid */
  .ws-sec{padding:26px 0}
  .ws-sec-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:0 0 16px}
  .ws-sec-head h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--ws-muted);margin:0;font-weight:700}
  .ws-sec-head a{color:var(--ws-accent);text-decoration:none;font-size:13px;font-weight:600}
  .ws-apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
  .ws-app{display:flex;align-items:center;gap:13px;padding:16px;border-radius:16px;background:var(--ws-surface);
    border:1px solid var(--ws-line);text-decoration:none;color:var(--ws-ink);transition:transform .12s,border-color .12s,box-shadow .12s}
  .ws-app:hover{transform:translateY(-2px);border-color:var(--ws-accent);box-shadow:0 16px 34px -22px rgba(0,0,0,.5)}
  .ws-app-ico{width:42px;height:42px;border-radius:12px;background:var(--ws-surface-2);display:flex;
    align-items:center;justify-content:center;color:var(--ws-accent);flex:none}
  .ws-app>span:last-child{min-width:0}
  .ws-app .t{display:block;font-weight:600;font-size:15px;line-height:1.2}
  .ws-app .d{display:block;font-size:12px;color:var(--ws-muted);margin-top:3px;line-height:1.3;overflow-wrap:anywhere}

  /* connect card */
  .ws-connect{display:flex;align-items:center;gap:18px;background:var(--ws-surface);border:1px solid var(--ws-line);
    border-radius:18px;padding:22px 24px;flex-wrap:wrap}
  .ws-connect-ico{width:52px;height:52px;border-radius:14px;background:var(--ws-surface-2);color:var(--ws-accent);
    display:flex;align-items:center;justify-content:center;flex:none}
  .ws-connect-body{flex:1;min-width:240px}
  .ws-connect-body h2{font-size:18px;margin:0 0 4px;color:var(--ws-ink);font-weight:700}
  .ws-connect-body p{margin:0;color:var(--ws-muted);font-size:14px;line-height:1.55;max-width:64ch}
  .ws-connect-body strong{color:var(--ws-ink)}
  .ws-connect-btn{display:inline-flex;align-items:center;gap:9px;background:var(--ws-accent);color:var(--ws-on-accent);
    font-weight:700;font-size:14px;padding:13px 20px;border-radius:12px;text-decoration:none;transition:transform .12s}
  .ws-connect-btn:hover{transform:translateY(-1px)}
  .ws-mine-acct{display:inline-flex;align-items:center;gap:8px;color:var(--ws-muted);font-size:12px}
  .ws-mine-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;flex:none;box-shadow:0 0 0 3px rgba(34,197,94,.18)}
  .ws-disc{background:none;border:0;color:var(--ws-muted);font:inherit;font-size:12px;cursor:pointer;text-decoration:underline;padding:0}
  .ws-disc:hover{color:var(--ws-ink)}
  .ws-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;
    border-radius:999px;background:var(--ws-accent);color:var(--ws-on-accent);font-size:11px;font-weight:800;margin-left:6px;vertical-align:middle}
  .ws-li.is-unread .ttl{font-weight:800}
  .ws-li .snip{font-size:12px;color:var(--ws-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  /* refresh + quick actions + join */
  .ws-refresh{background:none;border:0;color:var(--ws-muted);font:inherit;font-size:12px;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:4px}
  .ws-refresh:hover{color:var(--ws-accent)}
  .ws-refresh-ic{display:inline-block;transition:transform .5s ease}
  #wsMine.is-loading .ws-refresh-ic{animation:wsSpin .8s linear infinite}
  @keyframes wsSpin{to{transform:rotate(360deg)}}
  .ws-quick{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px}
  .ws-qbtn{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:10px;border:1px solid var(--ws-border,rgba(255,255,255,.12));
    background:var(--ws-surface,rgba(255,255,255,.04));color:var(--ws-ink);text-decoration:none;font-size:13.5px;font-weight:600;transition:border-color .15s,transform .15s}
  .ws-qbtn:hover{border-color:var(--ws-accent);transform:translateY(-1px)}
  .ws-qbtn-gold{background:var(--ws-accent);color:var(--ws-on-accent);border-color:var(--ws-accent)}
  .ws-join{flex:none;align-self:center;margin-left:8px;padding:4px 12px;border-radius:999px;background:var(--ws-accent);color:var(--ws-on-accent);
    font-size:12px;font-weight:700;text-decoration:none}
  .ws-join:hover{filter:brightness(.95)}
  /* team chat */
  .ws-chat-wrap{display:grid;grid-template-columns:200px 1fr;gap:14px;min-height:280px;margin-top:10px}
  @media(max-width:640px){.ws-chat-wrap{grid-template-columns:1fr}}
  .ws-chat-spaces{display:flex;flex-direction:column;gap:3px;border-right:1px solid var(--ws-border,rgba(255,255,255,.1));padding-right:10px;max-height:340px;overflow-y:auto}
  @media(max-width:640px){.ws-chat-spaces{border-right:0;border-bottom:1px solid var(--ws-border,rgba(255,255,255,.1));padding:0 0 8px;flex-direction:row;flex-wrap:wrap}}
  .chat-space{position:relative;text-align:left;padding:8px 11px;border:0;background:transparent;color:var(--ws-ink);border-radius:8px;font:inherit;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:7px}
  .chat-space:hover{background:var(--ws-surface,rgba(255,255,255,.05))}
  .chat-space.is-on{background:var(--ws-accent);color:var(--ws-on-accent);font-weight:700}
  .chat-space.has-unread .chat-space-name{font-weight:800}
  .chat-unread-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;flex:none}
  .ws-chat-main{display:flex;flex-direction:column;min-width:0}
  .ws-chat-thread{flex:1;overflow-y:auto;max-height:300px;padding:2px 2px 6px;display:flex;flex-direction:column;gap:11px}
  .chat-msg-h{display:flex;align-items:baseline;gap:8px}
  .chat-msg-h b{font-size:12.5px;color:var(--ws-ink)}.chat-msg-h span{font-size:11px;color:var(--ws-muted)}
  .chat-msg-b{font-size:13.5px;color:var(--ws-ink);line-height:1.5;overflow-wrap:anywhere}
  .ws-chat-compose{display:flex;gap:8px;margin-top:10px}
  .ws-chat-compose input{flex:1;min-width:0;padding:9px 13px;border-radius:9px;border:1px solid var(--ws-border,rgba(255,255,255,.12));background:var(--ws-surface,rgba(255,255,255,.04));color:var(--ws-ink);font:inherit;font-size:13.5px}
  .ws-chat-compose input:focus{outline:none;border-color:var(--ws-accent)}

  /* two-col panels */
  .ws-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .ws-cols-3{grid-template-columns:repeat(3,1fr)}
  @media(max-width:900px){.ws-cols-3{grid-template-columns:1fr}}
  .ws-card{background:var(--ws-surface);border:1px solid var(--ws-line);border-radius:18px;padding:18px 18px 8px}
  .ws-card .ws-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
  .ws-card h3{font-size:15px;margin:0;font-weight:700;color:var(--ws-ink)}
  .ws-card .ws-card-head a{font-size:13px;color:var(--ws-accent);text-decoration:none;font-weight:600}
  .ws-list{list-style:none;margin:0;padding:0}
  .ws-li{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--ws-line)}
  .ws-li:first-child{border-top:0}
  .ws-li .dot{width:8px;height:8px;border-radius:50%;background:var(--ws-accent);margin-top:6px;flex:none}
  .ws-li .body{min-width:0;flex:1}
  .ws-li .ttl{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ws-li .meta{font-size:12px;color:var(--ws-muted);margin-top:2px}
  .ws-li a{color:inherit;text-decoration:none}
  .ws-empty{color:var(--ws-muted);font-size:13px;padding:14px 0 18px}
  .ws-skel{height:14px;border-radius:6px;background:linear-gradient(90deg,var(--ws-surface-2),var(--ws-line),var(--ws-surface-2));
    background-size:200% 100%;animation:wssk 1.2s infinite;margin:10px 0}
  @keyframes wssk{0%{background-position:200% 0}100%{background-position:-200% 0}}

  /* embeds */
  .ws-frame{border:1px solid var(--ws-line);border-radius:14px;overflow:hidden;background:var(--ws-surface-2)}
  .ws-frame iframe{display:block;width:100%;height:360px;border:0}

  /* communities + directory */
  .ws-chips{display:flex;flex-wrap:wrap;gap:10px}
  .ws-chip{display:flex;flex-direction:column;gap:2px;padding:13px 16px;border-radius:14px;background:var(--ws-surface);
    border:1px solid var(--ws-line);text-decoration:none;color:var(--ws-ink);min-width:180px}
  .ws-chip:hover{border-color:var(--ws-accent)}
  .ws-chip .t{font-weight:600;font-size:14px}.ws-chip .d{font-size:12px;color:var(--ws-muted)}
  .ws-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
  .ws-person{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:14px;
    background:var(--ws-surface);border:1px solid var(--ws-line)}
  .ws-person .pa{width:38px;height:38px;border-radius:50%;background:var(--ws-surface-2);color:var(--ws-muted);
    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex:none;overflow:hidden}
  .ws-person .pa img{width:100%;height:100%;object-fit:cover}
  .ws-person .nm{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ws-person .em{font-size:12px;color:var(--ws-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  /* connect / status notices */
  .ws-note{background:var(--ws-surface);border:1px solid var(--ws-line);border-radius:16px;padding:18px 20px;
    display:flex;gap:14px;align-items:flex-start;color:var(--ws-muted);font-size:14px;line-height:1.55}
  .ws-note b{color:var(--ws-ink)}
  .ws-note svg{color:var(--ws-accent);flex:none;margin-top:2px}

  .ws-foot{padding:34px 0 48px;color:var(--ws-muted);font-size:12px;text-align:center}
  .ws-foot a{color:var(--ws-muted)}

  @media (max-width:760px){
    .ws-search{display:none}
    .ws-cols{grid-template-columns:1fr}
    .ws-bar-in{gap:8px}
  }
</style>

<header class="ws-bar">
  <div class="ws-wrap ws-bar-in">
    <a class="ws-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard home">
      <span><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
      <span class="ws-brand-tag">Workspace</span>
    </a>
    <form class="ws-search" role="search" onsubmit="return wsSearch(event)">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="search" name="q" placeholder="Search mail, files and calendar…" aria-label="Search Workspace">
    </form>
    <div class="ws-bar-actions">
      <button type="button" class="ws-icon-btn" id="wsThemeBtn" aria-label="Light / dark" title="Light / dark">
        <svg class="ico-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
        <svg class="ico-moon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 109.8 9.8z"/></svg>
      </button>
      <div class="ws-launcher">
        <button type="button" class="ws-icon-btn" id="wsLauncherBtn" aria-label="Workspace apps" aria-expanded="false" title="Workspace apps">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="5" r="2"/><circle cx="12" cy="5" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="12" cy="19" r="2"/><circle cx="19" cy="19" r="2"/></svg>
        </button>
        <div class="ws-pop" id="wsPop" role="menu" aria-label="Workspace apps">
          <div class="ws-pop-grid">
<?php foreach ($surfaces as $s): ?>            <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer" role="menuitem"><span class="ws-app-ico"><?= av_workspace_icon($s['icon']) ?></span><span><?= e($s['label']) ?></span></a>
<?php endforeach; ?>          </div>
        </div>
      </div>
      <div class="ws-launcher">
        <button type="button" class="ws-avatar" id="wsUserBtn" aria-label="Account" aria-expanded="false"><?= e($initials) ?></button>
        <div class="ws-menu" id="wsMenu" role="menu">
          <div class="who"><div><div class="nm"><?= e($name) ?></div><div class="em"><?= e($email) ?></div></div></div>
          <a href="/portal/" role="menuitem">Member portal</a>
          <a href="/academy/" role="menuitem">Academy</a>
<?php if ($isOrg): ?>          <a href="/mentorship/" role="menuitem">Mentorship</a>
<?php endif; ?>          <a href="#" role="menuitem" id="wsSignout">Sign out</a>
        </div>
      </div>
    </div>
  </div>
</header>

<main id="main-content" class="ws-wrap">

  <section class="ws-hero">
    <h1 class="ws-hello"><?= wsGreeting() ?>, <?= e($first) ?>.</h1>
    <p class="ws-sub">You're working in Afrovanguard Workspace as <span class="em"><?= e($email) ?></span><?= $isOrg ? ' · <span class="em">@' . e($domain) . '</span> member' : '' ?>.</p>
    <div class="ws-quick">
      <a class="ws-q ws-q--meet" href="<?= e($act['meet']) ?>" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/></svg>
        Start a Meet
      </a>
      <a class="ws-q" href="<?= e($act['event']) ?>" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M12 13v4M10 15h4"/></svg>
        New event
      </a>
      <a class="ws-q" href="<?= e($act['compose']) ?>" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L20 8a2.8 2.8 0 00-4-4L4 16v4z"/><path d="M14 6l4 4"/></svg>
        Compose
      </a>
      <a class="ws-q" href="<?= e($act['doc']) ?>" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>
        New doc
      </a>
    </div>
  </section>

<?php if ($oauthOn): ?>
  <!-- PER-USER: the member's OWN connected Google Workspace -->
  <section class="ws-sec" id="wsMine" data-connected="<?= $meConn ? '1' : '0' ?>">
<?php if (!$meConn): ?>
    <div class="ws-connect">
      <div class="ws-connect-ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.4 8.3-8 10-4.6-1.7-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg></div>
      <div class="ws-connect-body">
        <h2>Connect your Google Workspace</h2>
        <p>Bring your own <strong>Gmail, Calendar and Drive</strong> into this hub. You'll sign in with Google once and grant access — you can disconnect any time. Your data is read on demand and never shared.</p>
      </div>
      <a class="ws-connect-btn" href="/auth/google/connect?next=/workspace">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 11v2h5.5c-.2 1.3-1.6 3.9-5.5 3.9A6 6 0 1112 6c1.7 0 2.8.7 3.5 1.3l2-1.9C16.2 4.1 14.3 3.2 12 3.2A8.8 8.8 0 1012 21c5.1 0 8.5-3.6 8.5-8.7 0-.6 0-1-.1-1.4z"/></svg>
        Connect Google
      </a>
    </div>
<?php else: ?>
    <div class="ws-sec-head"><h2>Your Google Workspace</h2>
      <span class="ws-mine-acct"><span class="ws-mine-dot" title="Connected"></span><?= e($email) ?>
        · <button type="button" class="ws-refresh" id="wsRefresh" aria-label="Refresh"><span class="ws-refresh-ic" aria-hidden="true">↻</span> <span id="wsUpdated">Refresh</span></button>
        · <form method="post" action="/auth/google/disconnect" style="display:inline" onsubmit="return confirm('Disconnect your Google Workspace from this site?')"><input type="hidden" name="csrf" value="<?= e($discCsrf) ?>"><button type="submit" class="ws-disc">Disconnect</button></form></span>
    </div>
    <!-- Quick actions that use the connection: compose, schedule, create, meet -->
    <div class="ws-quick">
      <a class="ws-qbtn" href="https://mail.google.com/mail/?view=cm&fs=1" target="_blank" rel="noopener noreferrer"><span>✉️</span> Compose email</a>
      <a class="ws-qbtn" href="https://calendar.google.com/calendar/u/0/r/eventedit" target="_blank" rel="noopener noreferrer"><span>📅</span> New event</a>
      <a class="ws-qbtn" href="https://docs.google.com/document/create" target="_blank" rel="noopener noreferrer"><span>📄</span> New doc</a>
      <a class="ws-qbtn ws-qbtn-gold" href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer"><span>🎥</span> Start a Meet</a>
    </div>
    <div class="ws-cols ws-cols-3">
      <div class="ws-card">
        <div class="ws-card-head"><h3>Inbox <span class="ws-badge" id="wsUnread" hidden></span></h3><a href="https://mail.google.com/mail/u/0/" target="_blank" rel="noopener noreferrer">Gmail →</a></div>
        <ul class="ws-list" id="wsMineMail"><li class="ws-li"><div class="body"><div class="ws-skel" style="width:70%"></div><div class="ws-skel" style="width:45%"></div></div></li></ul>
      </div>
      <div class="ws-card">
        <div class="ws-card-head"><h3>Your calendar</h3><a href="https://calendar.google.com/calendar/u/0/r" target="_blank" rel="noopener noreferrer">Open →</a></div>
        <ul class="ws-list" id="wsMineEvents"><li class="ws-li"><div class="body"><div class="ws-skel" style="width:65%"></div><div class="ws-skel" style="width:40%"></div></div></li></ul>
      </div>
      <div class="ws-card">
        <div class="ws-card-head"><h3>Your Drive</h3><a href="https://drive.google.com/drive/u/0/" target="_blank" rel="noopener noreferrer">Open →</a></div>
        <ul class="ws-list" id="wsMineFiles"><li class="ws-li"><div class="body"><div class="ws-skel" style="width:60%"></div><div class="ws-skel" style="width:35%"></div></div></li></ul>
      </div>
    </div>

    <!-- Team Chat — live Google Chat (spaces + messages + send + unread) -->
    <div class="ws-card ws-chat" id="chatCard" data-csrf="<?= e($discCsrf ?: av_csrf_token()) ?>" style="margin-top:16px">
      <div class="ws-card-head">
        <h3>Team Chat <span class="ws-badge" id="chatSpaceCount" hidden></span> <span class="ws-badge" id="chatUnreadBadge" hidden style="background:#ef4444"></span></h3>
        <a href="https://chat.google.com/" target="_blank" rel="noopener noreferrer">Open Chat →</a>
      </div>
      <div class="ws-chat-wrap">
        <div class="ws-chat-spaces" id="chatSpaces"><p class="ws-empty">Loading spaces…</p></div>
        <div class="ws-chat-main">
          <div class="ws-chat-thread" id="chatThread"><p class="ws-empty">Pick a space to start chatting.</p></div>
          <form class="ws-chat-compose" id="chatCompose" autocomplete="off" hidden>
            <input type="text" id="chatInput" maxlength="4000" placeholder="Message this space…" aria-label="Message">
            <button type="submit" class="ws-qbtn ws-qbtn-gold">Send</button>
          </form>
        </div>
      </div>
    </div>
<?php endif; ?>
  </section>
<?php endif; ?>

  <!-- App launcher grid -->
  <section class="ws-sec">
    <div class="ws-sec-head"><h2>Your apps</h2><span style="color:var(--ws-muted);font-size:12px">Opens in Google Workspace</span></div>
    <div class="ws-apps">
<?php foreach ($surfaces as $s): ?>      <a class="ws-app" href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer">
        <span class="ws-app-ico"><?= av_workspace_icon($s['icon']) ?></span>
        <span><span class="t"><?= e($s['label']) ?></span><span class="d"><?= e($s['desc']) ?></span></span>
      </a>
<?php endforeach; ?>    </div>
  </section>

<?php if ($isOrg): ?>
  <!-- Live: coming up + recent files -->
  <section class="ws-sec">
    <div class="ws-cols">
      <div class="ws-card">
        <div class="ws-card-head"><h3>Coming up</h3><a href="<?= e(av_ws_link('AV_WS_CALENDAR_URL', "https://calendar.google.com/a/$domain")) ?>" target="_blank" rel="noopener noreferrer">Calendar →</a></div>
        <ul class="ws-list" id="wsEvents" data-ws="<?= $wsConfig ? '1' : '0' ?>">
<?php if ($wsConfig): ?>          <li class="ws-li"><div class="body"><div class="ws-skel" style="width:70%"></div><div class="ws-skel" style="width:40%"></div></div></li>
<?php else: ?>          <li class="ws-empty">Connect Workspace to see your team calendar here. Meanwhile, open <a href="<?= e(av_ws_link('AV_WS_CALENDAR_URL', "https://calendar.google.com/a/$domain")) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--ws-accent)">Calendar</a>.</li>
<?php endif; ?>        </ul>
      </div>
      <div class="ws-card">
        <div class="ws-card-head"><h3>Recent files</h3><a href="<?= e(av_ws_link('AV_WS_DRIVE_URL', "https://drive.google.com/a/$domain")) ?>" target="_blank" rel="noopener noreferrer">Drive →</a></div>
        <ul class="ws-list" id="wsFiles" data-ws="<?= $wsConfig ? '1' : '0' ?>">
<?php if ($wsConfig): ?>          <li class="ws-li"><div class="body"><div class="ws-skel" style="width:65%"></div><div class="ws-skel" style="width:35%"></div></div></li>
<?php else: ?>          <li class="ws-empty">Connect Workspace to see shared files here. Meanwhile, open <a href="<?= e(av_ws_link('AV_WS_DRIVE_URL', "https://drive.google.com/a/$domain")) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--ws-accent)">Drive</a>.</li>
<?php endif; ?>        </ul>
      </div>
    </div>
  </section>

<?php if (!empty($embeds['calendar']) || !empty($embeds['drive'])): ?>
  <!-- Embedded Calendar + Drive -->
  <section class="ws-sec">
    <div class="ws-sec-head"><h2>In-place</h2></div>
    <div class="ws-cols">
<?php if (!empty($embeds['calendar'])): ?>      <div class="ws-card"><div class="ws-card-head"><h3>Team calendar</h3></div><div class="ws-frame"><iframe src="<?= e($embeds['calendar']) ?>" title="Team calendar" loading="lazy" referrerpolicy="no-referrer"></iframe></div></div>
<?php endif; ?>
<?php if (!empty($embeds['drive'])): ?>      <div class="ws-card"><div class="ws-card-head"><h3>Shared files</h3></div><div class="ws-frame"><iframe src="<?= e($embeds['drive']) ?>" title="Shared files" loading="lazy" referrerpolicy="no-referrer"></iframe></div></div>
<?php endif; ?>    </div>
  </section>
<?php endif; ?>

<?php if ($comms): ?>
  <!-- Communities -->
  <section class="ws-sec">
    <div class="ws-sec-head"><h2>Communities</h2><a href="<?= e(av_ws_link('AV_WS_CHAT_URL', 'https://chat.google.com')) ?>" target="_blank" rel="noopener noreferrer">Open Chat →</a></div>
    <div class="ws-chips">
<?php foreach ($comms as $c): ?>      <a class="ws-chip" href="<?= e($c['url']) ?>" target="_blank" rel="noopener noreferrer"><span class="t"><?= e($c['name']) ?></span><?php if (!empty($c['desc'])): ?><span class="d"><?= e($c['desc']) ?></span><?php endif; ?></a>
<?php endforeach; ?>    </div>
  </section>
<?php endif; ?>

<?php if ($isAdmin && $wsConfig): ?>
  <!-- People directory (admins) -->
  <section class="ws-sec">
    <div class="ws-sec-head"><h2>People</h2><a href="<?= e(av_ws_link('AV_WS_ADMIN_URL', 'https://admin.google.com')) ?>" target="_blank" rel="noopener noreferrer">Admin console →</a></div>
    <div class="ws-people" id="wsPeople"><div class="ws-empty">Loading the directory…</div></div>
  </section>
<?php endif; ?>

<?php else: ?>
  <!-- Non-member: connect account -->
  <section class="ws-sec">
    <div class="ws-note">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.4 8.3-8 10-4.6-1.7-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
      <div>The Afrovanguard Workspace (Gmail, Calendar, Drive, Meet, Chat and Groups) is for <b>@<?= e($domain) ?></b> members. You're signed in as <b><?= e($email) ?></b>, so the launcher above opens your personal Google apps. To join the org Workspace, see <a href="/how-it-works" style="color:var(--ws-accent)">how membership works</a>.</div>
    </div>
  </section>
<?php endif; ?>

  <div class="ws-foot">
    Afrovanguard runs on Google Workspace. <a href="/portal/">Member portal</a> · <a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Home</a>
  </div>
</main>

<script>
(function(){
  var pop = document.getElementById('wsPop'), popBtn = document.getElementById('wsLauncherBtn');
  var menu = document.getElementById('wsMenu'), userBtn = document.getElementById('wsUserBtn');
  function toggle(el, btn, on){ el.classList.toggle('open', on); if(btn) btn.setAttribute('aria-expanded', on ? 'true':'false'); }
  popBtn && popBtn.addEventListener('click', function(e){ e.stopPropagation(); var o=!pop.classList.contains('open'); toggle(pop,popBtn,o); toggle(menu,userBtn,false); });
  userBtn && userBtn.addEventListener('click', function(e){ e.stopPropagation(); var o=!menu.classList.contains('open'); toggle(menu,userBtn,o); toggle(pop,popBtn,false); });
  document.addEventListener('click', function(){ toggle(pop,popBtn,false); toggle(menu,userBtn,false); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ toggle(pop,popBtn,false); toggle(menu,userBtn,false); } });
  pop && pop.addEventListener('click', function(e){ e.stopPropagation(); });
  menu && menu.addEventListener('click', function(e){ e.stopPropagation(); });

  // Theme toggle (shares the portal cookie, so both surfaces agree).
  var tBtn = document.getElementById('wsThemeBtn');
  function paintTheme(){ var light = document.body.classList.contains('ws-light');
    var sun=tBtn.querySelector('.ico-sun'), moon=tBtn.querySelector('.ico-moon');
    if(sun) sun.style.display = light ? 'none':'block'; if(moon) moon.style.display = light ? 'block':'none'; }
  tBtn && tBtn.addEventListener('click', function(){
    var light = document.body.classList.toggle('ws-light');
    document.cookie = 'av_portal_theme=' + (light?'light':'dark') + ';path=/;max-age=31536000;samesite=lax';
    paintTheme();
  });
  paintTheme();

  // Sign out (same endpoint the portal uses).
  var out = document.getElementById('wsSignout');
  out && out.addEventListener('click', function(e){ e.preventDefault();
    fetch('/academy/api.php?action=logout',{method:'POST',credentials:'same-origin'})
      .catch(function(){}).finally(function(){ location.href='/'; });
  });

  window.wsSearch = function(e){ e.preventDefault();
    var q = (e.target.q.value||'').trim(); if(!q) return false;
    // Search across Gmail — the most useful single Workspace search entry point.
    window.open('https://mail.google.com/mail/u/0/#search/' + encodeURIComponent(q), '_blank', 'noopener');
    return false;
  };

  function esc(s){ return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function fmtWhen(iso, allDay){
    if(!iso) return '';
    var d = new Date(iso); if(isNaN(d)) return esc(iso);
    var opt = allDay ? {weekday:'short',month:'short',day:'numeric'} : {weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'};
    try { return d.toLocaleString(undefined, opt); } catch(_){ return d.toString(); }
  }

  // Live Calendar + Drive (progressive; a slow/missing Google never blocks).
  var evEl = document.getElementById('wsEvents');
  if (evEl && evEl.getAttribute('data-ws') === '1') {
    fetch('/portal/workspace.php?action=events',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d || !d.ok){ evEl.innerHTML='<li class="ws-empty">Couldn’t load the calendar right now.</li>'; return; }
      var ev = d.events||[];
      if(!ev.length){ evEl.innerHTML='<li class="ws-empty">Nothing on the calendar yet.</li>'; return; }
      evEl.innerHTML = ev.map(function(x){
        var loc = x.location ? ' · '+esc(x.location) : '';
        var t = x.url ? '<a href="'+esc(x.url)+'" target="_blank" rel="noopener noreferrer">'+esc(x.title)+'</a>' : esc(x.title);
        return '<li class="ws-li"><span class="dot"></span><div class="body"><div class="ttl">'+t+'</div><div class="meta">'+fmtWhen(x.start,x.all_day)+loc+'</div></div></li>';
      }).join('');
    }).catch(function(){ evEl.innerHTML='<li class="ws-empty">Couldn’t load the calendar right now.</li>'; });
  }
  var flEl = document.getElementById('wsFiles');
  if (flEl && flEl.getAttribute('data-ws') === '1') {
    fetch('/portal/workspace.php?action=files',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d || !d.ok){ flEl.innerHTML='<li class="ws-empty">Couldn’t load Drive right now.</li>'; return; }
      var fs = d.files||[];
      if(!fs.length){ flEl.innerHTML='<li class="ws-empty">No shared files yet.</li>'; return; }
      flEl.innerHTML = fs.map(function(x){
        var when = x.modified ? fmtWhen(x.modified,false) : '';
        var t = x.url ? '<a href="'+esc(x.url)+'" target="_blank" rel="noopener noreferrer">'+esc(x.name)+'</a>' : esc(x.name);
        return '<li class="ws-li"><span class="dot"></span><div class="body"><div class="ttl">'+t+'</div><div class="meta">'+esc(when)+'</div></div></li>';
      }).join('');
    }).catch(function(){ flEl.innerHTML='<li class="ws-empty">Couldn’t load Drive right now.</li>'; });
  }

  // PER-USER: the member's own Gmail / Calendar / Drive — refreshable + auto-updating.
  var mineSec = document.getElementById('wsMine');
  if (mineSec && mineSec.getAttribute('data-connected') === '1') {
    var mailEl = document.getElementById('wsMineMail'), evEl = document.getElementById('wsMineEvents'), flEl = document.getElementById('wsMineFiles');
    var refreshBtn = document.getElementById('wsRefresh'), updatedEl = document.getElementById('wsUpdated');
    var mineTimer = null, mineLoading = false;
    function joinBtn(u){ return u ? '<a class="ws-join" href="'+esc(u)+'" target="_blank" rel="noopener noreferrer">Join</a>' : ''; }
    function loadMine(){
      if (mineLoading) return; mineLoading = true;
      if (mineSec) mineSec.classList.add('is-loading');
      fetch('/portal/workspace.php?action=me',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
        mineLoading = false; if (mineSec) mineSec.classList.remove('is-loading');
        var mine = d && d.mine ? d.mine : null;
        if (!mine) { [mailEl,evEl,flEl].forEach(function(el){ if(el) el.innerHTML='<li class="ws-empty">Couldn’t reach Google right now.</li>'; }); return; }
        if (updatedEl) updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'});
        var ub = document.getElementById('wsUnread');
        if (ub) { if (typeof mine.unread === 'number' && mine.unread > 0) { ub.textContent = mine.unread > 99 ? '99+' : mine.unread; ub.hidden = false; } else { ub.hidden = true; } }
        var mail = mine.mail||[];
        mailEl.innerHTML = mail.length ? mail.map(function(x){
          var t = x.url ? '<a href="'+esc(x.url)+'" target="_blank" rel="noopener noreferrer">'+esc(x.subject)+'</a>' : esc(x.subject);
          return '<li class="ws-li'+(x.unread?' is-unread':'')+'"><span class="dot"></span><div class="body"><div class="ttl">'+t+'</div><div class="meta">'+esc(x.from)+'</div><div class="snip">'+esc(x.snippet)+'</div></div></li>';
        }).join('') : '<li class="ws-empty">Inbox is clear. 🎉</li>';
        var ev = mine.events||[];
        evEl.innerHTML = ev.length ? ev.map(function(x){
          var loc = x.location ? ' · '+esc(x.location) : '';
          var t = x.url ? '<a href="'+esc(x.url)+'" target="_blank" rel="noopener noreferrer">'+esc(x.title)+'</a>' : esc(x.title);
          return '<li class="ws-li"><span class="dot"></span><div class="body"><div class="ttl">'+t+'</div><div class="meta">'+fmtWhen(x.start,x.all_day)+loc+'</div></div>'+joinBtn(x.meet_url)+'</li>';
        }).join('') : '<li class="ws-empty">Nothing coming up.</li>';
        var fs = mine.files||[];
        flEl.innerHTML = fs.length ? fs.map(function(x){
          var t = x.url ? '<a href="'+esc(x.url)+'" target="_blank" rel="noopener noreferrer">'+esc(x.name)+'</a>' : esc(x.name);
          return '<li class="ws-li"><span class="dot"></span><div class="body"><div class="ttl">'+t+'</div><div class="meta">'+esc(x.modified?fmtWhen(x.modified,false):'')+'</div></div></li>';
        }).join('') : '<li class="ws-empty">No recent files.</li>';
      }).catch(function(){
        mineLoading = false; if (mineSec) mineSec.classList.remove('is-loading');
        [mailEl,evEl,flEl].forEach(function(el){ if(el) el.innerHTML='<li class="ws-empty">Couldn’t reach Google right now.</li>'; });
      });
    }
    if (refreshBtn) refreshBtn.addEventListener('click', loadMine);
    loadMine();
    // Auto-refresh while the tab is visible (every 3 min); pause when hidden.
    function arm(){ clearInterval(mineTimer); mineTimer = setInterval(function(){ if(!document.hidden) loadMine(); }, 180000); }
    arm();
    document.addEventListener('visibilitychange', function(){ if(!document.hidden) loadMine(); });
  }

  // People directory (admins only — endpoint enforces it too).
  var ppl = document.getElementById('wsPeople');
  if (ppl) {
    fetch('/portal/workspace.php?action=directory',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d || !d.ok){ ppl.innerHTML='<div class="ws-empty">Directory unavailable.</div>'; return; }
      var us = d.users||[];
      if(!us.length){ ppl.innerHTML='<div class="ws-empty">No directory users found.</div>'; return; }
      ppl.innerHTML = us.map(function(x){
        var nm = esc(x.name||x.email);
        var ini = (nm.trim()[0]||'A').toUpperCase();
        var av = x.photo ? '<img src="'+esc(x.photo)+'" alt="" referrerpolicy="no-referrer">' : ini;
        return '<div class="ws-person"><div class="pa">'+av+'</div><div style="min-width:0"><div class="nm">'+nm+'</div><div class="em">'+esc(x.email||'')+'</div></div></div>';
      }).join('');
    }).catch(function(){ ppl.innerHTML='<div class="ws-empty">Directory unavailable.</div>'; });
  }
})();
</script>
<script src="/portal/team-chat.js" defer></script>
<?php
/** Local greeting by time of day (server tz). */
function wsGreeting(): string
{
    $h = (int) date('G');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}
