<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>ع الندهة | كل خدماتك اليومية في تطبيق واحد</title>
    <meta
        name="description"
        content="ع الندهة يجمع المطاعم والسوبرماركت وخدمات التنظيف في تطبيق واحد سريع وموثوق داخل مدينة حلب."
    >
    <meta name="theme-color" content="#1E2A78">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800" rel="stylesheet">

    @php($contactEmail = config('mail.from.address') ?: 'info@alnadha.net')

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
            background: var(--white);
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
            position: relative;
            overflow: hidden;
            isolation: isolate;
        }

        .page::before,
        .page::after {
            content: "";
            position: fixed;
            z-index: -2;
            width: 34rem;
            height: 34rem;
            border-radius: 50%;
            filter: blur(10px);
            pointer-events: none;
            opacity: 0.7;
        }

        .page::before {
            top: -14rem;
            right: -12rem;
            background: radial-gradient(circle, rgba(108, 99, 255, 0.16), rgba(108, 99, 255, 0));
            animation: backgroundFloatOne 13s ease-in-out infinite alternate;
        }

        .page::after {
            left: -13rem;
            bottom: -15rem;
            background: radial-gradient(circle, rgba(46, 196, 182, 0.14), rgba(46, 196, 182, 0));
            animation: backgroundFloatTwo 15s ease-in-out infinite alternate;
        }

        .background-dots {
            position: absolute;
            z-index: -1;
            width: 180px;
            height: 180px;
            top: 90px;
            left: 3vw;
            opacity: 0.45;
            background-image: radial-gradient(rgba(30, 42, 120, 0.22) 1.5px, transparent 1.5px);
            background-size: 17px 17px;
            animation: dotsDrift 11s ease-in-out infinite alternate;
            pointer-events: none;
        }

        .container {
            width: min(1120px, calc(100% - 32px));
            margin-inline: auto;
        }

        .hero {
            min-height: 88vh;
            display: grid;
            place-items: center;
            padding: 80px 0 64px;
        }

        .hero__grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(300px, 0.8fr);
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
            backdrop-filter: blur(12px);
        }

        .eyebrow__dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--secondary);
            box-shadow: 0 0 0 6px rgba(108, 99, 255, 0.12);
            animation: pulse 2.5s ease-in-out infinite;
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
            max-width: 660px;
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
            box-shadow: 0 10px 25px rgba(30, 42, 120, 0.06);
        }

        .meta-chip svg {
            width: 19px;
            height: 19px;
            color: var(--secondary);
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

        .store-button,
        .contact-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 11px;
            min-height: 52px;
            padding: 12px 18px;
            border: 1px solid rgba(30, 42, 120, 0.12);
            border-radius: 15px;
            color: var(--white);
            background: var(--primary);
            font-weight: 700;
            box-shadow: 0 14px 32px rgba(30, 42, 120, 0.18);
            transition: transform 220ms ease, box-shadow 220ms ease, background 220ms ease;
        }

        .store-button {
            min-width: 166px;
        }

        .store-button:hover,
        .contact-button:hover {
            transform: translateY(-3px);
            background: var(--primary-dark);
            box-shadow: 0 20px 42px rgba(30, 42, 120, 0.24);
        }

        .store-button--secondary {
            background: var(--secondary);
        }

        .store-button--secondary:hover {
            background: #5850ED;
        }

        .store-button svg,
        .contact-button svg {
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

        .service-orbit {
            position: relative;
            display: grid;
            place-items: center;
            min-height: 520px;
        }

        .service-orbit::before,
        .service-orbit::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .service-orbit::before {
            width: 410px;
            height: 410px;
            background: linear-gradient(145deg, rgba(30, 42, 120, 0.12), rgba(108, 99, 255, 0.04));
            animation: orbitBreath 6s ease-in-out infinite;
        }

        .service-orbit::after {
            width: 330px;
            height: 330px;
            border: 1px dashed rgba(108, 99, 255, 0.35);
            animation: orbitSpin 24s linear infinite;
        }

        .orbit-center {
            position: relative;
            z-index: 2;
            width: 190px;
            height: 190px;
            display: grid;
            place-items: center;
            padding: 24px;
            border: 1px solid rgba(30, 42, 120, 0.1);
            border-radius: 46px;
            color: var(--white);
            background: linear-gradient(145deg, var(--primary), #3142A6);
            box-shadow: 0 32px 70px rgba(30, 42, 120, 0.28);
            text-align: center;
            transform: rotate(-3deg);
            animation: centerFloat 5s ease-in-out infinite;
        }

        .orbit-center strong {
            display: block;
            font-size: 29px;
            line-height: 1.5;
        }

        .orbit-center small {
            font-size: 13px;
            opacity: 0.8;
        }

        .orbit-item {
            position: absolute;
            z-index: 3;
            width: 104px;
            min-height: 104px;
            display: grid;
            place-items: center;
            gap: 6px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 20px 45px rgba(30, 42, 120, 0.12);
            text-align: center;
            backdrop-filter: blur(12px);
            animation: itemFloat 5s ease-in-out infinite;
        }

        .orbit-item svg {
            width: 34px;
            height: 34px;
        }

        .orbit-item span {
            font-size: 12px;
            font-weight: 800;
        }

        .orbit-item--restaurant {
            top: 70px;
            right: 20px;
            color: var(--restaurant);
            animation-delay: -1s;
        }

        .orbit-item--market {
            bottom: 58px;
            right: 30px;
            color: var(--secondary);
            animation-delay: -2.3s;
        }

        .orbit-item--cleaning {
            left: 4px;
            top: 190px;
            color: var(--cleaning);
            animation-delay: -3.4s;
        }

        .section {
            padding: 88px 0;
        }

        .section--soft {
            background: linear-gradient(180deg, rgba(247, 248, 252, 0.72), rgba(255, 255, 255, 0.9));
        }

        .section-heading {
            max-width: 720px;
            margin: 0 auto 42px;
            text-align: center;
        }

        .section-heading__label {
            display: inline-block;
            margin-bottom: 9px;
            color: var(--secondary);
            font-size: 14px;
            font-weight: 800;
        }

        .section-heading h2 {
            margin-bottom: 10px;
            color: var(--primary);
            font-size: clamp(30px, 4vw, 44px);
            line-height: 1.35;
            font-weight: 800;
        }

        .section-heading p {
            margin-bottom: 0;
            color: var(--muted);
            font-size: 16px;
        }

        .services-grid,
        .partner-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .service-card,
        .partner-card,
        .benefit-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 45px rgba(30, 42, 120, 0.07);
            transition: transform 300ms cubic-bezier(.2,.8,.2,1), box-shadow 300ms ease, border-color 300ms ease;
        }

        .service-card:hover,
        .partner-card:hover,
        .benefit-card:hover {
            transform: translateY(-9px);
            box-shadow: 0 30px 65px rgba(30, 42, 120, 0.15);
            border-color: rgba(108, 99, 255, 0.24);
        }

        .service-card {
            min-height: 430px;
            padding: 30px;
            border-radius: 30px;
        }

        .service-card::after,
        .partner-card::after {
            content: "";
            position: absolute;
            width: 160px;
            height: 160px;
            left: -80px;
            bottom: -90px;
            border-radius: 50%;
            opacity: 0.55;
            transition: transform 450ms ease;
        }

        .service-card:hover::after,
        .partner-card:hover::after {
            transform: scale(1.35);
        }

        .service-card--restaurant {
            background: linear-gradient(160deg, var(--white), var(--restaurant-soft));
        }

        .service-card--restaurant::after {
            background: rgba(255, 122, 0, 0.14);
        }

        .service-card--market {
            background: linear-gradient(160deg, var(--white), var(--secondary-soft));
        }

        .service-card--market::after {
            background: rgba(108, 99, 255, 0.13);
        }

        .service-card--cleaning {
            background: linear-gradient(160deg, var(--white), var(--cleaning-soft));
        }

        .service-card--cleaning::after {
            background: rgba(46, 196, 182, 0.16);
        }

        .service-card__icon,
        .partner-card__icon,
        .benefit-card__icon {
            display: grid;
            place-items: center;
            border-radius: 22px;
        }

        .service-card__icon {
            width: 74px;
            height: 74px;
            margin-bottom: 24px;
        }

        .service-card__icon svg {
            width: 38px;
            height: 38px;
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
            color: var(--cleaning);
            background: var(--cleaning-soft);
        }

        .service-card h3 {
            margin-bottom: 8px;
            color: var(--primary);
            font-size: 25px;
        }

        .service-card__subtitle {
            margin-bottom: 14px;
            color: var(--text);
            font-weight: 700;
        }

        .service-card__description {
            margin-bottom: 22px;
            color: var(--muted);
            font-size: 14px;
        }

        .feature-list {
            display: grid;
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
        }

        .feature-list li::before {
            content: "";
            width: 8px;
            height: 8px;
            flex: 0 0 auto;
            border-radius: 50%;
            background: var(--secondary);
            box-shadow: 0 0 0 4px rgba(108, 99, 255, 0.1);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
        }

        .benefit-card {
            padding: 24px 18px;
            border-radius: 24px;
            text-align: center;
        }

        .benefit-card__icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 15px;
            color: var(--primary);
            background: var(--primary-soft);
        }

        .benefit-card__icon svg {
            width: 26px;
            height: 26px;
        }

        .benefit-card h3 {
            margin-bottom: 7px;
            color: var(--primary);
            font-size: 15px;
        }

        .benefit-card p {
            margin-bottom: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .join-section {
            position: relative;
        }

        .partner-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .partner-card {
            padding: 25px;
            border-radius: 27px;
        }

        .partner-card__icon {
            width: 58px;
            height: 58px;
            margin-bottom: 18px;
            color: var(--primary);
            background: var(--primary-soft);
        }

        .partner-card__icon svg {
            width: 29px;
            height: 29px;
        }

        .partner-card h3 {
            margin-bottom: 8px;
            color: var(--primary);
            font-size: 19px;
        }

        .partner-card p {
            margin-bottom: 18px;
            color: var(--muted);
            font-size: 13px;
        }

        .partner-card a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--secondary);
            font-size: 13px;
            font-weight: 800;
        }

        .partner-card a svg {
            width: 17px;
            height: 17px;
            transition: transform 180ms ease;
        }

        .partner-card a:hover svg {
            transform: translateX(-4px);
        }

        .partner-card--restaurant .partner-card__icon {
            color: var(--restaurant);
            background: var(--restaurant-soft);
        }

        .partner-card--cleaning .partner-card__icon {
            color: var(--cleaning);
            background: var(--cleaning-soft);
        }

        .partner-card--market .partner-card__icon {
            color: var(--secondary);
            background: var(--secondary-soft);
        }

        .contact-panel {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 32px;
            margin-top: 28px;
            padding: clamp(28px, 5vw, 48px);
            border-radius: 34px;
            color: var(--white);
            background: linear-gradient(135deg, var(--primary), #293A9D 64%, var(--secondary));
            box-shadow: var(--shadow);
        }

        .contact-panel::before,
        .contact-panel::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .contact-panel::before {
            width: 220px;
            height: 220px;
            top: -130px;
            left: 6%;
            animation: contactBubble 7s ease-in-out infinite alternate;
        }

        .contact-panel::after {
            width: 130px;
            height: 130px;
            right: 38%;
            bottom: -75px;
            animation: contactBubble 9s ease-in-out infinite alternate-reverse;
        }

        .contact-panel__content,
        .contact-panel__action {
            position: relative;
            z-index: 1;
        }

        .contact-panel h2 {
            margin-bottom: 8px;
            font-size: clamp(26px, 4vw, 40px);
            line-height: 1.45;
        }

        .contact-panel p {
            max-width: 720px;
            margin-bottom: 0;
            color: rgba(255, 255, 255, 0.78);
        }

        .contact-button {
            min-width: 190px;
            color: var(--primary);
            background: var(--white);
            box-shadow: 0 16px 40px rgba(10, 16, 64, 0.24);
        }

        .contact-button:hover {
            color: var(--primary-dark);
            background: #F7F8FF;
        }

        .final-cta {
            padding: 0 0 88px;
        }

        .final-cta__box {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 30px;
            padding: 36px;
            border: 1px solid var(--border);
            border-radius: 30px;
            background: linear-gradient(145deg, var(--white), var(--primary-soft));
            box-shadow: 0 20px 50px rgba(30, 42, 120, 0.08);
        }

        .final-cta h2 {
            margin-bottom: 7px;
            color: var(--primary);
            font-size: clamp(25px, 4vw, 36px);
        }

        .final-cta p {
            margin-bottom: 0;
            color: var(--muted);
        }

        .reveal {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity 700ms ease, transform 700ms cubic-bezier(.2,.8,.2,1);
        }

        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        @keyframes backgroundFloatOne {
            to { transform: translate(-45px, 70px) scale(1.08); }
        }

        @keyframes backgroundFloatTwo {
            to { transform: translate(55px, -45px) scale(1.12); }
        }

        @keyframes dotsDrift {
            to { transform: translateY(30px) rotate(5deg); }
        }

        @keyframes pulse {
            50% { transform: scale(1.22); box-shadow: 0 0 0 10px rgba(108, 99, 255, 0.04); }
        }

        @keyframes orbitBreath {
            50% { transform: scale(1.06); }
        }

        @keyframes orbitSpin {
            to { transform: rotate(360deg); }
        }

        @keyframes centerFloat {
            50% { transform: translateY(-12px) rotate(-1deg); }
        }

        @keyframes itemFloat {
            50% { transform: translateY(-14px); }
        }

        @keyframes contactBubble {
            to { transform: translate(38px, 24px) scale(1.12); }
        }

        @media (max-width: 960px) {
            .hero {
                min-height: auto;
            }

            .hero__grid {
                grid-template-columns: 1fr;
            }

            .hero__content {
                text-align: center;
            }

            .hero__description {
                margin-inline: auto;
            }

            .hero__meta,
            .download-actions {
                justify-content: center;
            }

            .service-orbit {
                min-height: 470px;
            }

            .services-grid {
                grid-template-columns: 1fr;
            }

            .service-card {
                min-height: auto;
            }

            .benefits-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .partner-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .contact-panel,
            .final-cta__box {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .contact-panel__action,
            .final-cta .download-actions {
                justify-self: center;
            }
        }

        @media (max-width: 620px) {
            .container {
                width: min(100% - 22px, 1120px);
            }

            .hero {
                padding: 54px 0 40px;
            }

            .hero h1 {
                font-size: 41px;
            }

            .store-button {
                width: 100%;
            }

            .service-orbit {
                min-height: 390px;
                transform: scale(0.9);
            }

            .service-orbit::before {
                width: 340px;
                height: 340px;
            }

            .service-orbit::after {
                width: 285px;
                height: 285px;
            }

            .orbit-center {
                width: 160px;
                height: 160px;
                border-radius: 38px;
            }

            .orbit-center strong {
                font-size: 25px;
            }

            .orbit-item {
                width: 92px;
                min-height: 92px;
                border-radius: 24px;
            }

            .section {
                padding: 64px 0;
            }

            .benefits-grid,
            .partner-grid {
                grid-template-columns: 1fr;
            }

            .service-card,
            .partner-card,
            .contact-panel,
            .final-cta__box {
                border-radius: 24px;
            }

            .contact-button {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }

            .reveal {
                opacity: 1;
                transform: none;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="background-dots" aria-hidden="true"></div>

    <main>
        <section class="hero">
            <div class="container hero__grid">
                <div class="hero__content reveal">
                    <div class="eyebrow">
                        <span class="eyebrow__dot" aria-hidden="true"></span>
                        متواجدون الآن في حلب
                    </div>

                    <h1>كل خدماتك اليومية في <span>تطبيق واحد</span></h1>

                    <p class="hero__description">
                        تعبت من المشاوير وتأخير الطلبات؟ تطبيق «ع الندهة» هو مساعدك اليومي لطلب الطعام،
                        حاجيات المنزل وخدمات التنظيف بسرعة وموثوقية، ومن مكان واحد.
                    </p>

                    <div class="hero__meta">
                        <span class="meta-chip">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 21s6-4.35 6-10a6 6 0 1 0-12 0c0 5.65 6 10 6 10Z"/>
                                <circle cx="12" cy="11" r="2"/>
                            </svg>
                            تغطية داخل مدينة حلب
                        </span>
                        <span class="meta-chip">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 3v18M3 12h18"/>
                            </svg>
                            خدمات متعددة في تطبيق واحد
                        </span>
                    </div>

                    <p class="download-label">حمّل تطبيق المستخدم</p>
                    <div class="download-actions">
                        <a class="store-button" href="#" aria-label="تحميل التطبيق من App Store">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M16.37 12.64c.02 2.2 1.93 2.93 1.95 2.94-.02.05-.3 1.04-1 2.06-.6.88-1.23 1.76-2.22 1.78-.97.02-1.28-.57-2.39-.57-1.1 0-1.45.55-2.37.59-.95.04-1.67-.95-2.28-1.82-1.24-1.8-2.19-5.08-.92-7.29a3.54 3.54 0 0 1 3.03-1.85c.94-.02 1.84.64 2.4.64.57 0 1.64-.79 2.76-.67.47.02 1.79.19 2.63 1.43-.07.04-1.58.92-1.57 2.76ZM14.6 7.27c.5-.6.83-1.44.74-2.27-.72.03-1.6.48-2.12 1.08-.46.53-.87 1.38-.76 2.2.81.07 1.64-.41 2.14-1.01Z"/>
                            </svg>
                            <span class="store-button__text"><small>تحميل من</small>App Store</span>
                        </a>

                        <a class="store-button store-button--secondary" href="#" aria-label="تحميل التطبيق من Google Play">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="m3.64 2.27 10.1 9.73-10.1 9.73A1.9 1.9 0 0 1 3 20.3V3.7c0-.55.23-1.05.64-1.43Zm11.43 11.01 2.29 2.21-10.5 5.95 8.21-8.16Zm3.55-2.02c.51.29.82.76.82 1.24s-.31.95-.82 1.24l-1.82 1.03-2.47-2.38.4-.39-.4-.39 2.47-2.38 1.82 1.03ZM6.86 2.56l10.5 5.95-2.29 2.21-8.21-8.16Z"/>
                            </svg>
                            <span class="store-button__text"><small>تحميل من</small>Google Play</span>
                        </a>
                    </div>
                </div>

                <div class="service-orbit reveal" aria-label="خدمات تطبيق ع الندهة">
                    <div class="orbit-center">
                        <div>
                            <strong>ع الندهة</strong>
                            <small>طلبك مجاب ووقتك محفوظ</small>
                        </div>
                    </div>

                    <div class="orbit-item orbit-item--restaurant">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 12h16M6 12a6 6 0 0 1 12 0M3 16h18M8 20h8"/>
                        </svg>
                        <span>المطاعم</span>
                    </div>

                    <div class="orbit-item orbit-item--market">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 5h2l2 10h9l2-7H7M9 20h.01M17 20h.01"/>
                        </svg>
                        <span>السوبرماركت</span>
                    </div>

                    <div class="orbit-item orbit-item--cleaning">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="m8 3 2 5M5 8h10l-1 13H6L5 8ZM15 5h4M17 3v4"/>
                        </svg>
                        <span>التنظيف</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="section section--soft">
            <div class="container">
                <div class="section-heading reveal">
                    <span class="section-heading__label">أقسام التطبيق</span>
                    <h2>ثلاث خدمات أساسية، تجربة واحدة متكاملة</h2>
                    <p>اختر الخدمة التي تحتاجها، حدّد طلبك، وتابع كل شيء بسهولة من التطبيق.</p>
                </div>

                <div class="services-grid">
                    <article class="service-card service-card--restaurant reveal">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M4 12h16M6 12a6 6 0 0 1 12 0M3 16h18M8 20h8"/>
                            </svg>
                        </div>
                        <h3>قسم المطاعم</h3>
                        <p class="service-card__subtitle">أكل حلب على أصوله</p>
                        <p class="service-card__description">
                            تصفّح مطاعم متنوعة واطلب وجبتك المفضلة لتصلك ساخنة وبأسرع وقت ممكن.
                        </p>
                        <ul class="feature-list">
                            <li>مطاعم وأصناف متنوعة</li>
                            <li>توصيل سريع ومتابعة واضحة</li>
                            <li>عروض وخصومات حصرية</li>
                        </ul>
                    </article>

                    <article class="service-card service-card--market reveal">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M4 5h2l2 10h9l2-7H7M9 20h.01M17 20h.01"/>
                            </svg>
                        </div>
                        <h3>قسم السوبرماركت</h3>
                        <p class="service-card__subtitle">حاجيات البيت دون تعب</p>
                        <p class="service-card__description">
                            اطلب المواد الغذائية والخضار والفواكه والمنظفات والاحتياجات اليومية من المتاجر القريبة.
                        </p>
                        <ul class="feature-list">
                            <li>منتجات يومية متنوعة</li>
                            <li>متاجر قريبة وأسعار مناسبة</li>
                            <li>طلب سهل من مكان واحد</li>
                        </ul>
                    </article>

                    <article class="service-card service-card--cleaning reveal">
                        <div class="service-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="m8 3 2 5M5 8h10l-1 13H6L5 8ZM15 5h4M17 3v4"/>
                            </svg>
                        </div>
                        <h3>قسم خدمات التنظيف</h3>
                        <p class="service-card__subtitle">راحة ونظافة تامة</p>
                        <p class="service-card__description">
                            خدمات تنظيف احترافية للمنازل والمكاتب والمنشآت والحفلات من خلال كوادر مدرّبة وموثوقة.
                        </p>
                        <ul class="feature-list">
                            <li>كوادر موثوقة ومدرّبة</li>
                            <li>خدمات للمنازل والمنشآت</li>
                            <li>تنظيف للمناسبات والحفلات</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="container">
                <div class="section-heading reveal">
                    <span class="section-heading__label">لماذا نحن؟</span>
                    <h2>لماذا تختار «ع الندهة»؟</h2>
                    <p>صممنا التجربة لتكون سريعة، واضحة، محلية وقريبة من احتياجاتك اليومية.</p>
                </div>

                <div class="benefits-grid">
                    <article class="benefit-card reveal">
                        <div class="benefit-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/></svg>
                        </div>
                        <h3>تطبيق واحد</h3>
                        <p>كل الخدمات اليومية دون تحميل عدة تطبيقات.</p>
                    </article>

                    <article class="benefit-card reveal">
                        <div class="benefit-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        </div>
                        <h3>استجابة سريعة</h3>
                        <p>شبكة محلية تساعد على تنفيذ الطلب بأسرع وقت.</p>
                    </article>

                    <article class="benefit-card reveal">
                        <div class="benefit-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 11V5a2 2 0 1 1 4 0v5M12 9V4a2 2 0 1 1 4 0v7M16 9a2 2 0 0 1 4 0v4c0 5-3 8-8 8h-1c-3 0-5-2-6-4l-2-4a2 2 0 0 1 3-2l2 2"/></svg>
                        </div>
                        <h3>واجهة بسيطة</h3>
                        <p>طلب واضح وسلس ببضع نقرات فقط.</p>
                    </article>

                    <article class="benefit-card reveal">
                        <div class="benefit-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h.01M16 16h.01M8 16l8-8"/></svg>
                        </div>
                        <h3>عروض مستمرة</h3>
                        <p>خصومات حصرية على المطاعم والخدمات.</p>
                    </article>

                    <article class="benefit-card reveal">
                        <div class="benefit-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1v-5ZM20 14h-3v6h2a1 1 0 0 0 1-1v-5Z"/></svg>
                        </div>
                        <h3>دعم محلي</h3>
                        <p>فريق متواجد لمتابعة طلباتك ومساعدتك.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section section--soft join-section" id="join-us">
            <div class="container">
                <div class="section-heading reveal">
                    <span class="section-heading__label">انضم إلى شبكة ع الندهة</span>
                    <h2>كبّر أعمالك وابدأ استقبال الطلبات معنا</h2>
                    <p>نرحب بأصحاب الأعمال ومقدمي الخدمات للانضمام إلى المنصة بعد التواصل مع فريق الإدارة.</p>
                </div>

                <div class="partner-grid">
                    <article class="partner-card partner-card--cleaning reveal">
                        <div class="partner-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m8 3 2 5M5 8h10l-1 13H6L5 8ZM15 5h4M17 3v4"/></svg>
                        </div>
                        <h3>أصحاب خدمات التنظيف</h3>
                        <p>أدر فرق العمل واستقبل طلبات تنظيف المنازل والمنشآت والمناسبات.</p>
                        <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('طلب انضمام - خدمات التنظيف') }}">
                            تواصل مع الإدارة
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                    </article>

                    <article class="partner-card partner-card--market reveal">
                        <div class="partner-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5h2l2 10h9l2-7H7M9 20h.01M17 20h.01"/></svg>
                        </div>
                        <h3>أصحاب السوبرماركت</h3>
                        <p>اعرض منتجات متجرك، استقبل الطلبات ووصل إلى عملاء أكثر داخل المدينة.</p>
                        <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('طلب انضمام - سوبرماركت') }}">
                            تواصل مع الإدارة
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                    </article>

                    <article class="partner-card partner-card--restaurant reveal">
                        <div class="partner-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 12h16M6 12a6 6 0 0 1 12 0M3 16h18M8 20h8"/></svg>
                        </div>
                        <h3>أصحاب المطاعم</h3>
                        <p>أضف مطعمك وقائمتك، استقبل الطلبات ووسّع حضورك الرقمي بسهولة.</p>
                        <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('طلب انضمام - مطعم') }}">
                            تواصل مع الإدارة
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                    </article>

                    <article class="partner-card reveal">
                        <div class="partner-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7zM7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>
                        </div>
                        <h3>مقدمو خدمات التوصيل</h3>
                        <p>انضم إلى شبكة المندوبين وساهم في توصيل الطلبات بسرعة وموثوقية.</p>
                        <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('طلب انضمام - خدمات التوصيل') }}">
                            تواصل مع الإدارة
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                    </article>
                </div>

                <div class="contact-panel reveal" id="contact">
                    <div class="contact-panel__content">
                        <h2>جاهز لتكون شريكاً معنا؟</h2>
                        <p>
                            أرسل نوع النشاط، الاسم، رقم التواصل وموقع العمل إلى فريق الإدارة، وسنتابع معك خطوات التسجيل والتفعيل.
                        </p>
                    </div>
                    <div class="contact-panel__action">
                        <a class="contact-button" href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('طلب انضمام إلى شبكة ع الندهة') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 5h18v14H3z"/><path d="m3 6 9 7 9-7"/></svg>
                            تواصل مع الإدارة
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="final-cta">
            <div class="container">
                <div class="final-cta__box reveal">
                    <div>
                        <h2>ع الندهة.. طلبك مجاب ووقتك محفوظ</h2>
                        <p>حمّل التطبيق واجعل احتياجاتك اليومية أسهل وأكثر راحة.</p>
                    </div>
                    <div class="download-actions">
                        <a class="store-button" href="#">App Store</a>
                        <a class="store-button store-button--secondary" href="#">Google Play</a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    (() => {
        const elements = document.querySelectorAll('.reveal');

        if (!('IntersectionObserver' in window)) {
            elements.forEach((element) => element.classList.add('is-visible'));
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        elements.forEach((element, index) => {
            element.style.transitionDelay = `${Math.min(index % 5, 4) * 70}ms`;
            observer.observe(element);
        });
    })();
</script>
</body>
</html>
