# Hamado Card Backend

واجهة خلفية (Node.js + Express) لإدارة:
- المستخدمين وتسجيل الدخول (مع حفظ IP ووقت الدخول)
- المحفظة والإيداعات والمعاملات
- الطلبات وعمليات الشراء
- سجل النشاط (Logs) للإدارة
- واجهات إدارة كاملة للطلبات، الإيداعات، KYC، الرصيد والإعدادات

## التشغيل

```bash
cd backend
npm install
npm start
```

السيرفر يعمل على:
- `http://localhost:3000`

## نقاط API الأساسية

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/google`
- `GET /api/auth/me`
- `POST /api/orders/create`
- `POST /api/wallet/deposit`
- `GET /api/admin/stats`
- `GET /api/admin/activity`
- `GET /api/admin/sessions`
- `GET /api/admin/transactions`

## ملاحظات

- قاعدة البيانات محلية في ملف `backend/data.json` ويتم توليدها تلقائياً.
- بيانات الأدمن الافتراضية:
  - username: `admin`
  - password: `Hamado@2025!`
