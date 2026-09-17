<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SkillSpan Admin — طلبات المؤسسات</title>
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
    --amber:#9C6B12;
    --amber-bg:#F6EBD6;
    --sage:#3C7A57;
    --sage-bg:#E3EFE6;
    --brick:#A23B33;
    --brick-bg:#F5E4E1;
    --radius:14px;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    background:var(--paper);
    color:var(--ink);
    font-family:'IBM Plex Sans Arabic', sans-serif;
    direction:rtl;
    line-height:1.6;
  }
  .wrap{
    max-width:760px;
    margin:0 auto;
    padding:40px 20px 100px;
  }
  header.page-head{
    margin-bottom:28px;
  }
  header.page-head h1{
    font-family:'Fraunces', serif;
    font-weight:500;
    font-size:32px;
    margin:0 0 6px;
    letter-spacing:-0.01em;
  }
  header.page-head p{
    margin:0;
    max-width:100%;
    overflow-wrap:anywhere;
    color:var(--ink-soft);
    font-size:15px;
  }

  .session-bar{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:12px 16px;
    margin-bottom:24px;
    display:flex;
    gap:12px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
  }
  .session-bar .who{font-size:13px;color:var(--ink-soft);overflow-wrap:anywhere;}
  .session-bar .who strong{color:var(--ink);font-weight:600;}
  .session-bar form{margin:0;}
  .session-bar button{
    border:1px solid var(--line);
    background:none;
    color:var(--ink-soft);
    padding:7px 14px;
    border-radius:8px;
    font-size:13px;
    cursor:pointer;
    font-family:inherit;
  }
  .session-bar button:hover{border-color:var(--brick);color:var(--brick);}

  .tabs{
    display:flex;
    gap:4px;
    margin-bottom:22px;
    border-bottom:1px solid var(--line);
  }
  .tabs button{
    border:none;
    background:none;
    padding:10px 4px;
    margin-left:22px;
    font-size:15px;
    color:var(--ink-soft);
    cursor:pointer;
    font-family:inherit;
    position:relative;
    top:1px;
  }
  .tabs button.active{
    color:var(--ink);
    font-weight:600;
    border-bottom:2px solid var(--teal);
  }

  #status-line{
    font-size:13px;
    color:var(--ink-soft);
    margin-bottom:14px;
    min-height:18px;
  }
  #status-line.err{color:var(--brick);}

  .feed{display:flex;flex-direction:column;gap:14px;}

  .card{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:18px 20px;
    transition:box-shadow .15s ease;
  }
  .card-top{
    display:flex;
    align-items:flex-start;
    gap:14px;
  }
  .avatar{
    width:44px;height:44px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-family:'Fraunces', serif;
    font-weight:600;font-size:17px;color:#fff;
    flex-shrink:0;
  }
  .card-main{flex:1;min-width:0;}
  .org-name{
    font-family:'Fraunces', serif;
    font-size:19px;
    font-weight:500;
    color:var(--ink);
    background:none;border:none;padding:0;
    cursor:pointer;
    text-align:right;
    display:inline;
    font-family:'Fraunces', serif;
  }
  .org-name:hover{color:var(--teal-deep);text-decoration:underline;text-decoration-color:var(--line);}
  .name-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .pill{
    font-size:12px;
    padding:3px 10px;
    border-radius:100px;
    font-weight:500;
    white-space:nowrap;
  }
  .pill.pending{background:var(--amber-bg);color:var(--amber);}
  .pill.verified{background:var(--sage-bg);color:var(--sage);}
  .pill.rejected{background:var(--brick-bg);color:var(--brick);}
  .meta-line{
    margin:5px 0 0;
    font-size:14px;
    color:var(--ink-soft);
  }

  .detail{
    margin-top:16px;
    padding-top:16px;
    border-top:1px solid var(--line);
    display:none;
  }
  .detail.open{display:block;}
  .detail .desc{
    font-size:14.5px;
    color:var(--ink);
    margin:0 0 14px;
  }
  .detail .desc.empty{color:var(--ink-soft);font-style:italic;}
  .facts{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px 20px;
    font-size:13.5px;
    margin-bottom:14px;
  }
  .facts div span{color:var(--ink-soft);}
  .proof-row{
    display:flex;align-items:center;justify-content:space-between;
    background:#FBFAF7;border:1px solid var(--line);border-radius:10px;
    padding:10px 14px;font-size:13.5px;margin-bottom:14px;
  }
  .proof-row a{color:var(--teal-deep);text-decoration:none;font-weight:500;}
  .proof-row a:hover{text-decoration:underline;}
  .actions{display:flex;gap:10px;}
  .actions button{
    border:none;border-radius:8px;padding:9px 18px;
    font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;
  }
  .btn-approve{background:var(--teal);color:#fff;}
  .btn-approve:hover{background:var(--teal-deep);}
  .btn-reject{background:none;border:1px solid var(--brick);color:var(--brick);}
  .btn-reject:hover{background:var(--brick-bg);}
  .actions button:disabled{opacity:.5;cursor:default;}

  .empty-state{
    text-align:center;
    padding:60px 20px;
    color:var(--ink-soft);
  }
  .empty-state .big{font-family:'Fraunces',serif;font-size:20px;color:var(--ink);margin-bottom:6px;}

  ::placeholder{color:#A9A398;}

  @media (max-width:520px){
    .facts{grid-template-columns:1fr;}
    .session-bar{flex-direction:column;align-items:stretch;}
  }
</style>
</head>
<body>
<div class="wrap">

  <header class="page-head">
    <h1>طلبات المؤسسات</h1>
    <p>راجع طلبات تسجيل الشركات والجامعات ومراكز التدريب قبل انضمامها لـ <span dir="ltr" style="unicode-bidi:isolate">SkillSpan</span>.</p>
  </header>

  <div class="session-bar">
    <span class="who">
      مسجّل دخول باسم <strong>{{ auth()->user()->name }}</strong>
      <span dir="ltr" style="unicode-bidi:isolate">({{ auth()->user()->email }})</span>
    </span>
    <form method="POST" action="{{ route('admin.logout') }}">
      @csrf
      <button type="submit">تسجيل الخروج</button>
    </form>
  </div>

  <div class="tabs" id="tabs">
    <button data-status="" class="active">الكل</button>
    <button data-status="pending">قيد المراجعة</button>
    <button data-status="verified">موافَق عليها</button>
    <button data-status="rejected">مرفوضة</button>
  </div>

  <p id="status-line"></p>

  <div class="feed" id="feed"></div>

</div>

<script>
// الصفحة محمية بجلسة الأدمن، فما في داعي لـ token هون: المتصفح يبعث
// كوكي الجلسة لحاله، ومنبعث معه CSRF token للطلبات يلي بتعدّل.
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

let currentStatus = '';
let cache = {}; // id -> full org detail once fetched

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
    pending: ['pill pending', 'قيد المراجعة'],
    verified: ['pill verified', 'موافَق عليها'],
    rejected: ['pill rejected', 'مرفوضة'],
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

  // 401 = ما في جلسة، 419 = الجلسة/الـ CSRF token صاروا قديمين.
  // بالحالتين منرجّع المستخدم ع صفحة تسجيل الدخول.
  if (res.status === 401 || res.status === 419) {
    window.location.href = '/admin/login';
    throw new Error('انتهت الجلسة، سجّل دخول من جديد.');
  }

  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(body.message || `${res.status} ${res.statusText}`);
  }
  return body;
}

