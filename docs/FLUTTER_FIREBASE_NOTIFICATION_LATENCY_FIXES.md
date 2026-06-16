# Flutter Firebase Notification Latency Fixes

This document describes required Flutter-side changes to reduce delayed or missed push notifications. Backend improvements (cached FCM OAuth and standard Laravel notifications) only help when the app registers a valid FCM token and displays notifications reliably.

## Affected apps

Apply these changes in each app `common_package`:

| App | Notification helper |
|-----|---------------------|
| `dllni-user-app` | `common_package/lib/helpers/notification_helper.dart` |
| `dllni_cleaning_owner_app` | `common_package/lib/helpers/notification_helper.dart` |
| `dllni_resturant_owner_app` | `common_package/lib/helpers/notification_helper.dart` |
| `dllni_supermarket_owner_app` | `common_package/lib/helpers/notification_helper.dart` |

---

## 1. Use FCM registration token on iOS (critical)

**Problem:** User and cleaning apps call `getAPNSToken()` on iOS. APNs token is not the FCM registration token the backend expects in `users.fcm_token`.

**Fix:** Always use `FirebaseMessaging.instance.getToken()` on both Android and iOS.

```dart
// Before (wrong on iOS)
final token = Platform.isIOS
    ? await FirebaseMessaging.instance.getAPNSToken()
    : await FirebaseMessaging.instance.getToken();

// After
final token = await FirebaseMessaging.instance.getToken();
```

Apply in all four apps. Restaurant and supermarket helpers also use `getAPNSToken()` and must be updated.

---

## 2. Register token immediately with backend (critical)

**Problem:** Token is saved to SharedPreferences and synced only when the next authenticated API request includes the `fcm-token` header. After login or token refresh, backend may keep a stale token for minutes or hours.

**Fix:** After app startup, login, and every `onTokenRefresh`, call the token registration API immediately.

### User app

```
PUT /api/v1/user/notifications/token
Authorization: Bearer {accessToken}
Content-Type: application/json

{ "fcmToken": "<firebase_registration_token>" }
```

### Cleaning worker app

```
PUT /api/v1/cleaning/worker/account/notifications/token
Authorization: Bearer {accessToken}
Content-Type: application/json

{ "fcmToken": "<firebase_registration_token>" }
```

### Delivery driver app

```
POST /api/v1/delivery/driver/push/register
Authorization: Bearer {accessToken}
```

### Recommended client flow

1. Fetch token with `getToken()`.
2. Save locally (SharedPreferences).
3. If user is authenticated, call the register endpoint immediately.
4. On `onTokenRefresh`, repeat steps 2–3.
5. Keep sending `fcm-token` header on Dio requests as a fallback only.

Do not rely on the header as the primary registration path.

---

## 3. Initialize Awesome Notifications in background isolate

**Problem:** `firebaseMessagingBackgroundHandler` creates Awesome local notifications without initializing the plugin in the background isolate. Background/data-only messages may not appear until the app opens.

**Fix:** Initialize Awesome Notifications inside the background handler before `createNotification()`:

```dart
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();

  await AwesomeNotifications().initialize(null, [
    NotificationChannel(
      channelKey: 'basic_channel',
      channelName: 'Basic Notifications',
      importance: NotificationImportance.High,
      onlyAlertOnce: false, // see section 6
      channelShowBadge: true,
      channelDescription: 'Basic Instant Notification',
    ),
  ]);

  // ... createNotification()
}
```

Alternatively, ensure backend always sends notification payload (title/body) so the OS can display the push without a local notification hop.

---

## 4. Request FCM permission in all apps

**Problem:** Restaurant and supermarket helpers skip `FirebaseMessaging.instance.requestPermission()`.

**Fix:** Request FCM permission on iOS in every app, same as user/cleaning apps:

```dart
await FirebaseMessaging.instance.requestPermission(
  alert: true,
  badge: true,
  sound: true,
);
```

Also request Android 13+ `POST_NOTIFICATIONS` where applicable.

---

## 5. iOS push configuration

Verify for user and cleaning apps (and any iOS build receiving push):

### `ios/Runner/Runner.entitlements`

```xml
<key>aps-environment</key>
<string>development</string> <!-- or production for release -->
```

### `ios/Runner/Info.plist`

```xml
<key>UIBackgroundModes</key>
<array>
  <string>remote-notification</string>
</array>
```

Confirm `GoogleService-Info.plist` matches the Firebase project used by the backend (`FIREBASE_PROJECT_ID`).

---

## 6. Review `onlyAlertOnce: true`

**Problem:** The shared Awesome channel uses `onlyAlertOnce: true`. Rapid repeated notifications (e.g. multiple order offers) may be collapsed or appear suppressed on Android.

**Fix:** Set `onlyAlertOnce: false` for order/offer channels, or use separate high-priority channels per module (`cleaning_orders`, `delivery_offers`, etc.).

---

## 7. Foreground display path

**Current behavior:** Foreground messages go through `FirebaseMessaging.onMessage` → Awesome local notification.

This adds a small client-side delay but is acceptable if token registration and background handling are correct. Optional optimization: use FCM notification payload and let the system display when possible; reserve Awesome for data-only messages.

---

## 8. Token fetch retry (user/cleaning apps)

Current retry backoff is up to ~12 seconds at cold start. This does not delay FCM delivery itself, but delays backend registration.

**Recommendation:**

- Do not block app startup on token fetch; run registration in parallel after `runApp()`.
- Register token with backend as soon as it becomes available.

---

## 9. Testing checklist

For each app (Android + iOS):

1. Fresh install → login → confirm backend `users.fcm_token` updates within seconds (not after unrelated API calls).
2. Trigger a high-priority push (cleaning new order, delivery offer).
3. Measure:
   - Backend notification dispatch succeeds
   - Device receives notification in background and killed state
   - Foreground notification appears without long delay
4. Revoke/reinstall app → confirm the new token registers server-side and push delivery resumes for that device.
5. iOS: confirm token in backend is FCM format (long string), not a short APNs device token only.

---

## 10. Backend coordination summary

Backend now:

- Caches Google OAuth token for FCM HTTP v1 (avoids per-send OAuth refresh)
- Uses standard Laravel notifications through the package `fcm` channel
- Preserves the existing notification payload keys and `users.fcm_token` routing

For production, keep the queue workers that already process your normal Laravel notifications running alongside the app.

Flutter must register valid FCM tokens promptly for these backend changes to improve delivery end-to-end.
