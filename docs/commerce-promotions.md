# عروض المطاعم والمتاجر

يدعم القسمان خصم المنتجات وعرض «اشترِ X واحصل على Y»، مع مكافأة من نفس المنتج أو الأرخص المؤهل أو منتجات محددة. الحساب وتوزيع الوحدات يأتيان من الباك إند؛ التطبيقات تعرض نتيجة التسعير ولا تعيد حساب المكافأة محلياً.

## التوافق وعقد المتجر

- عقد المطعم يبقى `offerType=product_discount|buy_x_get_y`.
- عقد المتجر يضيف `promotionType=product_discount|buy_x_get_y`، ويبقي `offerType=percent|value` للخصومات القديمة. لا تعِد تفسير `offerType` القديم باعتباره آلية العرض.
- الحقول المشتركة: `buyQuantity`, `rewardQuantity`, `rewardType`, `rewardDiscountPercent`, `isRepeatable`, `maxApplicationsPerOrder`, `rewardProductIds`, `showUpcoming`, `ruleVersion`.
- لا تُرسل `offerType` أو `discountValue` أو `discountPercent` للمتجر عند إنشاء مكافأة. للمطعم لا تُرسل `discountType` أو `discountValue` لهذا النوع.
- منتجات الشراء في المتجر تبقى `offerProducts: [{productId: ...}]`. منتجات المكافأة المحددة مستقلة عنها وتابعة للمتجر نفسه. المنتجات المنتهية أو غير المتاحة لا تؤهل للمكافأة.
- تعريف القاعدة يقبل 1–100 للشراء والمكافأة، والسطر النهائي يقبل حتى 200 وحدة بعد دمج الطلبات المتكررة.
- قوائم المالك تعيد `meta.advancedPromotionsEnabled`؛ نموذج الإنشاء يتحقق منه قبل إتاحة النوع المتقدم.

مثال إنشاء عرض متجر:

```json
{
  "name": "اشترِ عبوتين واحصل على عصير",
  "promotionType": "buy_x_get_y",
  "buyQuantity": 2,
  "rewardQuantity": 1,
  "rewardType": "specific_products",
  "rewardDiscountPercent": 100,
  "isRepeatable": true,
  "maxApplicationsPerOrder": 2,
  "offerProducts": [{"productId": 101}],
  "rewardProductIds": [102],
  "isActive": true,
  "showUpcoming": false
}
```

## السلة وتأكيد الطلب

- إضافة عرض متجر كاملة: `POST /api/v1/user/supermarket/cart/offers/{offerId}`. يرسل العميل `productId`, `quantity`, `ruleVersion` والخيارات، و`rewardProductId` وخيارات المكافأة للمنتجات المحددة. تُرفض العملية كاملة عند نفاد المكافأة أو بلوغ حد العرض؛ لا تُضاف وحدات كاملة السعر بصمت.
- اختيار مكافأة من السلة: `POST /api/v1/user/supermarket/carts/{cartId}/promotions/{offerId}/claim`. يرسل العميل **الكمية النهائية المطلوبة** و`expectedRevision`، لا كمية تُضاف عند كل إعادة محاولة.
- عناصر المكافأة تحمل `rewardOfferId` وتبقى منفصلة عن الأسطر العادية. إذا زال الاستحقاق تظهر `invalidRewards` و`invalidRewardQuantity` ويُمنع الدفع. يمكن حذف السطر أو طلب تحويله صراحةً عبر `convertRewardToOrdinary` عند تحديثه.
- النتيجة تتضمن `pricingFingerprint`, `appliedPromotions`, `promotionProgress` وتفصيل `allocations`, `freeQuantity`, `rewardQuantity` لكل سطر.
- إنشاء الطلب المتقدم يتطلب عرض السعر من `/api/v1/user/commerce/checkout/quotes` ثم `quoteId` و`Idempotency-Key`. تغير القاعدة أو السعر أو الخيارات أو المخزون يبطل عرض السعر القديم.
- لا يتراكم خصم المنتج مع مكافأة على الوحدات نفسها؛ يختار المحرك التوفير الأفضل. الكوبون يخضع لقواعده الحالية بعد احتساب أسعار المنتجات. الإضافات المدفوعة تبقى مدفوعة على الوحدات المجانية أيضاً.
- تُحفظ الأسعار وقواعد العرض وأسماؤه داخل الطلب وبنوده، ويُحجز مجموع الوحدات المدفوعة والمكافآت مرة واحدة. سجل `sm_order_promotion_applications` هو مصدر إحصاء الاستخدام المتقدم، ويُعكس عند انتقال الطلب إلى الإلغاء.
- سياسة المتجر الحالية التي تمنع الإلغاء العادي بعد الطلب تبقى كما هي؛ هذه الإضافة لا تنشئ سياسة إرجاع أو استرداد جديدة.