async function loadOrganizations(){
  const feed = document.getElementById('feed');
  setStatusLine('عم نجيب الطلبات...');
  try {
    const query = currentStatus ? `?status=${currentStatus}` : '';
    const body = await api(`/admin/api/organizations${query}`);
    const orgs = body.data?.data || [];
    renderFeed(orgs);
    setStatusLine(orgs.length ? '' : null);
  } catch (e) {
    setStatusLine('ما قدرنا نجيب الطلبات: ' + e.message, true);
    feed.innerHTML = '';
  }
}

function renderFeed(orgs){
  const feed = document.getElementById('feed');
  if (!orgs.length) {
    feed.innerHTML = `<div class="empty-state"><div class="big">ما في طلبات هون</div>ما في مؤسسات بهاي الحالة حاليًا.</div>`;
    return;
  }
  feed.innerHTML = orgs.map(org => cardTemplate(org)).join('');
}

function cardTemplate(org){
  return `
    <div class="card" id="card-${org.id}">
      <div class="card-top">
        <div class="avatar" style="background:${avatarColor(org.name)}">${monogram(org.name)}</div>
        <div class="card-main">
          <div class="name-row">
            <button class="org-name" onclick="toggleDetail(${org.id})">${org.name}</button>
            ${pillFor(org.verification_status)}
          </div>
          <p class="meta-line">${metaLine(org)}</p>
        </div>
      </div>
      <div class="detail" id="detail-${org.id}"></div>
    </div>
  `;
}

