# API Contract – Client Behavior (UI/API Usage)

**Audience:** Frontend / Flutter developer  
**Scope:** Applies to all API consumers. These rules define how the client must handle GET, POST, and PUT in the UI and in app logic.

For endpoint details, see the module-specific contracts: [API_CONTRACT_AUTH.md](API_CONTRACT_AUTH.md), [API_CONTRACT_HOMEPAGE.md](API_CONTRACT_HOMEPAGE.md), [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md), [API_CONTRACT_CLEANING.md](API_CONTRACT_CLEANING.md), [API_CONTRACT_CLEANING_WORKER.md](API_CONTRACT_CLEANING_WORKER.md), [API_CONTRACT_SUPERMARKET_ADMIN.md](API_CONTRACT_SUPERMARKET_ADMIN.md), [API_CONTRACT_SUPERMARKET_OWNER.md](API_CONTRACT_SUPERMARKET_OWNER.md).

---

## 1. GET operations that use an id or enum value

**Rule:** Any GET that is driven by an **id** (e.g. category id, store id) or by a **value from an enum** must get that value from a **select menu** (dropdown/picker). The raw id or enum value must **not** be shown to the user; only the human-readable label (e.g. category name, status label) may be displayed.

- **Example:** To get products of type "بقوليات" (legumes), the user selects "بقوليات" from a category select; the app then sends the **category id** (or enum value) in the request (query/path). The user never sees or edits the id.
- **Implementation:** Populate the select from a list endpoint or from a static enum map; store the selected id/value in app state and use it when calling the GET.

---

## 2. POST operations – data that exists on the backend

**Rule:** If a POST request requires data that already exists on the backend (e.g. ids, module keys, enum values), that data must be **held in page/screen state** and **must not** be shown to the user or be editable by the user.

- Store such values in memory (e.g. from navigation params, from a previous API response, or from app config) and include them in the POST body or headers without exposing them in the UI.
- The user must not have input fields or controls to change these values.

---

## 3. POST operations – data from the user

**Rule:** For every piece of data that the user must provide in a POST, the client must provide a **dedicated input field** (or equivalent control) for that data.

- One logical field in the API payload = one dedicated input in the UI (with appropriate type: text, number, date, select from user-visible options, etc.), so that the user can enter or choose the value explicitly.

---

## 4. PUT (or PATCH) operations – optimistic update

**Rule:** After sending a PUT/PATCH request, the client must **update the local data (UI state) immediately** with the new values, then **persist or confirm** only when the request **succeeds**. If the request fails, **revert** the local data to the previous state and show an error.

- **Flow:** User edits → send PUT → apply the same changes to local state right away (optimistic update) → on success, keep the updated state (and optionally sync with server response); on failure, revert and show error message.

---

## Summary table

| Operation | Client behavior |
| --------- | ----------------- |
| **GET** (with id or enum) | Value comes from a select menu; user sees only the label, not the id/enum value. |
| **POST** (backend-known data) | Keep in page state; do not show or allow user to edit. |
| **POST** (user data) | One dedicated input field per required/user-supplied field. |
| **PUT/PATCH** | Update local data immediately after sending; persist on success, revert on failure. |