## التفعيل والنشر

1. انشر الترحيلات الإضافية قبل تشغيل الكود الذي يقرأ حقولها. لم تُعدّل ملفات الترحيلات القديمة.
2. العلمان الافتراضيان بالباك إند هما `RESTAURANT_PROMOTIONS_ENABLED=false` و`SUPERMARKET_PROMOTIONS_ENABLED=false`.
3. نسخ Flutter المتوافقة تُبنى باستخدام `--dart-define=RESTAURANT_PROMOTIONS=true` و/أو `--dart-define=SUPERMARKET_PROMOTIONS=true`، كل علم في تطبيق المالك الموافق وتطبيق العميل. تبقى القيم الافتراضية `false`.
4. الترويسات `X-Restaurant-Promotions: 1` و`X-Supermarket-Promotions: 1` تُضاف فقط لمسارات القسم المتوافقة. العقود القديمة لا تُعرض عليها آلية لا تفهمها، وتُمنع من تعديل قاعدة متقدمة أو تأكيد سلة تحتاج مراجعة عرض متقدم.
5. اجتز الاختبارات وراجع السيناريوهات على بيئة تجريبية ثم فعّل كل قسم بصورة مستقلة. راقب أخطاء `PROMOTION_*`, `QUOTE_CHANGED` وسجلات `*_promotion_bounded_fallback`.
6. عند تعطيل العلم، لا تُحذف نية المكافأة أو مراجعة السعر المحفوظة بالسلة؛ يجب عرض تسعير جديد ومراجعته قبل الشراء.

## التحقق

```sh
php vendor/bin/pest -c phpunit.xml tests/Unit/Restaurant/RestaurantPromotionEngineTest.php tests/Feature/RestaurantPromotions tests/Feature/SupermarketPromotions tests/Feature/UserModule/CommerceCheckoutQuoteTest.php tests/Feature/UserModule/UserSupermarketCheckoutHardeningTest.php
```

اختبارات دورة المطعم نُقلت إلى `tests/Feature/RestaurantPromotions` لتدخل المجموعة الافتراضية. لم تعد تحتاج قاعدة MySQL المحلية المسماة سابقاً `alnadha_worker_test`.

يتحقق Workflow `commerce-promotions.yml` من SQLite وMySQL. اختبار التزامن يستخدم عمليتين مستقلتين للتنافس على آخر مجموعة شراء ومكافأة:

```sh
php vendor/bin/phpunit -c phpunit.promotions-mysql.xml
```

هذا الاختبار الأخير يعمل على Linux مع `pcntl` وقاعدة **محلية مخصصة قابلة للمسح** باسم `dllni_promotions_test`؛ يتحقق من هويتها قبل `migrate:fresh`. يُتخطّى على Windows. لا تُوجّهه إلى قاعدة تشغيلية.

في كل تطبيق مالك:

```sh
flutter test test/promotion_payload_test.dart test/promotion_fields_test.dart
```

وفي تطبيق العميل:

```sh
flutter test test/features/restaurant_v2/restaurant_v2_promotions_test.dart test/features/supermarket_v2/supermarket_promotions_test.dart test/features/supermarket_v2/supermarket_v2_offer_detail_test.dart test/features/supermarket_v2/supermarket_v2_cart_detail_test.dart test/features/supermarket_v2/supermarket_v2_checkout_test.dart
```

تغطي الاختبارات المكافآت الثلاث، المجاني والخصم الجزئي، الإضافات المطلوبة، التكرار وحدّه، سقف 200 وحدة، تغير القاعدة، نفاد المخزون، توافق النسخ القديمة، إعادة المحاولة، وحفظ بيانات الطلب. اختبارات الواجهات تشمل العربية والهاتف والوضع الأفقي والخط الكبير والوضع الداكن لنموذج المالك، وتنتج صور مراجعة داخل `build/qa`.
