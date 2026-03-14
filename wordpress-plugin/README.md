# Hamado Card WordPress Plugin

هذا المجلد يحتوي بلوجن WordPress بديل عن سيرفر Node خارجي، ويستخدم قاعدة بيانات ووردبريس مباشرة.

> ملاحظة: لم يتم حذف أو تعديل صفحات الـSEO الإضافية في المشروع، وتم الحفاظ على هيكل الصفحات كما هو.

## التثبيت
1. انسخ `hamado-card-api.php` إلى: `wp-content/plugins/hamado-card-api/hamado-card-api.php`
2. فعّل البلوجن من لوحة تحكم WordPress.
3. واجهات REST ستكون على:
   - `/wp-json/hamado/v1/...`

## يغطي
- المستخدمين (تسجيل/دخول/Google)
- الطلبات والشراء
- الإيداعات
- المعاملات
- KYC
- الجلسات + سجل النشاط + IP + الوقت
- واجهات الإدارة

## نقطة مهمة
- تم تأمين نقاط الإدارة بمصادقة Admin token في `permission_callback`.
- كلمة مرور الأدمن تُخزن كـ hash في خيار `hc_admin_pass_hash`.


## دعم صفحات SEO متعددة
- Endpoints إضافية لربط صفحات الأقسام:
  - `/wp-json/hamado/v1/sections`
  - `/wp-json/hamado/v1/products/by-section/{section}`
- الأقسام المدعومة افتراضياً: `games`, `apps`, `streaming`, `payments`.
