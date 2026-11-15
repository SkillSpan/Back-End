<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkillSpan Admin · Sign in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{

    --cw-navy-950:#020720;
    --cw-navy-900:#060D2E;
    --cw-navy-800:#0D1A4A;
    --cw-blue:#1667E0;
    --cw-blue-700:#0F52BA;
    --cw-blue-100:#D0E3FA;
    --cw-blue-50:#E2EEFC;
    --cw-gold:#F2B544;
    --cw-gray:#5A6482;
    --cw-gray-bg:#EEF1F6;
    --cw-soft:#F7FAFE;

    --nav-bg:#060D2E;
    --nav-bg-soft:#0D1A4A;
    --nav-bg-hover:rgba(255,255,255,.05);
    --nav-ink:#E8EEFF;
    --nav-ink-soft:#AAB5D6;
    --nav-line:rgba(255,255,255,.07);
    --accent:#3D8BFF;
    --accent-soft:rgba(22,103,224,.18);

    --paper:#F0F6FC;
    --paper-raised:#FFFFFF;
    --ink:#05103D;
    --ink-soft:#5A6482;
    --ink-faint:#98A1BA;
    --line:#E4EBF5;
    --line-soft:#EEF2F8;

    --teal:#1667E0;
    --teal-deep:#0F52BA;
    --amber:#9A5B00;
    --amber-bg:#FFF3DF;
    --sage:#15925A;
    --sage-bg:#E5F6EE;
    --brick:#D13B3B;
    --brick-bg:#FDECEC;
    --info:#0F52BA;
    --info-bg:#E2EEFC;
    --violet:#5B5BD6;
    --violet-bg:#EEEEFD;

    --radius:12px;
    --radius-sm:10px;
    --shadow:0 1px 2px rgba(5,16,61,.04), 0 6px 20px -8px rgba(5,16,61,.08);
    --sidebar-w:248px;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:40px 20px;
    background:var(--nav-bg);
    color:var(--ink);
    font-family:'Inter', system-ui, -apple-system, sans-serif;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
  }
  .shell{
    width:100%;
    max-width:440px;
    background:var(--paper-raised);
    border:1px solid rgba(255,255,255,.06);
    border-radius:18px;
    padding:38px 36px 32px;
    box-shadow:0 24px 60px rgba(0,0,0,.35);
  }
  .brand{
    display:flex;align-items:center;gap:10px;
    margin-bottom:26px;
  }
  .brand .bolt{
    width:34px;height:34px;border-radius:9px;
    background:var(--accent-soft);
    display:flex;align-items:center;justify-content:center;
    color:var(--accent);
  }
  .brand .name{
    font-family:inherit;font-weight:600;font-size:22px;
    letter-spacing:-.01em;color:var(--nav-bg);
  }
  .brand .name span{color:var(--teal);}
  h1{
    font-family:inherit;font-weight:600;font-size:26px;
    margin:0 0 6px;letter-spacing:-.01em;
  }
  .sub{margin:0 0 24px;font-size:14px;color:var(--ink-soft);}
  .field{margin-bottom:16px;}
  .field label{
    display:block;font-size:13px;color:var(--ink-soft);
    margin-bottom:6px;font-weight:500;
  }
  .field input{
    width:100%;
    border:1px solid var(--line);
    border-radius:10px;
    padding:11px 13px;
    font-size:14.5px;font-family:inherit;
    background:var(--cw-soft);color:var(--ink);
  }
  .field input:focus{
    outline:none;border-color:var(--teal);background:#fff;
    box-shadow:0 0 0 3px rgba(22,103,224,.14);
  }
  .remember{
    display:flex;align-items:center;gap:8px;
    font-size:13.5px;color:var(--ink-soft);
    margin:6px 0 22px;cursor:pointer;
  }
  .remember input{accent-color:var(--teal);width:15px;height:15px;}
  button[type="submit"]{
    width:100%;border:none;background:var(--teal);color:#fff;
    padding:12px 18px;border-radius:10px;
    font-size:15px;font-weight:500;font-family:inherit;cursor:pointer;
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
  }
  button[type="submit"]:hover{background:var(--teal-deep);}
  .alert{
    background:var(--brick-bg);color:var(--brick);
    border-radius:10px;padding:11px 14px;font-size:13.5px;margin-bottom:18px;
  }
  .alert ul{margin:0;padding-inline-start:18px;}
  ::placeholder{color:var(--ink-faint);}
  .foot{margin-top:20px;text-align:center;font-size:12.5px;color:var(--ink-faint);}

  body{font-family:'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;}
  ::placeholder{color:var(--ink-faint);}
  ::selection{background:var(--cw-blue-100);}
  a:focus-visible, button:focus-visible, [tabindex]:focus-visible{outline:2px solid var(--accent);outline-offset:2px;}

  .brand .bolt{width:34px;height:34px;border-radius:10px;background:var(--cw-blue-50);color:var(--cw-blue);}
  .brand .name{font-family:inherit;font-weight:600;font-size:19px;letter-spacing:-.01em;}
  .sidebar .brand .name{color:#fff;}
  .sidebar .brand .name span{color:#4C95FF;}
  .shell .brand .name{color:var(--ink);}
  .shell .brand .name span{color:var(--cw-blue);}

  .sidebar{background:var(--nav-bg);scrollbar-width:none;}
  .sidebar::-webkit-scrollbar{display:none;}
  .brand{border-bottom-color:var(--nav-line);}
  .nav-section{color:#5E6A92;letter-spacing:.08em;font-size:11px;}
  .nav{gap:4px;}
  .nav-item{padding:10px 12px;border-radius:10px;color:var(--nav-ink-soft);font-weight:500;}
  .nav-item:hover{background:var(--nav-bg-hover);color:#fff;}
  .nav-item.active{background:var(--accent-soft);color:#fff;font-weight:500;}
  .nav-item.active .ic{color:#5B9DFF;opacity:1;}
  .nav-item.active::before{background:var(--accent);}
  .nav-item.disabled{opacity:.45;}
  .nav-item.disabled:hover{background:none;color:var(--nav-ink-soft);}
  .nav-item .badge{background:var(--cw-gold);color:var(--cw-navy-950);font-weight:700;border-radius:999px;}
  .sidebar-foot{border-top-color:var(--nav-line);}
  .user-card{background:rgba(255,255,255,.05);border-radius:12px;}
  .user-card:hover{background:rgba(255,255,255,.09);}
  .user-card .avatar{background:var(--cw-blue);}
  .user-card .meta .rl{color:#8E9AC0;}

  .topbar{background:var(--paper-raised);border-bottom:1px solid var(--line);}
  .topbar .crumb{color:var(--ink-faint);}
  .topbar .crumb .cur{color:var(--ink);font-weight:600;}
  .topbar .search input{background:var(--cw-soft);border:1px solid var(--line);border-radius:10px;color:var(--ink);}
  .topbar .search input:focus{outline:none;border-color:var(--cw-blue);background:#fff;box-shadow:0 0 0 3px rgba(22,103,224,.14);}
  .logout-btn{border-radius:10px;color:var(--ink-soft);font-weight:500;}
  .logout-btn:hover{border-color:#F3C1C1;color:var(--brick);background:var(--brick-bg);}

  .page-head h1{font-family:inherit;font-weight:600;font-size:26px;line-height:1.2;letter-spacing:-.02em;color:var(--ink);}
  .page-head p{color:var(--ink-soft);font-size:14px;}
  .page-head p strong{color:var(--ink);font-weight:600;}

  .card, .stat, .table-wrap, .filters{border-color:var(--line);border-radius:var(--radius);box-shadow:var(--shadow);}
  .stat .glyph{border-radius:10px;}
  .stat .body .lbl{color:var(--ink-soft);}
  .stat .body .val{font-family:inherit;font-weight:600;font-size:24px;letter-spacing:-.02em;color:var(--ink);}
  .stat .body .val.skeleton{color:var(--ink-faint);}
  .section-title{font-family:inherit;font-weight:600;font-size:14.5px;color:var(--ink);}
  .empty-state{color:var(--ink-soft);}
  .empty-state .big{font-family:inherit;font-weight:600;font-size:16px;color:var(--ink);}

  .btn-primary{background:var(--cw-blue);color:#fff;border-radius:10px;font-weight:600;box-shadow:none;}
  .btn-primary:hover{background:var(--cw-blue-700);}
  .btn-secondary{background:#fff;border:1px solid var(--line);color:var(--ink);border-radius:10px;font-weight:500;}
  .btn-secondary:hover{border-color:#C9D6EA;background:var(--cw-soft);color:var(--ink);}
  .btn-ghost{border-radius:10px;}
  .btn-danger{background:var(--brick);color:#fff;border-radius:10px;font-weight:600;}
  .btn-danger:hover{background:#B02E2E;}
  .row-btn{border-radius:8px;color:var(--ink);font-weight:500;background:#fff;border-color:var(--line);}
  .row-btn:hover{border-color:var(--cw-blue);color:var(--cw-blue);background:var(--cw-blue-50);}
  .row-btn.danger:hover{border-color:#F3C1C1;color:var(--brick);background:var(--brick-bg);}

  .field label, .filters label{color:var(--ink-soft);font-weight:500;}
  .field input, .field select, .field textarea, .filters input, .filters select{background:#fff;border:1px solid var(--line);border-radius:10px;color:var(--ink);}
  .field input:focus, .field select:focus, .field textarea:focus, .filters input:focus, .filters select:focus{outline:none;border-color:var(--cw-blue);background:#fff;box-shadow:0 0 0 3px rgba(22,103,224,.14);}
  .field.invalid input, .field.invalid select, .field.invalid textarea{border-color:var(--brick);background:var(--brick-bg);}
  input[type="checkbox"], input[type="radio"]{accent-color:var(--cw-blue);}

  thead th{background:var(--cw-soft);color:#6B7593;font-size:11.5px;letter-spacing:.06em;border-bottom-color:var(--line);}
  tbody td{border-bottom-color:var(--line-soft);}
  tbody tr:hover{background:var(--cw-soft);}

  .card-top > .avatar{background:var(--cw-blue-50) !important;color:var(--cw-navy-800);font-family:inherit;font-weight:700;font-size:15px;letter-spacing:-.02em;border-radius:12px;}

  .table-scroll td.actions-cell, .table-scroll thead th:last-child{position:sticky;right:0;z-index:1;}
  .table-scroll td.actions-cell{background:#fff;}
  .table-scroll thead th:last-child{background:var(--cw-soft);}
  .table-scroll tbody tr:hover td.actions-cell{background:var(--cw-soft);}

  .tabs{border-color:var(--line);border-radius:var(--radius);box-shadow:var(--shadow);}
  .tabs button{color:var(--ink-soft);font-weight:500;}
  .tabs button:hover{color:var(--ink);}
  .tabs button.active{color:var(--ink);font-weight:600;border-bottom-color:var(--cw-blue);}
  .tabs button .count{background:var(--cw-gray-bg);border-color:transparent;color:var(--cw-gray);font-weight:600;}
  .tabs button.active .count{background:var(--cw-blue);color:#fff;border-color:transparent;}

  .pill{border-radius:999px;font-weight:600;font-size:12px;letter-spacing:0;}
  .pill.verified, .pill.approved, .pill.open, .pill.selection, .pill.active,
  .pill.completed, .pill.resolved, .pill.in-use{background:var(--sage-bg);color:var(--sage);}
  .pill.pending, .pill.submitted, .pill.changes_requested{background:var(--amber-bg);color:var(--amber);}
  .pill.under_review, .pill.assigned, .pill.single_choice, .pill.count, .pill.role{background:var(--cw-blue-50);color:var(--cw-blue-700);border-color:transparent;}
  .pill.text, .pill.reason{background:var(--violet-bg);color:var(--violet);}
  .pill.rejected, .pill.cancelled{background:var(--brick-bg);color:var(--brick);}
  .pill.draft, .pill.archived, .pill.closed, .pill.inactive, .pill.unused{background:var(--cw-gray-bg);color:var(--cw-gray);}
  .pill.free{background:#FDF3DC;color:#8A5D00;}

  .overlay{background:rgba(2,7,32,.5);}
  .modal{border-radius:var(--radius);box-shadow:0 24px 60px -12px rgba(2,7,32,.35);}
  .modal-head h2{font-family:inherit;font-weight:600;font-size:17px;letter-spacing:-.01em;color:var(--ink);}
  .modal-head .close:hover{background:var(--cw-gray-bg);color:var(--ink);}

  .table-scroll, .queue-list, .thread-scroll, .modal-body, .picker-list, .overlay{scrollbar-width:none;-ms-overflow-style:none;}
  .table-scroll::-webkit-scrollbar, .queue-list::-webkit-scrollbar, .thread-scroll::-webkit-scrollbar,
  .modal-body::-webkit-scrollbar, .picker-list::-webkit-scrollbar, .overlay::-webkit-scrollbar{display:none;}

  .toast{background:var(--cw-navy-900);color:#fff;border-radius:10px;}
  .toast.err{background:var(--brick);}
  .toast.ok{background:var(--sage);}

  @media (max-width:880px){
    .app{flex-direction:column;}
    .sidebar{position:sticky;top:0;left:0;width:100%;height:auto;z-index:40;box-shadow:none;
      flex-direction:row;align-items:center;gap:10px;padding:10px 12px;overflow-x:auto;transition:none;}
    .sidebar .brand{padding:0;margin:0;border:none;flex-shrink:0;}
    .sidebar .brand .name, .sidebar .nav-section, .sidebar .sidebar-foot{display:none;}
    .sidebar .nav{flex-direction:row;gap:4px;flex-shrink:0;}
    .sidebar .nav-item{white-space:nowrap;padding:8px 11px;font-size:13px;flex-shrink:0;}
    .sidebar .nav-item.active::before{display:none;}
    .topbar{position:static;flex-wrap:wrap;}
    .table-scroll td.actions-cell, .table-scroll thead th:last-child{box-shadow:-1px 0 0 var(--line);}
  }
  @media (max-width:520px){
    .topbar{padding:10px 16px;gap:10px;}
    .topbar .crumb > *:not(.cur){display:none;}
    .topbar-right{gap:8px;min-width:0;flex:1;justify-content:flex-end;}
    .topbar .search{min-width:0;flex:1;}
    .topbar .search input{width:100%;min-width:0;}
    .page-head h1{font-size:22px;}
    .table-scroll td.actions-cell{white-space:normal;width:118px;min-width:118px;max-width:118px;}
    .table-scroll td.actions-cell > *{margin:2px 0 2px 4px;}
    .table-scroll thead th:last-child{width:118px !important;}
    .filters .field{flex:1 1 100%;}
    .filters input, .filters select{width:100%;min-width:0;}
  }

  .brand .bolt{display:none !important;}
  .brand .name{font-weight:600;letter-spacing:-.01em;}
  .brand .name span{background:linear-gradient(180deg,#0491AF 0%,#034076 100%);-webkit-background-clip:text;background-clip:text;color:transparent !important;-webkit-text-fill-color:transparent;}
</style>
</head>
<body>
<div class="shell">
  <div class="brand">
    <span class="bolt" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
        <path d="M13 2L4.5 13.5H11l-2 8.5 9-12.5H11.5L13 2Z" fill="currentColor"/>
      </svg>
    </span>
    <span class="name">Skill<span>Span</span></span>
  </div>

  <h1>Admin Panel</h1>
  <p class="sub">Sign in with your administrator account to continue.</p>

  @if ($errors->any())
    <div class="alert">
      <ul>
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form method="POST" action="{{ route('admin.login') }}">
    @csrf

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email"
             value="{{ old('email') }}" required autofocus
             autocomplete="username" placeholder="admin@example.com">
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password"
             required autocomplete="current-password" placeholder="••••••••">
    </div>

    <label class="remember">
      <input type="checkbox" name="remember" value="1">
      Keep me signed in
    </label>

    <button type="submit">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 12H4m0 0 4-4m-4 4 4 4M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Sign in
    </button>
  </form>

  <p class="foot">SkillSpan · Administration access only</p>
</div>
</body>
</html>
