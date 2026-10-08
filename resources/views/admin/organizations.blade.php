<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Organization Requests</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    /* Sidebar — dark navy to match the SkillSpan product shell */
    --nav-bg:#0E1830;
    --nav-bg-soft:#16223F;
    --nav-bg-hover:#1D2A4E;
    --nav-ink:#E6EBF5;
    --nav-ink-soft:#8A93AB;
    --nav-line:rgba(255,255,255,.06);
    --accent:#5EEAD4;       /* SkillSpan teal — logo bolt + active marker */
    --accent-soft:rgba(94,234,212,.14);

    /* Content area — light, like the learner dashboard */
    --paper:#F4F5F8;
    --paper-raised:#FFFFFF;
    --ink:#1C2420;
    --ink-soft:#5B6560;
    --ink-faint:#8C9498;
    --line:#E5E2D9;
    --line-soft:#EEECE4;

    /* Status palette */
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

  .app{display:flex;min-height:100vh;}

  /* ----------------------------------------------------------------
   * Sidebar
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
    display:flex;
    align-items:center;
    gap:10px;
    padding:4px 8px 22px;
    border-bottom:1px solid var(--nav-line);
    margin-bottom:16px;
  }
  .brand .bolt{
    width:30px;height:30px;
    border-radius:8px;
    background:var(--accent-soft);
    display:flex;align-items:center;justify-content:center;
    color:var(--accent);
    flex-shrink:0;
  }
  .brand .name{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:19px;
    letter-spacing:-.01em;
    color:#fff;
  }
  .brand .name span{color:var(--accent);}

  .nav-section{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--nav-ink-soft);padding:14px 10px 6px;font-weight:600;}
  .nav{display:flex;flex-direction:column;gap:2px;}
  .nav-item{
    display:flex;
    align-items:center;
    gap:11px;
    padding:9px 11px;
    border-radius:9px;
    font-size:14px;
    color:var(--nav-ink-soft);
    text-decoration:none;
    cursor:pointer;
    transition:background .12s ease, color .12s ease;
    position:relative;
  }
  .nav-item .ic{
    width:18px;height:18px;
    display:flex;align-items:center;justify-content:center;
    color:inherit;
    opacity:.85;
    flex-shrink:0;
  }
  .nav-item:hover{background:var(--nav-bg-hover);color:var(--nav-ink);}
  .nav-item.active{
    background:var(--nav-bg-soft);
    color:#fff;
    font-weight:500;
  }
  .nav-item.active::before{
    content:'';position:absolute;left:-16px;top:6px;bottom:6px;width:3px;
    background:var(--accent);border-radius:0 3px 3px 0;
  }
  .nav-item.disabled{cursor:not-allowed;opacity:.55;}
  .nav-item.disabled:hover{background:none;color:var(--nav-ink-soft);}
  .nav-item .badge{
    margin-left:auto;
    font-size:11px;
    background:var(--accent-soft);
    color:var(--accent);
    padding:2px 7px;border-radius:100px;font-weight:600;
  }

  .sidebar-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--nav-line);}
  .user-card{
    display:flex;align-items:center;gap:10px;
    padding:10px;border-radius:10px;background:var(--nav-bg-soft);
    text-decoration:none;color:inherit;transition:background .15s ease;
  }
  .user-card:hover{background:var(--nav-bg-hover);}
  .user-card .avatar{
    width:38px;height:38px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-weight:600;font-size:14px;color:#fff;
    flex-shrink:0;overflow:hidden;
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
  .topbar h1{
    font-family:'Fraunces', serif;
    font-weight:500;font-size:22px;margin:0;letter-spacing:-.01em;
  }
  .topbar-right{margin-left:auto;display:flex;align-items:center;gap:14px;}
  .topbar .search{
    position:relative;display:flex;align-items:center;
  }
  .topbar .search input{
    width:240px;
    border:1px solid var(--line);
    background:var(--paper);
    border-radius:9px;
    padding:8px 12px 8px 36px;
    font-size:13.5px;color:var(--ink);font-family:inherit;
  }
  .topbar .search .ic{position:absolute;left:11px;color:var(--ink-faint);}
  .topbar form{margin:0;}
  .logout-btn{
    border:1px solid var(--line);
    background:var(--paper-raised);
    color:var(--ink-soft);
    padding:8px 14px;border-radius:9px;font-size:13.5px;
    cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;
  }
  .logout-btn:hover{border-color:var(--brick);color:var(--brick);}

  .content{padding:26px 30px 80px;max-width:1080px;width:100%;}

  .page-head{margin-bottom:22px;}
  .page-head h1{font-family:'Fraunces', serif;font-weight:500;font-size:30px;margin:0 0 6px;letter-spacing:-.015em;}
  .page-head p{margin:0;color:var(--ink-soft);font-size:14.5px;max-width:680px;}

  /* Stats row — mirrors the dashboard cards in the screenshots */
  .stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:26px;
  }
  .stat{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:16px 18px;
    display:flex;align-items:center;gap:14px;
    box-shadow:var(--shadow);
  }
  .stat .glyph{
    width:42px;height:42px;border-radius:11px;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;
  }
  .stat .glyph.total{background:var(--info-bg);color:var(--info);}
  .stat .glyph.pending{background:var(--amber-bg);color:var(--amber);}
  .stat .glyph.verified{background:var(--sage-bg);color:var(--sage);}
  .stat .glyph.rejected{background:var(--brick-bg);color:var(--brick);}
  .stat .body .lbl{font-size:12.5px;color:var(--ink-soft);font-weight:500;}
  .stat .body .val{font-family:'Fraunces',serif;font-size:24px;font-weight:500;line-height:1.1;margin-top:2px;}
  .stat .body .val.skeleton{color:var(--ink-faint);}

  /* Filter tabs */
  .tabs{
    display:flex;gap:4px;margin-bottom:18px;
    border-bottom:1px solid var(--line);
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:11px 11px 0 0;
    padding:0 6px;
  }
  .tabs button{
    border:none;background:none;
    padding:12px 14px;margin-right:6px;
    font-size:14px;color:var(--ink-soft);cursor:pointer;font-family:inherit;
    position:relative;top:1px;
  }
  .tabs button .count{
    font-size:11.5px;color:var(--ink-faint);
    background:var(--paper);border:1px solid var(--line);
    border-radius:100px;padding:1px 7px;margin-left:6px;
  }
  .tabs button.active{
    color:var(--ink);font-weight:600;
    border-bottom:2px solid var(--teal);
  }
  .tabs button.active .count{background:var(--teal-deep);color:#fff;border-color:transparent;}

  #status-line{
    font-size:13px;color:var(--ink-soft);margin-bottom:14px;min-height:18px;
  }
  #status-line.err{color:var(--brick);}

  .feed{display:flex;flex-direction:column;gap:14px;}

  .card{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:18px 20px;
    box-shadow:var(--shadow);
    transition:box-shadow .15s ease;
  }
  .card-top{display:flex;align-items:flex-start;gap:14px;}
  .avatar{
    width:44px;height:44px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-family:'Fraunces', serif;
    font-weight:600;font-size:17px;color:#fff;
    flex-shrink:0;
  }
  .card-main{flex:1;min-width:0;}
  .name-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .org-name{
    font-family:'Fraunces', serif;
    font-size:19px;font-weight:500;color:var(--ink);
    background:none;border:none;padding:0;cursor:pointer;
    text-align:left;display:inline;
  }
  .org-name:hover{color:var(--teal-deep);text-decoration:underline;text-decoration-color:var(--line);}
  .pill{
    font-size:12px;padding:3px 10px;border-radius:100px;
    font-weight:500;white-space:nowrap;
  }
  .pill.pending{background:var(--amber-bg);color:var(--amber);}
  .pill.verified{background:var(--sage-bg);color:var(--sage);}
  .pill.rejected{background:var(--brick-bg);color:var(--brick);}
  .meta-line{margin:5px 0 0;font-size:14px;color:var(--ink-soft);}
  .chevron{
    color:var(--ink-faint);flex-shrink:0;
    transition:transform .15s ease;
  }
  .card[data-open="true"] .chevron{transform:rotate(90deg);}

  .detail{margin-top:16px;padding-top:16px;border-top:1px solid var(--line);display:none;}
  .detail.open{display:block;}
  .detail .desc{font-size:14.5px;color:var(--ink);margin:0 0 14px;}
  .detail .desc.empty{color:var(--ink-soft);font-style:italic;}
  .facts{
    display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;
    font-size:13.5px;margin-bottom:14px;
  }
  .facts div span{color:var(--ink-soft);}
  .proof-row{
    display:flex;align-items:center;justify-content:space-between;
    background:#FBFAF7;border:1px solid var(--line);border-radius:10px;
    padding:10px 14px;font-size:13.5px;margin-bottom:14px;
  }
  .proof-row a{color:var(--teal-deep);text-decoration:none;font-weight:500;display:inline-flex;align-items:center;gap:6px;}
  .proof-row a:hover{text-decoration:underline;}
  .proof-row.missing{
    background:var(--amber-bg);border-color:var(--amber);color:var(--amber);
    flex-direction:column;align-items:flex-start;gap:4px;
  }
  .proof-row.missing .hint{font-size:12.5px;color:var(--ink-soft);}
  .actions{display:flex;gap:10px;}
  .actions button{
    border:none;border-radius:9px;padding:9px 18px;
    font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;
    display:inline-flex;align-items:center;gap:7px;
  }
  .btn-approve{background:var(--teal);color:#fff;}
  .btn-approve:hover{background:var(--teal-deep);}
  .btn-reject{background:none;border:1px solid var(--brick);color:var(--brick);}
  .btn-reject:hover{background:var(--brick-bg);}
  .actions button:disabled{opacity:.5;cursor:default;}

  .empty-state{text-align:center;padding:60px 20px;color:var(--ink-soft);}
  .empty-state .big{font-family:'Fraunces',serif;font-size:20px;color:var(--ink);margin-bottom:6px;}

  /* Mobile: collapse the sidebar into a top bar */
  @media (max-width:880px){
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .stats{grid-template-columns:repeat(2,1fr);}
    .topbar .search input{width:140px;}
  }
  @media (max-width:520px){
    .facts{grid-template-columns:1fr;}
    .content{padding:18px 16px 60px;}
    .topbar{padding:12px 16px;}
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

    <div class="nav-section">Admin</div>
    <nav class="nav">
      <a class="nav-item" href="{{ route('admin.organizations') }}" title="Dashboard overview (coming soon)">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 13h6V5H4v8Zm0 6h6v-4H4v4Zm10 0h6v-8h-6v8Zm0-12v4h6V7h-6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Dashboard
      </a>
      <a class="nav-item active" href="{{ route('admin.organizations') }}" aria-current="page">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Organizations
        <span class="badge" id="nav-pending-badge" style="display:none">0</span>
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
      <a class="nav-item" href="{{ route('admin.support') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Support
      </a>
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M16 3a5 5 0 0 1 4 8.06V20l-3-2-3 2-3-2-3 2V11.06A5 5 0 0 1 8 3a5 5 0 0 1 8 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Users
      </a>
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 19V9m5 10V5m5 14v-7m5 7V7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </span>
        Reports
      </a>
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M19.4 15a1.6 1.6 0 0 0 .32 1.77l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-.97 1.46V21a2 2 0 0 1-4 0v-.09a1.6 1.6 0 0 0-1.05-1.46 1.6 1.6 0 0 0-1.77.32l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.6 1.6 0 0 0 4.6 15a1.6 1.6 0 0 0-1.46-.97H3a2 2 0 0 1 0-4h.09A1.6 1.6 0 0 0 4.55 8.5a1.6 1.6 0 0 0-.32-1.77l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.6 1.6 0 0 0 1.77.32h.07a1.6 1.6 0 0 0 .97-1.46V3a2 2 0 0 1 4 0v.09a1.6 1.6 0 0 0 .97 1.46 1.6 1.6 0 0 0 1.77-.32l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.6 1.6 0 0 0-.32 1.77v.07a1.6 1.6 0 0 0 1.46.97H21a2 2 0 0 1 0 4h-.09a1.6 1.6 0 0 0-1.51 1.04Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Settings
      </a>
      <a class="nav-item" href="{{ route('admin.profile') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </span>
        Profile
      </a>
    </nav>

    @include('admin.partials.user-card')
  </aside>

  <!-- ============================= Main ============================= -->
  <main class="main">
    <header class="topbar">
      <div class="crumb">
        <span>SkillSpan</span>
        <span class="sep">/</span>
        <span>Admin</span>
        <span class="sep">/</span>
        <span class="cur">Organizations</span>
      </div>

      <div class="topbar-right">
        <label class="search">
          <span class="ic">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <input type="search" id="org-search" placeholder="Search organizations…" aria-label="Search organizations">
        </label>

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
        <h1>Organization Requests</h1>
        <p>Review registrations from companies, universities and training partners before they join <strong>SkillSpan</strong>.</p>
      </header>

      <!-- Stats -->
      <div class="stats" id="stats">
        <div class="stat">
          <div class="glyph total">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 21V9l9-6 9 6v12M9 21v-6h6v6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Total</div>
            <div class="val skeleton" id="stat-total">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph pending">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Pending</div>
            <div class="val skeleton" id="stat-pending">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph verified">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M20 7L9 18l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Verified</div>
            <div class="val skeleton" id="stat-verified">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph rejected">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Rejected</div>
            <div class="val skeleton" id="stat-rejected">—</div>
          </div>
        </div>
      </div>

      <!-- Filter tabs -->
      <div class="tabs" id="tabs">
        <button data-status="" class="active">All <span class="count" id="count-all">—</span></button>
        <button data-status="pending">Pending <span class="count" id="count-pending">—</span></button>
        <button data-status="verified">Verified <span class="count" id="count-verified">—</span></button>
        <button data-status="rejected">Rejected <span class="count" id="count-rejected">—</span></button>
      </div>

      <p id="status-line"></p>

      <div class="feed" id="feed"></div>

    </div>
  </main>

</div>

<script>
// The page is protected by the admin session, so there is no token to paste:
// the browser sends the session cookie automatically, and we send the CSRF
// token along for any mutating request.
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

let currentStatus = '';
let searchQuery = '';
let allOrgs = [];        // every org loaded once — used for stats + client-side filtering
let cache = {};          // id -> full org detail once fetched

function setStatusLine(text, isError){
  const el = document.getElementById('status-line');
  el.textContent = text || '';
  el.className = isError ? 'err' : '';
}

function monogram(name){
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
}

function avatarColor(name){
  let hash = 0;
  for (const ch of name) hash = (hash * 31 + ch.charCodeAt(0)) % 360;
  return `hsl(${hash}, 32%, 40%)`;
}

function pillFor(status){
  const map = {
    pending:  ['pill pending',  'Pending'],
    verified: ['pill verified', 'Verified'],
    rejected: ['pill rejected', 'Rejected'],
  };
  const [cls, label] = map[status] || ['pill', status];
  return `<span class="${cls}">${label}</span>`;
}

async function api(path, options = {}) {
  const res = await fetch(path, {
    ...options,
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': CSRF_TOKEN,
      ...(options.headers || {}),
    },
  });

  // 401 = no session, 419 = session/CSRF token has gone stale.
  // Either way, send the user back to the sign-in page.
  if (res.status === 401 || res.status === 419) {
    window.location.href = '/admin/login';
    throw new Error('Your session has ended — please sign in again.');
  }

  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(body.message || `${res.status} ${res.statusText}`);
  }
  return body;
}

