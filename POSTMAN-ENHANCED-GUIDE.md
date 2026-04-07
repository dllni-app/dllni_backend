# 🚀 Enhanced Postman Collection - Quick Start Guide

## What's New?

Your **Dllni-User-Module-Enhanced.postman_collection.json** now has:

✅ **Auto Token Capture** - Login endpoints automatically save tokens  
✅ **Response Logging** - Every request logs to Postman Console  
✅ **Bearer Auth Injection** - Authenticated endpoints auto-add Authorization header  
✅ **ID Auto-Extraction** - Restaurant/Product IDs automatically saved for chaining requests  
✅ **Cursor AI Ready** - Scripts structured so AI agents can understand the API flow  

---

## 🔧 Setup (5 minutes)

### 1. Import into Postman
- Open **Postman**
- Click **Import** (top left)
- Select `Dllni-User-Module-Enhanced.postman_collection.json`
- Click **Import**

### 2. Configure Variables
- Click **Collections** tab (left sidebar)
- Right-click collection → **Edit**
- Go to **Variables** tab
- Update these required variables:

| Variable | Value | Example |
|----------|-------|---------|
| `{{baseUrl}}` | Your API base (default works) | `http://Dllni.test` |
| `{{userPhone}}` | Test user phone | `+963944000222` |
| `{{userPassword}}` | Test user password | `secret123` |
| `{{userOtp}}` | Will be filled by SMS | Leave empty initially |

---

## 🔐 First Run: Login Flow

### Step 1: Send Login OTP
1. Expand **Auth Flow** folder
2. Click **"Login (send OTP)"**
3. Click **Send** (blue button)
4. **Check your phone for 6-digit OTP code**

### Step 2: Verify Login & Capture Token
1. In the UI, update `{{userOtp}}` with the code from SMS
2. Click **"Login verify (OTP) → Token"**
3. Click **Send**
4. **Check Postman Console** (Ctrl+Alt+C):
   ```
   ✓ [200] Response in 245ms
   ✅ TOKEN CAPTURED: eyJ0eXAiOiJKV1QiLCJhbGc...
   ```

### Step 3: Verify Token Saved
1. Click **Utilities** folder
2. Click **"Show All Variables"**
3. Click **Send** (dummy request)
4. Check Console - should show:
   ```
   Token: ✓ Present (eyJ0eXAiOiJK...)
   ```

---

## 💡 Using the Collection

### For Public Endpoints (No Auth)
- **Restaurants (Public)** - Browse restaurants
- **Supermarket (Public)** - Search products
- No token needed! ✓

### For Authenticated Endpoints
- **Account Management** - Profile, notifications
- **Favorites** - Save restaurants/products
- **Token auto-injected** ✓

Just run these - **token is automatically added** to Authorization header.

### Response Logging

After **every** request, Postman Console shows:
```
📍 REQUEST: GET /api/v1/user/restaurants/home/categories
🔐 TOKEN: ✓ Present (eyJ0eXAiOiJK...)
✓ [200] Response in 156ms
📌 Restaurant ID: 5
```

---

## 🤖 Using with Cursor AI

### Option 1: Manual Testing → Copy Logs
1. **Import** the collection into Postman
2. **Run requests** in sequence (Auth → Restaurants → Favorites)
3. **Open Postman Console** (Ctrl+Alt+C)
4. **Copy all logs** from console
5. **Paste to Cursor Chat**:
   ```
   [Paste logs here]
   
   These are the requests/responses from Dllni API.
   Can you analyze the patterns and generate:
   - TypeScript interfaces for responses
   - Pest test cases
   - API documentation
   ```

### Option 2: Automated Testing (Advanced)
```bash
# Run collection via Newman (CLI)
npx newman run postman/Dllni-User-Module-Enhanced.postman_collection.json \
  -e environment.json \
  --reporters cli,json \
  --reporter-json-export results.json
```
Then paste `results.json` to Cursor for analysis.

---

## 📊 Request Chaining

The collection **auto-extracts IDs** for chaining requests:

**Example:**
1. Run **"Discover Restaurants"** → response has `restaurant.id: 5`
2. Automatically sets `{{restaurantId}} = 5`
3. Run **"Get Restaurant Details"** → uses `{{restaurantId}}`
4. Works! ✓

