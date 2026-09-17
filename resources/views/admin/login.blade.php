<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkillSpan Admin — تسجيل الدخول</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#F6F4EF;
    --paper-raised:#FFFFFF;
    --ink:#1C2420;
    --ink-soft:#5B6560;
    --line:#DEDACD;
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
    background:var(--paper);
    color:var(--ink);
    font-family:'IBM Plex Sans Arabic', sans-serif;
    direction:rtl;
    line-height:1.6;
  }
  .card{
    width:100%;
    max-width:400px;
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:34px 30px 30px;
  }
  .mark{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:15px;
    letter-spacing:.02em;
    color:var(--teal);
    margin-bottom:22px;
  }
  h1{
    font-family:'Fraunces', serif;
    font-weight:500;
    font-size:26px;
    margin:0 0 6px;
    letter-spacing:-0.01em;
  }
  .sub{
    margin:0 0 26px;
    font-size:14px;
    color:var(--ink-soft);
  }
  .field{margin-bottom:16px;}
  .field label{
    display:block;
    font-size:13px;
    color:var(--ink-soft);
    margin-bottom:6px;
  }
  .field input{
    width:100%;
    border:1px solid var(--line);
    border-radius:9px;
    padding:10px 12px;
    font-size:14.5px;
    font-family:inherit;
    background:#FBFAF7;
    color:var(--ink);
  }
  .field input:focus{
    outline:none;
    border-color:var(--teal);
    background:#fff;
  }
  .field input[dir="ltr"]{text-align:left;}
  .remember{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13.5px;
    color:var(--ink-soft);
    margin:4px 0 20px;
    cursor:pointer;
  }
  .remember input{accent-color:var(--teal);width:15px;height:15px;}
  button[type="submit"]{
    width:100%;
    border:none;
    background:var(--teal);
    color:#fff;
    padding:11px 18px;
    border-radius:9px;
    font-size:15px;
    font-weight:500;
    font-family:inherit;
    cursor:pointer;
  }
  button[type="submit"]:hover{background:var(--teal-deep);}
  .alert{
    background:var(--brick-bg);
    color:var(--brick);
    border-radius:9px;
    padding:10px 14px;
    font-size:13.5px;
    margin-bottom:18px;
  }
  .alert ul{margin:0;padding-inline-start:18px;}
  ::placeholder{color:#A9A398;}
</style>
</head>
<body>
<div class="card">
  <div class="mark">SkillSpan</div>

  <h1>لوحة الإدارة</h1>
  <p class="sub">سجّل دخولك بحساب الأدمن للمتابعة.</p>

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
      <label for="email">الإيميل</label>
      <input id="email" name="email" type="email" dir="ltr"
             value="{{ old('email') }}" required autofocus
             autocomplete="username" placeholder="admin@example.com">
    </div>

    <div class="field">
      <label for="password">كلمة السر</label>
      <input id="password" name="password" type="password" dir="ltr"
             required autocomplete="current-password" placeholder="••••••••">
    </div>

    <label class="remember">
      <input type="checkbox" name="remember" value="1">
      خلّيني مسجّل دخول
    </label>

    <button type="submit">تسجيل الدخول</button>
  </form>
</div>
</body>
</html>
