<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — Specializations</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --nav-bg:#0E1830; --nav-bg-soft:#16223F; --nav-bg-hover:#1D2A4E;
    --nav-ink:#E6EBF5; --nav-ink-soft:#8A93AB; --nav-line:rgba(255,255,255,.06);
    --accent:#5EEAD4; --accent-soft:rgba(94,234,212,.14);
    --paper:#F4F5F8; --paper-raised:#FFFFFF; --ink:#1C2420; --ink-soft:#5B6560;
    --ink-faint:#8C9498; --line:#E5E2D9; --line-soft:#EEECE4;
    --teal:#2F6F5E; --teal-deep:#205043; --amber:#9C6B12; --amber-bg:#F6EBD6;
    --sage:#3C7A57; --sage-bg:#E3EFE6; --brick:#A23B33; --brick-bg:#F5E4E1;
    --info:#1E55A6; --info-bg:#E8EEFB; --violet:#5B4B9E; --violet-bg:#EDEAF8;
    --radius:14px; --radius-sm:10px;
    --shadow:0 1px 3px rgba(15,24,48,.05), 0 6px 20px rgba(15,24,48,.04);
    --sidebar-w:248px;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;height:100%;}
  body{background:var(--paper);color:var(--ink);font-family:'Inter', system-ui, -apple-system, sans-serif;line-height:1.55;-webkit-font-smoothing:antialiased;}
  ::placeholder{color:#A9A398;}
  .app{display:flex;min-height:100vh;}

  .sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--nav-bg);color:var(--nav-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;padding:22px 16px 18px;}
  .brand{display:flex;align-items:center;gap:10px;padding:4px 8px 22px;border-bottom:1px solid var(--nav-line);margin-bottom:16px;}
  .brand .bolt{width:30px;height:30px;border-radius:8px;background:var(--accent-soft);display:flex;align-items:center;justify-content:center;color:var(--accent);flex-shrink:0;}
  .brand .name{font-family:'Fraunces', serif;font-weight:600;font-size:19px;letter-spacing:-.01em;color:#fff;}
  .brand .name span{color:var(--accent);}
  .nav-section{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--nav-ink-soft);padding:14px 10px 6px;font-weight:600;}
  .nav{display:flex;flex-direction:column;gap:2px;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:9px;font-size:14px;color:var(--nav-ink-soft);text-decoration:none;cursor:pointer;transition:background .12s ease, color .12s ease;position:relative;}
  .nav-item .ic{width:18px;height:18px;display:flex;align-items:center;justify-content:center;color:inherit;}
  .nav-item:hover{background:var(--nav-bg-hover);color:var(--nav-ink);}
  .nav-item.active{background:var(--nav-bg-soft);color:#fff;font-weight:500;}
  .nav-item.active::before{content:'';position:absolute;left:-16px;top:50%;transform:translateY(-50%);width:3px;height:18px;border-radius:0 3px 3px 0;background:var(--accent);}
  .nav-item.disabled{cursor:not-allowed;opacity:.55;}
  .nav-item.disabled:hover{background:none;color:var(--nav-ink-soft);}
  .sidebar-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--nav-line);}
  .user-card{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;background:var(--nav-bg-soft);text-decoration:none;color:inherit;transition:background .15s ease;}
  .user-card:hover{background:var(--nav-bg-hover);}
  .user-card .avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;color:#fff;flex-shrink:0;overflow:hidden;background:linear-gradient(135deg,#5EEAD4,#2F6F5E);}
  .user-card .avatar img{width:100%;height:100%;object-fit:cover;display:block;}
  .user-card .meta{min-width:0;flex:1;}
  .user-card .meta .nm{font-size:13.5px;color:#fff;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .user-card .meta .rl{font-size:11.5px;color:var(--nav-ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  .main{flex:1;min-width:0;display:flex;flex-direction:column;}
  .topbar{background:var(--paper-raised);border-bottom:1px solid var(--line);padding:14px 30px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:10;}
  .topbar .crumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-faint);}
  .topbar .crumb .sep{opacity:.5;}
  .topbar .crumb .cur{color:var(--ink);font-weight:500;}
  .topbar-right{margin-left:auto;display:flex;align-items:center;gap:14px;}
  .topbar .search{position:relative;display:flex;align-items:center;}
  .topbar .search input{width:260px;border:1px solid var(--line);background:var(--paper);border-radius:9px;padding:8px 12px 8px 36px;font-size:13.5px;color:var(--ink);font-family:inherit;}
  .topbar .search .ic{position:absolute;left:11px;color:var(--ink-faint);}
  .logout-btn{border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);padding:8px 14px;border-radius:9px;font-size:13.5px;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;}
  .logout-btn:hover{border-color:var(--brick);color:var(--brick);}

  .content{padding:26px 30px 80px;max-width:1240px;width:100%;}
  .page-head{display:flex;align-items:flex-start;gap:20px;margin-bottom:22px;}
  .page-head .titles{flex:1;min-width:0;}
  .page-head h1{font-family:'Fraunces', serif;font-weight:500;font-size:30px;margin:0 0 6px;letter-spacing:-.015em;}
  .page-head p{margin:0;color:var(--ink-soft);font-size:14.5px;max-width:720px;}
  .btn-primary{background:var(--teal);color:#fff;border:none;border-radius:9px;padding:10px 18px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;}
  .btn-primary:hover{background:var(--teal-deep);}
  .btn-primary:disabled{opacity:.5;cursor:default;}

  .filters{background:var(--paper-raised);border:1px solid var(--line);border-radius:11px;padding:14px 16px;margin-bottom:14px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
  .filters .field{display:flex;flex-direction:column;gap:5px;}
  .filters label{font-size:12px;color:var(--ink-soft);font-weight:500;}
  .filters input,.filters select{border:1px solid var(--line);background:var(--paper);border-radius:8px;padding:8px 11px;font-size:13.5px;color:var(--ink);font-family:inherit;min-width:190px;}
  .btn-ghost{border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);padding:8px 14px;border-radius:8px;font-size:13.5px;cursor:pointer;font-family:inherit;}
  .btn-ghost:hover{border-color:var(--teal);color:var(--teal-deep);}
  #status-line{font-size:13px;color:var(--ink-soft);margin-bottom:12px;min-height:18px;}
  #status-line.err{color:var(--brick);}

  .table-wrap{background:var(--paper-raised);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
  .table-scroll{overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:900px;}
  thead th{text-align:left;font-weight:600;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);padding:12px 14px;border-bottom:1px solid var(--line);background:#FBFAF7;white-space:nowrap;}
  tbody td{padding:13px 14px;border-bottom:1px solid var(--line-soft);vertical-align:top;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:#FCFCFA;}
  td.name-cell .nm{font-weight:500;}
  td.desc-cell{color:var(--ink-soft);max-width:380px;}
  td.actions-cell{white-space:nowrap;text-align:right;}
  .pill{font-size:11.5px;padding:3px 10px;border-radius:100px;font-weight:500;white-space:nowrap;display:inline-block;}
  .pill.active{background:var(--sage-bg);color:var(--sage);}
  .pill.inactive{background:#ECEAE4;color:var(--ink-soft);}
  .pill.free{background:var(--violet-bg);color:var(--violet);}
  .pill.count{background:var(--info-bg);color:var(--info);}
  .row-btn{border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);border-radius:8px;padding:5px 11px;font-size:12.5px;cursor:pointer;font-family:inherit;margin-left:6px;}
  .row-btn:hover{border-color:var(--teal);color:var(--teal-deep);}
  .row-btn.danger:hover{border-color:var(--brick);color:var(--brick);}

  .pager{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-top:1px solid var(--line);flex-wrap:wrap;}
  .pager .info{font-size:13px;color:var(--ink-soft);}
  .pager .pages{display:flex;gap:6px;align-items:center;}
  .pager button{border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);border-radius:8px;min-width:34px;height:34px;font-size:13px;cursor:pointer;font-family:inherit;padding:0 10px;}
  .pager button:hover:not(:disabled){border-color:var(--teal);color:var(--teal-deep);}
  .pager button:disabled{opacity:.45;cursor:default;}
  .pager button.cur{background:var(--teal);border-color:var(--teal);color:#fff;}
  .empty-state{text-align:center;padding:56px 20px;color:var(--ink-soft);}
  .empty-state .big{font-family:'Fraunces',serif;font-size:20px;color:var(--ink);margin-bottom:6px;}

  .overlay{position:fixed;inset:0;background:rgba(14,24,48,.45);display:none;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;z-index:50;}
  .overlay.open{display:flex;}
  .modal{background:var(--paper-raised);border-radius:var(--radius);width:100%;max-width:640px;box-shadow:0 20px 60px rgba(15,24,48,.25);}
  .modal.wide{max-width:760px;}
  .modal.narrow{max-width:460px;}
  .modal-head{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);}
  .modal-head h2{font-family:'Fraunces',serif;font-weight:500;font-size:20px;margin:0;flex:1;}
  .modal-head .close{border:none;background:none;color:var(--ink-faint);cursor:pointer;font-size:22px;line-height:1;padding:4px 8px;border-radius:6px;}
  .modal-head .close:hover{background:var(--paper);color:var(--ink);}
  .modal-body{padding:20px 22px;max-height:64vh;overflow-y:auto;}
  .modal-foot{display:flex;gap:10px;justify-content:flex-end;padding:16px 22px;border-top:1px solid var(--line);}
  .modal-foot .spacer{flex:1;}
  .btn-secondary{border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);border-radius:9px;padding:9px 18px;font-size:14px;cursor:pointer;font-family:inherit;}
  .btn-secondary:hover{border-color:var(--ink-faint);color:var(--ink);}
  .btn-danger{background:var(--brick);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:14px;cursor:pointer;font-family:inherit;}
  .btn-danger:hover{background:#8A2F28;}
  .field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
  .field-row.one{grid-template-columns:1fr;}
  .field{display:flex;flex-direction:column;gap:5px;}
  .field label{font-size:12.5px;color:var(--ink-soft);font-weight:500;}
  .field label .req{color:var(--brick);}
  .field input,.field select,.field textarea{border:1px solid var(--line);background:var(--paper);border-radius:8px;padding:9px 11px;font-size:13.5px;color:var(--ink);font-family:inherit;width:100%;}
  .field textarea{resize:vertical;min-height:70px;}
  .field .hint{font-size:11.5px;color:var(--ink-faint);}
  .field.invalid input,.field.invalid select,.field.invalid textarea{border-color:var(--brick);background:var(--brick-bg);}
  .field .err{font-size:11.5px;color:var(--brick);}

  .picker-search{width:100%;margin-bottom:10px;}
  .picker-list{border:1px solid var(--line);border-radius:10px;max-height:340px;overflow-y:auto;}
  .picker-row{display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--line-soft);font-size:13.5px;}
  .picker-row:last-child{border-bottom:none;}
  .picker-row:hover{background:#FCFCFA;}
  .picker-row input{width:16px;height:16px;flex-shrink:0;}
  .picker-row .st{margin-left:auto;font-size:11px;color:var(--ink-faint);}
  .hidden{display:none !important;}

  #toasts{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:10px;z-index:80;}
  .toast{background:#12203C;color:#F2F5FA;border-radius:10px;padding:12px 16px;font-size:13.5px;max-width:380px;box-shadow:0 10px 30px rgba(15,24,48,.3);}
  .toast.err{background:var(--brick);}
  .toast.ok{background:var(--teal-deep);}

  @media (max-width:880px){
    .sidebar{position:fixed;left:-260px;height:auto;z-index:30;transition:left .2s ease;}
    .sidebar.open{left:0;box-shadow:0 0 60px rgba(0,0,0,.4);}
    .main{width:100%;}
    .content{padding:20px 16px 60px;}
    .topbar{padding:12px 16px;}
    .topbar .search input{width:150px;}
    .field-row{grid-template-columns:1fr;}
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
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M13 2L4.5 13.5H11l-2 8.5 9-12.5H11.5L13 2Z" fill="currentColor"/></svg>
      </span>
      <span class="name">Skill<span>Span</span></span>
    </div>

    <div class="nav-section">Admin</div>
    <nav class="nav">
      <a class="nav-item" href="{{ route('admin.organizations') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 13h6V5H4v8Zm0 6h6v-4H4v4Zm10 0h6v-8h-6v8Zm0-12v4h6V7h-6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
        Dashboard
      </a>
      <a class="nav-item" href="{{ route('admin.organizations') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
        Organizations
      </a>
      <a class="nav-item" href="{{ route('admin.projects') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
        Projects
      </a>
      <a class="nav-item" href="{{ route('admin.support') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
        Support
      </a>
      <a class="nav-item disabled" title="Coming soon">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M16 3a5 5 0 0 1 4 8.06V20l-3-2-3 2-3-2-3 2V11.06A5 5 0 0 1 8 3a5 5 0 0 1 8 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
        Users
      </a>
      <a class="nav-item active" href="{{ route('admin.specializations') }}" aria-current="page">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="19" cy="18" r="2" stroke="currentColor" stroke-width="1.6"/></svg></span>
        Specializations
      </a>
      <a class="nav-item" href="{{ route('admin.questions') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.8.9c0 1.6-2.4 2-2.4 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor"/></svg></span>
        Questions
      </a>
      <a class="nav-item" href="{{ route('admin.profile') }}">
        <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
        Profile
      </a>
    </nav>

    @include('admin.partials.user-card')
  </aside>

  <!-- ============================= Main ============================= -->
  <main class="main">
    <header class="topbar">
      <div class="crumb">
        <span>SkillSpan</span><span class="sep">/</span>
        <span>Admin</span><span class="sep">/</span>
        <span class="cur">Specializations</span>
      </div>
      <div class="topbar-right">
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
          <h1>Specializations</h1>
          <p>Manage the specializations and the career roles each one offers. A career role is a single record
             shared by every specialization that lists it, and the <strong>Self-Learning / Free Track</strong>
             always offers every role.</p>
        </div>
        <button class="btn-primary" id="btn-add">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Add Specialization
        </button>
      </header>

      <form class="filters" onsubmit="return false;">
        <div class="field">
          <label for="f-search">Search</label>
          <input type="search" id="f-search" placeholder="Name or description…">
        </div>
        <div class="field">
          <label for="f-status">Status</label>
          <select id="f-status">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
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
                <th>Name</th>
                <th>Description</th>
                <th style="width:140px">Career Roles</th>
                <th style="width:120px">Status</th>
                <th style="text-align:right;width:360px">Actions</th>
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
      <h2 id="form-title">Add Specialization</h2>
      <button class="close" data-close="form-overlay" aria-label="Close">&times;</button>
    </div>
    <form id="specialization-form">
      <div class="modal-body">
        <div class="field-row one">
          <div class="field" id="wrap-name">
            <label for="s-name">Name <span class="req">*</span></label>
            <input type="text" id="s-name" maxlength="191" required placeholder="e.g. Web Development">
          </div>
        </div>
        <div class="field-row one">
          <div class="field" id="wrap-description">
            <label for="s-description">Description</label>
            <textarea id="s-description" maxlength="2000" placeholder="Short description shown to administrators."></textarea>
          </div>
        </div>
        <div class="field-row one">
          <div class="field">
            <label for="s-status">Status</label>
            <select id="s-status">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn-secondary" data-close="form-overlay">Cancel</button>
        <button type="submit" class="btn-primary" id="form-submit">Create specialization</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================= Manage career roles modal ============================= -->
<div class="overlay" id="roles-overlay">
  <div class="modal wide">
    <div class="modal-head">
      <h2 id="roles-title">Manage Career Roles</h2>
      <button class="close" data-close="roles-overlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <input type="search" id="roles-search" class="picker-search" placeholder="Search career roles…">
      <div class="picker-list" id="roles-list"></div>
      <p class="field hint" style="margin:10px 0 0;">Check the roles this specialization offers. A role can belong to several specializations.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn-ghost" id="btn-create-role">+ New Career Role</button>
      <span class="spacer"></span>
      <button type="button" class="btn-secondary" data-close="roles-overlay">Cancel</button>
      <button type="button" class="btn-primary" id="roles-save">Save</button>
    </div>
  </div>
</div>

<!-- ============================= Create career role modal ============================= -->
<div class="overlay" id="role-form-overlay">
  <div class="modal wide">
    <div class="modal-head">
      <h2>New Career Role</h2>
      <button class="close" data-close="role-form-overlay" aria-label="Close">&times;</button>
    </div>
    <form id="role-form">
      <div class="modal-body">
        <div class="field-row one">
          <div class="field" id="wrap-title">
            <label for="r-title">Title <span class="req">*</span></label>
            <input type="text" id="r-title" maxlength="191" required placeholder="e.g. Web3 Developer">
          </div>
        </div>
        <div class="field-row one">
          <div class="field">
            <label>Required skills</label>
            <input type="search" id="skills-search" class="picker-search" placeholder="Search skills…">
            <div class="picker-list" id="skills-list" style="max-height:240px"></div>
            <span class="hint" id="skills-hint">Select the skills this role requires. A role with no skills cannot be assessed.</span>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn-secondary" data-close="role-form-overlay">Cancel</button>
        <button type="submit" class="btn-primary" id="role-form-submit">Create role</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================= View modal ============================= -->
<div class="overlay" id="view-overlay">
  <div class="modal wide">
    <div class="modal-head">
      <h2 id="view-title">Specialization</h2>
      <button class="close" data-close="view-overlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="field-row one">
        <div class="field">
          <label>Description</label>
          <p id="view-description" style="margin:0;color:var(--ink-soft)"></p>
        </div>
      </div>
      <div class="field-row">
        <div class="field"><label>Status</label><p id="view-status" style="margin:0"></p></div>
        <div class="field"><label>Career Roles</label><p id="view-count" style="margin:0"></p></div>
      </div>
      <div class="field-row one">
        <div class="field">
          <label>Linked career roles</label>
          <div id="view-roles" style="display:flex;gap:6px;flex-wrap:wrap"></div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="spacer"></span>
      <button type="button" class="btn-secondary" data-close="view-overlay">Close</button>
    </div>
  </div>
</div>

<!-- ============================= Confirm modal ============================= -->
<div class="overlay" id="confirm-overlay">
  <div class="modal narrow">
    <div class="modal-head"><h2 id="confirm-title">Are you sure?</h2></div>
    <div class="modal-body"><p id="confirm-text" style="margin:0;font-size:14px;"></p></div>
    <div class="modal-foot">
      <span class="spacer"></span>
      <button class="btn-secondary" data-close="confirm-overlay">Cancel</button>
      <button class="btn-danger" id="confirm-ok">Delete</button>
    </div>
  </div>
</div>

<div id="toasts"></div>

<script>
const state = {
  page: 1, perPage: 15, lastPage: 1, total: 0,
  rows: [], search: '', status: '',
  editingId: null, manageId: null, confirmAction: null,
};

function setStatusLine(text, kind){ const el = document.getElementById('status-line'); el.textContent = text || ''; el.className = kind || ''; }
function toast(message, kind){
  const box = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = 'toast' + (kind ? ' ' + kind : '');
  el.textContent = message;
  box.appendChild(el);
  setTimeout(() => el.remove(), 4200);
}
function escapeHtml(v){ return v === null || v === undefined ? '' : String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

let CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
async function refreshCsrfToken(){
  try {
    const res = await fetch(window.location.pathname, { credentials:'same-origin', headers:{ 'Accept':'text/html','X-Requested-With':'XMLHttpRequest' } });
    if (!res.ok) return false;
    const html = await res.text();
    const meta = html.match(/<meta[^>]*name="csrf-token"[^>]*>/i);
    if (meta){
      const c = meta[0].match(/content="([^"]*)"/i);
      if (c && c[1]){
        CSRF_TOKEN = c[1].replace(/&quot;/g,'"').replace(/&#039;/g,"'").replace(/&amp;/g,'&');
        document.querySelector('meta[name="csrf-token"]').setAttribute('content', CSRF_TOKEN);
        return true;
      }
    }
    return false;
  } catch(e){ return false; }
}
async function api(path, options = {}, isRetry = false){
  const res = await fetch(path, { ...options, credentials:'same-origin', headers:{ 'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN, ...(options.headers||{}) } });
  if (res.status === 419 && !isRetry){ if (await refreshCsrfToken()) return api(path, options, true); }
  if (res.status === 401){ window.location.href = '/admin/login'; throw new Error('Your session has ended.'); }
  if (res.status === 419) throw new Error('Your session token could not be verified. Please sign in again.');
  const body = await res.json().catch(() => ({}));
  if (!res.ok){
    const err = new Error(body.message || `${res.status} ${res.statusText}`);
    err.status = res.status; err.code = body.code; err.errors = body.errors || null;
    throw err;
  }
  return body;
}

// ── List ──────────────────────────────────────────────────────────────────
async function loadSpecializations(){
  setStatusLine('Loading specializations…');
  const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
  if (state.search) params.set('q', state.search);
  if (state.status) params.set('status', state.status);

  try {
    const body = await api('/admin/api/specializations?' + params.toString());
    const data = body.data || {};
    state.rows = data.data || [];
    state.total = data.total || 0;
    state.lastPage = data.last_page || 1;
    renderRows(state.rows);
    renderPager(data);
    setStatusLine(state.total === 0 ? 'No specializations match.' : '');
  } catch (e){
    setStatusLine('Could not load specializations: ' + e.message, 'err');
    document.getElementById('rows').innerHTML = `<tr><td colspan="5"><div class="empty-state"><div class="big">Could not load</div></div></td></tr>`;
    document.getElementById('pager').style.display = 'none';
  }
}

function renderRows(rows){
  const tbody = document.getElementById('rows');
  if (!rows.length){
    tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state"><div class="big">No specializations</div></div></td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map(s => {
    const status = s.is_active ? '<span class="pill active">Active</span>' : '<span class="pill inactive">Inactive</span>';
    const free = s.is_free_track ? ' <span class="pill free">Free Track</span>' : '';
    const manage = s.is_free_track
      ? ''
      : `<button class="row-btn" data-manage="${s.id}">Manage Roles</button>`;
    const toggle = s.is_active
      ? `<button class="row-btn" data-deactivate="${s.id}">Deactivate</button>`
      : `<button class="row-btn" data-activate="${s.id}">Activate</button>`;

    return `<tr>
      <td class="name-cell"><div class="nm">${escapeHtml(s.name)}</div></td>
      <td class="desc-cell">${s.description ? escapeHtml(s.description) : '<span style="color:var(--ink-faint)">—</span>'}</td>
      <td><span class="pill count">${s.career_roles_count} role${s.career_roles_count === 1 ? '' : 's'}</span></td>
      <td>${status}${free}</td>
      <td class="actions-cell">
        <button class="row-btn" data-view="${s.id}">View</button>
        <button class="row-btn" data-edit="${s.id}">Edit</button>
        ${manage}
        ${toggle}
        <button class="row-btn danger" data-del="${s.id}">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

function renderPager(data){
  const pager = document.getElementById('pager');
  if (!data || !data.total){ pager.style.display = 'none'; return; }
  pager.style.display = '';
  document.getElementById('pager-info').textContent = `Showing ${data.from ?? 0}–${data.to ?? 0} of ${data.total}`;
  const current = data.current_page || 1, last = data.last_page || 1;
  let html = `<button ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}">Prev</button>`;
  const start = Math.max(1, current - 2), end = Math.min(last, start + 4);
  for (let i = start; i <= end; i++) html += `<button class="${i === current ? 'cur' : ''}" data-page="${i}">${i}</button>`;
  if (end < last) html += `<span style="color:var(--ink-faint)">…</span><button data-page="${last}">${last}</button>`;
  html += `<button ${current >= last ? 'disabled' : ''} data-page="${current + 1}">Next</button>`;
  document.getElementById('pager-pages').innerHTML = html;
}

// ── Create / edit ─────────────────────────────────────────────────────────
function clearFormErrors(){
  document.querySelectorAll('#specialization-form .field').forEach(f => { f.classList.remove('invalid'); const e = f.querySelector('.err'); if (e) e.remove(); });
}
function showFormErrors(errors){
  clearFormErrors();
  if (!errors) return;
  for (const [field, messages] of Object.entries(errors)){
    const wrap = document.getElementById('wrap-' + field.split('.')[0]);
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
  document.getElementById('form-title').textContent = 'Add Specialization';
  document.getElementById('form-submit').textContent = 'Create specialization';
  document.getElementById('specialization-form').reset();
  document.getElementById('s-status').value = '1';
  clearFormErrors();
  document.getElementById('form-overlay').classList.add('open');
}
function openEditForm(id){
  const s = state.rows.find(r => String(r.id) === String(id));
  if (!s){ toast('That specialization is not on this page.', 'err'); return; }
  state.editingId = s.id;
  document.getElementById('form-title').textContent = 'Edit Specialization';
  document.getElementById('form-submit').textContent = 'Save changes';
  document.getElementById('s-name').value = s.name;
  document.getElementById('s-description').value = s.description || '';
  document.getElementById('s-status').value = s.is_active ? '1' : '0';
  clearFormErrors();
  document.getElementById('form-overlay').classList.add('open');
}
async function submitForm(event){
  event.preventDefault();
  const btn = document.getElementById('form-submit');
  const original = btn.textContent;
  clearFormErrors();
  const payload = {
    name: document.getElementById('s-name').value.trim(),
    description: document.getElementById('s-description').value.trim() || null,
    is_active: document.getElementById('s-status').value === '1',
  };
  btn.disabled = true; btn.textContent = state.editingId ? 'Saving…' : 'Creating…';
  try {
    if (state.editingId){
      await api('/admin/api/specializations/' + state.editingId, { method:'PATCH', body: JSON.stringify(payload) });
      toast('Specialization updated.', 'ok');
    } else {
      await api('/admin/api/specializations', { method:'POST', body: JSON.stringify(payload) });
      toast('Specialization created.', 'ok');
    }
    document.getElementById('form-overlay').classList.remove('open');
    await loadSpecializations();
  } catch (e){
    if (e.status === 422 && e.errors){ showFormErrors(e.errors); toast('Please fix the highlighted fields.', 'err'); }
    else toast(e.message, 'err');
  } finally { btn.disabled = false; btn.textContent = original; }
}

async function setActive(id, active){
  try {
    await api(`/admin/api/specializations/${id}/${active ? 'activate' : 'deactivate'}`, { method:'POST', body: JSON.stringify({}) });
    toast(active ? 'Specialization activated.' : 'Specialization deactivated.', 'ok');
    await loadSpecializations();
  } catch (e){ toast(e.message, 'err'); }
}

function doDelete(id){
  const s = state.rows.find(r => String(r.id) === String(id));
  askConfirm({
    title: 'Delete specialization?',
    html: `Delete <strong>${escapeHtml(s ? s.name : 'this specialization')}</strong>?<br><br>A specialization that still has career roles linked cannot be deleted — deactivate it instead.`,
    okLabel: 'Delete',
    onConfirm: async () => {
      try {
        await api('/admin/api/specializations/' + id, { method:'DELETE' });
        toast('Specialization deleted.', 'ok');
        await loadSpecializations();
      } catch (e){ toast(e.message, 'err'); }
    },
  });
}

// ── View ──────────────────────────────────────────────────────────────────
async function openView(id){
  const s = state.rows.find(r => String(r.id) === String(id));
  if (!s) return;

  document.getElementById('view-title').textContent = s.name;
  document.getElementById('view-description').textContent = s.description || 'No description.';
  document.getElementById('view-status').innerHTML = s.is_active
    ? '<span class="pill active">Active</span>'
    : '<span class="pill inactive">Inactive</span>';
  document.getElementById('view-count').textContent = s.career_roles_count + ' linked';
  document.getElementById('view-overlay').classList.add('open');

  const box = document.getElementById('view-roles');

  if (s.is_free_track){
    box.innerHTML = '<span class="pill free">Every career role is available</span>';
    return;
  }

  box.innerHTML = '<span style="color:var(--ink-faint)">Loading…</span>';
  try {
    const body = await api('/admin/api/specializations/' + id + '/career-roles');
    const attached = (body.data || []).filter(r => r.attached);
    box.innerHTML = attached.length
      ? attached.map(r => `<span class="pill count">${escapeHtml(r.title)}</span>`).join('')
      : '<span style="color:var(--ink-faint)">No career roles linked.</span>';
  } catch (e){
    box.innerHTML = `<span style="color:var(--brick)">${escapeHtml(e.message)}</span>`;
  }
}

// ── Manage career roles ───────────────────────────────────────────────────
async function openManage(id){
  const s = state.rows.find(r => String(r.id) === String(id));
  state.manageId = id;
  document.getElementById('roles-title').textContent = 'Manage Career Roles — ' + (s ? s.name : '');
  document.getElementById('roles-search').value = '';
  document.getElementById('roles-overlay').classList.add('open');
  await loadRoleOptions();
}
async function loadRoleOptions(){
  const list = document.getElementById('roles-list');
  list.innerHTML = '<div class="picker-row">Loading…</div>';
  const q = document.getElementById('roles-search').value.trim();
  const params = q ? '?q=' + encodeURIComponent(q) : '';
  try {
    const body = await api(`/admin/api/specializations/${state.manageId}/career-roles${params}`);
    const roles = body.data || [];
    list.innerHTML = roles.length
      ? roles.map(r => `<label class="picker-row">
          <input type="checkbox" value="${r.id}" ${r.attached ? 'checked' : ''}>
          <span>${escapeHtml(r.title)}</span>
          <span class="st">${escapeHtml(r.status)}</span>
        </label>`).join('')
      : '<div class="picker-row">No career roles found.</div>';
  } catch (e){
    list.innerHTML = `<div class="picker-row">${escapeHtml(e.message)}</div>`;
  }
}
async function saveRoles(){
  const ids = [...document.querySelectorAll('#roles-list input[type=checkbox]:checked')].map(i => Number(i.value));
  try {
    await api(`/admin/api/specializations/${state.manageId}/career-roles`, { method:'PUT', body: JSON.stringify({ role_ids: ids }) });
    toast('Career roles updated.', 'ok');
    document.getElementById('roles-overlay').classList.remove('open');
    await loadSpecializations();
  } catch (e){ toast(e.message, 'err'); }
}

// ── Create career role ────────────────────────────────────────────────────
async function openCreateRole(){
  document.getElementById('role-form').reset();
  document.getElementById('skills-list').innerHTML = '';
  document.getElementById('role-form-overlay').classList.add('open');
  await loadSkillOptions();
}
async function loadSkillOptions(){
  const list = document.getElementById('skills-list');
  list.innerHTML = '<div class="picker-row">Loading…</div>';
  const q = document.getElementById('skills-search').value.trim();
  const params = q ? '?q=' + encodeURIComponent(q) : '';
  try {
    const body = await api('/admin/api/specializations/skills' + params);
    const skills = body.data || [];
    const checked = new Set([...document.querySelectorAll('#skills-list input:checked')].map(i => i.value));
    list.innerHTML = skills.length
      ? skills.map(s => `<label class="picker-row">
          <input type="checkbox" value="${s.id}" ${checked.has(String(s.id)) ? 'checked' : ''}>
          <span>${escapeHtml(s.name)}</span>
        </label>`).join('')
      : '<div class="picker-row">No skills found.</div>';
  } catch (e){
    list.innerHTML = `<div class="picker-row">${escapeHtml(e.message)}</div>`;
  }
}
async function submitRole(event){
  event.preventDefault();
  const btn = document.getElementById('role-form-submit');
  const original = btn.textContent;
  const title = document.getElementById('r-title').value.trim();
  const skillIds = [...document.querySelectorAll('#skills-list input:checked')].map(i => Number(i.value));
  if (!skillIds.length && !confirm('This role has no required skills, so it cannot be assessed yet. Create it anyway?')) return;
  btn.disabled = true; btn.textContent = 'Creating…';
  try {
    const body = await api('/admin/api/specializations/career-roles', { method:'POST', body: JSON.stringify({ title, skill_ids: skillIds }) });
    toast('Career role created.', 'ok');
    document.getElementById('role-form-overlay').classList.remove('open');
    // Refresh the manage list so the new role is immediately selectable.
    if (state.manageId) await loadRoleOptions();
  } catch (e){
    toast(e.message, 'err');
  } finally { btn.disabled = false; btn.textContent = original; }
}

// ── Confirm ───────────────────────────────────────────────────────────────
function askConfirm(opts){
  document.getElementById('confirm-title').textContent = opts.title;
  document.getElementById('confirm-text').innerHTML = opts.html;
  document.getElementById('confirm-ok').textContent = opts.okLabel || 'Delete';
  state.confirmAction = opts.onConfirm;
  document.getElementById('confirm-overlay').classList.add('open');
}

// ── Wiring ────────────────────────────────────────────────────────────────
let searchTimer = null;
document.addEventListener('DOMContentLoaded', () => {
  loadSpecializations();

  document.getElementById('f-search').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.search = e.target.value.trim(); state.page = 1; loadSpecializations(); }, 320);
  });
  document.getElementById('f-status').addEventListener('change', (e) => { state.status = e.target.value; state.page = 1; loadSpecializations(); });
  document.getElementById('f-clear').addEventListener('click', () => {
    document.getElementById('f-search').value = '';
    document.getElementById('f-status').value = '';
    state.search = ''; state.status = ''; state.page = 1;
    loadSpecializations();
  });

  document.getElementById('rows').addEventListener('click', (e) => {
    const view = e.target.closest('[data-view]');
    if (view){ openView(view.dataset.view); return; }
    const edit = e.target.closest('[data-edit]');
    if (edit){ openEditForm(edit.dataset.edit); return; }
    const manage = e.target.closest('[data-manage]');
    if (manage){ openManage(manage.dataset.manage); return; }
    const deact = e.target.closest('[data-deactivate]');
    if (deact){ setActive(deact.dataset.deactivate, false); return; }
    const act = e.target.closest('[data-activate]');
    if (act){ setActive(act.dataset.activate, true); return; }
    const del = e.target.closest('[data-del]');
    if (del){ doDelete(del.dataset.del); }
  });

  document.getElementById('pager-pages').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    loadSpecializations();
  });

  document.getElementById('btn-add').addEventListener('click', openCreateForm);
  document.getElementById('specialization-form').addEventListener('submit', submitForm);

  let rolesTimer = null;
  document.getElementById('roles-search').addEventListener('input', () => { clearTimeout(rolesTimer); rolesTimer = setTimeout(loadRoleOptions, 300); });
  document.getElementById('roles-save').addEventListener('click', saveRoles);
  document.getElementById('btn-create-role').addEventListener('click', openCreateRole);

  let skillsTimer = null;
  document.getElementById('skills-search').addEventListener('input', () => { clearTimeout(skillsTimer); skillsTimer = setTimeout(loadSkillOptions, 300); });
  document.getElementById('role-form').addEventListener('submit', submitRole);

  document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => document.getElementById(btn.dataset.close).classList.remove('open')));
  document.querySelectorAll('.overlay').forEach(ov => ov.addEventListener('click', (e) => { if (e.target === ov) ov.classList.remove('open'); }));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open')); });

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
