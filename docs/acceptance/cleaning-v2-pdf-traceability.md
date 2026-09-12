# مصفوفة قبول ميزات التنظيف v2

آخر تحديث: 2026-09-11

## سلطة المتطلبات

ملفات PDF الخمسة المرفقة هي مصدر القبول النهائي. ملف `deep-research-report.md` استُخدم كمرجع تدقيق مساعد فقط، ولا يحل محل ملفات التوصيف الأصلية ولا يضيف متطلبات إليها.

المراجع المعتمدة:

1. `توصيف_ميزة_طلب_عامل_بوقت_مفتوح_RTL_نهائي.pdf`
2. `توصيف_ميزة_المناسبات_متعددة_الأيام_RTL_محدث.pdf`
3. `توصيف_ميزة_الخدمات_الخاصة_RTL_محدث_نهائي.pdf`
4. `توصيف_ميزة_الحجز_الدوري_لخدمات_التنظيف_RTL_معدل_نهائي_v2.pdf`
5. `توصيف_ميزة_مواد_التنظيف_الأولية_محدث_RTL.pdf`

## التتبع من المتطلب إلى التنفيذ

| الميزة | بنود القبول من PDF | تنفيذ الخادم والإدارة | تنفيذ التطبيقات | الاختبارات الآلية |
|---|---|---|---|---|
| مواد التنظيف | فصل النوع المسعّر عن المنتج، قواعد الغرفة والحجم، وحدات ثابتة، صورة ومخزون، حجز وتجهيز طقم، تكلفة منفصلة ولقطات ثابتة | `CleaningMaterialQuoteService`, `CleaningMaterialInventoryService`, `CleaningBookingMaterialKit`, ترحيل `2026_09_08_180000_complete_cleaning_suite_v2.php`، وموارد Filament للأنواع والمنتجات والأطقم | مفتاح واحد فقط لتوفير المنصة للمواد في `ClCleaningExtrasSectionWidget`، وعرض/تأكيد حالة الطقم في تطبيق العامل | `CleaningAgentBOperationalLifecycleTest`, `CleaningAgentBCompletionRegressionTest`, `CleaningV2PublicContractTest`, `CleaningOperationalExtrasLifecycleTest`, `CleaningV2AdminResourcesTest` |
| الخدمات الخاصة | طلب مستقل أو مع التنظيف/المواد، ربط بجلسات مختارة، عناصر متعددة عشرية، اتساخ لكل عنصر، صور، جنس/مهارات، تسعير وأجر وتكلفة وتنقل مستقلة، معدات محجوزة، حالة مستقلة، ومنعها مع الوقت المفتوح | `CleaningSpecialServiceQuoteService`, `CleaningBookingSessionWorkerEligibilityService`, `CleaningOperationalExtrasService`, جداول العناصر والمهارات والصلاحيات وحجوزات المعدات واللقطات المالية، وموارد Filament للكتالوج والاتساخ والمعدات | محرر عناصر متعدد مع قياس عشري واتساخ وملاحظات وربط جلسات في تطبيق العميل، وإجراءات البدء/الإنجاز/التعذر والمعدات في تطبيق العامل | `CleaningAgentBQuoteServicesTest`, `CleaningV2PublicContractTest`, `CleaningOperationalExtrasLifecycleTest`, اختبارات model/widget في التطبيقين |
| الوقت المفتوح | نوع مستقل، سقف متوقع وحد صلب، حجز الجدول، بدء العداد بعد OTP، مبلغ ووقت حيّان، تمديد العامل الحالي فقط، نهاية باتفاق الطرفين وتصعيد إداري، حد أدنى 60 دقيقة وتقريب 15 دقيقة، جلسات يومية مستقلة | `OpenTimeScheduleService`, `CleaningOpenTimeBillingService`, `CleaningOpenTimeLifecycleService`, متحكما العميل والعامل، مسارات الجلسة، ولقطات السياسة في الحجز والجلسة | اختيار السقف والجلسات، بطاقة عداد تعتمد `serverNow`، طلب التمديد والنهاية، وقرارات العامل | `CleaningOpenTimePreliminaryTest`, `OpenTimeScheduleServiceTest`, `OpenTimeMultiDayScheduleTest`, `OpenTimeSessionLifecycleTest`, `CleaningOpenTimeCustomerCompletionTest`, واختبارات Flutter للعداد والجلسات |
| الحجز الدوري | أب واحد وجلسات مستقلة حتى 30 يوماً، قبول كلي أو جزئي، بحث واضح، Skip/Pause قبل 24 ساعة، عدم الحركة قبل 15 دقيقة، عدم استبدال صامت، موافقة كل عامل متأثر على التعديل، ثم استبدال/تراجع/إلغاء صريح | `RecurringCleaningScheduleService`, `RecurringCleaningScheduleRevisionService`, `CleaningScheduleChangeApprovalService`, جداول الطلب والقرارات، ومسارات العميل والعامل | بطاقة حالة الجلسات وطلبات التغيير وخيارات الاستبدال/التراجع/الإلغاء للعميل، وشريط قبول/رفض للعامل | `RecurringCleaningScheduleTest`, `RecurringCleaningScheduleRevisionTest`, اختبارات pause/attendance/coverage/continuity، واختبارات model/widget للقرار في التطبيقين |
| المناسبات متعددة الأيام | طلب رئيسي وجلسات مستقلة، عدد عمال ثابت لكل جلسة، قبول جزئي، تغطية مستقلة، حركة وطلب ساخن وعقوبة ثقة للجلسة، تنقل ثابت/نسبي، حد سماح ولقطات مالية، تقييم كل عامل فريد، أنواع وحقول ديناميكية | `EventAssistanceScheduleService`, `EventAssistanceSessionRescheduleService`, `EventAssistanceReviewService`, `ValidatesDynamicCleaningEvent`, كتالوج الأنواع والحقول والعلاقات، وموارد Filament؛ ويقوم الترحيل بربط `property_details.event_type/eventType` بالـslug المرحّل | نموذج ديناميكي مرتب من API مع حفظ الإجابات، وجدولة متعددة الجلسات وعرض التغطية والتقييم | `EventAssistanceMultiDayScheduleTest`, `MultiDayEventAssistanceCloseoutTest`, `EventAssistanceReviewTest`, `EventAssistancePreviousWorkersScheduleTest`, `CleaningEventBookingVisibilityTest`, `CleaningSuiteConfigV2Test`, واختبارات Flutter للنموذج والجدولة |

