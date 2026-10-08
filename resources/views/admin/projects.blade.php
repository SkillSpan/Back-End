<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Projects</title>
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
   * Sidebar — identical to the organizations panel
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

  /* Stats */
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
  .stat{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);padding:16px 18px;
    display:flex;align-items:center;gap:14px;box-shadow:var(--shadow);
  }
  .stat .glyph{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .stat .glyph.total{background:var(--info-bg);color:var(--info);}
  .stat .glyph.draft{background:var(--violet-bg);color:var(--violet);}
  .stat .glyph.review{background:var(--amber-bg);color:var(--amber);}
  .stat .glyph.live{background:var(--sage-bg);color:var(--sage);}
  .stat .body .lbl{font-size:12.5px;color:var(--ink-soft);font-weight:500;}
  .stat .body .val{font-family:'Fraunces',serif;font-size:24px;font-weight:500;line-height:1.1;margin-top:2px;}
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

  /* Table */
  .table-wrap{
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
  }
  .table-scroll{overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:1080px;}
  thead th{
    text-align:left;font-weight:600;font-size:12px;
    letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);
    padding:12px 14px;border-bottom:1px solid var(--line);
    background:#FBFAF7;white-space:nowrap;
  }
  tbody td{padding:13px 14px;border-bottom:1px solid var(--line-soft);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:#FCFCFA;}
  td.title-cell{font-weight:500;max-width:260px;}
  td.title-cell .sub{display:block;font-weight:400;color:var(--ink-faint);font-size:12.5px;margin-top:2px;}
  td.mono{font-variant-numeric:tabular-nums;color:var(--ink-soft);white-space:nowrap;}
  td.actions-cell{white-space:nowrap;text-align:right;}

  .pill{font-size:11.5px;padding:3px 10px;border-radius:100px;font-weight:500;white-space:nowrap;display:inline-block;}
  .pill.draft{background:var(--violet-bg);color:var(--violet);}
  .pill.submitted{background:var(--amber-bg);color:var(--amber);}
  .pill.changes_requested{background:var(--amber-bg);color:var(--amber);}
  .pill.approved{background:var(--info-bg);color:var(--info);}
  .pill.rejected{background:var(--brick-bg);color:var(--brick);}
  .pill.cancelled{background:var(--brick-bg);color:var(--brick);}
  .pill.open{background:var(--sage-bg);color:var(--sage);}
  .pill.selection{background:var(--sage-bg);color:var(--sage);}
  .pill.active{background:var(--sage-bg);color:var(--sage);}
  .pill.under_review{background:var(--info-bg);color:var(--info);}
  .pill.completed{background:var(--sage-bg);color:var(--sage);}
  .pill.archived{background:#ECEAE4;color:var(--ink-soft);}
  .pill.closed{background:#ECEAE4;color:var(--ink-soft);}

  .type-tag{font-size:11.5px;padding:3px 9px;border-radius:100px;border:1px solid var(--line);color:var(--ink-soft);white-space:nowrap;}
  .type-tag.company_sponsored{border-color:var(--info);color:var(--info);}

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
    width:100%;max-width:760px;box-shadow:0 20px 60px rgba(15,24,48,.25);
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

  /* Detail view */
  .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 22px;font-size:13.5px;}
  .detail-grid .k{color:var(--ink-soft);}
  .detail-grid .v{font-weight:500;}
  .detail-desc{font-size:14px;margin:0 0 8px;white-space:pre-wrap;}
  .chip-list{display:flex;flex-wrap:wrap;gap:7px;}
  .chip{
    font-size:12.5px;padding:4px 11px;border-radius:100px;
    background:var(--paper);border:1px solid var(--line);color:var(--ink);
  }
  .chip.critical{border-color:var(--amber);color:var(--amber);background:var(--amber-bg);}

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
    .stats{grid-template-columns:repeat(2,1fr);}
  }
  @media (max-width:880px){
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .content{padding:20px 16px 60px;}
    .topbar .search input{width:150px;}
    .topbar{padding:12px 16px;}
    .field-row,.field-row.three,.detail-grid{grid-template-columns:1fr;}
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
      <a class="nav-item active" href="{{ route('admin.projects') }}" aria-current="page">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </span>
        Projects
        <span class="badge" id="nav-open-badge" style="display:none">0</span>
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
      <a class="nav-item" href="{{ route('admin.specializations') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="19" cy="18" r="2" stroke="currentColor" stroke-width="1.6"/></svg>
        </span>
        Specializations
      </a>
      <a class="nav-item" href="{{ route('admin.questions') }}">
        <span class="ic">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.8.9c0 1.6-2.4 2-2.4 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor"/></svg>
        </span>
        Questions
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
        <span class="cur">Projects</span>
      </div>

      <div class="topbar-right">
        <label class="search">
          <span class="ic">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <input type="search" id="global-search" placeholder="Search projects…" aria-label="Search projects">
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
          <h1>Projects</h1>
          <p>Manage SkillSpan projects — simulations and company-sponsored work. Every change goes through the Laravel lifecycle, which owns the allowed transitions.</p>
        </div>
        <button class="btn-primary" id="btn-add">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Add Project
        </button>
      </header>

      <!-- Stats -->
      <div class="stats">
        <div class="stat">
          <div class="glyph total">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Total</div>
            <div class="val skeleton" id="stat-total">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph draft">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10-10-4-4L4 16v4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Draft</div>
            <div class="val skeleton" id="stat-draft">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph review">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Awaiting Review</div>
            <div class="val skeleton" id="stat-review">—</div>
          </div>
        </div>
        <div class="stat">
          <div class="glyph live">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M20 7L9 18l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div class="body">
            <div class="lbl">Open</div>
            <div class="val skeleton" id="stat-open">—</div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <form class="filters" id="filters">
        <div class="field grow">
          <label for="f-q">Search</label>
          <input type="search" id="f-q" placeholder="Title, domain, role or project ID…">
        </div>
        <div class="field">
          <label for="f-type">Type</label>
          <select id="f-type">
            <option value="">All types</option>
            <option value="simulation">Simulation</option>
            <option value="company_sponsored">Company Sponsored</option>
          </select>
        </div>
        <div class="field">
          <label for="f-status">Status</label>
          <select id="f-status">
            <option value="">All statuses</option>
          </select>
        </div>
        <div class="field">
          <label for="f-difficulty">Difficulty</label>
          <select id="f-difficulty">
            <option value="">Any</option>
            <option value="0.5">0.5</option>
            <option value="1">1</option>
            <option value="1.5">1.5</option>
            <option value="2">2</option>
            <option value="2.5">2.5</option>
            <option value="3">3</option>
            <option value="3.5">3.5</option>
            <option value="4">4</option>
            <option value="4.5">4.5</option>
            <option value="5">5</option>
          </select>
        </div>
        <button type="button" class="btn-ghost" id="f-clear">Clear</button>
      </form>

      <p id="status-line"></p>

      <div class="table-wrap">
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Project</th>
                <th>Type</th>
                <th>Organization</th>
                <th>Owner</th>
                <th>Difficulty</th>
                <th>Status</th>
                <th>Capacity</th>
                <th>Start</th>
                <th>End</th>
                <th>Deadline</th>
                <th style="text-align:right">Actions</th>
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

<!-- ============================= Detail modal ============================= -->
<div class="overlay" id="detail-overlay">
  <div class="modal">
    <div class="modal-head">
      <h2 id="detail-title">Project</h2>
      <button class="close" data-close="detail-overlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="detail-body"></div>
    <div class="modal-foot">
      <span class="spacer"></span>
      <button class="btn-secondary" data-close="detail-overlay">Close</button>
      <button class="btn-primary" id="detail-edit" style="display:none">Edit</button>
    </div>
  </div>
</div>

<!-- ============================= Create / edit modal ============================= -->
<div class="overlay" id="form-overlay">
  <div class="modal">
    <div class="modal-head">
      <h2 id="form-title">Add Project</h2>
      <button class="close" data-close="form-overlay" aria-label="Close">&times;</button>
    </div>
    <form id="project-form">
      <div class="modal-body">
        <div class="section-title">Basics</div>

        <div class="field-row">
          <div class="field" id="wrap-type">
            <label for="p-type">Type <span class="req">*</span></label>
            <select id="p-type" name="type" required>
              <option value="simulation">Simulation</option>
              <option value="company_sponsored">Company Sponsored</option>
            </select>
            <span class="hint" id="type-hint">Simulation projects are SkillSpan-internal.</span>
          </div>
          <div class="field" id="wrap-domain">
            <label for="p-domain">Domain</label>
            <input type="text" id="p-domain" name="domain" maxlength="100" placeholder="e.g. Software &amp; IT Services">
          </div>
        </div>

        <div class="field-row one">
          <div class="field" id="wrap-title">
            <label for="p-title">Title <span class="req">*</span></label>
            <input type="text" id="p-title" name="title" maxlength="255" required>
          </div>
        </div>

        <div class="field-row one">
          <div class="field" id="wrap-description">
            <label for="p-description">Description</label>
            <textarea id="p-description" name="description" maxlength="5000"></textarea>
            <span class="hint">Required before the project can be submitted for review.</span>
          </div>
        </div>

        <div class="field-row one">
          <div class="field" id="wrap-objectives">
            <label for="p-objectives">Objectives</label>
            <textarea id="p-objectives" name="objectives" maxlength="5000"></textarea>
          </div>
        </div>

        <div class="section-title">Delivery</div>

        <div class="field-row three">
          <div class="field" id="wrap-difficulty">
            <label for="p-difficulty">Difficulty</label>
            <input type="number" id="p-difficulty" name="difficulty" min="0" max="5" step="0.5">
            <span class="hint">0 – 5</span>
          </div>
          <div class="field" id="wrap-work_mode">
            <label for="p-work-mode">Work mode</label>
            <input type="text" id="p-work-mode" name="work_mode" maxlength="50" placeholder="e.g. remote">
          </div>
          <div class="field" id="wrap-role">
            <label for="p-role">Role</label>
            <input type="text" id="p-role" name="role" maxlength="255" placeholder="e.g. Backend Developer">
          </div>
        </div>

        <div class="field-row three">
          <div class="field" id="wrap-schedule">
            <label for="p-schedule">Schedule</label>
            <input type="text" id="p-schedule" name="schedule" maxlength="255" placeholder="e.g. 20 h / week">
          </div>
          <div class="field" id="wrap-capacity">
            <label for="p-capacity">Capacity</label>
            <input type="number" id="p-capacity" name="capacity" min="1" max="1000">
            <span class="hint">Participants needed</span>
          </div>
          <div class="field" id="wrap-min_team_size">
            <label for="p-min-team-size">Min team size</label>
            <input type="number" id="p-min-team-size" name="min_team_size" min="1" max="1000">
          </div>
        </div>

        <div class="field-row three">
          <div class="field" id="wrap-start_date">
            <label for="p-start-date">Start date</label>
            <input type="date" id="p-start-date" name="start_date">
          </div>
          <div class="field" id="wrap-end_date">
            <label for="p-end-date">End date</label>
            <input type="date" id="p-end-date" name="end_date">
          </div>
          <div class="field" id="wrap-application_deadline">
            <label for="p-deadline">Application deadline</label>
            <input type="date" id="p-deadline" name="application_deadline">
            <span class="hint">Must be on or before the start date.</span>
          </div>
        </div>

        <div class="field-row">
          <div class="field" id="wrap-confidentiality">
            <label for="p-confidentiality">Confidentiality</label>
            <select id="p-confidentiality" name="confidentiality">
              <option value="">Not set</option>
              <option value="public">Public</option>
              <option value="restricted">Restricted</option>
            </select>
          </div>
        </div>

        <div class="section-title">Required Skills</div>
        <p class="hint" style="margin:0 0 10px;">At least one skill is required before the project can be submitted.</p>
        <div id="skills-list"></div>
        <button type="button" class="btn-ghost" id="add-skill" style="margin-top:8px;">+ Add skill</button>

        <div class="section-title">Required Roles</div>
        <p class="hint" style="margin:0 0 10px;">At least one role is required before the project can be submitted.</p>
        <div id="roles-list"></div>
        <button type="button" class="btn-ghost" id="add-role" style="margin-top:8px;">+ Add role</button>

        <div class="section-title">Eligibility</div>
        <p class="hint" style="margin:0 0 10px;">Optional. Laravel enforces these — the form only collects them.</p>
        <div id="eligibility-list"></div>
        <button type="button" class="btn-ghost" id="add-eligibility" style="margin-top:8px;">+ Add constraint</button>
      </div>

      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn-secondary" data-close="form-overlay">Cancel</button>
        <button type="submit" class="btn-primary" id="form-submit">Create project</button>
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
// CSRF token is sent for every mutating request. The token itself is declared
// next to the `api()` helper below, as `let`, so it can be refreshed in place.

// ── Lifecycle metadata, mirrored from App\Models\Project ──────────────────
// These are LABELS ONLY. The backend decides every transition; the UI just
// decides which buttons to offer so an operator is not shown an action that
// would be refused. `closed` is the legacy value, kept displayable.
const STATUS_LABELS = {
  draft: 'Draft',
  submitted: 'Submitted',
  changes_requested: 'Changes Requested',
  approved: 'Approved',
  rejected: 'Rejected',
  open: 'Open',
  selection: 'Selection',
  active: 'Active',
  under_review: 'Under Review',
  completed: 'Completed',
  cancelled: 'Cancelled',
  archived: 'Archived',
  closed: 'Closed',
};

// The only transitions the panel offers a button for. Mirrors
// Project::TRANSITIONS; anything else is refused by the backend.
const ACTION_FOR_STATUS = {
  draft: [{ key: 'submit', label: 'Submit', to: 'submitted' }],
  changes_requested: [{ key: 'submit', label: 'Submit', to: 'submitted' }],
  submitted: [
    { key: 'approve', label: 'Approve', to: 'approved' },
    { key: 'request-changes', label: 'Request changes', to: 'changes_requested' },
    { key: 'reject', label: 'Reject', to: 'rejected' },
  ],
  approved: [{ key: 'open', label: 'Open', to: 'open' }],
};

// Roles the backend accepts for an eligibility constraint — the vocabulary
// ProjectEligibilityService understands. No new types are invented here.
const ELIGIBILITY_TYPES = ['location', 'language', 'schedule', 'work_mode'];

let state = {
  page: 1,
  perPage: 15,
  lastPage: 1,
  total: 0,
  filters: { q: '', type: '', status: '', difficulty: '' },
  editingId: null,
  skills: [],
  detail: null,
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

// CSRF token is sent for every mutating request. It is held in a variable so a
// stale token can be refreshed in place instead of reloading the page.
let CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

/**
 * Ask the server for a fresh CSRF token and update the meta tag.
 *
 * A 419 means the token the page was rendered with is no longer the one the
 * session expects - typically because another tab, or a session rotation,
 * replaced it. Re-fetching the token lets the next request succeed without a
 * full reload.
 *
 * Returns true when a token was obtained.
 */
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
        // Un-escape the entities Blade may have emitted.
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
  // a freshly fetched token before concluding anything: an unconditional
  // redirect to /admin/login is what turned a stale token into an endless
  // reload loop for a user who was still signed in.
  if (res.status === 419 && !isRetry){
    if (await refreshCsrfToken()){
      return api(path, options, true);
    }
  }

  // 401 = genuinely no session. Send the user to sign in.
  if (res.status === 401){
    window.location.href = '/admin/login';
    throw new Error('Your session has ended — please sign in again.');
  }

  // A 419 that survived the retry still means the session is unusable, but say
  // so plainly rather than bouncing silently.
  if (res.status === 419){
    const err = new Error('Your session token could not be verified. Please sign in again.');
    err.status = 419;
    err.code = 'CSRF_TOKEN_MISMATCH';
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

function statusPill(status){
  const cls = STATUS_LABELS[status] ? status : 'archived';
  const label = STATUS_LABELS[status] || status;
  return `<span class="pill ${cls}">${escapeHtml(label)}</span>`;
}

function typeTag(type){
  const label = type === 'company_sponsored' ? 'Company Sponsored' : 'Simulation';
  return `<span class="type-tag ${escapeHtml(type)}">${escapeHtml(label)}</span>`;
}

function fmtDate(value){
  if (!value) return '—';
  // Dates arrive as YYYY-MM-DD; render them as stored rather than shifting
  // them into a local timezone.
  return escapeHtml(String(value).slice(0, 10));
}

function fmtDifficulty(value){
  if (value === null || value === undefined || value === '') return '—';
  return escapeHtml(String(value));
}

// ── Load + render the list ────────────────────────────────────────────────
async function loadProjects(){
  const tbody = document.getElementById('rows');
  setStatusLine('Loading projects…');

  const params = new URLSearchParams();
  params.set('page', state.page);
  params.set('per_page', state.perPage);
  for (const [key, value] of Object.entries(state.filters)){
    if (value !== '' && value !== null) params.set(key, value);
  }

  try {
    const body = await api('/admin/api/projects?' + params.toString());
    const rows = body.data?.data || [];
    const meta = body.data?.meta || {};

    state.total = meta.total || 0;
    state.lastPage = meta.last_page || 1;

    renderRows(rows);
    renderPager(meta, rows.length);
    updateStats(body.stats || null, rows, meta);

    if (state.total === 0){
      setStatusLine(hasFilters() ? 'No projects match these filters.' : 'No projects yet. Create the first one.');
    } else {
      setStatusLine('');
    }
  } catch (e){
    setStatusLine('Could not load projects: ' + e.message, 'err');
    tbody.innerHTML = `<tr><td colspan="12"><div class="empty-state"><div class="big">Could not load projects</div><div class="hint">${escapeHtml(e.message)}</div></div></td></tr>`;
    document.getElementById('pager').style.display = 'none';
  }
}

function hasFilters(){
  return Object.values(state.filters).some(v => v !== '' && v !== null);
}

function renderRows(rows){
  const tbody = document.getElementById('rows');

  if (!rows.length){
    tbody.innerHTML = `<tr><td colspan="12"><div class="empty-state">
      <div class="big">No projects found</div>
      <div class="hint">${hasFilters() ? 'Try clearing the filters.' : 'Create a project to get started.'}</div>
    </div></td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(p => {
    const org = p.organization?.title || '—';
    const owner = p.owner?.name || '—';
    return `<tr>
      <td class="mono">${escapeHtml(p.id)}</td>
      <td class="title-cell">
        ${escapeHtml(p.title)}
        ${p.domain ? `<span class="sub">${escapeHtml(p.domain)}</span>` : ''}
      </td>
      <td>${typeTag(p.type)}</td>
      <td>${escapeHtml(org)}</td>
      <td>${escapeHtml(owner)}</td>
      <td class="mono">${fmtDifficulty(p.difficulty)}</td>
      <td>${statusPill(p.status)}</td>
      <td class="mono">${escapeHtml(p.capacity ?? '—')}</td>
      <td class="mono">${fmtDate(p.start_date)}</td>
      <td class="mono">${fmtDate(p.end_date)}</td>
      <td class="mono">${fmtDate(p.application_deadline)}</td>
      <td class="actions-cell">
        <button class="row-btn" data-view="${p.id}">View</button>
        ${p.is_editable ? `<button class="row-btn" data-edit="${p.id}">Edit</button>` : ''}
        <button class="row-btn danger" data-del="${p.id}" data-title="${escapeHtml(p.title)}">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

function renderPager(meta, count){
  const pager = document.getElementById('pager');
  const info = document.getElementById('pager-info');
  const pages = document.getElementById('pager-pages');

  if (!meta || !meta.total){
    pager.style.display = 'none';
    return;
  }
  pager.style.display = '';

  const from = meta.from ?? 0;
  const to = meta.to ?? 0;
  info.textContent = `Showing ${from}–${to} of ${meta.total} projects`;

  const current = meta.current_page || 1;
  const last = meta.last_page || 1;
  let html = '';

  html += `<button ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}">Prev</button>`;

  // Windowed page numbers so a large table does not render 200 buttons.
  const start = Math.max(1, current - 2);
  const end = Math.min(last, start + 4);
  for (let i = start; i <= end; i++){
    html += `<button class="${i === current ? 'cur' : ''}" data-page="${i}">${i}</button>`;
  }
  if (end < last) html += `<span style="color:var(--ink-faint)">…</span><button data-page="${last}">${last}</button>`;

  html += `<button ${current >= last ? 'disabled' : ''} data-page="${current + 1}">Next</button>`;
  pages.innerHTML = html;
}

// Stats are derived from the page currently on screen plus the authoritative
// total, so they never claim a count the API did not return.
function updateStats(explicit, rows, meta){
  const byStatus = {};
  for (const p of rows) byStatus[p.status] = (byStatus[p.status] || 0) + 1;

  const set = (id, val) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = val;
    el.classList.remove('skeleton');
  };

  if (explicit){
    set('stat-total', explicit.total ?? meta?.total ?? 0);
    set('stat-draft', explicit.draft ?? 0);
    set('stat-review', explicit.awaiting_review ?? 0);
    set('stat-open', explicit.open ?? 0);
    const badge = document.getElementById('nav-open-badge');
    const openCount = explicit.open ?? 0;
    badge.style.display = openCount > 0 ? '' : 'none';
    badge.textContent = openCount;
    return;
  }

  set('stat-total', meta?.total ?? rows.length);
  set('stat-draft', byStatus.draft || 0);
  set('stat-review', (byStatus.submitted || 0) + (byStatus.changes_requested || 0));
  set('stat-open', byStatus.open || 0);
}

// ── Detail ────────────────────────────────────────────────────────────────
async function openDetail(id){
  const overlay = document.getElementById('detail-overlay');
  const body = document.getElementById('detail-body');
  document.getElementById('detail-title').textContent = 'Loading…';
  body.innerHTML = '<p class="hint">Loading project…</p>';
  overlay.classList.add('open');

  try {
    const res = await api('/admin/api/projects/' + id);
    const p = res.data;
    state.detail = p;

    document.getElementById('detail-title').textContent = p.title;

    const editBtn = document.getElementById('detail-edit');
    editBtn.style.display = p.is_editable ? '' : 'none';
    editBtn.dataset.edit = p.id;

    body.innerHTML = renderDetail(p);
  } catch (e){
    document.getElementById('detail-title').textContent = 'Project';
    body.innerHTML = `<p style="color:var(--brick);margin:0;">Could not load this project: ${escapeHtml(e.message)}</p>`;
  }
}

function factGrid(pairs){
  const items = pairs
    .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
    .map(([k, v]) => `<div><span class="k">${escapeHtml(k)}: </span><span class="v">${escapeHtml(v)}</span></div>`)
    .join('');
  return items ? `<div class="detail-grid">${items}</div>` : '<p class="hint" style="margin:0;">Not provided.</p>';
}

function renderDetail(p){
  const skills = (p.required_skills || []);
  const roles = (p.project_roles || p.available_project_roles || []);
  // The stored constraint rows. `p.eligibility` is the per-learner verdict and
  // is null on an admin response, so it is not a usable source here.
  const eligibility = Array.isArray(p.eligibility_constraints) ? p.eligibility_constraints : [];

  const skillsHtml = skills.length
    ? `<div class="chip-list">${skills.map(s => {
        const level = (s.minimum_level === null || s.minimum_level === undefined) ? '' : ` · min ${escapeHtml(s.minimum_level)}`;
        return `<span class="chip ${s.is_critical_entry ? 'critical' : ''}">${escapeHtml(s.skill_name)}${level}${s.is_critical_entry ? ' · critical' : ''}</span>`;
      }).join('')}</div>`
    : '<p class="hint" style="margin:0;">No skills specified.</p>';

  const rolesHtml = roles.length
    ? `<div class="chip-list">${roles.map(r => `<span class="chip">${escapeHtml(r.title)}</span>`).join('')}</div>`
    : '<p class="hint" style="margin:0;">No roles specified.</p>';

  let eligibilityHtml = '<p class="hint" style="margin:0;">No eligibility constraints.</p>';
  if (eligibility.length){
    eligibilityHtml = `<div class="chip-list">${eligibility.map(e =>
      `<span class="chip">${escapeHtml(e.constraint_type || '')}: ${escapeHtml(Array.isArray(e.value) ? e.value.join(', ') : e.value)}</span>`
    ).join('')}</div>`;
  }

  const actions = ACTION_FOR_STATUS[p.status] || [];
  const actionHtml = actions.length
    ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">${actions.map(a =>
        `<button class="btn-primary" data-action="${a.key}" data-id="${p.id}" style="padding:8px 15px;font-size:13.5px;">${escapeHtml(a.label)}</button>`
      ).join('')}</div>`
    : '';

  return `
    <div class="section-title" style="margin-top:0;">Basic Information</div>
    ${factGrid([
      ['Project ID', p.id],
      ['Title', p.title],
      ['Type', p.type === 'company_sponsored' ? 'Company Sponsored' : 'Simulation'],
      ['Domain', p.domain],
      ['Difficulty', p.difficulty],
      ['Status', STATUS_LABELS[p.status] || p.status],
      ['Version', p.version],
      ['Confidentiality', p.confidentiality],
    ])}

    <div class="section-title">Description</div>
    <p class="detail-desc">${p.description ? escapeHtml(p.description) : '<span class="hint">No description was provided for this project.</span>'}</p>
    ${p.objectives ? `<div style="margin-top:12px;"><span class="k" style="font-size:13.5px;color:var(--ink-soft);">Objectives:</span><p class="detail-desc" style="margin-top:4px;">${escapeHtml(p.objectives)}</p></div>` : ''}

    <div class="section-title">Organization</div>
    ${factGrid([
      ['Organization', p.organization?.title],
      ['Project Owner', p.owner?.name],
      ['Owner Email', p.owner?.email],
    ])}

    <div class="section-title">Requirements</div>
    <p style="font-size:13px;color:var(--ink-soft);margin:0 0 6px;">Required Skills</p>
    ${skillsHtml}
    <p style="font-size:13px;color:var(--ink-soft);margin:14px 0 6px;">Required Roles</p>
    ${rolesHtml}
    <p style="font-size:13px;color:var(--ink-soft);margin:14px 0 6px;">Eligibility</p>
    ${eligibilityHtml}

    <div class="section-title">Schedule</div>
    ${factGrid([
      ['Start Date', p.start_date],
      ['End Date', p.end_date],
      ['Application Deadline', p.application_deadline],
      ['Duration (days)', p.duration_days],
    ])}

    <div class="section-title">Capacity</div>
    ${factGrid([
      ['Capacity', p.capacity],
      ['Min Team Size', p.min_team_size],
      ['Capacity State', p.capacity_state],
    ])}

    <div class="section-title">Additional Information</div>
    ${factGrid([
      ['Work Mode', p.work_mode],
      ['Role', p.role],
      ['Schedule', p.schedule],
    ])}
    ${(p.learning_outcomes && p.learning_outcomes.length)
      ? `<p style="font-size:13px;color:var(--ink-soft);margin:14px 0 6px;">Learning Outcomes</p>
         <div class="chip-list">${p.learning_outcomes.map(o => `<span class="chip">${escapeHtml(o)}</span>`).join('')}</div>`
      : ''}

    ${actionHtml ? `<div class="section-title">Actions</div>${actionHtml}` : ''}
  `;
}

// ── Create / edit form ────────────────────────────────────────────────────
function skillOptions(selected){
  const opts = state.skills.map(s =>
    `<option value="${s.id}" ${String(s.id) === String(selected) ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
  ).join('');
  return `<option value="">Select a skill…</option>${opts}`;
}

function addSkillRow(data = {}){
  const wrap = document.createElement('div');
  wrap.className = 'field-row three skill-row';
  wrap.style.alignItems = 'end';
  wrap.innerHTML = `
    <div class="field">
      <label>Skill <span class="req">*</span></label>
      <select class="sk-id">${skillOptions(data.skill_id)}</select>
    </div>
    <div class="field">
      <label>Minimum level</label>
      <input type="number" class="sk-level" min="0" max="5" step="0.5" value="${data.minimum_level ?? ''}" placeholder="0 – 5">
    </div>
    <div class="field" style="flex-direction:row;align-items:center;gap:8px;">
      <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
        <input type="checkbox" class="sk-critical" ${data.is_critical_entry ? 'checked' : ''} style="width:auto;">
        Critical skill
      </label>
      <button type="button" class="row-btn danger rm-row" style="margin-left:auto;">Remove</button>
    </div>`;
  document.getElementById('skills-list').appendChild(wrap);
}

function addRoleRow(data = {}){
  const wrap = document.createElement('div');
  wrap.className = 'field-row role-row';
  wrap.style.alignItems = 'end';
  wrap.innerHTML = `
    <div class="field">
      <label>Title <span class="req">*</span></label>
      <input type="text" class="rl-title" maxlength="255" value="${escapeHtml(data.title || '')}">
    </div>
    <div class="field">
      <label>Description</label>
      <input type="text" class="rl-desc" maxlength="1000" value="${escapeHtml(data.description || '')}">
    </div>
    <div class="field" style="justify-content:flex-end;">
      <button type="button" class="row-btn danger rm-row">Remove</button>
    </div>`;
  document.getElementById('roles-list').appendChild(wrap);
}

function addEligibilityRow(data = {}){
  const opts = ELIGIBILITY_TYPES.map(t =>
    `<option value="${t}" ${t === data.constraint_type ? 'selected' : ''}>${t}</option>`
  ).join('');
  const wrap = document.createElement('div');
  wrap.className = 'field-row el-row';
  wrap.style.alignItems = 'end';
  wrap.innerHTML = `
    <div class="field">
      <label>Type <span class="req">*</span></label>
      <select class="el-type">${opts}</select>
    </div>
    <div class="field">
      <label>Value <span class="req">*</span></label>
      <input type="text" class="el-value" maxlength="255" value="${escapeHtml(data.value || '')}" placeholder="e.g. Jordan">
    </div>
    <div class="field" style="justify-content:flex-end;">
      <button type="button" class="row-btn danger rm-row">Remove</button>
    </div>`;
  document.getElementById('eligibility-list').appendChild(wrap);
}

function clearFormErrors(){
  document.querySelectorAll('#project-form .field').forEach(f => {
    f.classList.remove('invalid');
    const err = f.querySelector('.err');
    if (err) err.remove();
  });
}

function showFormErrors(errors){
  clearFormErrors();
  if (!errors) return;
  for (const [field, messages] of Object.entries(errors)){
    // Server keys use dot notation for the repeatable rows
    // (required_skills.0.skill_id); point the message at the first row.
    const base = field.split('.')[0];
    const wrap = document.getElementById('wrap-' + base);
    if (!wrap) continue;
    wrap.classList.add('invalid');
    const el = document.createElement('span');
    el.className = 'err';
    el.textContent = Array.isArray(messages) ? messages[0] : messages;
    wrap.appendChild(el);
  }
}

function openCreateForm(){
  state.editingId = null;
  document.getElementById('form-title').textContent = 'Add Project';
  document.getElementById('form-submit').textContent = 'Create project';
  document.getElementById('project-form').reset();
  document.getElementById('skills-list').innerHTML = '';
  document.getElementById('roles-list').innerHTML = '';
  document.getElementById('eligibility-list').innerHTML = '';
  clearFormErrors();
  addSkillRow();
  addRoleRow();
  document.getElementById('form-overlay').classList.add('open');
}

async function openEditForm(id){
  setStatusLine('Loading project…');
  try {
    const res = await api('/admin/api/projects/' + id);
    const p = res.data;

    state.editingId = p.id;
    document.getElementById('form-title').textContent = 'Edit Project #' + p.id;
    document.getElementById('form-submit').textContent = 'Save changes';
    document.getElementById('project-form').reset();
    clearFormErrors();

    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el && value !== null && value !== undefined) el.value = value;
    };

    set('p-type', p.type);
    set('p-domain', p.domain);
    set('p-title', p.title);
    set('p-description', p.description);
    set('p-objectives', p.objectives);
    set('p-difficulty', p.difficulty);
    set('p-work-mode', p.work_mode);
    set('p-role', p.role);
    set('p-schedule', p.schedule);
    set('p-capacity', p.capacity);
    set('p-min-team-size', p.min_team_size);
    set('p-start-date', p.start_date);
    set('p-end-date', p.end_date);
    set('p-deadline', p.application_deadline);
    set('p-confidentiality', p.confidentiality);

    document.getElementById('skills-list').innerHTML = '';
    (p.required_skills || []).forEach(s => addSkillRow(s));

    document.getElementById('roles-list').innerHTML = '';
    // `project_roles` is the complete stored list (including deactivated roles);
    // `available_project_roles` is the learner-facing list that hides them.
    // Editing must start from the complete list, otherwise an inactive role
    // would vanish from the form and be silently dropped on save.
    (p.project_roles || p.available_project_roles || []).forEach(r => addRoleRow(r));

    document.getElementById('eligibility-list').innerHTML = '';
    const elig = p.eligibility_constraints;
    if (Array.isArray(elig)) elig.forEach(e => addEligibilityRow(e));

    // The repeatable sections are required by the backend, so an empty list
    // still gets one blank row rather than no row at all.
    if (!document.querySelectorAll('.skill-row').length) addSkillRow();
    if (!document.querySelectorAll('.role-row').length) addRoleRow();

    document.getElementById('detail-overlay').classList.remove('open');
    document.getElementById('form-overlay').classList.add('open');
    setStatusLine('');
  } catch (e){
    setStatusLine('Could not load project: ' + e.message, 'err');
  }
}

/**
 * Collect the form into the exact payload StoreProjectRequest /
 * UpdateProjectRequest validate. Empty text inputs are omitted rather than
 * sent as "", so a nullable field is cleared instead of stored as an empty
 * string, and numbers are sent as numbers so the numeric rules apply.
 */
function collectPayload(){
  const value = (id) => document.getElementById(id).value.trim();
  const num = (id) => {
    const raw = value(id);
    return raw === '' ? null : Number(raw);
  };
  const orNull = (v) => (v === '' ? null : v);

  const payload = {
    type: value('p-type'),
    title: value('p-title'),
    domain: orNull(value('p-domain')),
    description: orNull(value('p-description')),
    objectives: orNull(value('p-objectives')),
    difficulty: num('p-difficulty'),
    work_mode: orNull(value('p-work-mode')),
    role: orNull(value('p-role')),
    schedule: orNull(value('p-schedule')),
    capacity: num('p-capacity'),
    min_team_size: num('p-min-team-size'),
    start_date: orNull(value('p-start-date')),
    end_date: orNull(value('p-end-date')),
    application_deadline: orNull(value('p-deadline')),
    confidentiality: orNull(value('p-confidentiality')),
  };

  // Drop nulls so an omitted field is genuinely absent - the backend applies
  // its own default and the update path keeps the stored value.
  const cleaned = {};
  for (const [k, v] of Object.entries(payload)){
    if (k === 'type' || k === 'title'){ cleaned[k] = v; continue; }
    if (v !== null && v !== '') cleaned[k] = v;
  }

  const skills = [...document.querySelectorAll('.skill-row')]
    .map(row => ({
      skill_id: Number(row.querySelector('.sk-id').value) || null,
      minimum_level: row.querySelector('.sk-level').value === '' ? null : Number(row.querySelector('.sk-level').value),
      is_critical_entry: row.querySelector('.sk-critical').checked,
    }))
    .filter(s => s.skill_id !== null)
    .map(s => {
      const out = { skill_id: s.skill_id };
      if (s.minimum_level !== null) out.minimum_level = s.minimum_level;
      if (s.is_critical_entry) out.is_critical_entry = true;
      return out;
    });

  const roles = [...document.querySelectorAll('.role-row')]
    .map(row => ({
      title: row.querySelector('.rl-title').value.trim(),
      description: row.querySelector('.rl-desc').value.trim() || null,
    }))
    .filter(r => r.title !== '')
    .map(r => (r.description ? r : { title: r.title }));

  const eligibility = [...document.querySelectorAll('.el-row')]
    .map(row => ({
      constraint_type: row.querySelector('.el-type').value,
      value: row.querySelector('.el-value').value.trim(),
    }))
    .filter(e => e.value !== '');

  if (skills.length) payload.required_skills = skills;
  if (roles.length) payload.roles = roles;
  if (eligibility.length) payload.eligibility_constraints = eligibility;

  return { cleaned, skills, roles, eligibility };
}

async function submitForm(event){
  event.preventDefault();
  const btn = document.getElementById('form-submit');
  const original = btn.textContent;
  clearFormErrors();

  const { cleaned, skills, roles, eligibility } = collectPayload();

  // The repeatable sections are only sent when they have content, so merge
  // them onto the scalar payload rather than replacing it.
  const body = { ...cleaned };
  if (skills.length) body.required_skills = skills;
  if (roles.length) body.roles = roles;
  if (eligibility.length) body.eligibility_constraints = eligibility;

  btn.disabled = true;
  btn.textContent = state.editingId ? 'Updating…' : 'Creating…';

  try {
    if (state.editingId){
      await api('/admin/api/projects/' + state.editingId, {
        method: 'PATCH',
        body: JSON.stringify(body),
      });
      toast('Project updated.', 'ok');
    } else {
      await api('/admin/api/projects', {
        method: 'POST',
        body: JSON.stringify(body),
      });
      toast('Project created.', 'ok');
    }

    document.getElementById('form-overlay').classList.remove('open');
    await reload();
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

// ── Lifecycle actions ─────────────────────────────────────────────────────
const ACTION_META = {
  submit: { path: 'submit', label: 'Submitting…', done: 'Project submitted for review.' },
  approve: { path: 'approve', label: 'Approving…', done: 'Project approved.' },
  'request-changes': { path: 'request-changes', label: 'Requesting changes…', done: 'Changes requested.' },
  reject: { path: 'reject', label: 'Rejecting…', done: 'Project rejected.' },
  open: { path: 'open', label: 'Opening…', done: 'Project opened.' },
};

async function runAction(key, id){
  const meta = ACTION_META[key];
  if (!meta) return;

  // Reject and request-changes are review decisions and may carry a reason.
  let reason = null;
  if (key === 'reject' || key === 'request-changes'){
    reason = window.prompt(
      key === 'reject' ? 'Reason for rejection (optional):' : 'What changes are needed? (optional):'
    );
    if (reason === null) return; // cancelled
  }

  toast(meta.label);
  try {
    await api(`/api/v1/projects/${id}/${meta.path}`, {
      method: 'POST',
      body: JSON.stringify(reason ? { reason } : {}),
    });
    toast(meta.done, 'ok');
    document.getElementById('detail-overlay').classList.remove('open');
    await reload();
  } catch (e){
    // The backend's refusal is the source of truth - show it verbatim.
    toast(e.message, 'err');
  }
}

// ── Delete (soft) ─────────────────────────────────────────────────────────
let confirmAction = null;

function askConfirm(opts){
  document.getElementById('confirm-title').textContent = opts.title;
  document.getElementById('confirm-text').innerHTML = opts.html;
  document.getElementById('confirm-ok').textContent = opts.okLabel || 'Delete';
  confirmAction = opts.onConfirm;
  document.getElementById('confirm-overlay').classList.add('open');
}

async function doDelete(id, title){
  askConfirm({
    title: 'Delete project?',
    html: `Delete <strong>${escapeHtml(title)}</strong>?<br><br>The project is marked <strong>cancelled</strong> rather than removed, so its applications, recommendations and audit history are preserved. This cannot be undone from here.`,
    okLabel: 'Delete',
    onConfirm: async () => {
      try {
        await api(`/admin/api/projects/${id}/cancel`, { method: 'POST', body: JSON.stringify({}) });
        toast('Project cancelled.', 'ok');
        await reload();
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
  searchTimer = setTimeout(() => { state.page = 1; loadProjects(); }, 320);
}

async function reload(){
  await loadProjects();
}

function loadSkills(){
  // Reference data comes from the real API; the form never hard-codes a list.
  // Returns { success, message, data: [ { id, name, ... } ] } - a flat
  // collection of active skills.
  //
  // NOTE: this is the SESSION-backed twin (/admin/api/skills/taxonomy), not the
  // canonical /api/v1/skills/taxonomy. The latter sits behind auth:sanctum and
  // needs a bearer token, which a browser session cannot provide - calling it
  // directly returned 401 and left the picker empty.
  return api('/admin/api/skills/taxonomy')
    .then(body => {
      const list = Array.isArray(body.data) ? body.data : [];
      state.skills = list
        .filter(s => s && s.id && s.name)
        .map(s => ({ id: s.id, name: s.name }));
    })
    .catch(() => { state.skills = []; });
}

document.addEventListener('DOMContentLoaded', () => {
  // Populate the status filter from the same list the backend validates.
  const statusSelect = document.getElementById('f-status');
  for (const [value, label] of Object.entries(STATUS_LABELS)){
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = label;
    statusSelect.appendChild(opt);
  }

  loadSkills().then(loadProjects);

  // Filters
  document.getElementById('f-q').addEventListener('input', (e) => {
    state.filters.q = e.target.value.trim();
    debounceLoad();
  });
  document.getElementById('f-type').addEventListener('change', (e) => {
    state.filters.type = e.target.value; state.page = 1; loadProjects();
  });
  document.getElementById('f-status').addEventListener('change', (e) => {
    state.filters.status = e.target.value; state.page = 1; loadProjects();
  });
  document.getElementById('f-difficulty').addEventListener('change', (e) => {
    state.filters.difficulty = e.target.value; state.page = 1; loadProjects();
  });
  document.getElementById('f-clear').addEventListener('click', () => {
    state.filters = { q: '', type: '', status: '', difficulty: '' };
    document.getElementById('f-q').value = '';
    document.getElementById('f-type').value = '';
    document.getElementById('f-status').value = '';
    document.getElementById('f-difficulty').value = '';
    state.page = 1;
    loadProjects();
  });

  // Top-bar search mirrors the filter box.
  document.getElementById('global-search').addEventListener('input', (e) => {
    state.filters.q = e.target.value.trim();
    document.getElementById('f-q').value = e.target.value;
    debounceLoad();
  });

  // Row actions (delegated)
  document.getElementById('rows').addEventListener('click', (e) => {
    const view = e.target.closest('[data-view]');
    if (view){ openDetail(view.dataset.view); return; }

    const edit = e.target.closest('[data-edit]');
    if (edit){ openEditForm(edit.dataset.edit); return; }

    const del = e.target.closest('[data-del]');
    if (del){ doDelete(del.dataset.del, del.dataset.title); }
  });

  // Detail lifecycle actions (delegated)
  document.getElementById('detail-body').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (btn) runAction(btn.dataset.action, btn.dataset.id);
  });

  document.getElementById('detail-edit').addEventListener('click', (e) => {
    if (e.target.dataset.edit) openEditForm(e.target.dataset.edit);
  });

  // Pagination (delegated)
  document.getElementById('pager-pages').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    loadProjects();
  });

  // Form
  document.getElementById('btn-add').addEventListener('click', openCreateForm);
  document.getElementById('project-form').addEventListener('submit', submitForm);
  document.getElementById('add-skill').addEventListener('click', () => addSkillRow());
  document.getElementById('add-role').addEventListener('click', () => addRoleRow());
  document.getElementById('add-eligibility').addEventListener('click', () => addEligibilityRow());

  // Remove buttons inside the repeatable rows (delegated)
  document.querySelectorAll('#skills-list, #roles-list, #eligibility-list').forEach(box => {
    box.addEventListener('click', (e) => {
      const rm = e.target.closest('.rm-row');
      if (rm) rm.closest('.field-row').remove();
    });
  });

  // Type hint
  document.getElementById('p-type').addEventListener('change', (e) => {
    document.getElementById('type-hint').textContent = e.target.value === 'company_sponsored'
      ? 'Company-sponsored projects are owned by a company representative.'
      : 'Simulation projects are SkillSpan-internal.';
  });

  // Modals: close buttons, backdrop, Escape
  document.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => document.getElementById(btn.dataset.close).classList.remove('open'));
  });
  document.querySelectorAll('.overlay').forEach(ov => {
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.classList.remove('open'); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open'));
  });

  // Confirm modal
  document.getElementById('confirm-ok').addEventListener('click', async () => {
    const fn = confirmAction;
    confirmAction = null;
    document.getElementById('confirm-overlay').classList.remove('open');
    if (fn) await fn();
  });
});
</script>

</body>
</html>
