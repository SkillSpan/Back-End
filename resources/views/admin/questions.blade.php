<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Questions</title>
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
   * Sidebar — identical to the rest of the panel
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
  .topbar .search{position:relative;display:flex;align-items:center;}
  .topbar .search input{
    width:260px;border:1px solid var(--line);background:var(--paper);
    border-radius:9px;padding:8px 12px 8px 36px;
    font-size:13.5px;color:var(--ink);font-family:inherit;
  }
  .topbar .search .ic{position:absolute;left:11px;color:var(--ink-faint);}
  .topbar form{margin:0;}
  .logout-btn{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    padding:8px 14px;border-radius:9px;font-size:13.5px;
    cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;
  }
  .logout-btn:hover{border-color:var(--brick);color:var(--brick);}

  .content{padding:26px 30px 80px;max-width:1240px;width:100%;}

  .page-head{display:flex;align-items:flex-start;gap:20px;margin-bottom:22px;}
  .page-head .titles{flex:1;min-width:0;}
  .page-head h1{font-family:'Fraunces', serif;font-weight:500;font-size:30px;margin:0 0 6px;letter-spacing:-.015em;}
  .page-head p{margin:0;color:var(--ink-soft);font-size:14.5px;max-width:680px;}

  .btn-primary{
    background:var(--teal);color:#fff;border:none;
    border-radius:9px;padding:10px 18px;font-size:14px;font-weight:500;
    cursor:pointer;font-family:inherit;
    display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  }
  .btn-primary:hover{background:var(--teal-deep);}
  .btn-primary:disabled{opacity:.5;cursor:default;}

  /* Filter bar */
  .filters{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:11px;padding:14px 16px;margin-bottom:14px;
    display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;
  }
  .filters .field{display:flex;flex-direction:column;gap:5px;}
  .filters label{font-size:12px;color:var(--ink-soft);font-weight:500;}
  .filters input,.filters select{
    border:1px solid var(--line);background:var(--paper);
    border-radius:8px;padding:8px 11px;font-size:13.5px;
    color:var(--ink);font-family:inherit;min-width:190px;
  }
  .filters .btn-ghost{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    padding:8px 14px;border-radius:8px;font-size:13.5px;cursor:pointer;font-family:inherit;
  }
  .filters .btn-ghost:hover{border-color:var(--teal);color:var(--teal-deep);}

  /* The selected chain, rendered as a breadcrumb of pills */
  .chain{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    font-size:13px;color:var(--ink-soft);margin:0 0 16px;min-height:26px;
  }
  .chain .node{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:100px;padding:3px 12px;font-size:12.5px;color:var(--ink);
  }
  .chain .node.empty{color:var(--ink-faint);border-style:dashed;}
  .chain .arrow{color:var(--ink-faint);}

  #status-line{font-size:13px;color:var(--ink-soft);margin-bottom:12px;min-height:18px;}
  #status-line.err{color:var(--brick);}
  #status-line.ok{color:var(--sage);}

  /* Table */
  .table-wrap{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
  }
  .table-scroll{overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:900px;}
  thead th{
    text-align:left;font-weight:600;font-size:12px;
    letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);
    padding:12px 14px;border-bottom:1px solid var(--line);
    background:#FBFAF7;white-space:nowrap;
  }
  tbody td{padding:13px 14px;border-bottom:1px solid var(--line-soft);vertical-align:top;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:#FCFCFA;}
  td.title-cell{max-width:460px;}
  td.title-cell .q{font-weight:500;}
  td.title-cell .sub{display:block;font-weight:400;color:var(--ink-faint);font-size:12.5px;margin-top:3px;}
  td.mono{font-variant-numeric:tabular-nums;color:var(--ink-soft);white-space:nowrap;}
  td.actions-cell{white-space:nowrap;text-align:right;}

  .pill{font-size:11.5px;padding:3px 10px;border-radius:100px;font-weight:500;white-space:nowrap;display:inline-block;}
  .pill.single_choice{background:var(--info-bg);color:var(--info);}
  .pill.text{background:var(--violet-bg);color:var(--violet);}
  .pill.in-use{background:var(--amber-bg);color:var(--amber);}
  .pill.unused{background:#ECEAE4;color:var(--ink-soft);}

  .row-btn{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    border-radius:8px;padding:5px 11px;font-size:12.5px;cursor:pointer;font-family:inherit;
    margin-left:6px;
  }
  .row-btn:hover{border-color:var(--teal);color:var(--teal-deep);}
  .row-btn.danger:hover{border-color:var(--brick);color:var(--brick);}

  /* Pagination */
  .pager{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-top:1px solid var(--line);flex-wrap:wrap;}
  .pager .info{font-size:13px;color:var(--ink-soft);}
  .pager .pages{display:flex;gap:6px;align-items:center;}
  .pager button{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    border-radius:8px;min-width:34px;height:34px;font-size:13px;cursor:pointer;font-family:inherit;padding:0 10px;
  }
  .pager button:hover:not(:disabled){border-color:var(--teal);color:var(--teal-deep);}
  .pager button:disabled{opacity:.45;cursor:default;}
  .pager button.cur{background:var(--teal);border-color:var(--teal);color:#fff;}

  .empty-state{text-align:center;padding:56px 20px;color:var(--ink-soft);}
  .empty-state .big{font-family:'Fraunces',serif;font-size:20px;color:var(--ink);margin-bottom:6px;}
  .empty-state .hint{font-size:13.5px;}

  /* Modal */
  .overlay{
    position:fixed;inset:0;background:rgba(14,24,48,.45);
    display:none;align-items:flex-start;justify-content:center;
    padding:40px 20px;overflow-y:auto;z-index:50;
  }
  .overlay.open{display:flex;}
  .modal{
    background:var(--paper-raised);border-radius:var(--radius);
    width:100%;max-width:720px;box-shadow:0 20px 60px rgba(15,24,48,.25);
  }
  .modal.narrow{max-width:460px;}
  .modal-head{
    display:flex;align-items:center;gap:12px;
    padding:18px 22px;border-bottom:1px solid var(--line);
  }
  .modal-head h2{font-family:'Fraunces',serif;font-weight:500;font-size:20px;margin:0;flex:1;}
  .modal-head .close{
    border:none;background:none;color:var(--ink-faint);cursor:pointer;font-size:22px;
    line-height:1;padding:4px 8px;border-radius:6px;
  }
  .modal-head .close:hover{background:var(--paper);color:var(--ink);}
  .modal-body{padding:20px 22px;max-height:64vh;overflow-y:auto;}
  .modal-foot{
    display:flex;gap:10px;justify-content:flex-end;
    padding:16px 22px;border-top:1px solid var(--line);
  }
  .modal-foot .spacer{flex:1;}

  .btn-secondary{
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    border-radius:9px;padding:9px 18px;font-size:14px;cursor:pointer;font-family:inherit;
  }
  .btn-secondary:hover{border-color:var(--ink-faint);color:var(--ink);}
  .btn-danger{background:var(--brick);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:14px;cursor:pointer;font-family:inherit;}
  .btn-danger:hover{background:#8A2F28;}

  .field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
  .field-row.one{grid-template-columns:1fr;}
  .field-row.three{grid-template-columns:1fr 1fr 1fr;}
  .field{display:flex;flex-direction:column;gap:5px;}
  .field label{font-size:12.5px;color:var(--ink-soft);font-weight:500;}
  .field label .req{color:var(--brick);}
  .field input,.field select,.field textarea{
    border:1px solid var(--line);background:var(--paper);
    border-radius:8px;padding:9px 11px;font-size:13.5px;
    color:var(--ink);font-family:inherit;width:100%;
  }
  .field textarea{resize:vertical;min-height:78px;}
  .field .hint{font-size:11.5px;color:var(--ink-faint);}
  .field.invalid input,.field.invalid select,.field.invalid textarea{border-color:var(--brick);background:var(--brick-bg);}
  .field .err{font-size:11.5px;color:var(--brick);}

  .section-title{
    font-family:'Fraunces',serif;font-size:16px;font-weight:500;
    margin:22px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--line);
  }
  .section-title:first-child{margin-top:0;}

  /* Option editor rows */
  .opt-row{display:flex;gap:10px;align-items:center;margin-bottom:8px;}
  .opt-row input{flex:1;}
  .opt-row .idx{
    width:24px;height:24px;border-radius:50%;flex-shrink:0;
    background:var(--paper);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;
    font-size:11.5px;color:var(--ink-soft);font-weight:600;
  }
  .hidden{display:none !important;}

  /* Toast */
  #toasts{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:10px;z-index:80;}
  .toast{
    background:#12203C;color:#F2F5FA;border-radius:10px;
    padding:12px 16px;font-size:13.5px;max-width:380px;
    box-shadow:0 10px 30px rgba(15,24,48,.3);
  }
  .toast.err{background:var(--brick);}
  .toast.ok{background:var(--teal-deep);}

  @media (max-width:880px){
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .content{padding:20px 16px 60px;}
    .topbar .search input{width:150px;}
    .topbar{padding:12px 16px;}
    .field-row,.field-row.three{grid-template-columns:1fr;}
    .page-head{flex-direction:column;}
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
      <a class="nav-item active" href="{{ route('admin.questions') }}" aria-current="page">
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
        <span class="cur">Questions</span>
      </div>

      <div class="topbar-right">
        <label class="search">
          <span class="ic">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <input type="search" id="global-search" placeholder="Search question text…" aria-label="Search questions">
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
        <div class="titles">
          <h1>Questions</h1>
          <p>Manage the Dynamic Assessment question bank. Every question is attached to a skill, so the chain
             <strong>Specialization &rarr; Career Role &rarr; Skill &rarr; Question</strong> stays intact and the
             baseline assessment keeps selecting questions from the right skills.</p>
        </div>
        <button class="btn-primary" id="btn-add">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Add Question
        </button>
      </header>

      <!-- Filters -->
      <form class="filters" id="filters" onsubmit="return false;">
        <div class="field">
          <label for="f-spec">Specialization</label>
          <select id="f-spec">
            <option value="">All specializations</option>
          </select>
        </div>
        <div class="field">
          <label for="f-role">Career Role</label>
          <select id="f-role" disabled>
            <option value="">All career roles</option>
          </select>
        </div>
        <div class="field">
          <label for="f-skill">Skill</label>
          <select id="f-skill" disabled>
            <option value="">All skills</option>
          </select>
        </div>
        <button type="button" class="btn-ghost" id="f-clear">Clear</button>
      </form>

      <!-- Selected chain -->
      <div class="chain" id="chain" aria-live="polite"></div>

      <p id="status-line"></p>

      <div class="table-wrap">
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th style="width:70px">ID</th>
                <th>Question</th>
                <th style="width:150px">Type</th>
                <th style="width:220px">Answer</th>
                <th style="width:110px">Usage</th>
                <th style="text-align:right;width:160px">Actions</th>
              </tr>
            </thead>
            <tbody id="rows"></tbody>
          </table>
        </div>

        <div class="pager" id="pager" style="display:none">
          <div class="info" id="pager-info"></div>
          <div class="pages" id="pager-pages"></div>
        </div>
      </div>
    </div>
  </main>

</div>

<!-- ============================= Create / edit modal ============================= -->
<div class="overlay" id="form-overlay">
  <div class="modal">
    <div class="modal-head">
      <h2 id="form-title">Add Question</h2>
      <button class="close" data-close="form-overlay" aria-label="Close">&times;</button>
    </div>
    <form id="question-form">
      <div class="modal-body">
        <div class="section-title">Placement</div>

        <div class="field-row three">
          <div class="field" id="wrap-specialization_id">
            <label for="q-spec">Specialization <span class="req">*</span></label>
            <select id="q-spec" required>
              <option value="">Select a specialization…</option>
            </select>
          </div>
          <div class="field" id="wrap-career_role_id">
            <label for="q-role">Career Role <span class="req">*</span></label>
            <select id="q-role" required disabled>
              <option value="">Select a specialization first…</option>
            </select>
          </div>
          <div class="field" id="wrap-skill_id">
            <label for="q-skill">Skill <span class="req">*</span></label>
            <select id="q-skill" required disabled>
              <option value="">Select a career role first…</option>
            </select>
          </div>
        </div>
        <p class="hint" style="margin:0 0 4px;">The dropdowns are dependent: a career role lists only the roles linked to the chosen specialization, and a skill lists only the skills that role requires.</p>

        <div class="section-title">Question</div>

        <div class="field-row">
          <div class="field" id="wrap-item_type">
            <label for="q-type">Question Type <span class="req">*</span></label>
            <select id="q-type" required>
              <option value="single_choice">Multiple choice</option>
              <option value="text">Text answer</option>
            </select>
          </div>
        </div>

        <div class="field-row one">
          <div class="field" id="wrap-question_text">
            <label for="q-text">Question <span class="req">*</span></label>
            <textarea id="q-text" maxlength="2000" required placeholder="e.g. What is the main difference between the GET and POST HTTP methods?"></textarea>
          </div>
        </div>

        <!-- Multiple choice -->
        <div id="mcq-fields">
          <div class="section-title">Options</div>
          <div id="options-list"></div>
          <button type="button" class="btn-ghost" id="add-option" style="margin-top:4px;">+ Add option</button>

          <div class="field-row one" style="margin-top:14px;">
            <div class="field" id="wrap-correct_answer">
              <label for="q-correct">Correct answer <span class="req">*</span></label>
              <select id="q-correct" required>
                <option value="">Add options first…</option>
              </select>
              <span class="hint">Must be one of the options above.</span>
            </div>
          </div>
        </div>

        <!-- Text answer -->
        <div id="text-fields" class="hidden">
          <div class="section-title">Model answer</div>
          <div class="field-row one">
            <div class="field" id="wrap-model_answer">
              <label for="q-model">Model answer <span class="req">*</span></label>
              <input type="text" id="q-model" maxlength="255" placeholder="The expected answer used for grading.">
              <span class="hint">Stored in the item bank's <code>correct_answer</code> column.</span>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn-secondary" data-close="form-overlay">Cancel</button>
        <button type="submit" class="btn-primary" id="form-submit">Create question</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================= Confirm modal ============================= -->
<div class="overlay" id="confirm-overlay">
  <div class="modal narrow">
    <div class="modal-head">
      <h2 id="confirm-title">Are you sure?</h2>
    </div>
    <div class="modal-body">
      <p id="confirm-text" style="margin:0;font-size:14px;"></p>
    </div>
    <div class="modal-foot">
      <span class="spacer"></span>
      <button class="btn-secondary" data-close="confirm-overlay">Cancel</button>
      <button class="btn-danger" id="confirm-ok">Delete</button>
    </div>
  </div>
</div>

<div id="toasts"></div>

<script>
// Session-authenticated panel: the cookie rides along automatically and the
// CSRF token is sent for every mutating request.

const TYPE_LABELS = { single_choice: 'Multiple choice', text: 'Text answer' };

let state = {
  page: 1,
  perPage: 15,
  lastPage: 1,
  total: 0,
  filters: { specialization_id: '', career_role_id: '', skill_id: '', q: '' },
  rows: [],
  specializations: [],
  editingId: null,
  confirmAction: null,
};

// ── Helpers ───────────────────────────────────────────────────────────────
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

let CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

async function refreshCsrfToken(){
  try {
    const res = await fetch(window.location.pathname, {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) return false;
    const html = await res.text();
    const meta = html.match(/<meta[^>]*name="csrf-token"[^>]*>/i);
    if (meta){
      const content = meta[0].match(/content="([^"]*)"/i);
      if (content && content[1]){
        CSRF_TOKEN = content[1]
          .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&');
        document.querySelector('meta[name="csrf-token"]').setAttribute('content', CSRF_TOKEN);
        return true;
      }
    }
    return false;
  } catch (e){ return false; }
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

  if (res.status === 419 && !isRetry){
    if (await refreshCsrfToken()) return api(path, options, true);
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
    err.body = body;
    throw err;
  }
  return body;
}

function optionHtml(list, selected, placeholder){
  const opts = list.map(item =>
    `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(item.label ?? item.name ?? item.title)}</option>`
  ).join('');
  return `<option value="">${escapeHtml(placeholder)}</option>${opts}`;
}

// ── Dropdowns ─────────────────────────────────────────────────────────────
async function loadSpecializations(){
  const body = await api('/admin/api/questions/specializations');
  state.specializations = (body.data || []).map(s => ({ id: s.id, label: s.name }));
  return state.specializations;
}

async function fetchCareerRoles(specializationId){
  if (!specializationId) return [];
  const body = await api('/admin/api/questions/career-roles?specialization_id=' + encodeURIComponent(specializationId));
  return (body.data || []).map(r => ({ id: r.id, label: r.title }));
}

async function fetchSkills(careerRoleId){
  if (!careerRoleId) return [];
  const body = await api('/admin/api/questions/skills?career_role_id=' + encodeURIComponent(careerRoleId));
  return (body.data || []).map(s => ({ id: s.id, label: s.name }));
}

// ── Filters ───────────────────────────────────────────────────────────────
async function onFilterSpecializationChange(){
  const specId = document.getElementById('f-spec').value;
  state.filters.specialization_id = specId;
  state.filters.career_role_id = '';
  state.filters.skill_id = '';

  const roleSel = document.getElementById('f-role');
  const skillSel = document.getElementById('f-skill');

  skillSel.innerHTML = '<option value="">All skills</option>';
  skillSel.disabled = true;

  if (!specId){
    roleSel.innerHTML = '<option value="">All career roles</option>';
    roleSel.disabled = true;
    renderChain();
    state.page = 1;
    return loadQuestions();
  }

  roleSel.disabled = true;
  roleSel.innerHTML = '<option value="">Loading…</option>';
  try {
    const roles = await fetchCareerRoles(specId);
    roleSel.innerHTML = optionHtml(roles, '', 'All career roles');
    roleSel.disabled = false;
  } catch (e){
    roleSel.innerHTML = '<option value="">All career roles</option>';
  }
  renderChain();
  state.page = 1;
  return loadQuestions();
}

async function onFilterRoleChange(){
  const roleId = document.getElementById('f-role').value;
  state.filters.career_role_id = roleId;
  state.filters.skill_id = '';

  const skillSel = document.getElementById('f-skill');
  if (!roleId){
    skillSel.innerHTML = '<option value="">All skills</option>';
    skillSel.disabled = true;
    renderChain();
    state.page = 1;
    return loadQuestions();
  }

  skillSel.disabled = true;
  skillSel.innerHTML = '<option value="">Loading…</option>';
  try {
    const skills = await fetchSkills(roleId);
    skillSel.innerHTML = optionHtml(skills, '', 'All skills');
    skillSel.disabled = false;
  } catch (e){
    skillSel.innerHTML = '<option value="">All skills</option>';
  }
  renderChain();
  state.page = 1;
  return loadQuestions();
}

function onFilterSkillChange(){
  state.filters.skill_id = document.getElementById('f-skill').value;
  renderChain();
  state.page = 1;
  loadQuestions();
}

function selectedLabel(selectId){
  const sel = document.getElementById(selectId);
  if (!sel || !sel.value) return null;
  const opt = sel.options[sel.selectedIndex];
  return opt ? opt.textContent : null;
}

function renderChain(){
  const chain = document.getElementById('chain');
  const spec = selectedLabel('f-spec');
  const role = selectedLabel('f-role');
  const skill = selectedLabel('f-skill');

  const node = (text, filled) =>
    `<span class="node ${filled ? '' : 'empty'}">${escapeHtml(text)}</span>`;

  chain.innerHTML =
    node(spec || 'Specialization', !!spec) + '<span class="arrow">&rarr;</span>' +
    node(role || 'Career Role', !!role) + '<span class="arrow">&rarr;</span>' +
    node(skill || 'Skill', !!skill) + '<span class="arrow">&rarr;</span>' +
    node('Questions', true);
}

// ── Questions list ────────────────────────────────────────────────────────
async function loadQuestions(){
  const tbody = document.getElementById('rows');
  setStatusLine('Loading questions…');

  const params = new URLSearchParams();
  params.set('page', state.page);
  params.set('per_page', state.perPage);
  for (const [key, value] of Object.entries(state.filters)){
    if (value !== '' && value !== null) params.set(key, value);
  }

  try {
    const body = await api('/admin/api/questions?' + params.toString());
    const data = body.data || {};
    const rows = data.data || [];

    state.rows = rows;
    state.total = data.total || 0;
    state.lastPage = data.last_page || 1;

    renderRows(rows);
    renderPager(data);

    if (state.total === 0){
      setStatusLine(hasFilters() ? 'No questions match these filters.' : 'No questions in the bank yet.');
    } else {
      setStatusLine('');
    }
  } catch (e){
    setStatusLine('Could not load questions: ' + e.message, 'err');
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><div class="big">Could not load questions</div><div class="hint">${escapeHtml(e.message)}</div></div></td></tr>`;
    document.getElementById('pager').style.display = 'none';
  }
}

function hasFilters(){
  return state.filters.specialization_id || state.filters.career_role_id || state.filters.skill_id || state.filters.q;
}

function renderRows(rows){
  const tbody = document.getElementById('rows');

  if (!rows.length){
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state">
      <div class="big">No questions found</div>
      <div class="hint">${hasFilters() ? 'Try clearing the filters, or add a question for this skill.' : 'Add a question to get started.'}</div>
    </div></td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(q => {
    const isMcq = q.item_type === 'single_choice';
    const answer = isMcq
      ? `<span class="pill single_choice">${escapeHtml(q.correct_answer || '—')}</span>`
      : `<span style="color:var(--ink-soft)">${escapeHtml(q.correct_answer || '—')}</span>`;

    const usage = q.usage_count > 0
      ? `<span class="pill in-use">${q.usage_count} assessment${q.usage_count === 1 ? '' : 's'}</span>`
      : `<span class="pill unused">Unused</span>`;

    const opts = isMcq && Array.isArray(q.options) && q.options.length
      ? `<span class="sub">${q.options.map(o => escapeHtml(o)).join(' · ')}</span>`
      : (q.skill_name ? `<span class="sub">Skill: ${escapeHtml(q.skill_name)}</span>` : '');

    return `<tr>
      <td class="mono">${escapeHtml(q.id)}</td>
      <td class="title-cell">
        <div class="q">${escapeHtml(q.question_text || '(no text)')}</div>
        ${opts}
      </td>
      <td><span class="pill ${escapeHtml(q.item_type)}">${escapeHtml(TYPE_LABELS[q.item_type] || q.item_type)}</span></td>
      <td>${answer}</td>
      <td>${usage}</td>
      <td class="actions-cell">
        <button class="row-btn" data-edit="${q.id}">Edit</button>
        <button class="row-btn danger" data-del="${q.id}">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

function renderPager(data){
  const pager = document.getElementById('pager');
  const info = document.getElementById('pager-info');
  const pages = document.getElementById('pager-pages');

  if (!data || !data.total){
    pager.style.display = 'none';
    return;
  }
  pager.style.display = '';

  info.textContent = `Showing ${data.from ?? 0}–${data.to ?? 0} of ${data.total} questions`;

  const current = data.current_page || 1;
  const last = data.last_page || 1;
  let html = '';

  html += `<button ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}">Prev</button>`;

  const start = Math.max(1, current - 2);
  const end = Math.min(last, start + 4);
  for (let i = start; i <= end; i++){
    html += `<button class="${i === current ? 'cur' : ''}" data-page="${i}">${i}</button>`;
  }
  if (end < last) html += `<span style="color:var(--ink-faint)">…</span><button data-page="${last}">${last}</button>`;
  html += `<button ${current >= last ? 'disabled' : ''} data-page="${current + 1}">Next</button>`;

  pages.innerHTML = html;
}

// ── Form ──────────────────────────────────────────────────────────────────
function addOptionRow(value){
  const list = document.getElementById('options-list');
  const wrap = document.createElement('div');
  wrap.className = 'opt-row';
  wrap.innerHTML = `
    <span class="idx"></span>
    <input type="text" class="opt-input" maxlength="255" value="${escapeHtml(value || '')}" placeholder="Option text">
    <button type="button" class="row-btn danger rm-opt">Remove</button>`;
  list.appendChild(wrap);
  renumberOptions();
}

function renumberOptions(){
  document.querySelectorAll('#options-list .opt-row').forEach((row, i) => {
    row.querySelector('.idx').textContent = String(i + 1);
  });
  syncCorrectOptions();
}

/** Rebuild the "correct answer" select from the current option inputs. */
function syncCorrectOptions(){
  const values = [...document.querySelectorAll('#options-list .opt-input')]
    .map(i => i.value.trim())
    .filter(v => v !== '');

  const sel = document.getElementById('q-correct');
  const previous = sel.value;
  sel.innerHTML = values.length
    ? '<option value="">Select the correct answer…</option>' + values.map(v =>
        `<option value="${escapeHtml(v)}" ${v === previous ? 'selected' : ''}>${escapeHtml(v)}</option>`
      ).join('')
    : '<option value="">Add options first…</option>';
}

function applyTypeVisibility(){
  const type = document.getElementById('q-type').value;
  const isMcq = type === 'single_choice';
  document.getElementById('mcq-fields').classList.toggle('hidden', !isMcq);
  document.getElementById('text-fields').classList.toggle('hidden', isMcq);

  document.getElementById('q-correct').required = isMcq;
  document.getElementById('q-model').required = !isMcq;
}

function clearFormErrors(){
  document.querySelectorAll('#question-form .field').forEach(f => {
    f.classList.remove('invalid');
    const err = f.querySelector('.err');
    if (err) err.remove();
  });
}

function showFormErrors(errors){
  clearFormErrors();
  if (!errors) return;
  for (const [field, messages] of Object.entries(errors)){
    const base = field.split('.')[0];
    // The MCQ/Text answer inputs live under their own wrappers.
    const map = { correct_answer: 'wrap-correct_answer', options: 'wrap-correct_answer' };
    const wrapId = map[base] || ('wrap-' + base);
    const wrap = document.getElementById(wrapId);
    if (!wrap) continue;
    wrap.classList.add('invalid');
    const el = document.createElement('span');
    el.className = 'err';
    el.textContent = Array.isArray(messages) ? messages[0] : messages;
    wrap.appendChild(el);
  }
}

async function openCreateForm(){
  state.editingId = null;
  document.getElementById('form-title').textContent = 'Add Question';
  document.getElementById('form-submit').textContent = 'Create question';
  document.getElementById('question-form').reset();
  clearFormErrors();

  const specSel = document.getElementById('q-spec');
  specSel.innerHTML = optionHtml(state.specializations, '', 'Select a specialization…');

  const roleSel = document.getElementById('q-role');
  roleSel.innerHTML = '<option value="">Select a specialization first…</option>';
  roleSel.disabled = true;

  const skillSel = document.getElementById('q-skill');
  skillSel.innerHTML = '<option value="">Select a career role first…</option>';
  skillSel.disabled = true;

  document.getElementById('options-list').innerHTML = '';
  addOptionRow();
  addOptionRow();
  syncCorrectOptions();

  // Pre-select the current filter context so the common path is one click.
  if (state.filters.specialization_id){
    specSel.value = state.filters.specialization_id;
    await onFormSpecializationChange(state.filters.career_role_id, state.filters.skill_id);
  }

  applyTypeVisibility();
  document.getElementById('form-overlay').classList.add('open');
}

async function onFormSpecializationChange(presetRole, presetSkill){
  const specId = document.getElementById('q-spec').value;
  const roleSel = document.getElementById('q-role');
  const skillSel = document.getElementById('q-skill');

  skillSel.innerHTML = '<option value="">Select a career role first…</option>';
  skillSel.disabled = true;

  if (!specId){
    roleSel.innerHTML = '<option value="">Select a specialization first…</option>';
    roleSel.disabled = true;
    return;
  }

  roleSel.disabled = true;
  roleSel.innerHTML = '<option value="">Loading…</option>';
  try {
    const roles = await fetchCareerRoles(specId);
    roleSel.innerHTML = optionHtml(roles, presetRole || '', 'Select a career role…');
    roleSel.disabled = false;
  } catch (e){
    roleSel.innerHTML = '<option value="">Select a career role…</option>';
    return;
  }

  if (presetRole){
    roleSel.value = String(presetRole);
    await onFormRoleChange(presetSkill);
  }
}

async function onFormRoleChange(presetSkill){
  const roleId = document.getElementById('q-role').value;
  const skillSel = document.getElementById('q-skill');

  if (!roleId){
    skillSel.innerHTML = '<option value="">Select a career role first…</option>';
    skillSel.disabled = true;
    return;
  }

  skillSel.disabled = true;
  skillSel.innerHTML = '<option value="">Loading…</option>';
  try {
    const skills = await fetchSkills(roleId);
    skillSel.innerHTML = optionHtml(skills, presetSkill || '', 'Select a skill…');
    skillSel.disabled = false;
  } catch (e){
    skillSel.innerHTML = '<option value="">Select a skill…</option>';
  }
}

async function openEditForm(id){
  const q = state.rows.find(row => String(row.id) === String(id));
  if (!q){ toast('That question is not on the current page.', 'err'); return; }

  state.editingId = q.id;
  document.getElementById('form-title').textContent = 'Edit Question #' + q.id;
  document.getElementById('form-submit').textContent = 'Save changes';
  document.getElementById('question-form').reset();
  clearFormErrors();

  document.getElementById('q-type').value = q.item_type;
  document.getElementById('q-text').value = q.question_text || '';
  document.getElementById('q-model').value = q.item_type === 'text' ? (q.correct_answer || '') : '';

  const optionsList = document.getElementById('options-list');
  optionsList.innerHTML = '';
  const opts = (Array.isArray(q.options) ? q.options : []).filter(o => String(o).trim() !== '');
  if (opts.length){
    opts.forEach(o => addOptionRow(o));
  } else {
    addOptionRow();
    addOptionRow();
  }
  syncCorrectOptions();
  if (q.item_type === 'single_choice' && q.correct_answer){
    document.getElementById('q-correct').value = q.correct_answer;
  }

  applyTypeVisibility();

  // Resolve the specialization/career role this question's skill sits under,
  // then pre-select the whole chain. Falls back to the active filter when the
  // reverse lookup finds nothing.
  const specSel = document.getElementById('q-spec');
  specSel.innerHTML = optionHtml(state.specializations, '', 'Select a specialization…');

  try {
    const ctx = await api('/admin/api/questions/skill-context?skill_id=' + encodeURIComponent(q.skill_id));
    const context = ctx.data || {};
    const specId = context.specialization_id || state.filters.specialization_id || '';
    const roleId = context.career_role_id || state.filters.career_role_id || '';

    specSel.value = specId ? String(specId) : '';
    if (specId){
      await onFormSpecializationChange(roleId, q.skill_id);
    }
  } catch (e){
    // Leave the chain empty rather than blocking the edit.
  }

  document.getElementById('form-overlay').classList.add('open');
}

function collectPayload(){
  const type = document.getElementById('q-type').value;
  const options = [...document.querySelectorAll('#options-list .opt-input')]
    .map(i => i.value.trim())
    .filter(v => v !== '');

  const payload = {
    specialization_id: Number(document.getElementById('q-spec').value) || null,
    career_role_id: Number(document.getElementById('q-role').value) || null,
    skill_id: Number(document.getElementById('q-skill').value) || null,
    item_type: type,
    question_text: document.getElementById('q-text').value.trim(),
  };

  if (type === 'single_choice'){
    payload.options = options;
    payload.correct_answer = document.getElementById('q-correct').value.trim();
  } else {
    payload.options = [];
    payload.correct_answer = document.getElementById('q-model').value.trim();
  }

  return payload;
}

async function submitForm(event){
  event.preventDefault();
  const btn = document.getElementById('form-submit');
  const original = btn.textContent;
  clearFormErrors();

  const payload = collectPayload();
  btn.disabled = true;
  btn.textContent = state.editingId ? 'Saving…' : 'Creating…';

  try {
    if (state.editingId){
      await api('/admin/api/questions/' + state.editingId, { method: 'PATCH', body: JSON.stringify(payload) });
      toast('Question updated.', 'ok');
    } else {
      await api('/admin/api/questions', { method: 'POST', body: JSON.stringify(payload) });
      toast('Question created.', 'ok');
    }
    document.getElementById('form-overlay').classList.remove('open');
    await loadQuestions();
  } catch (e){
    if (e.status === 422 && e.errors){
      showFormErrors(e.errors);
      toast('Please fix the highlighted fields.', 'err');
    } else if (e.status === 403){
      toast('You are not allowed to do that.', 'err');
    } else {
      toast(e.message, 'err');
    }
  } finally {
    btn.disabled = false;
    btn.textContent = original;
  }
}

// ── Delete ────────────────────────────────────────────────────────────────
function askConfirm(opts){
  document.getElementById('confirm-title').textContent = opts.title;
  document.getElementById('confirm-text').innerHTML = opts.html;
  document.getElementById('confirm-ok').textContent = opts.okLabel || 'Delete';
  state.confirmAction = opts.onConfirm;
  document.getElementById('confirm-overlay').classList.add('open');
}

function doDelete(id){
  const q = state.rows.find(row => String(row.id) === String(id));
  const text = q ? q.question_text : 'this question';

  askConfirm({
    title: 'Delete question?',
    html: `Delete <strong>${escapeHtml(text)}</strong>?<br><br>This removes it from the question bank. A question already used by an assessment cannot be deleted.`,
    okLabel: 'Delete',
    onConfirm: async () => {
      try {
        await api('/admin/api/questions/' + id, { method: 'DELETE' });
        toast('Question deleted.', 'ok');
        await loadQuestions();
      } catch (e){
        toast(e.message, 'err');
      }
    },
  });
}

// ── Wiring ────────────────────────────────────────────────────────────────
let searchTimer = null;
function debounceLoad(){
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { state.page = 1; loadQuestions(); }, 320);
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    await loadSpecializations();
  } catch (e){
    setStatusLine('Could not load specializations: ' + e.message, 'err');
  }

  const specFilter = document.getElementById('f-spec');
  specFilter.innerHTML = optionHtml(state.specializations, '', 'All specializations');

  renderChain();
  loadQuestions();

  // Filters
  specFilter.addEventListener('change', onFilterSpecializationChange);
  document.getElementById('f-role').addEventListener('change', onFilterRoleChange);
  document.getElementById('f-skill').addEventListener('change', onFilterSkillChange);
  document.getElementById('f-clear').addEventListener('click', () => {
    state.filters = { specialization_id: '', career_role_id: '', skill_id: '', q: '' };
    document.getElementById('f-spec').value = '';
    const roleSel = document.getElementById('f-role');
    roleSel.innerHTML = '<option value="">All career roles</option>';
    roleSel.disabled = true;
    const skillSel = document.getElementById('f-skill');
    skillSel.innerHTML = '<option value="">All skills</option>';
    skillSel.disabled = true;
    document.getElementById('global-search').value = '';
    renderChain();
    state.page = 1;
    loadQuestions();
  });

  // Top-bar search mirrors the question-text search.
  document.getElementById('global-search').addEventListener('input', (e) => {
    state.filters.q = e.target.value.trim();
    debounceLoad();
  });

  // Row actions (delegated)
  document.getElementById('rows').addEventListener('click', (e) => {
    const edit = e.target.closest('[data-edit]');
    if (edit){ openEditForm(edit.dataset.edit); return; }
    const del = e.target.closest('[data-del]');
    if (del){ doDelete(del.dataset.del); }
  });

  // Pagination (delegated)
  document.getElementById('pager-pages').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    loadQuestions();
  });

  // Form
  document.getElementById('btn-add').addEventListener('click', openCreateForm);
  document.getElementById('question-form').addEventListener('submit', submitForm);
  document.getElementById('q-type').addEventListener('change', applyTypeVisibility);
  document.getElementById('q-spec').addEventListener('change', () => onFormSpecializationChange());
  document.getElementById('q-role').addEventListener('change', () => onFormRoleChange());
  document.getElementById('add-option').addEventListener('click', () => addOptionRow());

  document.getElementById('options-list').addEventListener('click', (e) => {
    const rm = e.target.closest('.rm-opt');
    if (!rm) return;
    if (document.querySelectorAll('#options-list .opt-row').length <= 2){
      toast('A multiple-choice question needs at least two options.', 'err');
      return;
    }
    rm.closest('.opt-row').remove();
    renumberOptions();
  });

  document.getElementById('options-list').addEventListener('input', (e) => {
    if (e.target.classList.contains('opt-input')) syncCorrectOptions();
  });

  // Modals
  document.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => document.getElementById(btn.dataset.close).classList.remove('open'));
  });
  document.querySelectorAll('.overlay').forEach(ov => {
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.classList.remove('open'); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open'));
  });

  document.getElementById('confirm-ok').addEventListener('click', async () => {
    const fn = state.confirmAction;
    state.confirmAction = null;
    document.getElementById('confirm-overlay').classList.remove('open');
    if (fn) await fn();
  });
});
</script>

</body>
</html>
