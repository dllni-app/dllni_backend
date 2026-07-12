<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>على الندهة | كل خدماتك اليومية في تطبيق واحد</title>
    <meta
        name="description"
        content="على الندهة يجمع المطاعم والسوبرماركت وخدمات التنظيف في تطبيق واحد سريع وموثوق داخل مدينة حلب."
    >
    <meta name="theme-color" content="#1E2A78">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link
        href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800"
        rel="stylesheet"
    >

    <style>
        :root {
            --primary: #1E2A78;
            --primary-dark: #151F61;
            --primary-soft: #EEF0FF;
            --secondary: #6C63FF;
            --secondary-soft: #F1F0FF;
            --restaurant: #FF7A00;
            --restaurant-soft: #FFF4E8;
            --cleaning: #2EC4B6;
            --cleaning-soft: #EAFBF8;
            --text: #15172A;
            --muted: #65697C;
            --surface: #F7F8FC;
            --border: #E6E8F0;
            --white: #FFFFFF;
            --shadow: 0 24px 70px rgba(30, 42, 120, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 85% 8%, rgba(108, 99, 255, 0.09), transparent 25rem),
                radial-gradient(circle at 10% 28%, rgba(46, 196, 182, 0.07), transparent 22rem),
                var(--white);
            font-family: "Cairo", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.8;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        svg {
            display: block;
        }

        .page {
            overflow: hidden;
        }

        .container {
            width: min(1120px, calc(100% - 32px));
            margin-inline: auto;
        }

        .hero {
            min-height: 92vh;
            display: grid;
            place-items: center;
            padding: 72px 0 60px;
        }

        .hero__grid {
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(300px, 0.82fr);
            align-items: center;
            gap: clamp(44px, 7vw, 96px);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding: 8px 16px;
            border: 1px solid rgba(30, 42, 120, 0.12);
            border-radius: 999px;
            color: var(--primary);
            background: rgba(255, 255, 255, 0.82);
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(30, 42, 120, 0.06);
        }

        .eyebrow__dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--restaurant);
            box-shadow: 0 0 0 6px rgba(255, 122, 0, 0.12);
        }

        h1,
        h2,
        h3,
        p {
            margin-top: 0;
        }

        .hero h1 {
            max-width: 690px;
            margin-bottom: 22px;
            color: var(--primary);
            font-size: clamp(42px, 6vw, 76px);
            line-height: 1.25;
            letter-spacing: -0.035em;
            font-weight: 800;
        }

        .hero h1 span {
            color: var(--secondary);
        }

        .hero__description {
            max-width: 640px;
            margin-bottom: 28px;
            color: var(--muted);
            font-size: clamp(17px, 2vw, 20px);
        }

        .hero__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 30px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--white);
            color: var(--primary);
            font-size: 14px;
            font-weight: 700;
        }

        .meta-chip svg {
            width: 19px;
            height: 19px;
            color: var(--restaurant);
        }

        .download-label {
            margin-bottom: 12px;
            color: var(--text);
            font-size: 14px;
            font-weight: 700;
        }

        .download-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .store-button {
            min-width: 166px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 11px;
            padding: 12px 16px;
            border: 1px solid rgba(30, 42, 120, 0.12);
            border-radius: 15px;
            color: var(--white);
            background: var(--primary);
            font-weight: 700;
            box-shadow: 0 14px 32px rgba(30, 42, 120, 0.18);
            transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease;
        }

        .store-button:hover {
            transform: translateY(-2px);
            background: var(--primary-dark);
            box-shadow: 0 18px 38px rgba(30, 42, 120, 0.24);
        }

        .store-button--secondary {
            background: var(--secondary);
        }

        .store-button--secondary:hover {
            background: #5850ED;
        }

        .store-button svg {
            width: 23px;
            height: 23px;
            flex: 0 0 auto;
        }

        .store-button__text {
            display: grid;
            gap: 1px;
            line-height: 1.25;
            text-align: right;
        }

        .store-button__text small {
            font-size: 10px;
            font-weight: 500;
            opacity: 0.78;
        }

        .phone-stage {
            position: relative;
            display: grid;
            place-items: center;
            min-height: 610px;
        }

        .phone-stage::before,
        .phone-stage::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .phone-stage::before {
            width: 420px;
            height: 420px;
            background: linear-gradient(145deg, rgba(30, 42, 120, 0.12), rgba(108, 99, 255, 0.06));
        }

        .phone-stage::after {
            width: 260px;
            height: 260px;
            inset-inline-start: -20px;
            bottom: 40px;
            border: 1px dashed rgba(108, 99, 255, 0.32);
        }

        .phone {
            position: relative;
            z-index: 2;
            width: min(338px, 84vw);
            padding: 11px;
            border: 4px solid #11131A;
            border-radius: 48px;
            background: #11131A;
            box-shadow: 0 34px 70px rgba(15, 18, 45, 0.25);
            transform: rotate(2deg);
        }

        .phone::before {
            content: "";
            position: absolute;
            z-index: 5;
            top: 15px;
            left: 50%;
            width: 92px;
            height: 25px;
            border-radius: 0 0 16px 16px;
            background: #11131A;
            transform: translateX(-50%);
        }

        .phone__screen {
            min-height: 580px;
            overflow: hidden;
            border-radius: 36px;
            background: var(--surface);
        }

        .phone__top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 42px 21px 16px;
            background: var(--white);
        }

        .phone__greeting small {
            display: block;
            color: #85899A;
            font-size: 10px;
        }

        .phone__greeting strong {
            color: var(--primary);
            font-size: 18px;
        }

        .phone__icons {
            display: flex;
            gap: 8px;
        }

        .phone__circle {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 50%;
            background: var(--white);
            color: var(--primary);
        }

        .phone__circle svg {
            width: 18px;
            height: 18px;
        }

        .phone__body {
            padding: 0 18px 20px;
        }

        .phone__search {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 14px 0 18px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 17px;
            color: #979BAC;
            background: var(--white);
            font-size: 11px;
        }

        .phone__search svg {
            width: 18px;
            height: 18px;
            color: var(--primary);
        }

        .phone__offer {
            position: relative;
            overflow: hidden;
            margin-bottom: 18px;
            padding: 20px;
            border-radius: 21px;
            color: var(--white);
            background: linear-gradient(135deg, var(--primary), #3142A6);
        }

        .phone__offer::after {
            content: "";
            position: absolute;
            width: 130px;
            height: 130px;
            inset-inline-start: -34px;
            top: -45px;
            border-radius: 50%;
            background: rgba(108, 99, 255, 0.42);
        }

        .phone__offer span,
        .phone__offer strong {
            position: relative;
            z-index: 1;
            display: block;
        }

        .phone__offer span {
            margin-bottom: 5px;
            font-size: 11px;
            opacity: 0.78;
        }

        .phone__offer strong {
            max-width: 205px;
            font-size: 16px;
            line-height: 1.6;
        }

        .phone__title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 800;
        }

        .phone__title small {
            color: var(--secondary);
            font-size: 9px;
        }

        .phone__services {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 18px;
        }

        .phone-service {
            min-height: 86px;
            display: grid;
            place-items: center;
            gap: 5px;
            padding: 10px 5px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: var(--white);
            font-size: 9px;
            font-weight: 700;
            text-align: center;
        }

        .phone-service__icon {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            color: var(--primary);
            background: var(--primary-soft);
        }

        .phone-service__icon--restaurant {
            color: var(--restaurant);
            background: var(--restaurant-soft);
        }

        .phone-service__icon--cleaning {
            color: #159B90;
            background: var(--cleaning-soft);
        }

        .phone-service__icon svg {
            width: 21px;
            height: 21px;
        }

        .phone__stores {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .phone-store {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: var(--white);
        }

        .phone-store__image {
            height: 74px;
            display: grid;
            place-items: center;
            color: var(--primary);
            background:
                linear-gradient(135deg, rgba(30, 42, 120, 0.08), rgba(108, 99, 255, 0.14)),
                var(--white);
        }

        .phone-store__image svg {
            width: 38px;
            height: 38px;
        }

        .phone-store__content {
            padding: 9px;
        }

        .phone-store__content strong {
            display: block;
            margin-bottom: 3px;
            font-size: 10px;
        }

        .phone-store__rating {
            color: #14A167;
            font-size: 9px;
            font-weight: 800;
        }

        .section {
            padding: 76px 0;
        }

        .section--soft {
            background: linear-gradient(180deg, rgba(247, 248, 252, 0.78), rgba(255, 255, 255, 0.94));
        }

        .section-heading {
            max-width: 690px;
            margin: 0 auto 38px;
            text-align: center;
        }

        .section-heading__label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--secondary);
            font-size: 13px;
            font-weight: 800;
        }

        .section-heading h2 {
            margin-bottom: 10px;
            color: var(--primary);
            font-size: clamp(30px, 4vw, 44px);
            line-height: 1.4;
        }

        .section-heading p {
            margin-bottom: 0;
            color: var(--muted);
            font-size: 16px;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .service-card {
            position: relative;
            min-height: 430px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 30px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: var(--white);
            box-shadow: 0 16px 44px rgba(30, 42, 120, 0.07);
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 24px 54px rgba(30, 42, 120, 0.12);
        }

        .service-card::before {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            top: -92px;
            inset-inline-start: -65px;
            border-radius: 50%;
            opacity: 0.62;
        }

        .service-card--restaurant::before {
            background: var(--restaurant-soft);
        }

        .service-card--market::before {
            background: var(--secondary-soft);
        }

        .service-card--cleaning::before {
            background: var(--cleaning-soft);
        }

        .service-card__icon {
            position: relative;
            z-index: 1;
            width: 78px;
            height: 78px;
            display: grid;
            place-items: center;
            margin-bottom: 22px;
            border-radius: 24px;
        }

        .service-card__icon svg {
            width: 42px;
            height: 42px;
        }

        .service-card--restaurant .service-card__icon {
            color: var(--restaurant);
            background: var(--restaurant-soft);
        }

        .service-card--market .service-card__icon {
            color: var(--secondary);
            background: var(--secondary-soft);
        }

        .service-card--cleaning .service-card__icon {
            color: #159B90;
            background: var(--cleaning-soft);
        }

        .service-card h3 {
            margin-bottom: 6px;
            font-size: 23px;
            line-height: 1.5;
        }

        .service-card--restaurant h3 {
            color: var(--restaurant);
        }

        .service-card--market h3 {
            color: var(--secondary);
        }

        .service-card--cleaning h3 {
            color: #159B90;
        }

        .service-card__tagline {
            margin-bottom: 14px;
            color: var(--text);
            font-size: 15px;
            font-weight: 700;
        }

        .service-card__description {
            margin-bottom: 24px;
            color: var(--muted);
            font-size: 14px;
        }

        .feature-list {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 9px;
            margin-top: auto;
        }

        .feature {
            display: grid;
            justify-items: center;
            gap: 7px;
            padding: 11px 6px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
        }

        .feature svg {
            width: 20px;
            height: 20px;
        }

        .service-card--restaurant .feature {
            color: #D86100;
            background: var(--restaurant-soft);
        }

        .service-card--market .feature {
            color: #5148DE;
            background: var(--secondary-soft);
        }

        .service-card--cleaning .feature {
            color: #138C83;
            background: var(--cleaning-soft);
        }

        .benefits {
            padding: 42px;
            border: 1px solid var(--border);
            border-radius: 30px;
            background:
                linear-gradient(135deg, rgba(30, 42, 120, 0.035), rgba(108, 99, 255, 0.045)),
                var(--white);
            box-shadow: var(--shadow);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0;
        }

        .benefit {
            padding: 12px 20px;
            text-align: center;
        }

        .benefit + .benefit {
            border-inline-start: 1px solid var(--border);
        }

        .benefit__icon {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            margin: 0 auto 15px;
            border-radius: 17px;
            color: var(--primary);
            background: var(--primary-soft);
        }

        .benefit:nth-child(even) .benefit__icon {
            color: var(--secondary);
            background: var(--secondary-soft);
        }

        .benefit__icon svg {
            width: 27px;
            height: 27px;
        }

        .benefit h3 {
            margin-bottom: 7px;
            color: var(--primary);
            font-size: 14px;
        }

        .benefit p {
            margin-bottom: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.9;
        }

        .cta {
            padding: 24px 0 78px;
        }

        .cta-card {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 36px;
            padding: clamp(30px, 5vw, 54px);
            border-radius: 32px;
            color: var(--white);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary) 58%, #3547AD);
            box-shadow: 0 28px 70px rgba(30, 42, 120, 0.26);
        }

        .cta-card::before,
        .cta-card::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(108, 99, 255, 0.24);
        }

        .cta-card::before {
            width: 240px;
            height: 240px;
            inset-inline-start: -92px;
            top: -118px;
        }

        .cta-card::after {
            width: 140px;
            height: 140px;
            inset-inline-end: 33%;
            bottom: -96px;
        }

        .cta-card__content,
        .cta-card__actions {
            position: relative;
            z-index: 1;
        }

        .cta-card h2 {
            margin-bottom: 8px;
            color: var(--white);
            font-size: clamp(28px, 4vw, 44px);
            line-height: 1.45;
        }

        .cta-card h2 span {
            color: #FFB066;
        }

        .cta-card p {
            max-width: 620px;
            margin-bottom: 0;
            color: rgba(255, 255, 255, 0.78);
            font-size: 16px;
        }

        .cta-card__actions {
            display: grid;
            gap: 10px;
            min-width: 206px;
        }

        .cta-card .store-button {
            border-color: rgba(255, 255, 255, 0.18);
            color: var(--primary);
            background: var(--white);
            box-shadow: none;
        }

        .cta-card .store-button:hover {
            color: var(--primary);
            background: #F6F7FF;
        }

        .cta-note {
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.6);
            font-size: 10px;
            text-align: center;
        }

        @media (max-width: 980px) {
            .hero {
                padding-top: 54px;
            }

            .hero__grid {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero__content {
                display: grid;
                justify-items: center;
            }

            .hero__meta,
            .download-actions {
                justify-content: center;
            }

            .phone-stage {
                min-height: 590px;
            }

            .services-grid {
                grid-template-columns: 1fr;
                max-width: 680px;
                margin-inline: auto;
            }

            .service-card {
                min-height: auto;
            }

            .benefits-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 24px 0;
            }

            .benefit + .benefit {
                border-inline-start: 0;
            }

            .benefit:nth-child(even) {
                border-inline-start: 1px solid var(--border);
            }

            .benefit:last-child {
                grid-column: 1 / -1;
                max-width: 320px;
                margin-inline: auto;
            }

            .cta-card {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .cta-card p {
                margin-inline: auto;
            }

            .cta-card__actions {
                width: min(100%, 360px);
                margin-inline: auto;
            }
        }

        @media (max-width: 640px) {
            .container {
                width: min(100% - 22px, 1120px);
            }

            .hero {
                min-height: auto;
                padding: 40px 0 30px;
            }

            .hero h1 {
                font-size: 40px;
            }

            .hero__description {
                font-size: 16px;
            }

            .download-actions {
                width: 100%;
            }

            .store-button {
                width: 100%;
            }

            .phone-stage {
                min-height: 540px;
            }

            .phone {
                width: min(308px, 88vw);
            }

            .phone__screen {
                min-height: 530px;
            }

            .section {
                padding: 58px 0;
            }

            .section-heading {
                margin-bottom: 28px;
            }

            .service-card {
                padding: 24px;
                border-radius: 23px;
            }

            .feature-list {
                grid-template-columns: 1fr;
            }

            .feature {
                grid-template-columns: auto 1fr;
                justify-items: start;
                text-align: right;
            }

            .benefits {
                padding: 28px 18px;
            }

            .benefits-grid {
                grid-template-columns: 1fr;
            }

            .benefit,
            .benefit:nth-child(even) {
                border-inline-start: 0;
            }

            .benefit + .benefit {
                padding-top: 24px;
                border-top: 1px solid var(--border);
            }

            .benefit:last-child {
                grid-column: auto;
            }

            .cta-card {
                padding: 30px 20px;
                border-radius: 24px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="hero" aria-labelledby="hero-title">
            <div class="container hero__grid">
                <div class="hero__content">
                    <div class="eyebrow">
                        <span class="eyebrow__dot" aria-hidden="true"></span>
                        متواجدون الآن في مدينة حلب
                    </div>

                    <h1 id="hero-title">
                        كل خدماتك اليومية
                        <br>
                        في <span>تطبيق واحد</span>
                    </h1>

                    <p class="hero__description">
                        تعبت من مشاوير وتأخير الطلبات؟ تطبيق «على الندهة» هو مساعدك الشخصي
                        لخدمات المطاعم والسوبرماركت والتنظيف، بسرعة وموثوقية وفي أي وقت.
                    </p>

                    <div class="hero__meta" aria-label="مميزات سريعة">
                        <span class="meta-chip">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 21s7-4.6 7-11a7 7 0 1 0-14 0c0 6.4 7 11 7 11Z" stroke="currentColor" stroke-width="1.8"/>
                                <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            </svg>
                            تغطية أحياء حلب
                        </span>
                        <span class="meta-chip">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 3a9 9 0 1 0 9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                <path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M17 3h4v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            استجابة سريعة
                        </span>
                    </div>

                    <div class="download-label">حمّل تطبيق المستخدم</div>
                    <div class="download-actions" aria-label="روابط تحميل التطبيق">
                        <a class="store-button" href="#" aria-label="تحميل التطبيق من App Store">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M16.7 12.9c0-2.5 2.1-3.7 2.2-3.8-1.2-1.7-3-1.9-3.7-1.9-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.3-.9-1.7 0-3.3 1-4.2 2.5-1.8 3.1-.5 7.8 1.3 10.3.9 1.3 2 2.7 3.4 2.6 1.3-.1 1.9-.9 3.5-.9s2.1.9 3.5.9c1.5 0 2.4-1.3 3.3-2.6 1-1.5 1.5-3 1.5-3.1-.1 0-2.8-1.1-2.8-4Zm-2.5-7.4c.7-.9 1.2-2.1 1.1-3.3-1.1 0-2.4.7-3.2 1.6-.7.8-1.3 2-1.1 3.2 1.2.1 2.4-.6 3.2-1.5Z"/>
                            </svg>
                            <span class="store-button__text">
                                <small>قريباً على</small>
                                App Store
                            </span>
                        </a>

                        <a class="store-button store-button--secondary" href="#" aria-label="تحميل التطبيق من Google Play">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M3.5 2.7c-.3.4-.5.9-.5 1.6v15.4c0 .6.2 1.2.5 1.6l9-9.3-9-9.3Zm10.2 10.5-2.3-2.4-7.1 7.4 9.4-5Zm.8-.8 2.7-1.5c.8-.5.8-1.2 0-1.7l-3.3-1.9-2.4 2.5 3 2.6Zm-.7.8-9.5 5.3 9.6-10 2.5 2.8-2.6 1.9Z"/>
                            </svg>
                            <span class="store-button__text">
                                <small>قريباً على</small>
                                Google Play
                            </span>
                        </a>
                    </div>
                </div>

                <div class="phone-stage" aria-label="معاينة واجهة تطبيق على الندهة">
                    <div class="phone">
                        <div class="phone__screen">
                            <div class="phone__top">
                                <div class="phone__greeting">
                                    <small>مرحباً بعودتك 👋</small>
                                    <strong>أهلاً بك</strong>
                                </div>

                                <div class="phone__icons" aria-hidden="true">
                                    <span class="phone__circle">
                                        <svg viewBox="0 0 24 24" fill="none">
                                            <path d="M6 8h12l-1 11H7L6 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                            <path d="M9 9V6a3 3 0 0 1 6 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                    <span class="phone__circle">
                                        <svg viewBox="0 0 24 24" fill="none">
                                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                            <path d="M10 20h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <div class="phone__body">
                                <div class="phone__search">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/>
                                        <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                    ابحث عن متجر أو منتج...
                                </div>

                                <div class="phone__offer">
                                    <span>عروض استثنائية</span>
                                    <strong>خصومات على طلباتك اليومية من متاجر حلب</strong>
                                </div>

                                <div class="phone__title">
                                    <span>خدماتنا</span>
                                    <small>عرض الكل</small>
                                </div>

                                <div class="phone__services">
                                    <div class="phone-service">
                                        <span class="phone-service__icon phone-service__icon--restaurant">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M5 12h14a7 7 0 0 0-14 0Z" stroke="currentColor" stroke-width="1.8"/>
                                                <path d="M3 12h18M8 17h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                                <path d="M7 17h10l1-5H6l1 5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        المطاعم
                                    </div>

                                    <div class="phone-service">
                                        <span class="phone-service__icon">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 5h2l2 10h9l2-7H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                <circle cx="10" cy="19" r="1.5" fill="currentColor"/>
                                                <circle cx="17" cy="19" r="1.5" fill="currentColor"/>
                                            </svg>
                                        </span>
                                        السوبرماركت
                                    </div>

                                    <div class="phone-service">
                                        <span class="phone-service__icon phone-service__icon--cleaning">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M8 5h7l2 5v9H6v-9l2-5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                                <path d="M10 5V3h4v2M9 13h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                                <path d="M18 4h3M19.5 2.5v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                            </svg>
                                        </span>
                                        التنظيف
                                    </div>
                                </div>

                                <div class="phone__title">
                                    <span>متاجر قريبة منك</span>
                                    <small>في حلب</small>
                                </div>

                                <div class="phone__stores">
                                    <article class="phone-store">
                                        <div class="phone-store__image">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 10h16v10H4V10Z" stroke="currentColor" stroke-width="1.6"/>
                                                <path d="m3 10 2-6h14l2 6M8 10V6M12 10V6M16 10V6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            </svg>
                                        </div>
                                        <div class="phone-store__content">
                                            <strong>متجر الحي</strong>
                                            <span class="phone-store__rating">★ 4.8</span>
                                        </div>
                                    </article>

                                    <article class="phone-store">
                                        <div class="phone-store__image">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 10h16v10H4V10Z" stroke="currentColor" stroke-width="1.6"/>
                                                <path d="m3 10 2-6h14l2 6M8 10V6M12 10V6M16 10V6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            </svg>
                                        </div>
                                        <div class="phone-store__content">
                                            <strong>سوبرماركت النور</strong>
                                            <span class="phone-store__rating">★ 4.7</span>
                                        </div>
                                    </article>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section section--soft" aria-labelledby="services-title">
            <div class="container">
                <header class="section-heading">
                    <span class="section-heading__label">ثلاث خدمات، تجربة واحدة</span>
                    <h2 id="services-title">أقسام التطبيق</h2>
                    <p>كل ما تحتاجه خلال يومك ضمن تطبيق بسيط وسريع.</p>
                </header>

                <div class="services-grid">
                    <article class="service-card service-card--restaurant">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                                <path d="M10 24h28c0-8-6.3-14-14-14S10 16 10 24Z" stroke="currentColor" stroke-width="3"/>
                                <path d="M7 24h34M13 34h22" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                <path d="M12 34h24l2-10H10l2 10Z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                                <path d="M24 10V7" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3>قسم المطاعم</h3>
                        <p class="service-card__tagline">أكل حلب على أصوله</p>
                        <p class="service-card__description">
                            تصفّح مجموعة واسعة من المطاعم الغربية والشرقية والوجبات السريعة
                            والحلويات، واطلب وجبتك المفضلة لتصلك ساخنة إلى بابك.
                        </p>

                        <div class="feature-list">
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 21s8-4 8-11V5l-8-3-8 3v5c0 7 8 11 8 11Z" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="m9 11 2 2 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                مطاعم موثوقة
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M3 14h11V7H6l-3 4v3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M14 10h4l3 3v4h-7v-7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <circle cx="7" cy="18" r="2" stroke="currentColor" stroke-width="1.8"/>
                                    <circle cx="17" cy="18" r="2" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                                توصيل سريع
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M5 12h14a7 7 0 0 0-14 0Z" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M3 12h18M8 17h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                                خيارات متنوعة
                            </span>
                        </div>
                    </article>

                    <article class="service-card service-card--market">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                                <path d="M8 10h5l4 22h20l5-16H15" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="20" cy="39" r="3" fill="currentColor"/>
                                <circle cx="34" cy="39" r="3" fill="currentColor"/>
                                <path d="M22 12V7M31 12V7M18 9h17" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3>قسم السوبرماركت</h3>
                        <p class="service-card__tagline">حاجيات البيت دون تعب</p>
                        <p class="service-card__description">
                            اطلب المواد الغذائية والخضار والفواكه والمنظفات والاحتياجات
                            اليومية من أقرب سوبرماركت إليك، دون طوابير أو مشاوير.
                        </p>

                        <div class="feature-list">
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M6 8h12l-1 12H7L6 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M9 8a3 3 0 0 1 6 0" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                                منتجات يومية
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 3v18M7 7h7.5a3 3 0 1 1 0 6H9.5a3 3 0 1 0 0 6H17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                                أسعار مناسبة
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 21s7-4.6 7-11a7 7 0 1 0-14 0c0 6.4 7 11 7 11Z" stroke="currentColor" stroke-width="1.8"/>
                                    <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                                متاجر قريبة
                            </span>
                        </div>
                    </article>

                    <article class="service-card service-card--cleaning">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                                <path d="M17 13h14l5 10v18H12V23l5-10Z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                                <path d="M21 13V8h7v5M18 29h12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                <path d="M36 9h7M39.5 5.5v7" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3>قسم خدمات التنظيف</h3>
                        <p class="service-card__tagline">راحة ونظافة تامة</p>
                        <p class="service-card__description">
                            خدمات تنظيف احترافية للمنازل والمكاتب والمنشآت، إضافة إلى
                            تنظيف الحفلات والمناسبات، عبر كوادر مدرّبة وموثوقة.
                        </p>

                        <div class="feature-list">
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 21s8-4 8-11V5l-8-3-8 3v5c0 7 8 11 8 11Z" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="m9 11 2 2 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                كوادر موثوقة
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M5 20h14M7 20V9l5-5 5 5v11" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M10 20v-6h4v6" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                                منازل ومنشآت
                            </span>
                            <span class="feature">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                                نتائج مضمونة
                            </span>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section" aria-labelledby="benefits-title">
            <div class="container">
                <header class="section-heading">
                    <span class="section-heading__label">تجربة مصممة لراحتك</span>
                    <h2 id="benefits-title">لماذا تختار «على الندهة»؟</h2>
                    <p>نوفّر وقتك ونبسط طلباتك اليومية من البداية حتى التسليم.</p>
                </header>

                <div class="benefits">
                    <div class="benefits-grid">
                        <article class="benefit">
                            <div class="benefit__icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="7" y="2" width="10" height="20" rx="2" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M10 18h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h3>تطبيق واحد</h3>
                            <p>مطاعم وسوبرماركت وتنظيف في مكان واحد.</p>
                        </article>

                        <article class="benefit">
                            <div class="benefit__icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 3a9 9 0 1 0 9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M17 3h4v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <h3>استجابة سريعة</h3>
                            <p>شبكة محلية تلبّي طلبك بأقصر وقت ممكن.</p>
                        </article>

                        <article class="benefit">
                            <div class="benefit__icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M9 11V7a2 2 0 1 1 4 0v4M13 10V6a2 2 0 1 1 4 0v7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <path d="M9 10V8a2 2 0 1 0-4 0v6c0 5 3 8 8 8h1c4 0 6-3 6-7v-3a2 2 0 0 0-4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h3>واجهة بسيطة</h3>
                            <p>اطلب خدمتك ببضع نقرات واضحة وسهلة.</p>
                        </article>

                        <article class="benefit">
                            <div class="benefit__icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m4 15 11-11 5 5-11 11H4v-5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M12 7h.01M16 11h.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h3>عروض مستمرة</h3>
                            <p>خصومات حصرية على المطاعم والخدمات.</p>
                        </article>

                        <article class="benefit">
                            <div class="benefit__icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M4 14v-3a8 8 0 0 1 16 0v3" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M4 13H2v5h4v-5H4ZM20 13h2v5h-4v-5h2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M18 20h-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h3>دعم فني محلي</h3>
                            <p>فريق يتابع طلبك ويساعدك خطوة بخطوة.</p>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section class="cta" aria-labelledby="cta-title">
            <div class="container">
                <div class="cta-card">
                    <div class="cta-card__content">
                        <h2 id="cta-title">متواجدون الآن في <span>حلب!</span></h2>
                        <p>
                            بدأنا من حلب وجاهزون لتلبية احتياجات أحيائها.
                            حمّل التطبيق واجعل حياتك اليومية أسهل وأكثر راحة.
                        </p>
                    </div>

                    <div class="cta-card__actions">
                        <a class="store-button" href="#" aria-label="تحميل تطبيق على الندهة">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 3v12M7 10l5 5 5-5M5 21h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            روابط التحميل قريباً
                        </a>
                        <p class="cta-note">سيتم تحديث الروابط عند نشر التطبيق على المتاجر.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