**Auto-Extracted IDs:**
- `{{restaurantId}}` - From restaurant responses
- `{{productId}}` - From product responses
- `{{smStoreId}}` - From supermarket store responses
- `{{addressId}}` - From address responses
- `{{orderId}}` - From order responses

---

## 🆘 Troubleshooting

### ❌ "Token missing" error
**Solution:**
1. Run **Login** → **Login verify** again
2. Check Console for `✅ TOKEN CAPTURED` message
3. If not captured, manually copy token from response
4. Paste into Variables → `{{token}}`

### ❌ Postman Console not showing
**Solution:**
- Press **Ctrl+Alt+C** (Windows/Linux)
- Or click **View** → **Show Postman Console**

### ❌ OTP not arriving
**Solution:**
- Check if `{{userPhone}}` is correct
- Check spam/SMS folder
- Verify SMS provider is configured in backend

### ❌ 422 Validation Error
**Solution:**
- Check request body format in **Body** tab
- Example error: `"errors": {"email": ["Email already registered"]}`
- Use a new email/phone for registration

---

## 📝 Example Workflow with Cursor

```
1. Open Postman with enhanced collection
2. Run: Auth → Login → Login verify [token captured ✓]
3. Run: Restaurants → Home Categories [logs endpoint output]
4. Run: Favorites → Add Restaurant [shows favorite creation]
5. Run: Supermarket → Search Products [logs responses]
6. Select all Console logs (Ctrl+A in Console)
7. Copy → Paste to Cursor:

---

@cursor: Here are my API logs. The API is at /api/v1/user. 
Please write full Pest tests for:
- User registration + verification
- Restaurant browsing + favorites
- Product search

[PASTE LOGS HERE]

---

8. Cursor generates complete test suite based on observed responses ✓
```

---

## 🔍 Collection Structure

```
Dllni-User-Module (Self-Running Enhanced)
├── Auth Flow
│   ├── Register (send OTP)
│   ├── Verify account (OTP) → Token
│   ├── Login (send OTP)
│   └── Login verify (OTP) → Token ⭐ [auto-captures {{token}}]
├── Account Management
│   ├── Get Current User (Me)
│   ├── Get Account Summary
│   └── List Notifications
├── Restaurants (Public)
│   ├── Home - Categories
│   ├── Home - Nearest Restaurants
│   ├── Home - Exclusive Offers
│   └── Discover Restaurants
├── Favorites ⭐ [auto-injects {{token}}]
│   ├── List Favorite Restaurants
│   └── Add Restaurant to Favorites
├── Supermarket (Public)
│   ├── Home - Nearby Stores
│   └── Search Products
├── Response Logs
│   └── View Last Response
└── Utilities
    ├── Show All Variables
    └── Clear Token (Reset)
```

---

## 🎯 Key Endpoints for Testing

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `/api/v1/user/register` | ❌ | Create account + send OTP |
| `/api/v1/user/verify-account` | ❌ | Verify OTP → get token |
| `/api/v1/user/login` | ❌ | Login + send OTP |
| `/api/v1/user/login/verify` | ❌ | Verify OTP → get token ⭐ |
| `/api/v1/user/me` | ✅ | Get profile |
| `/api/v1/user/restaurants/home/categories` | ❌ | List cuisines |
| `/api/v1/user/restaurants` | ❌ | Discover restaurants |
| `/api/v1/user/favorites/restaurants` | ✅ | List saved restaurants |
| `/api/v1/user/favorites/restaurants/{id}` | ✅ | Add/remove favorite |
| `/api/v1/user/supermarket/products` | ❌ | Search products |

---

## 📚 API Docs

See [Postman collection description](../docs/) for:
- Full endpoint documentation
- Response schemas
- Error codes & handling
- Pagination details
- Media/image fields

---

## ✨ Next Steps

1. ✅ Import enhanced collection
2. ✅ Run Auth Flow (register/login)
3. ✅ Test public endpoints (restaurants, supermarket)
4. ✅ Test authenticated endpoints (favorites, profile)
5. ✅ Copy Console logs → paste to Cursor
6. ✅ Have Cursor generate tests/TypeScript

**Result**: Full test suite generated from real API behavior! 🚀

---

**Questions?** Check Postman Console output (Ctrl+Alt+C) - every request logs details.