function metaLine(org){
  const bits = [];
  if (org.type) bits.push(org.type === 'company' ? 'شركة' : org.type);
  if (org.industry) bits.push(org.industry);
  const place = [org.city, org.country].filter(Boolean).join('، ');
  let line = bits.join(' — ');
  if (place) line += (line ? ' · ' : '') + place;
  return line || 'ما في تفاصيل إضافية بعد.';
}

async function toggleDetail(id){
  const detailEl = document.getElementById(`detail-${id}`);
  const isOpen = detailEl.classList.contains('open');

  // سكّر أي كارد تاني مفتوح، متل بوست بالفيسبوك بينفتح واحد بالمرة.
  document.querySelectorAll('.detail.open').forEach(d => {
    d.classList.remove('open');
    d.innerHTML = '';
  });

  if (isOpen) return;

  detailEl.classList.add('open');
  detailEl.innerHTML = '<p class="meta-line">عم نجيب التفاصيل...</p>';

  try {
    let org = cache[id];
    if (!org) {
      const body = await api(`/admin/api/organizations/${id}`);
      org = body.data;
      cache[id] = org;
    }
    detailEl.innerHTML = detailTemplate(org);
  } catch (e) {
    detailEl.innerHTML = `<p class="meta-line" style="color:var(--brick)">ما قدرنا نجيب التفاصيل: ${e.message}</p>`;
  }
}

function detailTemplate(org){
  const desc = org.description
    ? `<p class="desc">${org.description}</p>`
    : `<p class="desc empty">لسا ما ضافوا وصف للمؤسسة.</p>`;

  const proof = org.proof_file
    ? `<div class="proof-row">
         <span>ملف الإثبات — ${org.proof_file.status === 'pending' ? 'بانتظار المراجعة' : org.proof_file.status}</span>
         <a href="${org.proof_file.download_url}" target="_blank">فتح الملف</a>
       </div>`
    : `<div class="proof-row"><span>ما في ملف إثبات مرفوع.</span></div>`;

  const facts = `
    <div class="facts">
      ${org.contact_email ? `<div><span>الإيميل: </span><span dir="ltr" style="unicode-bidi:isolate">${org.contact_email}</span></div>` : ''}
      ${org.contact_phone ? `<div><span>الهاتف: </span><span dir="ltr" style="unicode-bidi:isolate">${org.contact_phone}</span></div>` : ''}
      ${org.website ? `<div><span>الموقع: </span><span dir="ltr" style="unicode-bidi:isolate">${org.website}</span></div>` : ''}
      ${org.company_size ? `<div><span>عدد الموظفين: </span><span dir="ltr" style="unicode-bidi:isolate">${org.company_size}</span></div>` : ''}
    </div>`;

  const actions = org.verification_status === 'pending'
    ? `<div class="actions">
         <button class="btn-approve" onclick="decide(${org.id}, 'approve')">قبول</button>
         <button class="btn-reject" onclick="decide(${org.id}, 'reject')">رفض</button>
       </div>`
    : '';

  return desc + facts + proof + actions;
}

async function decide(id, action){
  if (action === 'reject' && !confirm('متأكد بدك ترفض هالمؤسسة؟')) return;

  let reason = null;
  if (action === 'reject') {
    reason = prompt('سبب الرفض (اختياري):') || null;
  }

  try {
    await api(`/admin/api/organizations/${id}/${action}`, {
      method: 'POST',
      body: JSON.stringify(reason ? { reason } : {}),
    });
    delete cache[id];
    setStatusLine(action === 'approve' ? 'تمت الموافقة على المؤسسة.' : 'تم رفض المؤسسة.');
    loadOrganizations();
  } catch (e) {
    alert('صار خطأ: ' + e.message);
  }
}

document.getElementById('tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentStatus = btn.dataset.status;
  loadOrganizations();
});

loadOrganizations();
</script>
</body>
</html>
