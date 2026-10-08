<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Profile</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --nav-bg:#0E1830;
    --nav-bg-soft:#16223F;
    --nav-bg-hover:#1D2A4E;
    --nav-ink:#E6EBF5;
    --nav-ink-soft:#8A93AB;
    --nav-line:rgba(255,255,255,.06);
    --accent:#5EEAD4;
    --accent-soft:rgba(94,234,212,.14);

    --paper:#F4F5F8;
    --paper-raised:#FFFFFF;
    --ink:#1C2420;
    --ink-soft:#5B6560;
    --ink-faint:#8C9498;
    --line:#E5E2D9;
    --line-soft:#EEECE4;

    --teal:#2F6F5E;
    --teal-deep:#205043;
    --amber:#9C6B12;
    --amber-bg:#F6EBD6;
    --sage:#3C7A57;
    --sage-bg:#E3EFE6;
    --brick:#A23B33;
    --brick-bg:#F5E4E1;
    --info:#1E55A6;
    --info-bg:#E8EEFB;
    --violet:#5B4B9E;
    --violet-bg:#EDEAF8;

    --radius:14px;
    --radius-sm:10px;
    --shadow:0 1px 3px rgba(15,24,48,.05), 0 6px 20px rgba(15,24,48,.04);
    --sidebar-w:248px;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;height:100%;}
  body{
    background:var(--paper);
    color:var(--ink);
    font-family:'Inter', system-ui, -apple-system, sans-serif;
    line-height:1.55;
    -webkit-font-smoothing:antialiased;
  }
  ::placeholder{color:#A9A398;}

  /* `hidden` must win over the layout rules below: an author-level
     `display:block` outranks the user-agent `[hidden]{display:none}`, so
     without this the avatar image would stay visible while "hidden". */
  [hidden]{display:none !important;}

  .app{display:flex;min-height:100vh;}

  /* ----------------------------------------------------------------
   * Sidebar — identical to the organizations / projects / support panels
   * ---------------------------------------------------------------- */
  .sidebar{
    width:var(--sidebar-w);
    flex-shrink:0;
    background:var(--nav-bg);
    color:var(--nav-ink);
    display:flex;
    flex-direction:column;
    position:sticky;
    top:0;
    height:100vh;
    padding:22px 16px 18px;
  }
  .brand{
    display:flex;align-items:center;gap:10px;
    padding:4px 8px 22px;
    border-bottom:1px solid var(--nav-line);
    margin-bottom:16px;
  }
  .brand .bolt{
    width:30px;height:30px;border-radius:8px;
    background:var(--accent-soft);
    display:flex;align-items:center;justify-content:center;
    color:var(--accent);flex-shrink:0;
  }
  .brand .name{font-family:'Fraunces', serif;font-weight:600;font-size:19px;letter-spacing:-.01em;color:#fff;}
  .brand .name span{color:var(--accent);}

  .nav-section{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--nav-ink-soft);padding:14px 10px 6px;font-weight:600;}
  .nav{display:flex;flex-direction:column;gap:2px;}
  .nav-item{
    display:flex;align-items:center;gap:11px;
    padding:9px 11px;border-radius:9px;
    font-size:14px;color:var(--nav-ink-soft);
    text-decoration:none;cursor:pointer;
    transition:background .12s ease, color .12s ease;
    position:relative;
  }
  .nav-item .ic{width:18px;height:18px;display:flex;align-items:center;justify-content:center;color:inherit;}
  .nav-item:hover{background:var(--nav-bg-hover);color:var(--nav-ink);}
  .nav-item.active{background:var(--nav-bg-soft);color:#fff;font-weight:500;}
  .nav-item.active::before{
    content:'';position:absolute;left:-16px;top:50%;transform:translateY(-50%);
    width:3px;height:18px;border-radius:0 3px 3px 0;background:var(--accent);
  }
  .nav-item.disabled{cursor:not-allowed;opacity:.55;}
  .nav-item.disabled:hover{background:none;color:var(--nav-ink-soft);}

  .sidebar-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--nav-line);}
  .user-card{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;background:var(--nav-bg-soft);text-decoration:none;color:inherit;transition:background .15s ease;}
  .user-card:hover{background:var(--nav-bg-hover);}
  .user-card .avatar{
    width:38px;height:38px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-weight:600;font-size:14px;color:#fff;flex-shrink:0;overflow:hidden;
    background:linear-gradient(135deg,#5EEAD4,#2F6F5E);
  }
  .user-card .avatar img{width:100%;height:100%;object-fit:cover;display:block;}
  .user-card .meta{min-width:0;flex:1;}
  .user-card .meta .nm{font-size:13.5px;color:#fff;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .user-card .meta .rl{font-size:11.5px;color:var(--nav-ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  /* ----------------------------------------------------------------
   * Main column
   * ---------------------------------------------------------------- */
  .main{flex:1;min-width:0;display:flex;flex-direction:column;}
  .topbar{
    background:var(--paper-raised);
    border-bottom:1px solid var(--line);
    padding:14px 30px;
    display:flex;align-items:center;gap:16px;
    position:sticky;top:0;z-index:10;
  }
  .topbar .crumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-faint);}
  .topbar .crumb .sep{opacity:.5;}
  .topbar .crumb .cur{color:var(--ink);font-weight:500;}
  .topbar-right{margin-left:auto;display:flex;align-items:center;gap:14px;}
  .topbar form{margin:0;}
  .logout-btn{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    padding:8px 14px;border-radius:9px;font-size:13.5px;
    cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;
  }
  .logout-btn:hover{border-color:var(--brick);color:var(--brick);}

  .content{padding:26px 30px 80px;max-width:1180px;width:100%;}

  .page-head{display:flex;align-items:flex-start;gap:20px;margin-bottom:22px;}
  .page-head .titles{flex:1;min-width:0;}
  .page-head h1{font-family:'Fraunces', serif;font-weight:500;font-size:30px;margin:0 0 6px;letter-spacing:-.015em;}
  .page-head p{margin:0;color:var(--ink-soft);font-size:14.5px;max-width:720px;}

  /* Hero — photo + identity */
  .hero{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow);
    padding:22px;display:flex;gap:22px;align-items:center;margin-bottom:22px;
  }
  .hero-avatar{
    position:relative;width:96px;height:96px;border-radius:50%;flex-shrink:0;
    overflow:hidden;display:flex;align-items:center;justify-content:center;
    font-family:'Fraunces',serif;font-size:36px;font-weight:600;color:#fff;
    background:linear-gradient(135deg,#5EEAD4,#2F6F5E);
  }
  .hero-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
  .hero-avatar-overlay{
    position:absolute;inset:0;border:none;cursor:pointer;
    background:rgba(14,24,48,.62);color:#fff;font-family:inherit;
    font-size:11.5px;font-weight:500;
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
    opacity:0;transition:opacity .15s ease;
  }
  .hero-avatar:hover .hero-avatar-overlay,
  .hero-avatar-overlay:focus-visible{opacity:1;}

  .hero-body{flex:1;min-width:0;}
  .hero-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .hero-top h2{font-family:'Fraunces',serif;font-weight:500;font-size:24px;margin:0;letter-spacing:-.01em;}
  .hero-sub{color:var(--ink-soft);font-size:14px;margin-top:4px;}
  .hero-email{color:var(--ink-faint);font-size:13px;margin-top:2px;}
  .hero-actions{display:flex;align-items:center;gap:12px;margin-top:13px;flex-wrap:wrap;}
  .hero-actions .hint{font-size:12px;color:var(--ink-faint);}

  .pill{font-size:11px;padding:2px 9px;border-radius:100px;font-weight:500;white-space:nowrap;display:inline-block;}
  .pill.role{background:var(--info-bg);color:var(--info);border:1px solid rgba(30,85,166,.16);padding:3px 11px;font-size:11.5px;}

  /* Layout */
  .grid{display:grid;grid-template-columns:1.35fr 1fr;gap:18px;align-items:start;}
  .col{display:flex;flex-direction:column;gap:18px;}

  .card{background:var(--paper-raised);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
  .card-head{padding:15px 18px;border-bottom:1px solid var(--line);display:flex;align-items:baseline;gap:10px;}
  .card-head h3{font-family:'Fraunces',serif;font-weight:500;font-size:17px;margin:0;}
  .card-head .card-sub{font-size:12.5px;color:var(--ink-faint);margin-left:auto;}
  .card-body{padding:18px;display:flex;flex-direction:column;gap:15px;}
  .card-foot{padding:14px 18px;border-top:1px solid var(--line);background:#FBFBF9;display:flex;align-items:center;gap:10px;}

  /* Fields */
  .field{display:flex;flex-direction:column;gap:6px;}
  .field.narrow{max-width:170px;}
  .field label{font-size:12.5px;font-weight:500;color:var(--ink-soft);}
  .field input,.field textarea{
    border:1px solid var(--line);background:var(--paper);border-radius:9px;
    padding:9px 12px;font-size:14px;font-family:inherit;color:var(--ink);width:100%;
  }
  .field textarea{resize:vertical;min-height:92px;line-height:1.55;}
  .field input:focus,.field textarea:focus{outline:none;border-color:var(--teal);background:var(--paper-raised);}
  .field input.bad,.field textarea.bad{border-color:var(--brick);background:var(--brick-bg);}
  .field-foot{display:flex;align-items:center;gap:10px;min-height:17px;}
  .field-foot .err{font-size:11.5px;color:var(--brick);}
  .field-foot .count{margin-left:auto;font-size:11.5px;color:var(--ink-faint);white-space:nowrap;}
  .field-foot .count.over{color:var(--brick);font-weight:600;}

  /* Key/value list */
  .kv{margin:0;display:flex;flex-direction:column;}
  .kv > div{display:flex;align-items:baseline;gap:14px;padding:9px 0;border-bottom:1px solid var(--line-soft);}
  .kv > div:last-child{border-bottom:none;}
  .kv dt{font-size:12.5px;color:var(--ink-soft);flex-shrink:0;width:112px;}
  .kv dd{margin:0;font-size:13.5px;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;}

  /* Mini stats */
  .mini-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
  .mini{background:var(--paper);border:1px solid var(--line-soft);border-radius:10px;padding:11px 13px;}
  .mini .lbl{font-size:11.5px;color:var(--ink-soft);font-weight:500;}
  .mini .val{font-family:'Fraunces',serif;font-size:20px;font-weight:500;line-height:1.15;margin-top:2px;}
  .mini .val.skeleton{color:var(--ink-faint);}

  .btn-primary{
    background:var(--teal);color:#fff;border:none;
    border-radius:9px;padding:10px 18px;font-size:14px;font-weight:500;
    cursor:pointer;font-family:inherit;
    display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  }
  .btn-primary:hover{background:var(--teal-deep);}
  .btn-primary:disabled{opacity:.5;cursor:default;}

  .btn-ghost{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    border-radius:9px;padding:9px 15px;font-size:13.5px;cursor:pointer;font-family:inherit;
  }
  .btn-ghost:hover{border-color:var(--teal);color:var(--teal-deep);}
  .btn-ghost:disabled{opacity:.5;cursor:default;}

  .link{font-size:13px;color:var(--teal-deep);text-decoration:none;font-weight:500;}
  .link:hover{text-decoration:underline;}

  /* Toast */
  #toasts{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:10px;z-index:80;}
  .toast{
    background:#12203C;color:#F2F5FA;border-radius:10px;
    padding:12px 16px;font-size:13.5px;max-width:380px;
    box-shadow:0 10px 30px rgba(15,24,48,.3);
  }
  .toast.err{background:var(--brick);}
  .toast.ok{background:var(--teal-deep);}

  @media (max-width:1080px){
    .grid{grid-template-columns:1fr;}
  }
  @media (max-width:980px){
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .content{padding:20px 16px 60px;}
    .topbar{padding:12px 16px;}
    .hero{flex-direction:column;align-items:flex-start;}
  }
</style>
</head>
<body>

<div class="app">

  <!-- ============================= Sidebar ============================= -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="bolt" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
          <path d="M13 2L4.5 13.5H11l-2 8.5 9-12.5H11.5L13 2Z" fill="currentColor"/>
        </svg>
      </span>
      <span class="name">Skill<span>Span</span></span>
    </div>

    @php
      // Every page in this panel carries its own inline sidebar, so this one
      // repeats the list rather than sharing a layout. The gate matches the
      // routes: the pages behind the `admin` middleware are only offered to an
      // administrator, while Support and Profile are open to both audiences.
      $panelIsAdmin = \App\Support\PanelAccess::isAdministrator(auth()->user());
    @endphp

    <div class="nav-section">{{ $panelIsAdmin ? 'Admin' : 'Support' }}</div>
    <nav class="nav">
      @if ($panelIsAdmin)
      <a class="nav-item" href="{{ route('admin.organizations') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 13h6V5H4v8Zm0 6h6v-4H4v4Zm10 0h6v-8h-6v8Zm0-12v4h6V7h-6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Dashboard
      </a>
      <a class="nav-item" href="{{ route('admin.organizations') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Organizations
      </a>
      <a class="nav-item" href="{{ route('admin.projects') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Projects
      </a>
      <a class="nav-item" href="{{ route('admin.questions') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.8.9c0 1.6-2.4 2-2.4 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor"/></svg>
        </span>
        Questions
      </a>
      @endif
      <a class="nav-item" href="{{ route('admin.support') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Support
      </a>
      @if ($panelIsAdmin)
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M16 3a5 5 0 0 1 4 8.06V20l-3-2-3 2-3-2-3 2V11.06A5 5 0 0 1 8 3a5 5 0 0 1 8 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Users
      </a>
      @endif
      <a class="nav-item active" href="{{ route('admin.profile') }}" aria-current="page">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </span>
        Profile
      </a>
    </nav>

    {{-- No link back to the page we are already on. --}}
    @include('admin.partials.user-card', ['panelCardLinksToProfile' => false])
  </aside>

  <!-- ============================= Main ============================= -->
  <main class="main">
    <header class="topbar">
      <div class="crumb">
        <span>SkillSpan</span>
        <span class="sep">/</span>
        <span>Admin</span>
        <span class="sep">/</span>
        <span class="cur">Profile</span>
      </div>

      <div class="topbar-right">
        <button class="logout-btn" id="btn-reload" title="Reload from the server">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M20 11A8 8 0 1 0 18.5 16M20 5v6h-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Refresh
        </button>

        <form method="POST" action="{{ route('admin.logout') }}">
          @csrf
          <button type="submit" class="logout-btn" title="Sign out">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 12H4m0 0 4-4m-4 4 4 4M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Log out
          </button>
        </form>
      </div>
    </header>

    <div class="content">
      <header class="page-head">
        <div class="titles">
          <h1>Profile</h1>
          <p>Your name, photo and password. These are the details the rest of the panel shows for you — the card in the sidebar, and every support reply you send.</p>
        </div>
      </header>

      <!-- ── Hero: photo and identity ────────────────────────────────── -->
      <section class="hero">
        <div class="hero-avatar" id="hero-avatar">
          <img id="hero-avatar-img" alt="" hidden>
          <span id="hero-avatar-initials">A</span>
          <button class="hero-avatar-overlay" id="btn-pick-avatar" type="button" title="Upload a new photo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 8a2 2 0 0 1 2-2h1.6l1.2-2h6.4l1.2 2H19a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12.5" r="3.2" stroke="currentColor" stroke-width="1.6"/></svg>
            <span>Change</span>
          </button>
        </div>

        <div class="hero-body">
          <div class="hero-top">
            <h2 id="hero-name">—</h2>
            <span class="pill role" id="hero-role">—</span>
          </div>
          <div class="hero-sub" id="hero-title">—</div>
          <div class="hero-email" id="hero-email">—</div>
          <div class="hero-actions">
            <button class="row-btn btn-ghost" id="btn-remove-avatar" type="button" hidden>Remove photo</button>
            <span class="hint" id="avatar-hint">JPEG, PNG, WebP or GIF · cropped to a square</span>
          </div>
        </div>

        <input type="file" id="avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      </section>

      <div class="grid">
        <!-- ── Left column ──────────────────────────────────────────── -->
        <div class="col">
          <section class="card">
            <div class="card-head">
              <h3>Details</h3>
              <span class="card-sub">Shown across the panel</span>
            </div>
            <div class="card-body">
              <div class="field">
                <label for="f-name">Name</label>
                <input id="f-name" type="text" maxlength="100" autocomplete="name" spellcheck="false">
                <div class="field-foot"><span class="err" id="e-name"></span><span class="count" id="c-name"></span></div>
              </div>

              <div class="field">
                <label for="f-title">Title</label>
                <input id="f-title" type="text" maxlength="60" placeholder="e.g. Platform Administrator">
                <div class="field-foot"><span class="err" id="e-title"></span><span class="count" id="c-title"></span></div>
              </div>

              <div class="field">
                <label for="f-bio">Bio</label>
                <textarea id="f-bio" rows="4" maxlength="600" placeholder="A sentence or two about what you do here."></textarea>
                <div class="field-foot"><span class="err" id="e-bio"></span><span class="count" id="c-bio"></span></div>
              </div>

              <div class="field narrow">
                <label for="f-age">Age</label>
                <input id="f-age" type="number" min="16" max="100" inputmode="numeric">
                <div class="field-foot"><span class="err" id="e-age"></span><span class="count" id="c-age"></span></div>
              </div>
            </div>
            <div class="card-foot">
              <button class="btn-primary" id="btn-save-details" type="button">Save changes</button>
              <button class="btn-ghost" id="btn-reset-details" type="button">Reset</button>
            </div>
          </section>

          <section class="card">
            <div class="card-head">
              <h3>Password</h3>
              <span class="card-sub">You stay signed in here</span>
            </div>
            <div class="card-body">
              <div class="field">
                <label for="f-current">Current password</label>
                <input id="f-current" type="password" autocomplete="current-password">
                <div class="field-foot"><span class="err" id="e-current"></span></div>
              </div>

              <div class="field">
                <label for="f-new">New password</label>
                <input id="f-new" type="password" autocomplete="new-password">
                <div class="field-foot"><span class="err" id="e-new"></span><span class="count">at least 8 characters</span></div>
              </div>

              <div class="field">
                <label for="f-confirm">Confirm new password</label>
                <input id="f-confirm" type="password" autocomplete="new-password">
                <div class="field-foot"><span class="err" id="e-confirm"></span></div>
              </div>
            </div>
            <div class="card-foot">
              <button class="btn-primary" id="btn-save-password" type="button">Change password</button>
            </div>
          </section>
        </div>

        <!-- ── Right column ─────────────────────────────────────────── -->
        <div class="col">
          <section class="card">
            <div class="card-head"><h3>Account</h3></div>
            <div class="card-body">
              <dl class="kv">
                <div><dt>Email</dt><dd id="kv-email">—</dd></div>
                <div><dt>Role</dt><dd id="kv-role">—</dd></div>
                <div><dt>Member since</dt><dd id="kv-created">—</dd></div>
                <div><dt>Last sign-in</dt><dd id="kv-login">—</dd></div>
                <div><dt>Email verified</dt><dd id="kv-verified">—</dd></div>
              </dl>
            </div>
          </section>

          <section class="card">
            <div class="card-head">
              <h3>Support</h3>
              <span class="card-sub" id="stats-scope">—</span>
            </div>
            <div class="card-body">
              <div class="mini-stats">
                <div class="mini"><div class="lbl">Total</div><div class="val skeleton" id="s-total">—</div></div>
                <div class="mini" id="mini-pending"><div class="lbl">Unclaimed</div><div class="val skeleton" id="s-pending">—</div></div>
                <div class="mini"><div class="lbl">In progress</div><div class="val skeleton" id="s-assigned">—</div></div>
                <div class="mini"><div class="lbl">Resolved</div><div class="val skeleton" id="s-resolved">—</div></div>
              </div>
              <a class="link" href="{{ route('admin.support') }}">Open the support inbox &rarr;</a>
            </div>
          </section>
        </div>
      </div>
    </div>
  </main>
</div>

<div id="toasts"></div>

<script>
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const BASE = '/admin/api/profile';

  const $ = function (id) { return document.getElementById(id); };

  const state = {
    data: null,
    limits: { display_title: 60, bio: 600, age_min: 16, age_max: 100, avatar_max_kb: 4096 },
    previewUrl: null,
  };

  // ── Transport ─────────────────────────────────────────────────────────

  async function api(path, options) {
    options = options || {};

    const isForm = options.body instanceof FormData;
    const headers = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': CSRF,
    };

    // Let the browser set the multipart boundary itself.
    if (!isForm && options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(path, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body,
      credentials: 'same-origin',
    });

    let payload = null;
    try { payload = await res.json(); } catch (e) { /* empty or non-JSON body */ }

    if (!res.ok) {
      const err = new Error(firstMessage(payload) || ('Request failed with ' + res.status + '.'));
      err.status = res.status;
      err.payload = payload;
      throw err;
    }

    return payload;
  }

  function firstMessage(payload) {
    if (!payload) return null;
    if (payload.errors) {
      for (const key in payload.errors) {
        const list = payload.errors[key];
        if (Array.isArray(list) && list.length) return list[0];
      }
    }
    return payload.message || null;
  }

  function toast(text, kind) {
    const node = document.createElement('div');
    node.className = 'toast ' + (kind || '');
    node.textContent = text;
    $('toasts').appendChild(node);
    setTimeout(function () { node.remove(); }, 4200);
  }

  // ── Formatting ────────────────────────────────────────────────────────

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
      + ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  }

  function initialsOf(name) {
    const s = String(name || '').trim();
    return s ? s.charAt(0).toUpperCase() : 'A';
  }

  function num(value) {
    return (value === null || value === undefined) ? '—' : String(value);
  }

  function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  // ── Render ────────────────────────────────────────────────────────────

  function apply(data) {
    state.data = data;

    const user = data.user || {};
    const profile = data.profile || {};
    const stats = data.stats || {};

    if (profile.limits) state.limits = profile.limits;

    applyHero(user, data.role, profile);
    applyAvatar(user, profile);
    applyForm(user, profile);
    applyAccount(user, data.role);
    applyStats(stats);
    applySidebar(user, profile, data.role);

    $('avatar-hint').textContent = 'JPEG, PNG, WebP or GIF · up to '
      + Math.round(state.limits.avatar_max_kb / 1024) + ' MB · cropped to a square';
  }

  function applyHero(user, role, profile) {
    $('hero-name').textContent = user.name || '—';
    $('hero-role').textContent = role || '—';
    $('hero-email').textContent = user.email || '—';

    const title = $('hero-title');
    if (profile.display_title) {
      title.textContent = profile.display_title;
      title.style.color = '';
    } else {
      title.textContent = 'No title set yet';
      title.style.color = 'var(--ink-faint)';
    }
  }

  function applyAvatar(user, profile) {
    if (state.previewUrl) {
      URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = null;
    }

    const img = $('hero-avatar-img');
    const initials = $('hero-avatar-initials');

    if (profile.avatar_url) {
      img.src = profile.avatar_url;
      img.hidden = false;
      initials.hidden = true;
    } else {
      img.removeAttribute('src');
      img.hidden = true;
      initials.hidden = false;
      initials.textContent = initialsOf(user.name);
    }

    $('btn-remove-avatar').hidden = !profile.has_avatar;
  }

  function applyForm(user, profile) {
    $('f-name').value = user.name || '';
    $('f-title').value = profile.display_title || '';
    $('f-bio').value = profile.bio || '';
    $('f-age').value = (profile.age === null || profile.age === undefined) ? '' : profile.age;

    $('f-age').min = state.limits.age_min;
    $('f-age').max = state.limits.age_max;
    $('f-name').maxLength = 100;
    $('f-title').maxLength = state.limits.display_title;
    $('f-bio').maxLength = state.limits.bio;

    clearErrors();
    updateCounters();
  }

  function applyAccount(user, role) {
    $('kv-email').textContent = user.email || '—';
    $('kv-email').title = user.email || '';
    $('kv-role').textContent = role || '—';
    $('kv-created').textContent = fmtDate(user.created_at);
    $('kv-login').textContent = fmtDate(user.last_login_at);
    $('kv-verified').textContent = user.email_verified_at
      ? fmtDate(user.email_verified_at)
      : 'Not verified';
  }

  function applyStats(stats) {
    const ids = ['s-total', 's-pending', 's-assigned', 's-resolved'];
    ids.forEach(function (id) {
      const node = $(id);
      node.classList.remove('skeleton');
    });

    $('s-total').textContent = num(stats.total);
    $('s-pending').textContent = num(stats.pending);
    $('s-assigned').textContent = num(stats.assigned);
    $('s-resolved').textContent = num(stats.resolved);

    const seesEverything = stats.scope === 'all';

    $('stats-scope').textContent = seesEverything ? 'Whole platform' : 'Your assignments';

    // "Unclaimed" is an administrator's view of the queue. For a mentor every
    // request they can see is already theirs, so the counter is always zero and
    // showing it would just be a confusing box.
    $('mini-pending').hidden = !seesEverything;
  }

  /**
   * Keep the sidebar card in step with the profile we just saved.
   *
   * The card is rendered by the server on every page, so without this a name
   * change would not appear until the next navigation.
   */
  function applySidebar(user, profile, role) {
    const nameNode = $('nav-user-name');
    if (nameNode) nameNode.textContent = user.name || '';

    const roleNode = $('nav-user-role');
    if (roleNode) roleNode.textContent = profile.display_title || role || '';

    const box = $('nav-avatar');
    if (!box) return;

    if (profile.avatar_url) {
      let img = $('nav-avatar-img');
      if (!img) {
        box.innerHTML = '';
        img = document.createElement('img');
        img.id = 'nav-avatar-img';
        img.alt = '';
        box.appendChild(img);
      }
      img.src = profile.avatar_url;
    } else {
      box.innerHTML = '';
      box.textContent = initialsOf(user.name);
    }
  }

  // ── Field state ───────────────────────────────────────────────────────

  function clearErrors() {
    ['name', 'title', 'bio', 'age', 'current', 'new', 'confirm'].forEach(function (key) {
      const node = $('e-' + key);
      if (node) node.textContent = '';
    });

    document.querySelectorAll('.field .bad').forEach(function (node) {
      node.classList.remove('bad');
    });
  }

  function updateCounters() {
    counter('f-title', 'c-title', state.limits.display_title);
    counter('f-bio', 'c-bio', state.limits.bio);
    counter('f-age', 'c-age', 0);
  }

  function counter(inputId, countId, max) {
    const input = $(inputId);
    const out = $(countId);
    if (!input || !out) return;

    if (!max) {
      out.textContent = state.limits.age_min + '–' + state.limits.age_max + ' years';
      return;
    }

    const used = input.value.length;
    out.textContent = used + ' / ' + max;
    out.classList.toggle('over', used > max);
  }

  /**
   * Put each validation message under the field it belongs to.
   *
   * Laravel answers a rejected FormRequest with `{message, errors}`, so the
   * per-field keys are already there — this only maps them onto the markup.
   */
  function showErrors(err) {
    clearErrors();

    const errors = (err.payload && err.payload.errors) || null;

    if (errors) {
      const fields = {
        name: 'name', display_title: 'title', bio: 'bio', age: 'age',
        current_password: 'current', password: 'new',
      };

      let placed = false;

      for (const key in errors) {
        const slot = fields[key];
        if (!slot) continue;

        const target = $('e-' + slot);
        const input = $('f-' + slot);
        const message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];

        if (target) target.textContent = message;
        if (input) input.classList.add('bad');
        placed = true;
      }

      if (placed) return;
    }

    toast(err.message || 'Something went wrong.', 'err');
  }

  // ── Actions ───────────────────────────────────────────────────────────

  async function load() {
    try {
      const body = await api(BASE);
      apply(body.data);
    } catch (err) {
      toast(err.message || 'Could not load your profile.', 'err');
    }
  }

  async function saveDetails() {
    const button = $('btn-save-details');
    const ageRaw = $('f-age').value.trim();

    const payload = {
      name: $('f-name').value.trim(),
      // An explicit null means "clear this", which is exactly what an emptied
      // box should do — see UpdateAdminProfileRequest.
      display_title: $('f-title').value.trim() || null,
      bio: $('f-bio').value.trim() || null,
      age: ageRaw === '' ? null : Number(ageRaw),
    };

    button.disabled = true;
    button.textContent = 'Saving…';

    try {
      const body = await api(BASE, { method: 'PATCH', body: JSON.stringify(payload) });
      apply(body.data);
      toast('Profile updated.', 'ok');
    } catch (err) {
      showErrors(err);
    } finally {
      button.disabled = false;
      button.textContent = 'Save changes';
    }
  }

  function resetDetails() {
    if (!state.data) return;
    applyForm(state.data.user || {}, state.data.profile || {});
    toast('Form reset to the saved values.');
  }

  async function uploadAvatar(file) {
    const form = new FormData();
    form.append('avatar', file);

    try {
      const body = await api(BASE + '/avatar', { method: 'POST', body: form });
      apply(body.data);
      toast('Profile photo updated.', 'ok');
    } catch (err) {
      showErrors(err);

      // A rejected file must not leave the optimistic preview on screen.
      if (state.data) applyAvatar(state.data.user || {}, state.data.profile || {});
    }
  }

  async function removeAvatar() {
    if (!window.confirm('Remove your profile photo?')) return;

    try {
      const body = await api(BASE + '/avatar', { method: 'DELETE' });
      apply(body.data);
      toast('Profile photo removed.', 'ok');
    } catch (err) {
      showErrors(err);
    }
  }

  async function savePassword() {
    const button = $('btn-save-password');
    const current = $('f-current').value;
    const next = $('f-new').value;
    const confirm = $('f-confirm').value;

    clearErrors();

    if (!current) {
      $('e-current').textContent = 'Enter your current password.';
      $('f-current').classList.add('bad');
      return;
    }

    if (next.length < 8) {
      $('e-new').textContent = 'The new password must be at least 8 characters.';
      $('f-new').classList.add('bad');
      return;
    }

    if (next !== confirm) {
      $('e-confirm').textContent = 'The two new passwords do not match.';
      $('f-confirm').classList.add('bad');
      return;
    }

    button.disabled = true;
    button.textContent = 'Changing…';

    try {
      await api(BASE + '/password', {
        method: 'POST',
        body: JSON.stringify({
          current_password: current,
          password: next,
          password_confirmation: confirm,
        }),
      });

      $('f-current').value = '';
      $('f-new').value = '';
      $('f-confirm').value = '';
      toast('Password changed.', 'ok');
    } catch (err) {
      // The wrong current password is a service refusal, so it arrives in the
      // project envelope rather than Laravel's `errors` shape.
      if (err.payload && err.payload.code === 'PROFILE_CURRENT_PASSWORD_INCORRECT') {
        $('e-current').textContent = err.payload.message;
        $('f-current').classList.add('bad');
      } else {
        showErrors(err);
      }
    } finally {
      button.disabled = false;
      button.textContent = 'Change password';
    }
  }

  // ── Wiring ────────────────────────────────────────────────────────────

  $('btn-save-details').addEventListener('click', saveDetails);
  $('btn-reset-details').addEventListener('click', resetDetails);
  $('btn-save-password').addEventListener('click', savePassword);
  $('btn-remove-avatar').addEventListener('click', removeAvatar);
  $('btn-reload').addEventListener('click', load);

  $('btn-pick-avatar').addEventListener('click', function () {
    $('avatar-input').click();
  });

  $('avatar-input').addEventListener('change', function (event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const limit = state.limits.avatar_max_kb;

    if (limit && file.size > limit * 1024) {
      toast('That image is ' + humanSize(file.size) + ' — the limit is '
        + Math.round(limit / 1024) + ' MB.', 'err');
      event.target.value = '';
      return;
    }

    // Show the chosen file straight away so the upload feels immediate; the
    // server re-encodes it and replaces this with the stored version.
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = URL.createObjectURL(file);

    $('hero-avatar-img').src = state.previewUrl;
    $('hero-avatar-img').hidden = false;
    $('hero-avatar-initials').hidden = true;

    uploadAvatar(file).finally(function () { event.target.value = ''; });
  });

  ['f-title', 'f-bio', 'f-age'].forEach(function (id) {
    $(id).addEventListener('input', updateCounters);
  });

  // Ctrl/Cmd+S saves the details rather than the browser's page dialog.
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      saveDetails();
    }
  });

  load();
})();
</script>
</body>
</html>
