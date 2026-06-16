# Previous Workers Filtering Handoff

## Issue
The backend endpoint `/api/v1/user/cleaning/orders/previous-workers` now supports filtering by booking `propertyType`, but the Flutter client still needs to send that context when it asks for previous workers.

Without that parameter, event-assistance booking screens can still receive workers whose preferred work type is cleaning-only.

## Backend Behavior
- The endpoint accepts an optional `propertyType` query parameter.
- When `propertyType=event_assistance`, workers with `preferred_work_type=cleaning` are excluded.
- When `propertyType` is a regular cleaning type such as `apartment`, `house`, `villa`, `office`, or `studio`, event-only workers are excluded.
- If `propertyType` is omitted, the endpoint keeps the legacy behavior and returns all previous workers.

## Flutter Changes Needed
Update the previous-workers request path so it passes the active booking type:

- Add `propertyType` to `GetPreviousCleaningWorkersParams`.
- Send the current booking `propertyType` from the booking screen that opens the previous-workers list.
- Pass `event_assistance` for event booking flows.
- Pass the selected cleaning type for regular cleaning flows.

## Files To Update In Flutter
- `dllni-user-app/lib/features/cl_main/domain/usecases/get_previous_cleaning_workers_use_case.dart`
- `dllni-user-app/lib/features/cl_main/data/source/cl_main_remote_data_source.dart`
- `dllni-user-app/lib/features/cl_main/view/screens/cl_main_home_description_screen.dart`
- `dllni-user-app/lib/features/cl_main/view/screens/cl_main_occasion_schedule_screen.dart`

## Expected Result
- Event-assistance screens should only show workers that can handle event bookings or both work types.
- Regular cleaning screens should keep showing workers that can handle cleaning or both work types.
- No changes are needed in Flutter for the worker birthday fix or the notification timing fix.
