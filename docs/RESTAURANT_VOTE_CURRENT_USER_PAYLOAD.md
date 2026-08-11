# Restaurant Vote Current User Payload

Backend vote details now return creator data and current-user selection state.

Use `GET /api/v1/user/restaurants/votes/{voteId}` as the source of truth.

Important fields:

- `data.vote.creator`: id, name, avatarUrl, isCurrentUser.
- `data.vote.currentUserVote.hasVoted`: true when this authenticated user has voted.
- `data.vote.currentUserVote.optionId`: selected option id for this authenticated user.
- `data.options[*].isSelectedByCurrentUser`: true only for the current user's selected option.

Flutter selection priority:

1. `data.vote.currentUserVote.optionId`
2. `data.vote.currentUserOptionId`
3. `data.vote.selectedOptionId`
4. the option where `isSelectedByCurrentUser == true`

Realtime `vote.updated` events are neutral. After every event, Flutter should refetch `GET /api/v1/user/restaurants/votes/{voteId}` with the current auth header and replace the local state.
