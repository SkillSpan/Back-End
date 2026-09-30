<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkillSpan Admin — Sign in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --nav-bg:#0E1830;
    --nav-bg-soft:#16223F;
    --accent:#5EEAD4;
    --accent-soft:rgba(94,234,212,.14);
    --paper:#F4F5F8;
    --paper-raised:#FFFFFF;
    --ink:#1C2420;
    --ink-soft:#5B6560;
    --ink-faint:#8C9498;
    --line:#E5E2D9;
    --teal:#2F6F5E;
    --teal-deep:#205043;
    --brick:#A23B33;
    --brick-bg:#F5E4E1;
    --radius:14px;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:40px 20px;
    background:
      radial-gradient(1200px 600px at 80% -10%, rgba(94,234,212,.08), transparent 60%),
      var(--nav-bg);
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
    font-family:'Fraunces', serif;font-weight:600;font-size:22px;
    letter-spacing:-.01em;color:var(--nav-bg);
  }
  .brand .name span{color:var(--teal);}
  h1{
    font-family:'Fraunces', serif;font-weight:500;font-size:26px;
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
    background:#FBFAF7;color:var(--ink);
  }
  .field input:focus{
    outline:none;border-color:var(--teal);background:#fff;
    box-shadow:0 0 0 3px rgba(47,111,94,.12);
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
  ::placeholder{color:#A9A398;}
  .foot{margin-top:20px;text-align:center;font-size:12.5px;color:var(--ink-faint);}
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
