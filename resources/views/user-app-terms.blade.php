<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AL NADHA - Terms of Use - User Application</title>
  <style>
    :root {
      --bg: #f7f7f5;
      --paper: #ffffff;
      --ink: #1f2933;
      --muted: #64748b;
      --brand: #0f766e;
      --brand-dark: #115e59;
      --line: #e5e7eb;
      --soft: #f0fdfa;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font-family: Arial, Tahoma, sans-serif;
      line-height: 1.85;
    }

    a {
      color: inherit;
    }

    .page {
      max-width: 980px;
      margin: 0 auto;
      padding: 24px 14px 56px;
    }

    .document {
      overflow: hidden;
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 8px;
      box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
    }

    .hero {
      padding: 34px 28px 24px;
      text-align: center;
      background: linear-gradient(135deg, #ecfeff, #ffffff);
      border-bottom: 1px solid var(--line);
    }

    .hero h1 {
      margin: 0 0 8px;
      color: var(--brand);
      font-size: clamp(28px, 5vw, 42px);
      line-height: 1.25;
    }

    .hero p {
      margin: 4px 0;
      color: var(--muted);
      font-weight: 700;
    }

    .nav {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 10px;
      margin-top: 22px;
    }

    .nav a {
      min-width: 160px;
      padding: 9px 14px;
      border: 1px solid var(--brand);
      border-radius: 8px;
      color: var(--brand-dark);
      text-decoration: none;
      font-weight: 700;
    }

    .nav a.active {
      background: var(--brand);
      color: #ffffff;
    }

    .content {
      padding: 24px 28px 36px;
    }

    .legal-section + .legal-section {
      margin-top: 44px;
      padding-top: 34px;
      border-top: 1px solid var(--line);
    }

    .title {
      margin: 0 0 6px;
      color: var(--brand-dark);
      text-align: center;
      font-size: 28px;
      line-height: 1.35;
    }

    .subtitle {
      margin: 3px 0;
      color: var(--muted);
      text-align: center;
      font-weight: 700;
    }

    .meta {
      margin: 24px 0 30px;
      padding: 16px 18px;
      background: #f8fafc;
      border: 1px solid var(--line);
      border-radius: 8px;
      color: #334155;
      text-align: center;
    }

    .meta p {
      margin: 4px 0;
    }

    .article {
      margin: 30px 0 16px;
      padding: 12px 16px;
      background: var(--soft);
      border: 1px solid #ccfbf1;
      border-radius: 8px;
      color: var(--brand-dark);
      text-align: center;
      font-size: 22px;
      line-height: 1.45;
    }

    .lead {
      margin: 10px 0 12px;
      font-weight: 700;
    }

    p,
    li {
      font-size: 16px;
    }

    ol {
      margin: 8px 0 12px;
      padding-inline-start: 26px;
    }

    [dir="rtl"] ol {
      padding-inline-start: 0;
      padding-inline-end: 26px;
    }

    li {
      margin: 7px 0;
    }

    .alpha {
      list-style-type: lower-alpha;
    }

    [dir="rtl"] {
      direction: rtl;
      text-align: right;
    }

    [dir="ltr"] {
      direction: ltr;
      text-align: left;
    }

    footer {
      padding: 18px 28px;
      border-top: 1px solid var(--line);
      color: var(--muted);
      text-align: center;
      font-size: 14px;
    }

    @media (max-width: 640px) {
      .content,
      .hero {
        padding-left: 18px;
        padding-right: 18px;
      }

      .nav a {
        width: 100%;
      }

      p,
      li {
        font-size: 15px;
      }
    }
  </style>
</head>
<body>
  <main class="page">
    <article class="document">
      <header class="hero">
        <h1>ع الندهة | AL NADHA</h1>
        <p>شروط الاستخدام - Terms of Use</p>
        <p>تطبيق المستخدم - User Application</p>
        <nav class="nav" aria-label="Legal pages">
          <a href="{{ route('legal.user-app') }}">سياسة الخصوصية</a>
          <a class="active" href="{{ route('legal.user-app.terms') }}">شروط الاستخدام</a>
        </nav>
      </header>

      <section class="content">
        <section class="legal-section" dir="rtl" lang="ar">
          <h2 class="title">شروط الاستخدام</h2>
          <p class="subtitle">تطبيق المستخدم - العميل</p>
          <p class="subtitle">النسخة العربية</p>

          <div class="meta">
            <p>الجهة المشغلة: منصة AL NADHA - شهادة تسجيل تاجر فرد رقم 96849 لعام 2025</p>
            <p>العنوان: الجمهورية العربية السورية - حلب - الميرديان - شارع محمد فارس</p>
            <p>للتواصل والدعم: 0944489418 | alnadhaservices@gmail.com | www.alnadha.net</p>
          </div>

          <h3 class="article">المادة الأولى - قبول الشروط</h3>
          <ol>
            <li>باستخدامك تطبيق ع الندهة | AL NADHA أو تسجيلك فيه بأي طريقة كانت، فإنك تقر بأنك قرأت هذه الشروط وفهمتها وقبلتها كاملة بما تتضمنه من حقوق والتزامات.</li>
            <li>إن كنت لا توافق على أي بند من هذه الشروط، يجب عليك التوقف عن استخدام التطبيق فوراً.</li>
            <li>تحتفظ المنصة بحق تعديل هذه الشروط في أي وقت مع إشعار مسبق للمستخدمين قبل سبعة أيام من تاريخ سريان التعديل.</li>
            <li>استمرارك في استخدام التطبيق بعد انقضاء مهلة الإشعار يعد قبولاً صريحاً وضمنياً بجميع التعديلات.</li>
          </ol>

          <h3 class="article">المادة الثانية - شروط التسجيل والحساب</h3>
          <ol>
            <li>يشترط أن يكون عمر المستخدم 18 سنة ميلادية أو أكثر عند التسجيل. باستخدامك التطبيق فإنك تقر بأنك بلغت هذا السن.</li>
            <li>لا تتوفر في التطبيق حالياً وسيلة للتحقق المباشر من عمر المستخدم. وعليه فإن المنصة تعتمد على إقرار المستخدم، وتبرأ ذمتها من أي مسؤولية تترتب على تسجيل قاصر ببيانات مزورة أو غير صحيحة.</li>
            <li>في حال اكتشاف أن المستخدم دون سن الثامنة عشرة، تحتفظ المنصة بحق تعليق حسابه أو حذفه فوراً دون إشعار مسبق مع الاحتفاظ ببياناته للأغراض القانونية.</li>
            <li>رقم الهاتف المسجل يعد هوية المستخدم الرقمية، ولا يسمح بأكثر من حساب واحد لكل رقم هاتف.</li>
            <li>أنت مسؤول مسؤولية كاملة وحصرية عن الحفاظ على سرية بيانات حسابك وعدم الإفصاح عنها لأي طرف ثالث.</li>
            <li>أي نشاط يتم من حسابك يعد صادراً منك شخصياً سواء أجريته بنفسك أم من خلال أي شخص آخر تمكن من الوصول لحسابك.</li>
            <li>يجب إخطار المنصة فوراً عند الاشتباه بأي استخدام غير مصرح لحسابك.</li>
          </ol>

          <h3 class="article">المادة الثالثة - طبيعة المنصة وحدود مسؤوليتها</h3>
          <p class="lead">منصة ع الندهة | AL NADHA وسيط تقني محايد يربط بين العملاء ومقدمي الخدمات. المنصة ليست طرفاً في عقد البيع أو الخدمة المبرم بين العميل ومقدم الخدمة، ولا تكتسب أي صفة بائع أو مزود خدمة أو صاحب عمل بأي حال من الأحوال.</p>
          <ol>
            <li>المنصة غير مسؤولة عن جودة المنتجات أو الخدمات المقدمة من مقدمي الخدمة.</li>
            <li>المنصة غير مسؤولة عن أي ضرر مباشر أو غير مباشر أو عرضي أو تبعي ينشأ عن استخدام خدمات مقدمي الخدمات.</li>
            <li>المنصة غير مسؤولة عن أي تصرف أو إهمال أو سلوك صادر من عامل تنظيف أو مقدم خدمة مناسبات.</li>
            <li>المنصة غير مسؤولة عن أي خسارة أو ضرر ناجم عن تعطل التطبيق أو انقطاع الخدمة لأي سبب كان بما في ذلك القوة القاهرة.</li>
            <li>إبراء ذمة المنصة من المسؤولية لا يمنع تدخلها كوسيط للمساعدة في حل النزاعات بين الأطراف.</li>
          </ol>

          <h3 class="article">المادة الرابعة - الخدمات المتاحة</h3>
          <ol>
            <li>تتيح المنصة حالياً للمستخدم حجز خدمات التنظيف المنزلي.</li>
            <li>تتيح المنصة حالياً للمستخدم حجز خدمات المساعدة والدعم في المناسبات والفعاليات.</li>
            <li>تمثل الخدمتان المذكورتان أعلاه (التنظيف والمناسبات) نطاق الخدمات المتاحة فعلياً عند إطلاق التطبيق، وتحتفظ المنصة بحق إضافة خدمات أخرى مستقبلاً (كخدمات المطاعم والتوصيل والتسوق) دون أن يستلزم ذلك إشعاراً مسبقاً للمستخدم.</li>
            <li>تحتفظ المنصة بحق تعليق أي خدمة أو إيقافها أو تعديل نطاقها في أي وقت دون إشعار مسبق.</li>
            <li>توفر الخدمات مقيد بالنطاق الجغرافي لمدينة حلب، ولا تتحمل المنصة مسؤولية عدم توفر الخدمة في أي منطقة.</li>
          </ol>

          <h3 class="article">المادة الخامسة - الطلبات والدفع</h3>
          <ol>
            <li>يتم سداد قيمة الخدمة إما نقداً عند إتمام الخدمة، أو إلكترونياً عبر واجهات الدفع الإلكتروني التي توفرها المنصة داخل التطبيق، وذلك وفق الخيار الذي يختاره المستخدم عند تأكيد الطلب.</li>
            <li>بتأكيدك الطلب فإنك تتعهد بالتواجد في العنوان أو المكان المحدد بالموعد المتفق عليه، وبتسديد كامل المبلغ المستحق للخدمة بالوسيلة التي اخترتها.</li>
            <li>في حال طلبك عاملة أنثى تحديداً، فإنك تلتزم عبر التطبيق بضمان وجود امرأة بالغة من أفراد المنزل حاضرة أثناء تأدية الخدمة. وفي حال عدم التزامك بهذا الشرط وتعذر دخول العاملة أو تأدية الخدمة نتيجة ذلك، يعد ذلك بمثابة إلغاء من قبلك، ويترتب عليك دفع كامل قيمة الخدمة المطلوبة كتعويض، ويحق للعاملة الانصراف دون أداء الخدمة.</li>
            <li>
              يحق لك إلغاء الخدمة المحجوزة قبل حلول موعدها، على أن تلتزم عند الإلغاء بدفع تعويض للعامل عن الوقت والترتيبات المحجوزة، وفق الجدول التالي:
              <ol class="alpha">
                <li>الإلغاء قبل أكثر من 24 ساعة من الموعد المحدد: دون أي رسوم.</li>
                <li>الإلغاء خلال أقل من 24 ساعة وحتى 3 ساعات قبل الموعد: نسبة 50% من قيمة الخدمة.</li>
                <li>الإلغاء خلال أقل من 3 ساعات من الموعد، أو بعد تحرك العامل إلى الموقع، أو عدم تواجدك عند الوصول: كامل قيمة الخدمة.</li>
              </ol>
            </li>
            <li>
              تحتفظ المنصة بحق تحصيل أي مبالغ مستحقة بموجب البندين (3) و(4) أعلاه من خلال إحدى الوسائل التالية أو أكثر بحسب توفرها:
              <ol class="alpha">
                <li>الخصم المباشر من وسيلة الدفع الإلكترونية المسجلة أو المرتبطة بحساب المستخدم داخل التطبيق.</li>
                <li>الخصم من رصيد المحفظة الإلكترونية للمستخدم داخل التطبيق، إن وجدت.</li>
                <li>تسجيل المبلغ كمديونية على حساب المستخدم، وتعليق قدرته على حجز أي خدمة جديدة لحين سداد كامل المستحقات.</li>
                <li>في حال تعذر التحصيل بأي من الوسائل السابقة، تحتفظ المنصة بحق تعليق الحساب أو حذفه نهائياً، مع اتخاذ ما تراه مناسباً من إجراءات قانونية لاسترداد المبلغ المستحق.</li>
              </ol>
            </li>
            <li>رفض السداد أو التهرب منه بشكل متكرر يعد سبباً كافياً لتعليق حساب المستخدم بشكل دائم وفق المادة الثامنة من هذه الشروط.</li>
          </ol>

          <h3 class="article">المادة السادسة - التقييمات والمراجعات</h3>
          <ol>
            <li>بعد اكتمال كل طلب أو خدمة، يتاح للمستخدم تقديم تقييم لمقدم الخدمة.</li>
            <li>التقييمات والتعليقات التي تنشرها تصبح ملكاً للمنصة وتحتفظ بحق استخدامها وعرضها وتوظيفها في التسويق دون أي مقابل.</li>
            <li>يجب أن تكون تقييماتك صادقة وتعكس تجربتك الفعلية، ويحظر التقييم الكيدي أو الملفق.</li>
            <li>المنصة غير مسؤولة عن محتوى التقييمات، وتحتفظ بحق حذف أي تقييم يخالف سياساتها.</li>
          </ol>

          <h3 class="article">المادة السابعة - سلوك المستخدم والمحظورات</h3>
          <ol>
            <li>يحظر استخدام التطبيق لأغراض غير مشروعة أو تخالف القوانين السورية النافذة.</li>
            <li>يحظر إدخال بيانات مزورة أو مضللة عند التسجيل أو عند تقديم الطلبات.</li>
            <li>يحظر التواصل مع مقدمي الخدمة خارج التطبيق لأغراض تجارية أو لتجاوز المنصة.</li>
            <li>يحظر إساءة معاملة مقدمي الخدمة أو التحرش بهم أو التهديد بأي شكل.</li>
            <li>يحظر محاولة اختراق التطبيق أو استغلال أي ثغرة فيه أو التلاعب بأي بيانات.</li>
            <li>يحظر إنشاء أكثر من حساب واحد.</li>
            <li>يحظر نشر أي محتوى مسيء أو مخالف للآداب العامة أو القوانين السورية.</li>
            <li>يحظر استخدام الحساب لأي غرض تجاري دون موافقة خطية مسبقة من المنصة.</li>
          </ol>

          <h3 class="article">المادة الثامنة - تعليق الحسابات وإنهاؤها</h3>
          <p class="lead">تحتفظ المنصة بالحق المطلق وغير المقيد في تعليق أي حساب أو إيقافه أو حذفه بشكل مؤقت أو دائم، في أي وقت وبدون إشعار مسبق، وذلك في الحالات التالية وما يماثلها:</p>
          <ol>
            <li>مخالفة أي بند من بنود هذه الشروط مهما كانت درجتها.</li>
            <li>تقديم بيانات مزورة أو غير صحيحة في أي مرحلة.</li>
            <li>الاشتباه في أي نشاط احتيالي أو غير مشروع.</li>
            <li>تكرار الشكاوى من مقدمي الخدمات أو العملاء الآخرين.</li>
            <li>رفض الدفع أو التغيب المتكرر عند موعد الخدمة.</li>
            <li>إساءة استخدام قسم النزاعات أو تقديم شكاوى كيدية متكررة.</li>
            <li>انتهاك أي قانون سوري نافذ.</li>
            <li>أي سبب تراه المنصة كافياً للمحافظة على سلامة المنصة وحماية مقدمي الخدمات والمستخدمين الآخرين.</li>
            <li>لا يحق للمستخدم المطالبة بأي تعويض جراء تعليق حسابه أو حذفه.</li>
          </ol>

          <h3 class="article">المادة التاسعة - نظام النزاعات</h3>
          <ol>
            <li>تتدخل المنصة كوسيط محايد لحل النزاعات الناشئة بين المستخدم ومقدمي الخدمة عبر قسم النزاعات في التطبيق.</li>
            <li>يجب على المستخدم تقديم الشكوى عبر قسم النزاعات خلال مدة أقصاها 24 ساعة من انتهاء تنفيذ الخدمة أو من واقعة النزاع، وإلا سقط حقه في تقديم الشكوى داخل المنصة، دون إخلال بحقه في اللجوء للقضاء.</li>
            <li>قرار المنصة في النزاعات نهائي وملزم داخل المنصة، ولا يمنع ذلك أي طرف من اللجوء للقضاء.</li>
            <li>يحق لأي طرف في النزاع طلب نسخة من سجلات العملية لاستخدامها في أي إجراء قانوني.</li>
            <li>تتخذ المنصة الإجراءات المناسبة بحق المسؤول عن النزاع سواء أكان عميلاً أم مقدم خدمة.</li>
            <li>المنصة غير مسؤولة عن تعويض أي طرف مالياً إلا في الحالات التي تقرها المنصة صراحة ووفق تقديرها المطلق.</li>
          </ol>

          <h3 class="article">المادة العاشرة - الإشعارات والتسويق</h3>
          <ol>
            <li>بتسجيلك في التطبيق فإنك توافق على تلقي إشعارات تتعلق بطلباتك وحسابك وتحديثات الخدمة.</li>
            <li>تحتفظ المنصة بحق إرسال إشعارات ترويجية وعروض وأخبار.</li>
            <li>يحق لك التحكم في إشعاراتك كاملاً من إعدادات التطبيق - إما تفعيل جميع الإشعارات أو إيقافها جميعاً.</li>
            <li>إيقاف الإشعارات قد يؤثر على تجربتك في التطبيق وتلقيك للتحديثات المهمة.</li>
          </ol>

          <h3 class="article">المادة الحادية عشرة - الملكية الفكرية</h3>
          <ol>
            <li>جميع حقوق الملكية الفكرية للتطبيق بما يشمل التصميم والكود والمحتوى والعلامة التجارية محفوظة لمنصة ع الندهة | AL NADHA.</li>
            <li>يحظر نسخ أي جزء من التطبيق أو استنساخه أو توزيعه أو الاستفادة منه تجارياً دون إذن خطي مسبق.</li>
            <li>المحتوى الذي تنشره في التطبيق كالتقييمات والتعليقات تمنح المنصة بموجبه رخصة غير حصرية وغير محدودة زمنياً لاستخدامه وعرضه وتوظيفه في التسويق.</li>
          </ol>

          <h3 class="article">المادة الثانية عشرة - القانون الواجب التطبيق</h3>
          <ol>
            <li>تخضع هذه الشروط وتفسر وفق أحكام القانون السوري النافذ.</li>
            <li>أي نزاع ينشأ عن هذه الشروط أو يتعلق بها يخضع لاختصاص المحاكم السورية وتحديداً محاكم مدينة حلب.</li>
            <li>في حال تعارض أي بند من هذه الشروط مع القانون السوري النافذ يعد ذلك البند لاغياً دون أن يؤثر على سريان باقي البنود.</li>
          </ol>
        </section>

        <section class="legal-section" dir="ltr" lang="en">
          <h2 class="title">Terms of Use</h2>
          <p class="subtitle">User Application</p>
          <p class="subtitle">English Version</p>

          <div class="meta">
            <p>Operator: AL NADHA Platform - Individual Trader Registration No. 96849, 2025</p>
            <p>Address: Syrian Arab Republic - Aleppo - Al-Meridian - Mohamed Fares Street</p>
            <p>Support: 0944489418 | alnadhaservices@gmail.com | www.alnadha.net</p>
          </div>

          <h3 class="article">Article 1 - Acceptance of Terms</h3>
          <ol>
            <li>By using or registering on the AL NADHA application in any manner, you acknowledge that you have read, understood, and fully accepted these Terms, including all rights and obligations contained herein.</li>
            <li>If you do not agree to any provision of these Terms, you must immediately cease using the application.</li>
            <li>The Platform reserves the right to amend these Terms at any time, with seven (7) days' prior notice to users before the amendment takes effect.</li>
            <li>Your continued use of the application after the notice period expires constitutes your explicit and implicit acceptance of all amendments.</li>
          </ol>

          <h3 class="article">Article 2 - Registration &amp; Account</h3>
          <ol>
            <li>Users must be at least 18 years of age at the time of registration. By using the application, you declare that you meet this age requirement.</li>
            <li>The application does not currently offer a direct means of verifying a user's age. The Platform therefore relies on the user's declaration and disclaims any liability arising from registration by a minor using false or inaccurate information.</li>
            <li>If a user is found to be under 18, the Platform reserves the right to suspend or delete the account immediately without prior notice, while retaining the relevant data for legal purposes.</li>
            <li>The registered phone number constitutes the user's digital identity, and no more than one account is permitted per phone number.</li>
            <li>You are solely and fully responsible for maintaining the confidentiality of your account credentials and for not disclosing them to any third party.</li>
            <li>Any activity carried out through your account is deemed to originate from you personally, whether performed by you or by any other person who gained access to your account.</li>
            <li>You must notify the Platform immediately upon suspecting any unauthorized use of your account.</li>
          </ol>

          <h3 class="article">Article 3 - Nature of the Platform &amp; Limits of Liability</h3>
          <p class="lead">AL NADHA is a neutral technology intermediary connecting customers with service providers. The Platform is not a party to any sale or service contract concluded between a customer and a service provider, and does not assume the role of seller, service provider, or employer under any circumstances.</p>
          <ol>
            <li>The Platform is not liable for the quality of the products or services provided by service providers.</li>
            <li>The Platform is not liable for any direct, indirect, incidental, or consequential damage arising from the use of service providers' services.</li>
            <li>The Platform is not liable for any act, omission, or conduct of any cleaning worker or event-services provider.</li>
            <li>The Platform is not liable for any loss or damage resulting from application downtime or service interruption for any reason, including force majeure.</li>
            <li>This disclaimer of liability does not preclude the Platform from intervening as a mediator to assist in resolving disputes between the parties.</li>
          </ol>

          <h3 class="article">Article 4 - Available Services</h3>
          <ol>
            <li>The Platform currently enables users to book home cleaning services.</li>
            <li>The Platform currently enables users to book assistance and support services for occasions and events.</li>
            <li>The two services referred to above (cleaning and events) constitute the scope of services actually available at the launch of the application. The Platform reserves the right to add other services in the future (such as restaurant, delivery, and shopping services) without this requiring prior notice to users.</li>
            <li>The Platform reserves the right to suspend, discontinue, or modify the scope of any service at any time without prior notice.</li>
            <li>Availability of services is limited to the geographic area of the city of Aleppo, and the Platform bears no responsibility for the unavailability of a service in any given area.</li>
          </ol>

          <h3 class="article">Article 5 - Orders &amp; Payment</h3>
          <ol>
            <li>The value of the service is payable either in cash upon completion of the service, or electronically through the electronic payment interfaces made available by the Platform within the application, according to the option selected by the user when confirming the order.</li>
            <li>By confirming an order, you undertake to be present at the specified address or location at the agreed time, and to pay the full amount due for the service through the method you selected.</li>
            <li>If you specifically request a female worker, you undertake through the application to ensure that an adult female member of the household is present during performance of the service. Should you fail to meet this undertaking, such that the worker is unable to enter the premises or perform the service as a result, this shall be treated as a cancellation on your part, and you shall be obligated to pay the full value of the requested service as compensation; the worker shall be entitled to leave without performing the service.</li>
            <li>
              You may cancel a booked service before its scheduled time, provided that you pay compensation to the worker for the time and arrangements reserved, in accordance with the following schedule:
              <ol class="alpha">
                <li>Cancellation more than 24 hours before the scheduled time: no charge.</li>
                <li>Cancellation between 24 hours and 3 hours before the scheduled time: 50% of the service value.</li>
                <li>Cancellation less than 3 hours before the scheduled time, or after the worker has departed for the location, or your absence upon arrival: the full service value.</li>
              </ol>
            </li>
            <li>
              The Platform reserves the right to collect any amounts due under paragraphs (3) and (4) above through one or more of the following means, as available:
              <ol class="alpha">
                <li>Direct debit from the electronic payment method registered or linked to the user's account within the application.</li>
                <li>Deduction from the user's in-app electronic wallet balance, if any.</li>
                <li>Recording the amount as a debt on the user's account and suspending the user's ability to book any new service until the outstanding amount is paid in full.</li>
                <li>Where collection cannot be effected through the above means, the Platform reserves the right to suspend or permanently delete the account, and to take whatever legal action it deems appropriate to recover the amount due.</li>
              </ol>
            </li>
            <li>Repeated refusal to pay, or repeated evasion of payment, constitutes sufficient grounds for permanently suspending the user's account under Article 8 of these Terms.</li>
          </ol>

          <h3 class="article">Article 6 - Ratings &amp; Reviews</h3>
          <ol>
            <li>Upon completion of each order or service, the user is given the opportunity to rate the service provider.</li>
            <li>Ratings and comments you post become the property of the Platform, which reserves the right to use, display, and employ them for marketing purposes without any compensation.</li>
            <li>Your ratings must be honest and reflect your actual experience; malicious or fabricated ratings are prohibited.</li>
            <li>The Platform is not responsible for the content of ratings and reserves the right to delete any rating that violates its policies.</li>
          </ol>

          <h3 class="article">Article 7 - User Conduct &amp; Prohibitions</h3>
          <ol>
            <li>Use of the application for unlawful purposes, or in violation of applicable Syrian law, is prohibited.</li>
            <li>Entering false or misleading information during registration or when placing orders is prohibited.</li>
            <li>Contacting service providers outside the application for commercial purposes, or to circumvent the Platform, is prohibited.</li>
            <li>Mistreating, harassing, or threatening service providers in any manner is prohibited.</li>
            <li>Attempting to breach the application, exploit any vulnerability in it, or tamper with any data is prohibited.</li>
            <li>Creating more than one account is prohibited.</li>
            <li>Posting any content that is offensive or contrary to public morals or Syrian law is prohibited.</li>
            <li>Using the account for any commercial purpose without the Platform's prior written consent is prohibited.</li>
          </ol>

          <h3 class="article">Article 8 - Account Suspension &amp; Termination</h3>
          <p class="lead">The Platform reserves the absolute and unrestricted right to suspend, deactivate, or delete any account, temporarily or permanently, at any time and without prior notice, in the following circumstances and any similar cases:</p>
          <ol>
            <li>Violation of any provision of these Terms, regardless of severity.</li>
            <li>Providing false or inaccurate information at any stage.</li>
            <li>Suspicion of any fraudulent or unlawful activity.</li>
            <li>Repeated complaints from service providers or other users.</li>
            <li>Repeated refusal to pay or repeated absence at the scheduled time of service.</li>
            <li>Misuse of the disputes section or repeated submission of malicious complaints.</li>
            <li>Violation of any applicable Syrian law.</li>
            <li>Any other reason the Platform deems sufficient to safeguard the integrity of the Platform and protect service providers and other users.</li>
            <li>The user has no right to claim any compensation as a result of the suspension or deletion of their account.</li>
          </ol>

          <h3 class="article">Article 9 - Dispute Resolution</h3>
          <ol>
            <li>The Platform intervenes as a neutral mediator to resolve disputes arising between a user and a service provider through the in-app disputes section.</li>
            <li>The user must submit a complaint through the disputes section within a maximum period of 24 hours from completion of the service or from the event giving rise to the dispute; failing this, the user's right to submit a complaint within the Platform shall lapse, without prejudice to the user's right to seek judicial recourse.</li>
            <li>The Platform's decision on disputes is final and binding within the Platform, and does not prevent any party from seeking judicial recourse.</li>
            <li>Any party to a dispute is entitled to request a copy of the transaction records for use in any legal proceeding.</li>
            <li>The Platform takes appropriate action against the party found responsible for the dispute, whether a customer or a service provider.</li>
            <li>The Platform is not liable to compensate any party financially except in cases expressly approved by the Platform, at its absolute discretion.</li>
          </ol>

          <h3 class="article">Article 10 - Notifications &amp; Marketing</h3>
          <ol>
            <li>By registering on the application, you agree to receive notifications relating to your orders, your account, and service updates.</li>
            <li>The Platform reserves the right to send promotional notifications, offers, and news.</li>
            <li>You may fully control your notifications through the application settings - either enabling all notifications or disabling them all.</li>
            <li>Disabling notifications may affect your experience of the application and your receipt of important updates.</li>
          </ol>

          <h3 class="article">Article 11 - Intellectual Property</h3>
          <ol>
            <li>All intellectual property rights in the application, including its design, code, content, and trademark, are reserved to the AL NADHA Platform.</li>
            <li>Copying, reproducing, distributing, or commercially exploiting any part of the application without prior written permission is prohibited.</li>
            <li>Content you post within the application, such as ratings and comments, grants the Platform a non-exclusive, perpetual license to use, display, and employ it for marketing purposes.</li>
          </ol>

          <h3 class="article">Article 12 - Governing Law</h3>
          <ol>
            <li>These Terms are governed by, and shall be construed in accordance with, the laws of the Syrian Arab Republic.</li>
            <li>Any dispute arising from or relating to these Terms shall be subject to the exclusive jurisdiction of the Syrian courts, specifically the courts of the city of Aleppo.</li>
            <li>If any provision of these Terms conflicts with applicable Syrian law, that provision shall be deemed void without affecting the validity of the remaining provisions.</li>
          </ol>
        </section>
      </section>

      <footer>
        AL NADHA Platform - Terms of Use - User Application<br />
        Support: 0944489418 | alnadhaservices@gmail.com | www.alnadha.net
      </footer>
    </article>
  </main>
</body>
</html>