async function loadOrganizations(){
  const feed = document.getElementById('feed');
  setStatusLine('Loading organization requests…');
  try {
    // Pull every org once (per_page=100 keeps the full set in a single page for
    // the review queue) so the stats and the search both work client-side with
    // no extra round-trips when the filter changes.
    const body = await api('/admin/api/organizations?per_page=100');
    allOrgs = body.data?.data || [];

    updateStats(allOrgs);
    renderFeed(filterOrgs(allOrgs, currentStatus, searchQuery));
    setStatusLine(allOrgs.length ? '' : 'No organizations have registered yet.');
  } catch (e) {
    setStatusLine('Could not load organizations: ' + e.message, true);
    feed.innerHTML = '';
  }
}

function updateStats(orgs){
  const counts = { total: orgs.length, pending: 0, verified: 0, rejected: 0 };
  for (const o of orgs) {
    if (counts[o.verification_status] !== undefined) counts[o.verification_status]++;
  }
  const set = (id, val) => {
    const el = document.getElementById(id);
    el.textContent = val;
    el.classList.remove('skeleton');
  };
  set('stat-total', counts.total);
  set('stat-pending', counts.pending);
  set('stat-verified', counts.verified);
  set('stat-rejected', counts.rejected);
  set('count-all', counts.total);
  set('count-pending', counts.pending);
  set('count-verified', counts.verified);
  set('count-rejected', counts.rejected);

  const badge = document.getElementById('nav-pending-badge');
  if (counts.pending > 0) {
    badge.textContent = counts.pending;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

function filterOrgs(orgs, status, query){
  let list = orgs;
  if (status) list = list.filter(o => o.verification_status === status);
  if (query) {
    const q = query.toLowerCase();
    list = list.filter(o =>
      (o.name || '').toLowerCase().includes(q) ||
      (o.industry || '').toLowerCase().includes(q) ||
      (o.contact_email || '').toLowerCase().includes(q) ||
      (o.type || '').toLowerCase().includes(q)
    );
  }
  return list;
}

function renderFeed(orgs){
  const feed = document.getElementById('feed');
  if (!orgs.length) {
    feed.innerHTML = `<div class="empty-state"><div class="big">Nothing here yet</div>There are no organizations matching this filter right now.</div>`;
    return;
  }
  feed.innerHTML = orgs.map(org => cardTemplate(org)).join('');
}

function cardTemplate(org){
  return `
    <div class="card" id="card-${org.id}" data-open="false">
      <div class="card-top">
        <div class="avatar" style="background:${avatarColor(org.name)}">${monogram(org.name)}</div>
        <div class="card-main">
          <div class="name-row">
            <button class="org-name" onclick="toggleDetail(${org.id})">${escapeHtml(org.name)}</button>
            ${pillFor(org.verification_status)}
          </div>
          <p class="meta-line">${metaLine(org)}</p>
        </div>
        <span class="chevron">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
      </div>
      <div class="detail" id="detail-${org.id}"></div>
    </div>
  `;
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function metaLine(org){
  const bits = [];
  if (org.type) bits.push(org.type === 'company' ? 'Company' : capitalize(org.type));
  if (org.industry) bits.push(org.industry);
  const place = [org.city, org.country].filter(Boolean).join(', ');
  let line = bits.join(' — ');
  if (place) line += (line ? ' · ' : '') + place;
  return escapeHtml(line || 'No additional details yet.');
}

function capitalize(s){ return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

async function toggleDetail(id){
  const card = document.getElementById(`card-${id}`);
  const detailEl = document.getElementById(`detail-${id}`);
  const isOpen = detailEl.classList.contains('open');

  // Close any other open card — like a Facebook post, only one is open at a time.
  document.querySelectorAll('.detail.open').forEach(d => {
    d.classList.remove('open');
    const c = d.closest('.card');
    if (c) c.dataset.open = 'false';
    d.innerHTML = '';
  });

  if (isOpen) return;

  card.dataset.open = 'true';
  detailEl.classList.add('open');
  detailEl.innerHTML = '<p class="meta-line">Loading details…</p>';

  try {
    let org = cache[id];
    if (!org) {
      const body = await api(`/admin/api/organizations/${id}`);
      org = body.data;
      cache[id] = org;
    }
    detailEl.innerHTML = detailTemplate(org);
  } catch (e) {
    detailEl.innerHTML = `<p class="meta-line" style="color:var(--brick)">Could not load details: ${escapeHtml(e.message)}</p>`;
  }
}

function detailTemplate(org){
  // A description that is null, undefined, or only whitespace all mean the
  // same thing to a reviewer: nothing was written. Testing `org.description`
  // for truthiness alone let a whitespace-only value through and rendered an
  // empty paragraph — neither the description nor the "not provided" fallback,
  // which reads like a rendering glitch. Trimming first keeps the two real
  // cases (has a description / has none) distinguishable and exhaustive.
  const description = (org.description === null || org.description === undefined)
    ? ''
    : String(org.description).trim();

  const desc = description
    ? `<p class="desc">${escapeHtml(description)}</p>`
    : `<p class="desc empty">No description was provided for this organization.</p>`;

  // Three distinct cases, each of which must look different:
  //   1) no proof file was uploaded at all,
  //   2) there is a row in the database but the file itself is missing from disk
  //      (happened to us after every deploy),
  //   3) the file is present and downloadable.
  // Collapsing into two would make "missing" look like "none" and misstate the cause.
  const proof = !org.proof_file
    ? `<div class="proof-row"><span>No proof document has been uploaded.</span></div>`
    : (org.proof_file.available === false
        ? `<div class="proof-row missing">
             <span>The proof document is registered in the database, but the file is missing from the server.</span>
             <span class="hint">This happens when a file was uploaded before a redeploy without persistent storage.</span>
           </div>`
        : `<div class="proof-row">
             <span>Proof document — ${org.proof_file.status === 'pending' ? 'awaiting review' : escapeHtml(org.proof_file.status)}</span>
             <a href="${org.proof_file.download_url}" target="_blank" rel="noopener">
               Open file
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M7 17L17 7M17 7H8m9 0v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
             </a>
           </div>`);

  // Every organization detail the API returns is rendered here. The list
  // used to show only email / phone / website / company size, so industry,
  // country, city, address and postal code were fetched on every request and
  // then thrown away — an organization could submit a full address and the
  // reviewer had no way to see it before approving.
  //
  // Each row is omitted when its value is empty, so an organization that
  // genuinely left a field blank does not render an empty label. `fact()`
  // treats null, undefined and a whitespace-only string as "not provided".
  const fact = (label, value) => {
    const v = (value === null || value === undefined) ? '' : String(value).trim();
    return v ? `<div><span>${label}: </span><span>${escapeHtml(v)}</span></div>` : '';
  };

  const facts = `
    <div class="facts">
      ${fact('Email', org.contact_email)}
      ${fact('Phone', org.contact_phone)}
      ${fact('Website', org.website)}
      ${fact('Industry', org.industry)}
      ${fact('Company size', org.company_size)}
      ${fact('Country', org.country)}
      ${fact('City', org.city)}
      ${fact('Address', org.address)}
      ${fact('Postal code', org.postal_code)}
    </div>`;

  const actions = org.verification_status === 'pending'
    ? `<div class="actions">
         <button class="btn-approve" onclick="decide(${org.id}, 'approve')">
           <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M20 7L9 18l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
           Approve
         </button>
         <button class="btn-reject" onclick="decide(${org.id}, 'reject')">
           <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
           Reject
         </button>
       </div>`
    : '';

  return desc + facts + proof + actions;
}

async function decide(id, action){
  if (action === 'reject' && !confirm('Are you sure you want to reject this organization?')) return;

  let reason = null;
  if (action === 'reject') {
    reason = prompt('Rejection reason (optional):') || null;
  }

  try {
    await api(`/admin/api/organizations/${id}/${action}`, {
      method: 'POST',
      body: JSON.stringify(reason ? { reason } : {}),
    });
    delete cache[id];
    setStatusLine(action === 'approve' ? 'Organization approved.' : 'Organization rejected.');
    // Refresh the full list so stats and the active filter both reflect the change.
    loadOrganizations();
  } catch (e) {
    alert('Something went wrong: ' + e.message);
  }
}

document.getElementById('tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentStatus = btn.dataset.status;
  renderFeed(filterOrgs(allOrgs, currentStatus, searchQuery));
});

document.getElementById('org-search').addEventListener('input', (e) => {
  searchQuery = e.target.value.trim();
  renderFeed(filterOrgs(allOrgs, currentStatus, searchQuery));
});

loadOrganizations();
</script>
</body>
</html>