## العقود والتوافق

- العقد الحديث للمواد هو `materials: { providedByPlatform }` مع استمرار قبول `requestMaterials` القديم.
- العقد الحديث للخدمات هو `specialServices[].items[]` ويدعم `quantity`, `dirtinessLevelId`, `notes`, `attachments`; تتحول صيغة الخدمة القديمة إلى عنصر واحد.
- العقد الحديث للوقت المفتوح هو `openTime: { workerCount, expectedMaxMinutes, sessions? }`; القيمة الافتراضية للطلبات القديمة 480 دقيقة.
- العقد الحديث للمناسبة هو `event: { eventTypeId, dynamicAnswers }`; تبقى slugs القديمة مقروءة بعد ترحيلها إلى الكتالوج.
- استجابات v2 تنشر `schemaVersion`, `capabilities`, و`serverNow`، وتعرض لقطات التسعير والسياسة من السجل بدلاً من إعادة حساب التاريخ القديم.
- إجراءات التمديد والنهاية والخدمات وطقم المواد والمعدات وقرارات تعديل الجدول قابلة لإعادة الإرسال دون تكرار الأثر أو إعادة كتابة حالة نهائية مختلفة.

## مراجعة ui-ux-pro-max

استُخدمت قواعد `ui-ux-pro-max` المحلية كـ fallback موثق لأن مشغل Python الاختياري غير متاح في بيئة Windows الحالية. مصدر التصميم الأعلى لكل تطبيق هو `design-system/MASTER.md` مع page overrides للوقت المفتوح والحجز الدوري والمناسبات والخدمات الخاصة/العمليات.

التحقق الآلي يغطي RTL، النص الكبير، عرض 375px، الوضع الداكن للمكونات الجديدة، semantics، أهداف لمس 48dp، حالات التحميل/الخطأ/الانتظار، ومنع تكرار الإرسال. لا تُستخدم Emoji كأيقونات.

## بوابات التسليم

- Workflow الخادم يشغّل الترحيلات من الصفر، lint، كامل `tests/Unit/Cleaning` و`tests/Feature/Cleaning` واختبارات UserModule المرتبطة بالتنظيف/الوقت المفتوح/المناسبات وموارد Filament، ويحفظ JUnit والتغطية والسجل.
- Workflow كل تطبيق يشغّل format/analyze وكامل `flutter test --coverage` وبناء Android، ويحفظ التقارير وAPK.
- Job مستقل على macOS يبني iOS باستخدام `flutter build ios --no-codesign` ويحفظ `Runner.app`.
- جميع الـ workflows تعمل على `v2` و`workflow_dispatch`؛ لا يعتمد القبول على staging أو اختبار يدوي.
