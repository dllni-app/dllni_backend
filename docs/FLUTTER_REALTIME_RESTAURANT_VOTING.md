# Flutter Integration: Restaurant Voting Realtime (Pusher)

This guide explains how the Flutter app should integrate with the restaurant group voting APIs and realtime updates.

## 1) Backend Contract Summary

### REST endpoints

- `GET /api/restaurants/votes/suggestions` (public)
- `GET /api/restaurants/votes/{vote}` (public)
- `POST /api/restaurants/votes` (token required)
- `POST /api/restaurants/votes/{vote}/ballots` (token required)
- `POST /api/restaurants/votes/{vote}/end` (token required, creator only)

### Broadcasting

- Auth endpoint: `POST /broadcasting/auth` (private channel auth)
- Channel: `private-vote.{voteId}`
- Event name: `vote.updated`
- Backend channel authorization is registered as `vote.{voteId}` with `guards: ['sanctum']`.
  Laravel automatically maps `private-vote.{voteId}` from Pusher to that channel pattern.
- Event payload shape:

```json
{
  "vote": {
    "id": 123,
    "status": "active",
    "foodCategoryHint": "...",
    "cuisineTypeId": 2,
    "cuisineType": { "id": 2, "name": "Italian", "slug": "italian" },
    "durationMinutes": 30,
    "endsAt": "2026-04-07T12:00:00Z",
    "secondsRemaining": 1400,
    "creatorUserId": 99,
    "isCreator": false,
    "createdAt": "2026-04-07T11:30:00Z"
  }
}
```

Important: current realtime payload contains only `vote` meta. To refresh `options`, `voteCount`, `percent`, `voters`, and `winner`, call:

- `GET /api/restaurants/votes/{voteId}` after every `vote.updated` event.

## 2) Required Backend Runtime Settings

Set these values in backend `.env`:

```env
BROADCAST_CONNECTION=pusher
QUEUE_CONNECTION=database

PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_APP_CLUSTER=...
```

Since the event uses `ShouldBroadcast`, queue worker must be running:

```bash
php artisan queue:work
```

## 3) Flutter Packages

Example dependencies:

```yaml
dependencies:
  dio: ^5.7.0
  pusher_channels_flutter: ^2.4.0
```

## 4) Flutter Realtime Flow

1. Open vote screen with `voteId`.
2. Call `GET /api/restaurants/votes/{voteId}` to render initial state.
3. Connect to Pusher.
4. Subscribe to `private-vote.{voteId}`.
5. On `vote.updated`, call `GET /api/restaurants/votes/{voteId}` and replace local state.
6. When user votes, call `POST /api/restaurants/votes/{voteId}/ballots`.
7. When creator ends vote, call `POST /api/restaurants/votes/{voteId}/end`.
8. Keep local countdown ticking every second using `secondsRemaining` from latest response.

## 5) Flutter Example (Dio + Pusher)

```dart
import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';

class VoteRealtimeService {
  VoteRealtimeService({
    required this.baseUrl,
    required this.pusherKey,
    required this.pusherCluster,
    required this.token,
  }) : _dio = Dio(BaseOptions(baseUrl: baseUrl));

  final String baseUrl;
  final String pusherKey;
  final String pusherCluster;
  final String token;
  final Dio _dio;

  final PusherChannelsFlutter _pusher = PusherChannelsFlutter.getInstance();
  PusherChannel? _channel;

  Future<Map<String, dynamic>> fetchVote(int voteId) async {
    final res = await _dio.get(
      '/api/restaurants/votes/$voteId',
      options: Options(headers: {'Authorization': 'Bearer $token'}),
    );
    return Map<String, dynamic>.from(res.data['data'] as Map);
  }

  Future<void> castBallot({required int voteId, required int optionId}) async {
    await _dio.post(
      '/api/restaurants/votes/$voteId/ballots',
      data: {'optionId': optionId},
      options: Options(headers: {'Authorization': 'Bearer $token'}),
    );
  }

  Future<void> endVote(int voteId) async {
    await _dio.post(
      '/api/restaurants/votes/$voteId/end',
      options: Options(headers: {'Authorization': 'Bearer $token'}),
    );
  }

  Future<void> connectAndSubscribe({
    required int voteId,
    required Future<void> Function(Map<String, dynamic> freshData) onVoteChanged,
  }) async {
    await _pusher.init(
      apiKey: pusherKey,
      cluster: pusherCluster,
      onSubscriptionSucceeded: (channelName, data) {},
      onSubscriptionError: (message, e) {},
      onError: (message, code, e) {},
      onEvent: (event) async {
        if (event.channelName == 'private-vote.$voteId' && event.eventName == 'vote.updated') {
          final fresh = await fetchVote(voteId);
          await onVoteChanged(fresh);
        }
      },
      authEndpoint: '$baseUrl/broadcasting/auth',
      onAuthorizer: (channelName, socketId, options) async {
        final res = await _dio.post(
          '/broadcasting/auth',
          data: {'channel_name': channelName, 'socket_id': socketId},
          options: Options(headers: {'Authorization': 'Bearer $token'}),
        );

        return {
          'auth': res.data['auth'],
          if (res.data['channel_data'] != null) 'channel_data': res.data['channel_data'],
        };
      },
    );

    await _pusher.connect();
    _channel = await _pusher.subscribe(channelName: 'private-vote.$voteId');
  }

  Future<void> dispose() async {
    if (_channel != null) {
      await _pusher.unsubscribe(channelName: _channel!.channelName);
    }
    await _pusher.disconnect();
  }
}
```

## 6) UI State Recommendation

Keep one source of truth in state:

- `vote` object (status, secondsRemaining)
- `options` list (voteCount, percent)
- `voters` list
- `winner`

Update this state from:

- initial fetch
- successful vote/end response
- every `vote.updated` event (via re-fetch)

## 7) Troubleshooting

If updates are not realtime:

1. Confirm queue worker is running (`php artisan queue:work`).
2. Confirm app is subscribed to `private-vote.{voteId}`.
3. Confirm auth token is sent to `/broadcasting/auth`.
4. Confirm Pusher key/cluster values are correct in backend and Flutter.
5. Confirm event name listener is exactly `vote.updated`.
6. Confirm backend channel callback uses Sanctum guard options (`['guards' => ['sanctum']]`).
  Without explicit channel guards, Laravel may resolve channel user via default `web` guard and return 403.
