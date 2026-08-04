<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل - SkillBridge (اختبار)</title>
    <style>
        body { font-family: 'Tahoma', sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f9f9f9; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .hidden { display: none; }
        button { width: 100%; padding: 12px; margin-top: 20px; background: #3498db; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        button:hover { background: #2980b9; }
        .message { padding: 10px; margin-top: 10px; border-radius: 5px; display: none; }
        .success { background: #d4edda; color: #155724; display: block; }
        .error { background: #f8d7da; color: #721c24; display: block; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 تسجيل مستخدم - SkillBridge</h1>
    <p style="text-align: center; color: #888;">صفحة اختبار للـ Backend فقط</p>
    <div id="response" class="message"></div>
    <form id="registerForm" enctype="multipart/form-data">
        @csrf
        <label for="user_type">نوع المستخدم</label>
        <select id="user_type" name="user_type" required>
            <option value="individual">فرد (طالب/خريج)</option>
            <option value="organization">مؤسسة (شركة/جامعة)</option>
        </select>
        <label for="name">الاسم الكامل</label>
        <input type="text" id="name" name="name" required>
        <label for="email">البريد الإلكتروني</label>
        <input type="email" id="email" name="email" required>
        <label for="phone">رقم الجوال (اختياري)</label>
        <input type="text" id="phone" name="phone">
        <label for="password">كلمة المرور</label>
        <input type="password" id="password" name="password" required>
        <label for="password_confirmation">تأكيد كلمة المرور</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required>
        <div id="individual_fields">
            <label for="education">التخصص الأكاديمي</label>
            <input type="text" id="education" name="education">
            <label for="specialization">التخصص الدقيق</label>
            <input type="text" id="specialization" name="specialization">
            <label for="career_status">الحالة المهنية</label>
            <input type="text" id="career_status" name="career_status" value="طالب">
        </div>
        <div id="organization_fields" class="hidden">
            <label for="organization_name">اسم المؤسسة</label>
            <input type="text" id="organization_name" name="organization_name">
            <label for="organization_type">نوع المؤسسة</label>
            <select id="organization_type" name="organization_type">
                <option value="company">شركة</option>
                <option value="university">جامعة</option>
                <option value="training_partner">جهة تدريب</option>
            </select>
            <label for="organization_contact_email">بريد المؤسسة</label>
            <input type="email" id="organization_contact_email" name="organization_contact_email">
            <label for="organization_contact_phone">جوال المؤسسة</label>
            <input type="text" id="organization_contact_phone" name="organization_contact_phone">
            <label for="organization_website">موقع المؤسسة (اختياري)</label>
            <input type="url" id="organization_website" name="organization_website">
            <label for="organization_description">وصف المؤسسة (اختياري)</label>
            <textarea id="organization_description" name="organization_description" rows="3"></textarea>
            <label for="proof_file">ملف إثبات المؤسسة (صورة أو PDF)</label>
            <input type="file" id="proof_file" name="proof_file" accept=".jpg,.jpeg,.png,.pdf">
        </div>
        <label><input type="checkbox" name="terms_accepted" value="1" required> أوافق على الشروط والأحكام</label>
        <label><input type="checkbox" name="privacy_accepted" value="1" required> أوافق على سياسة الخصوصية</label>
        <button type="submit">تسجيل</button>
    </form>
</div>
<script>
    const userTypeSelect = document.getElementById('user_type');
    const individualFields = document.getElementById('individual_fields');
    const organizationFields = document.getElementById('organization_fields');
    userTypeSelect.addEventListener('change', function() {
        if (this.value === 'individual') {
            individualFields.classList.remove('hidden');
            organizationFields.classList.add('hidden');
        } else {
            individualFields.classList.add('hidden');
            organizationFields.classList.remove('hidden');
        }
    });
    document.getElementById('registerForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const responseDiv = document.getElementById('response');
        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData,
            });
            const data = await response.json();
            responseDiv.className = 'message ' + (response.ok ? 'success' : 'error');
            let msg = data.message || 'حدث خطأ';
            if (data.errors) {
                msg += ' — ' + Object.values(data.errors).flat().join(' | ');
            }
            responseDiv.textContent = (response.ok ? '✅ ' : '❌ ') + msg;
        } catch (error) {
            responseDiv.className = 'message error';
            responseDiv.textContent = '❌ فشل الاتصال بالخادم';
        }
    });
    userTypeSelect.dispatchEvent(new Event('change'));
</script>
</body>
</html>
