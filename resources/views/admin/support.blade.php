<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Support</title>
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

  .app{display:flex;min-height:100vh;}

  /* ----------------------------------------------------------------
   * Sidebar — identical to the organizations / projects panels
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
  .nav-item .badge{
    margin-left:auto;font-size:11px;
    background:var(--accent-soft);color:var(--accent);
    padding:2px 7px;border-radius:100px;font-weight:600;
  }

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

  .content{padding:26px 30px 80px;max-width:1360px;width:100%;}

  .page-head{display:flex;align-items:flex-start;gap:20px;margin-bottom:22px;}
  .page-head .titles{flex:1;min-width:0;}
  .page-head h1{font-family:'Fraunces', serif;font-weight:500;font-size:30px;margin:0 0 6px;letter-spacing:-.015em;}
  .page-head p{margin:0;color:var(--ink-soft);font-size:14.5px;max-width:720px;}

  .btn-primary{
    background:var(--teal);color:#fff;border:none;
    border-radius:9px;padding:10px 18px;font-size:14px;font-weight:500;
    cursor:pointer;font-family:inherit;
    display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  }
  .btn-primary:hover{background:var(--teal-deep);}
  .btn-primary:disabled{opacity:.5;cursor:default;}

  /* Stats */
  .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px;}
  .stat{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);padding:14px 16px;
    display:flex;align-items:center;gap:12px;box-shadow:var(--shadow);
  }
  .stat .glyph{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .stat .glyph.total{background:var(--info-bg);color:var(--info);}
  .stat .glyph.pending{background:var(--amber-bg);color:var(--amber);}
  .stat .glyph.assigned{background:var(--violet-bg);color:var(--violet);}
  .stat .glyph.resolved{background:var(--sage-bg);color:var(--sage);}
  .stat .glyph.unread{background:var(--brick-bg);color:var(--brick);}
  .stat .body .lbl{font-size:12.5px;color:var(--ink-soft);font-weight:500;}
  .stat .body .val{font-family:'Fraunces',serif;font-size:22px;font-weight:500;line-height:1.1;margin-top:2px;}
  .stat .body .val.skeleton{color:var(--ink-faint);}

  /* Filter bar */
  .filters{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:11px;padding:14px 16px;margin-bottom:18px;
    display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;
  }
  .filters .field{display:flex;flex-direction:column;gap:5px;}
  .filters label{font-size:12px;color:var(--ink-soft);font-weight:500;}
  .filters input,.filters select{
    border:1px solid var(--line);background:var(--paper);
    border-radius:8px;padding:8px 11px;font-size:13.5px;
    color:var(--ink);font-family:inherit;min-width:150px;
  }
  .filters .grow{flex:1;min-width:220px;}
  .filters .grow input{width:100%;}
  .filters .btn-ghost{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    padding:8px 14px;border-radius:8px;font-size:13.5px;cursor:pointer;font-family:inherit;
  }
  .filters .btn-ghost:hover{border-color:var(--teal);color:var(--teal-deep);}

  #status-line{font-size:13px;color:var(--ink-soft);margin-bottom:12px;min-height:18px;}
  #status-line.err{color:var(--brick);}
  #status-line.ok{color:var(--sage);}

  /* ----------------------------------------------------------------
   * Two-pane inbox: queue on the left, thread on the right
   * ---------------------------------------------------------------- */
  .inbox{
    display:grid;grid-template-columns:380px 1fr;gap:18px;
    align-items:start;
  }
  .pane{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow);
    display:flex;flex-direction:column;overflow:hidden;
  }
  .pane.queue{max-height:calc(100vh - 240px);}
  .pane.thread{min-height:560px;max-height:calc(100vh - 240px);}

  .pane-head{
    padding:13px 16px;border-bottom:1px solid var(--line);
    display:flex;align-items:center;gap:10px;flex-shrink:0;
  }
  .pane-head .t{font-family:'Fraunces',serif;font-size:16px;font-weight:500;flex:1;min-width:0;}
  .pane-head .count{font-size:12px;color:var(--ink-faint);}

  .queue-list{overflow-y:auto;flex:1;}

  .q-item{
    padding:13px 16px;border-bottom:1px solid var(--line-soft);
    cursor:pointer;display:flex;gap:11px;align-items:flex-start;
    transition:background .1s ease;
  }
  .q-item:last-child{border-bottom:none;}
  .q-item:hover{background:#FCFCFA;}
  .q-item.sel{background:var(--info-bg);}
  .q-item .dot{
    width:8px;height:8px;border-radius:50%;background:var(--brick);
    margin-top:7px;flex-shrink:0;
  }
  .q-item .dot.hidden{visibility:hidden;}
  .q-item .body{min-width:0;flex:1;}
  .q-item .who{font-size:13.5px;font-weight:500;display:flex;align-items:center;gap:7px;}
  .q-item .who .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .q-item .subj{
    font-size:12.5px;color:var(--ink-soft);margin-top:3px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  }
  .q-item .meta{display:flex;align-items:center;gap:8px;margin-top:7px;font-size:11.5px;color:var(--ink-faint);}
  .q-item .meta .when{margin-left:auto;white-space:nowrap;}

  .pill{font-size:11px;padding:2px 9px;border-radius:100px;font-weight:500;white-space:nowrap;display:inline-block;}
  .pill.pending{background:var(--amber-bg);color:var(--amber);}
  .pill.assigned{background:var(--violet-bg);color:var(--violet);}
  .pill.resolved{background:var(--sage-bg);color:var(--sage);}
  .pill.closed{background:#ECEAE4;color:var(--ink-soft);}
  .pill.reason{background:var(--paper);border:1px solid var(--line);color:var(--ink-soft);}

  /* Thread */
  .thread-head{
    padding:14px 18px;border-bottom:1px solid var(--line);flex-shrink:0;
    display:flex;align-items:flex-start;gap:14px;
  }
  .thread-head .who{flex:1;min-width:0;}
  .thread-head .nm{font-family:'Fraunces',serif;font-size:18px;font-weight:500;}
  .thread-head .em{font-size:12.5px;color:var(--ink-faint);overflow:hidden;text-overflow:ellipsis;}
  .thread-head .subj{font-size:13.5px;color:var(--ink-soft);margin-top:7px;max-width:640px;}
  .thread-head .acts{display:flex;gap:8px;flex-shrink:0;}

  .row-btn{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    border-radius:8px;padding:6px 12px;font-size:12.5px;cursor:pointer;font-family:inherit;
    white-space:nowrap;
  }
  .row-btn:hover:not(:disabled){border-color:var(--teal);color:var(--teal-deep);}
  .row-btn:disabled{opacity:.45;cursor:default;}

  .thread-scroll{overflow-y:auto;flex:1;padding:18px;background:#FBFBF9;}

  .snapshot-note{
    font-size:11.5px;color:var(--ink-faint);text-align:center;
    margin:0 0 14px;padding:7px 12px;border-radius:8px;
    background:var(--paper);border:1px dashed var(--line);
  }

  .turn{display:flex;margin-bottom:12px;gap:9px;align-items:flex-end;}
  .turn.out{flex-direction:row-reverse;}
  .turn .av{
    width:28px;height:28px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:600;color:#fff;
  }
  .turn .av.learner{background:linear-gradient(135deg,#7C8AA8,#4A5878);}
  .turn .av.assistant{background:linear-gradient(135deg,#5EEAD4,#2F6F5E);}
  .turn .av.support{background:linear-gradient(135deg,#8C7BE0,#5B4B9E);}
  .turn .bub{max-width:74%;}
  .turn .bub .txt{
    padding:10px 13px;border-radius:13px;font-size:13.5px;
    white-space:pre-wrap;word-break:break-word;
    background:var(--paper-raised);border:1px solid var(--line);
  }
  .turn.out .bub .txt{background:var(--teal);border-color:var(--teal);color:#fff;}
  .turn .bub .who{
    font-size:11px;color:var(--ink-faint);margin-bottom:4px;
  }
  .turn.out .bub .who{text-align:right;}
  .turn .bub .at{font-size:10.5px;color:var(--ink-faint);margin-top:4px;}
  .turn.out .bub .at{text-align:right;}

  .turn.system{justify-content:center;margin:16px 0;}
  .turn.system .bub{max-width:80%;}
  .turn.system .bub .txt{
    background:var(--amber-bg);border:1px solid #E8D6B4;color:var(--amber);
    font-size:12.5px;text-align:center;border-radius:100px;
  }

  .day-sep{
    text-align:center;font-size:11px;color:var(--ink-faint);
    margin:18px 0 14px;position:relative;
  }
  .day-sep::before,.day-sep::after{
    content:'';position:absolute;top:50%;width:34%;height:1px;background:var(--line);
  }
  .day-sep::before{left:0;}
  .day-sep::after{right:0;}

  .composer{
    border-top:1px solid var(--line);padding:13px 16px;flex-shrink:0;
    display:flex;gap:10px;align-items:flex-end;background:var(--paper-raised);
  }
  .composer textarea{
    flex:1;border:1px solid var(--line);background:var(--paper);
    border-radius:10px;padding:10px 12px;font-size:13.5px;
    font-family:inherit;color:var(--ink);resize:none;min-height:44px;max-height:140px;
  }
  .composer textarea:disabled{background:#F1F0EC;color:var(--ink-faint);}

  .empty-state{text-align:center;padding:64px 24px;color:var(--ink-soft);}
  .empty-state .big{font-family:'Fraunces',serif;font-size:20px;color:var(--ink);margin-bottom:6px;}
  .empty-state .hint{font-size:13.5px;max-width:420px;margin:0 auto;}

  /* Toast */
  #toasts{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:10px;z-index:80;}
  .toast{
    background:#12203C;color:#F2F5FA;border-radius:10px;
    padding:12px 16px;font-size:13.5px;max-width:380px;
    box-shadow:0 10px 30px rgba(15,24,48,.3);
  }
  .toast.err{background:var(--brick);}
  .toast.ok{background:var(--teal-deep);}

  @media (max-width:1180px){
    .stats{grid-template-columns:repeat(3,1fr);}
  }
  @media (max-width:980px){
    .inbox{grid-template-columns:1fr;}
    .pane.queue{max-height:340px;}
    .pane.thread{max-height:none;}
    .stats{grid-template-columns:repeat(2,1fr);}
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .content{padding:20px 16px 60px;}
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

    @php
      // The inbox is shared by administrators and mentors, but the rest of the
      // panel is not: every other page sits behind the `admin` middleware. A
      // mentor is shown only what they can actually open, so the sidebar never
      // offers a link that answers 403.
      $panelIsAdmin = auth()->user()->hasRole('admin');
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
      @endif
      <a class="nav-item active" href="{{ route('admin.support') }}" aria-current="page">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Support
        <span class="badge" id="nav-unread-badge" style="display:none">0</span>
      </a>
      @if ($panelIsAdmin)
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M16 3a5 5 0 0 1 4 8.06V20l-3-2-3 2-3-2-3 2V11.06A5 5 0 0 1 8 3a5 5 0 0 1 8 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Users
      </a>
      @endif
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
        <span class="cur">Support</span>
      </div>

      <div class="topbar-right">
        <button class="logout-btn" id="btn-refresh" title="Reload the queue">
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
          <h1>Support</h1>
          <p>Learners who asked the assistant for help, and the conversations that followed. Each request carries the AI chat that failed to answer, so the person taking it over starts with the full picture.</p>
        </div>
      </header>

      <!-- Stats -->
      <div class="stats">
        <div class="stat">
          <div class="glyph total">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </div>
          <div class="body"><div class="lbl">Total</div><div class="val skeleton" id="stat-total">—</div></div>
        </div>
        <div class="stat">
          <div class="glyph pending">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
          <div class="body"><div class="lbl">Unclaimed</div><div class="val skeleton" id="stat-pending">—</div></div>
        </div>
        <div class="stat">
          <div class="glyph assigned">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M4 21a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
          <div class="body"><div class="lbl">In progress</div><div class="val skeleton" id="stat-assigned">—</div></div>
        </div>
        <div class="stat">
          <div class="glyph resolved">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="m8 12 3 3 5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div class="body"><div class="lbl">Resolved</div><div class="val skeleton" id="stat-resolved">—</div></div>
        </div>
        <div class="stat">
          <div class="glyph unread">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v12H4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </div>
          <div class="body"><div class="lbl">Unread</div><div class="val skeleton" id="stat-unread">—</div></div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters">
        <div class="field grow">
          <label for="f-q">Search</label>
          <input type="search" id="f-q" placeholder="Learner name, email, subject or request id…">
        </div>
        <div class="field">
          <label for="f-scope">Show</label>
          <select id="f-scope">
            <option value="open">Open only</option>
            <option value="">Everything</option>
          </select>
        </div>
        <div class="field">
          <label for="f-status">Status</label>
          <select id="f-status">
            <option value="">Any status</option>
            <option value="pending">Unclaimed</option>
            <option value="assigned">In progress</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
          </select>
        </div>
        <div class="field">
          <label for="f-reason">Reason</label>
          <select id="f-reason">
            <option value="">Any reason</option>
            <option value="insufficient_context">Assistant had no answer</option>
            <option value="learner_requested">Learner asked for a person</option>
          </select>
        </div>
        <button class="btn-ghost" id="btn-clear">Clear</button>
      </div>

      <div id="status-line"></div>

      <!-- Two panes -->
      <div class="inbox">
        <section class="pane queue">
          <div class="pane-head">
            <div class="t">Queue</div>
            <div class="count" id="queue-count"></div>
          </div>
          <div class="queue-list" id="queue"></div>
        </section>

        <section class="pane thread" id="thread-pane">
          <div class="empty-state" id="thread-empty" style="margin:auto">
            <div class="big">No conversation selected</div>
            <div class="hint">Pick a request from the queue to read the AI chat that led to it and reply to the learner.</div>
          </div>

          <div id="thread-body" style="display:none;flex-direction:column;flex:1;min-height:0;">
            <div class="thread-head">
              <div class="who">
                <div class="nm" id="t-name">—</div>
                <div class="em" id="t-email"></div>
                <div class="subj" id="t-subject"></div>
              </div>
              <div class="acts">
                <button class="row-btn" id="btn-claim">Claim</button>
                <button class="row-btn" id="btn-resolve">Mark resolved</button>
              </div>
            </div>

            <div class="thread-scroll" id="thread-scroll"></div>

            <div class="composer">
              <textarea id="reply-body" rows="1" placeholder="Write a reply to the learner…"></textarea>
              <button class="btn-primary" id="btn-send">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 12l16-8-6 8 6 8-16-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                Send
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </main>
</div>

<div id="toasts"></div>

<script>
// Session-authenticated panel: the cookie rides along automatically and the
// CSRF token is sent for every mutating request.

// ── Labels, mirrored from App\Models\SupportRequest ──────────────────────
// LABELS ONLY. The backend owns every state change; the UI just decides which
// buttons to offer so an operator is not shown an action that would be refused.
const STATUS_LABELS = {
  pending: 'Unclaimed',
  assigned: 'In progress',
  resolved: 'Resolved',
  closed: 'Closed',
};

const REASON_LABELS = {
  insufficient_context: 'Assistant had no answer',
  learner_requested: 'Learner asked for a person',
};

let state = {
  filters: { q: '', scope: 'open', status: '', reason: '' },
  selectedId: null,
  detail: null,
  viewer: null,
  poll: null,
};

// ── Small helpers ─────────────────────────────────────────────────────────
function setStatusLine(text, kind){
  const el = document.getElementById('status-line');
  el.textContent = text || '';
  el.className = kind || '';
}

function toast(message, kind){
  const box = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = 'toast' + (kind ? ' ' + kind : '');
  el.textContent = message;
  box.appendChild(el);
  setTimeout(() => el.remove(), 4200);
}

function escapeHtml(value){
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// CSRF token is sent for every mutating request. Held in a variable so a stale
// token can be refreshed in place instead of reloading the page.
let CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

async function refreshCsrfToken(){
  try {
    const res = await fetch(window.location.pathname, {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!res.ok) return false;

    const html = await res.text();
    // Attribute order is not guaranteed and the value may be HTML-escaped, so
    // scan for the meta tag itself rather than assuming `name` precedes
    // `content`.
    const meta = html.match(/<meta[^>]*name="csrf-token"[^>]*>/i);

    if (meta){
      const content = meta[0].match(/content="([^"]*)"/i);

      if (content && content[1]){
        CSRF_TOKEN = content[1]
          .replace(/&quot;/g, '"')
          .replace(/&#039;/g, "'")
          .replace(/&amp;/g, '&');

        document.querySelector('meta[name="csrf-token"]').setAttribute('content', CSRF_TOKEN);
        return true;
      }
    }

    return false;
  } catch (e){
    return false;
  }
}

async function api(path, options = {}, isRetry = false){
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

  // 419 = the CSRF token is stale, NOT that the session ended. Retry ONCE with
  // a freshly fetched token before concluding anything.
  if (res.status === 419 && !isRetry){
    if (await refreshCsrfToken()){
      return api(path, options, true);
    }
  }

  if (res.status === 401){
    window.location.href = '/admin/login';
    throw new Error('Your session has ended — please sign in again.');
  }

  if (res.status === 419){
    const err = new Error('Your session token could not be verified. Please sign in again.');
    err.status = 419;
    throw err;
  }

  const body = await res.json().catch(() => ({}));
  if (!res.ok){
    const err = new Error(body.message || `${res.status} ${res.statusText}`);
    err.status = res.status;
    err.code = body.code;
    err.errors = body.errors || null;
    throw err;
  }
  return body;
}

function statusPill(status){
  const label = STATUS_LABELS[status] || status;
  const cls = STATUS_LABELS[status] ? status : 'closed';
  return `<span class="pill ${cls}">${escapeHtml(label)}</span>`;
}

function reasonPill(reason){
  return `<span class="pill reason">${escapeHtml(REASON_LABELS[reason] || reason)}</span>`;
}

function initials(name){
  const parts = String(name || '?').trim().split(/\s+/);
  return (parts[0]?.[0] || '?').toUpperCase() + (parts[1]?.[0] || '').toUpperCase();
}

/**
 * A short, human "when".
 *
 * Deliberately relative: an operator scanning a queue cares about "3m ago",
 * not the exact timestamp. The full value is kept in the title attribute.
 */
function fmtWhen(value){
  if (!value) return '—';
  const then = new Date(value);
  if (isNaN(then.getTime())) return '—';

  const secs = Math.floor((Date.now() - then.getTime()) / 1000);

  if (secs < 60) return 'just now';
  if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
  if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
  if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';

  return then.toISOString().slice(0, 10);
}

function fmtTime(value){
  if (!value) return '';
  const d = new Date(value);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function fmtDay(value){
  if (!value) return '';
  const d = new Date(value);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
}

// ── Load the queue ────────────────────────────────────────────────────────
async function loadQueue(){
  const list = document.getElementById('queue');
  setStatusLine('Loading queue…');

  const params = new URLSearchParams();
  params.set('per_page', '50');
  for (const [key, value] of Object.entries(state.filters)){
    if (value !== '' && value !== null) params.set(key, value);
  }

  try {
    const body = await api('/admin/api/support?' + params.toString());
    const rows = body.data?.data || [];
    const meta = body.data?.meta || {};

    state.viewer = body.viewer || null;
    applyViewer();

    renderQueue(rows);
    updateStats(body.stats || null);

    document.getElementById('queue-count').textContent =
      meta.total ? `${meta.total} request${meta.total === 1 ? '' : 's'}` : '';

    if (!rows.length){
      setStatusLine(hasFilters() ? 'No requests match these filters.' : 'No support requests yet.');
    } else {
      setStatusLine('');
    }
  } catch (e){
    setStatusLine('Could not load the queue: ' + e.message, 'err');
    list.innerHTML = `<div class="empty-state"><div class="big">Could not load</div><div class="hint">${escapeHtml(e.message)}</div></div>`;
  }
}

function hasFilters(){
  return Object.entries(state.filters).some(([k, v]) => k !== 'scope' && v !== '' && v !== null);
}

/**
 * Adjust the page to who is looking at it.
 *
 * A mentor only ever receives their own assignments, so "claim" is meaningless
 * for them — the button is hidden rather than offered and then refused.
 */
function applyViewer(){
  const isAdmin = !!state.viewer?.is_admin;
  document.getElementById('btn-claim').style.display = isAdmin ? '' : 'none';
}

function renderQueue(rows){
  const list = document.getElementById('queue');

  if (!rows.length){
    list.innerHTML = `<div class="empty-state" style="padding:40px 20px">
      <div class="big">Nothing here</div>
      <div class="hint">${hasFilters() ? 'Try clearing the filters.' : 'Requests appear here when a learner asks for a person.'}</div>
    </div>`;
    return;
  }

  list.innerHTML = rows.map(r => {
    const unread = !!r.has_unread;
    const sel = state.selectedId === r.id ? ' sel' : '';
    const when = r.last_message_at || r.created_at;

    return `<div class="q-item${sel}" data-id="${r.id}">
      <span class="dot${unread ? '' : ' hidden'}"></span>
      <div class="body">
        <div class="who">
          <span class="nm">${escapeHtml(r.user_name || 'Learner')}</span>
          ${statusPill(r.status)}
        </div>
        <div class="subj">${escapeHtml(r.subject || 'No subject')}</div>
        <div class="meta">
          ${reasonPill(r.reason)}
          ${r.assignee_name ? `<span>· ${escapeHtml(r.assignee_name)}</span>` : ''}
          <span class="when" title="${escapeHtml(when || '')}">${escapeHtml(fmtWhen(when))}</span>
        </div>
      </div>
    </div>`;
  }).join('');

  list.querySelectorAll('.q-item').forEach(el => {
    el.addEventListener('click', () => openRequest(parseInt(el.dataset.id, 10)));
  });
}

function updateStats(stats){
  if (!stats) return;

  const set = (id, value) => {
    const el = document.getElementById(id);
    el.textContent = value;
    el.classList.remove('skeleton');
  };

  set('stat-total', stats.total ?? 0);
  set('stat-pending', stats.pending ?? 0);
  set('stat-assigned', stats.assigned ?? 0);
  set('stat-resolved', stats.resolved ?? 0);
  set('stat-unread', stats.unread ?? 0);

  const badge = document.getElementById('nav-unread-badge');
  if (stats.unread > 0){
    badge.textContent = stats.unread;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

// ── Open one request ──────────────────────────────────────────────────────
async function openRequest(id){
  state.selectedId = id;

  // Reflect the selection immediately — waiting for the response to highlight
  // the row makes the click feel lost on a slow connection.
  document.querySelectorAll('.q-item').forEach(el => {
    el.classList.toggle('sel', parseInt(el.dataset.id, 10) === id);
  });

  try {
    const body = await api('/admin/api/support/' + id);
    state.detail = body.data;

    renderThread(state.detail);

    // The server marked the learner's messages read on open, so refresh the
    // queue counters once the thread is on screen.
    loadQueueCounters();
  } catch (e){
    toast('Could not open the request: ' + e.message, 'err');
  }
}

/** Cheap refresh of the queue + cards without disturbing the open thread. */
async function loadQueueCounters(){
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(state.filters)){
    if (value !== '' && value !== null) params.set(key, value);
  }

  try {
    const body = await api('/admin/api/support?per_page=50&' + params.toString());
    updateStats(body.stats || null);
    renderQueue(body.data?.data || []);
  } catch (e){
    // Non-fatal: the thread is still usable, and the next poll will retry.
  }
}

function renderThread(d){
  document.getElementById('thread-empty').style.display = 'none';

  const wrap = document.getElementById('thread-body');
  wrap.style.display = 'flex';

  document.getElementById('t-name').textContent = d.user_name || 'Learner';
  document.getElementById('t-email').textContent = d.user_email || '';
  document.getElementById('t-subject').innerHTML =
    `${escapeHtml(d.subject || 'No subject')} &nbsp; ${statusPill(d.status)} ${reasonPill(d.reason)}`;

  const closed = d.status === 'resolved' || d.status === 'closed';

  document.getElementById('btn-claim').disabled = d.assigned_to !== null;
  document.getElementById('btn-resolve').disabled = closed;

  const ta = document.getElementById('reply-body');
  const send = document.getElementById('btn-send');
  ta.disabled = closed;
  send.disabled = closed;
  ta.placeholder = closed
    ? 'This request is closed.'
    : 'Write a reply to the learner…';

  document.getElementById('thread-scroll').innerHTML = buildThread(d);

  // Jump to the newest message, which is what an operator opens a thread for.
  const scroll = document.getElementById('thread-scroll');
  scroll.scrollTop = scroll.scrollHeight;
}

/**
 * Build the thread body: the handoff snapshot first, then the live replies.
 *
 * The two are rendered as one continuous conversation on purpose — an operator
 * reading "what did the learner already say" should not have to hold two
 * separate lists in their head. A separator marks where the AI chat ended and
 * the human thread began.
 */
function buildThread(d){
  const parts = [];
  const snapshot = Array.isArray(d.transcript) ? d.transcript : [];

  if (snapshot.length){
    parts.push(`<div class="snapshot-note">
      The AI conversation that led to this request — captured at the moment the learner asked for a person.
    </div>`);

    snapshot.forEach(turn => {
      const isAssistant = turn.role === 'assistant';
      parts.push(`<div class="turn${isAssistant ? '' : ' out'}">
        <div class="av ${isAssistant ? 'assistant' : 'learner'}">${isAssistant ? 'AI' : 'L'}</div>
        <div class="bub">
          <div class="who">${isAssistant ? 'Assistant' : escapeHtml(d.user_name || 'Learner')}</div>
          <div class="txt">${escapeHtml(turn.body)}</div>
        </div>
      </div>`);
    });
  }

  const messages = Array.isArray(d.messages) ? d.messages : [];

  if (messages.length){
    parts.push(`<div class="day-sep">Human support</div>`);

    let lastDay = null;

    messages.forEach(m => {
      const day = fmtDay(m.created_at);

      if (day && day !== lastDay){
        parts.push(`<div class="day-sep">${escapeHtml(day)}</div>`);
        lastDay = day;
      }

      // The handoff marker is a system event, not a person speaking.
      if (m.message_type === 'system'){
        parts.push(`<div class="turn system">
          <div class="bub"><div class="txt">${escapeHtml(m.body)}</div></div>
        </div>`);
        return;
      }

      // `sender_id` is what distinguishes the sides. The learner's messages sit
      // on the right, support's on the left — the same orientation the learner
      // sees, so a screenshot of either view reads the same way.
      const fromLearner = m.sender_id === d.user_id;

      parts.push(`<div class="turn${fromLearner ? ' out' : ''}">
        <div class="av ${fromLearner ? 'learner' : 'support'}">${escapeHtml(initials(m.sender_name || (fromLearner ? d.user_name : 'S')))}</div>
        <div class="bub">
          <div class="who">${escapeHtml(m.sender_name || (fromLearner ? 'Learner' : 'Support'))}</div>
          <div class="txt">${escapeHtml(m.body)}</div>
          <div class="at">${escapeHtml(fmtTime(m.created_at))}</div>
        </div>
      </div>`);
    });
  }

  if (!parts.length){
    return `<div class="empty-state"><div class="big">Nothing to show</div>
      <div class="hint">This request has no transcript and no replies yet.</div></div>`;
  }

  return parts.join('');
}

// ── Actions ───────────────────────────────────────────────────────────────
async function sendReply(){
  const ta = document.getElementById('reply-body');
  const body = ta.value.trim();

  if (!body || state.selectedId === null) return;

  const btn = document.getElementById('btn-send');
  btn.disabled = true;

  try {
    await api(`/admin/api/support/${state.selectedId}/messages`, {
      method: 'POST',
      body: JSON.stringify({ body }),
    });

    ta.value = '';
    toast('Reply sent.', 'ok');

    await openRequest(state.selectedId);
  } catch (e){
    toast('Could not send: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
  }
}

async function claim(){
  if (state.selectedId === null) return;

  try {
    await api(`/admin/api/support/${state.selectedId}/assign`, { method: 'POST', body: '{}' });
    toast('Claimed.', 'ok');
    await openRequest(state.selectedId);
  } catch (e){
    toast('Could not claim: ' + e.message, 'err');
  }
}

async function resolve(){
  if (state.selectedId === null) return;

  try {
    await api(`/admin/api/support/${state.selectedId}/resolve`, { method: 'POST', body: '{}' });
    toast('Marked as resolved.', 'ok');
    await openRequest(state.selectedId);
  } catch (e){
    toast('Could not resolve: ' + e.message, 'err');
  }
}

// ── Wiring ────────────────────────────────────────────────────────────────
function readFilters(){
  state.filters.q = document.getElementById('f-q').value.trim();
  state.filters.scope = document.getElementById('f-scope').value;
  state.filters.status = document.getElementById('f-status').value;
  state.filters.reason = document.getElementById('f-reason').value;
}

let searchTimer = null;

document.getElementById('f-q').addEventListener('input', () => {
  clearTimeout(searchTimer);
  // Debounced: typing a name should not fire one request per keystroke.
  searchTimer = setTimeout(() => { readFilters(); loadQueue(); }, 320);
});

['f-scope', 'f-status', 'f-reason'].forEach(id => {
  document.getElementById(id).addEventListener('change', () => {
    readFilters();
    loadQueue();
  });
});

document.getElementById('btn-clear').addEventListener('click', () => {
  document.getElementById('f-q').value = '';
  document.getElementById('f-scope').value = 'open';
  document.getElementById('f-status').value = '';
  document.getElementById('f-reason').value = '';
  readFilters();
  loadQueue();
});

document.getElementById('btn-refresh').addEventListener('click', () => {
  readFilters();
  loadQueue();
  if (state.selectedId !== null) openRequest(state.selectedId);
});

document.getElementById('btn-send').addEventListener('click', sendReply);
document.getElementById('btn-claim').addEventListener('click', claim);
document.getElementById('btn-resolve').addEventListener('click', resolve);

// Enter sends, Shift+Enter adds a line — the convention for a chat composer.
document.getElementById('reply-body').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey){
    e.preventDefault();
    sendReply();
  }
});

// Grow the composer with its content, up to the CSS max-height.
document.getElementById('reply-body').addEventListener('input', (e) => {
  e.target.style.height = 'auto';
  e.target.style.height = Math.min(e.target.scrollHeight, 140) + 'px';
});

/**
 * Poll for new messages while a thread is open.
 *
 * A learner can reply at any time, and an operator should not have to reload
 * to notice. 20s is slow enough not to hammer the server and fast enough that
 * a live conversation still feels live.
 */
function startPolling(){
  if (state.poll) clearInterval(state.poll);

  state.poll = setInterval(() => {
    // Skip while the tab is hidden — nobody is reading it.
    if (document.hidden) return;

    loadQueueCounters();

    if (state.selectedId !== null && !document.getElementById('reply-body').disabled){
      // Do not clobber a reply in progress.
      if (document.getElementById('reply-body').value.trim() === ''){
        openRequest(state.selectedId);
      }
    }
  }, 20000);
}

readFilters();
loadQueue();
startPolling();
</script>
</body>
</html>
