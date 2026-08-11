# Filament Cleaning Dashboard QA Report

## 1. Executive Summary

- Project: Alnadha
- Environment: Testing/staging
- Dashboard URL: https://alnadha.net/admin/login
- Tested user: redacted
- Test date: 2026-07-12T19:00:37.258Z
- Browser: Chromium via Playwright
- Overall status: Completed with recorded coverage
- Main risk: Only the Service Add-ons CRUD flow is automatically mutated; prerequisite-dependent workflows remain observational until dedicated QA fixtures are approved.
- Recommendation: Review findings and extend the same cleanup pattern to each resource that has a safe isolated fixture.

## 2. Scope Tested

- حجوزات التنظيف: passed — Search accepts and clears a QA value.
- العاملون: passed — Search accepts and clears a QA value.
- النزاعات والشكاوى: passed — Search accepts and clears a QA value.
- SOS Alerts: passed
- الإعدادات المالية: passed
- إضافات الخدمة: passed — Search accepts and clears a QA value.
- بنرات التنظيف: passed — Search accepts and clears a QA value.
- التغطية الجغرافية: passed — Search accepts and clears a QA value.
- الأحياء: passed — Search accepts and clears a QA value.
- المعاملات المالية: passed — Search accepts and clears a QA value.
- التقرير المالي: passed
- تنبيهات النظام: passed
- الأدوار والصلاحيات: passed — Search accepts and clears a QA value.
- مدراء النظام: passed — Search accepts and clears a QA value.

## 3. Navigation Map

- [حجوزات التنظيف](https://alnadha.net/admin/cleaning-bookings)
- [العاملون](https://alnadha.net/admin/cleaning-workers)
- [النزاعات والشكاوى](https://alnadha.net/admin/disputes)
- [SOS Alerts](https://alnadha.net/admin/sos-alerts)
- [الإعدادات المالية](https://alnadha.net/admin/financial-settings)
- [إضافات الخدمة](https://alnadha.net/admin/service-addons)
- [بنرات التنظيف](https://alnadha.net/admin/cleaning-banners)
- [التغطية الجغرافية](https://alnadha.net/admin/geographic-coverage)
- [الأحياء](https://alnadha.net/admin/cleaning-neighborhoods)
- [المعاملات المالية](https://alnadha.net/admin/cleaning-worker-deposits)
- [التقرير المالي](https://alnadha.net/admin/cleaning-financial-report)
- [تنبيهات النظام](https://alnadha.net/admin/system-alerts)
- [الأدوار والصلاحيات](https://alnadha.net/admin/roles)
- [مدراء النظام](https://alnadha.net/admin/users)

## 4. CRUD Coverage Matrix

| Module | List | Search | Filters | View | Create | Edit | Actions | Delete | Result |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| حجوزات التنظيف | Yes | Yes | Opened | passed |
| العاملون | Yes | Yes | Opened | passed |
| النزاعات والشكاوى | Yes | Yes | Opened | passed |
| SOS Alerts | Yes | — | Opened | passed |
| الإعدادات المالية | — | — | Opened | passed |
| إضافات الخدمة | Yes | Yes | Opened | passed |
| بنرات التنظيف | Yes | Yes | Opened | passed |
| التغطية الجغرافية | Yes | Yes | Opened | passed |
| الأحياء | Yes | Yes | Opened | passed |
| المعاملات المالية | Yes | Yes | Opened | passed |
| التقرير المالي | — | — | Opened | passed |
| تنبيهات النظام | Yes | — | Opened | passed |
| الأدوار والصلاحيات | Yes | Yes | Opened | passed |
| مدراء النظام | Yes | Yes | Opened | passed |
| Service Add-ons | Yes | Yes | Manual follow-up | Yes | Yes | Yes | — | Yes | Passed and cleaned up |

## 5. Issue Summary

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |
| Suggestions | 0 |

## 6. Detailed Issues

No reproducible dashboard defects were recorded by this run.

## 7. Module-by-Module Results

- حجوزات التنظيف: passed — Search accepts and clears a QA value.
- العاملون: passed — Search accepts and clears a QA value.
- النزاعات والشكاوى: passed — Search accepts and clears a QA value.
- SOS Alerts: passed
- الإعدادات المالية: passed
- إضافات الخدمة: passed — Search accepts and clears a QA value.
- بنرات التنظيف: passed — Search accepts and clears a QA value.
- التغطية الجغرافية: passed — Search accepts and clears a QA value.
- الأحياء: passed — Search accepts and clears a QA value.
- المعاملات المالية: passed — Search accepts and clears a QA value.
- التقرير المالي: passed
- تنبيهات النظام: passed
- الأدوار والصلاحيات: passed — Search accepts and clears a QA value.
- مدراء النظام: passed — Search accepts and clears a QA value.

## 8. Validation Results

- Service Add-ons rejects an empty required form.

## 9. Security and Permission Notes

- Credentials, cookies, and authorization headers are never written to artifacts.
- The password-entry page is outside the recorded browser context.

## 10. Performance Notes

- Inspect `logs/network-errors.log` and `logs/browser-console.log` for captured technical evidence.

## 11. Responsive and Accessibility Notes

- Representative screenshots are captured at 1440×900, 1024×768, 768×1024, and 390×844.
- Keyboard focus is checked on the overview screen.

## 12. Created, Modified, and Deleted Records

- Service Add-ons: QA-VIDEO-20260712190001 Add-on — created=2026-07-12T19:00:27.886Z, modified=true, deleted=true

## 13. Cleanup and Restoration

- Deleted QA-created Service Add-on QA-VIDEO-20260712190001 Add-on through the dashboard UI.

## 14. Recommended Action Plan

1. Retest any logged error using its screenshot and console/network evidence.
2. Add dedicated QA fixtures before mutating bookings, disputes, SOS alerts, workers, or singleton financial settings.
3. Archive the finalized video with this report.

## 15. Retest Checklist

- [ ] All recorded issues have a verified resolution.
- [ ] Service Add-ons CRUD still cleans up its QA record.
- [ ] Every route in the navigation map remains accessible.
