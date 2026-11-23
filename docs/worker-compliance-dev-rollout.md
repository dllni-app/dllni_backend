# تشغيل تجربة مواقع العمال وتنبيهات الإدارة — فرع dev

## ما هو مفعّل في الكود؟
- على `APP_ENV=local|development|staging` تكون بوابة جمع الموقع، ومطابقة الزيارات، وتنبيهات الإدارة **مفعّلة كإعدادات افتراضية** في فرع `dev`، ما لم تُعطَّل بمتغيرات البيئة.
- على `APP_ENV=production|testing` تبقى **معطلة افتراضياً**.
- المنصة التجريبية المتاحة افتراضياً هي `android` فقط.
- لا يبدأ جمع أي موقع إلا بعد وجود **رابط HTTPS صالح**، وإدراج عامل محدد في التجربة، وعرض الإشعار له وقبوله، ومنح أذونات الجهاز.
- يمكن للعامل رفض التجربة والاستمرار بالتطبيق، أو إيقافها وإعادة تفعيلها لاحقاً. مشاركة الموقع أثناء المهمة المقبولة منفصلة عنها.

## قبل إطلاق التجربة على خادم dev
1. انشر فرع `dev` الخاص بالباك إند بما فيه صفحة `/legal/cleaning-worker-monitoring`، وتحقق أنها تعطي HTTP 200 من الهاتف والمتصفح عبر HTTPS. **بتاريخ 2026-11-23 أعاد هذا المسار 404 على `alnadha.net` و`dllni.mustafafares.com`، لذا لا يجوز اعتبار التجربة فعالة على أيٍّ منهما.**
2. تأكد أن `APP_ENV=staging` (أو `development`)، وأن `APP_URL=https://<actual-dev-host>` يشير إلى عنوان النطاق الذي نُشرت عليه الصفحة. لا تستخدم بيئة الإنتاج أو قاعدة بيانات الإنتاج.
3. إذا أردت تثبيت القيم صراحةً في إعدادات dev فقط:

```dotenv
CLEANING_WORKER_COMPLIANCE_ENABLED=true
CLEANING_WORKER_COMPLIANCE_MATCHING_ENABLED=true
CLEANING_WORKER_COMPLIANCE_NOTIFICATIONS_ENABLED=true
CLEANING_WORKER_COMPLIANCE_ENABLED_PLATFORMS=android
CLEANING_WORKER_COMPLIANCE_NOTICE_VERSION=2026-11-23-v2
CLEANING_WORKER_COMPLIANCE_PRIVACY_URL=https://<actual-dev-host>/legal/cleaning-worker-monitoring
```

4. على خادم dev نفّذ `php artisan migrate --force` و`php artisan db:seed --class=Database\\Seeders\\Permissions\\WorkerCompliancePermissionsSeeder --force` و`php artisan optimize:clear`. تأكد من تشغيل `schedule:run` كل دقيقة ووجود عامل `queue:work` للمهام المؤجلة.
5. امنح مسؤول الاختبار صلاحية `cleaning.worker_compliance.configure`، وصلاحيات مشاهدة الحالات ومراجعتها لمن يحتاجها فقط. من صفحة العمال فعّل **إدراج في تجربة الموقع** لحساب عامل اختبار واحد بعد علمه وموافقته. لا تُدرج جميع العمال تلقائياً.
6. حدث تطبيق العامل على جهاز Android اختباري، ثم سجّل الدخول. يجب ظهور إشعار واضح قبل صلاحيات الموقع، مع خيار الاستمرار دون مراقبة إضافية. عند القبول ابدأ السماح بالموقع الدقيق في المقدمة والخلفية وأذونات الإشعارات وفق حوار النظام.
7. تحقق من استقبال heartbeat والعينات في جداول التجربة، ومطابقة الزيارات بعد توفر موقع زيارة مؤهل، ومراجعة الحالات في Filament. تنبيهات الإدارة تُرسل داخل قاعدة بيانات Filament وتُعرض من لوحة التحكم، وليست إشعارات FCM للمدير.
8. اختبر إيقاف GPS، وإيقاف إذنه، وإغلاق التطبيق، وانقطاع الإنترنت، وانتهاء مدة الجلسة، ورفض المشاركة وتسجيل الخروج. لا تستنتج مخالفة تلقائياً من صمت GPS أو قرب الموقع.

## تشغيل المهام والتحقق
- `php artisan schedule:list` يعرض **purge/reconcile** المجدولين مسبقاً داخل `CleaningServiceProvider`، إضافةً إلى `cleaning:notify-stale-compliance-workers` المجدول كل 30 دقيقة. تجنّب جدولة الأمرين القديمين مرة ثانية.
- `php artisan cleaning:notify-stale-compliance-workers` يمكن استدعاؤه يدوياً للاختبار. يُرسل إشعاراً إجمالياً واحداً حتى مع تعدد العمال، ويحدّ تكرار الإشعارات مدة 55 دقيقة.
- `php artisan test tests/Feature/Cleaning/WorkerComplianceIngestionTest.php tests/Feature/Cleaning/WorkerCompliancePilotEnrollmentTest.php tests/Feature/Cleaning/WorkerComplianceHealthAlertTest.php tests/Feature/Cleaning/WorkerCompliancePrivacyNoticeTest.php`.

## قيود لا يجب تجاوزها
- لا يمكن ضمان الحصول على الموقع 24/7 بسبب قيود نظام التشغيل وإيقاف التطبيق والاتصال.
- **iOS:** لم يُنفَّذ مسار خلفي موثوق ولا توجد اختبارات على جهاز iPhone في هذه الجولة؛ لا تعرض التجربة على أجهزة iOS ولا تُجبر المستخدم على أذونات الموقع لاستخدام التطبيق.
- يخضع التتبع المستمر خارج المهام لمراجعة قانونية وموافقة سياسات Android وApple، ولا يُستخدم لتوقيع عقوبة آلية. 
- لا تستخدم سياسة الخصوصية التجريبية كاعتماد قانوني نهائي، بل أكمل بيانات الشركة ووسيلة اتصالها قبل الإطلاق العام.
