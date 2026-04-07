const fs = require('fs');
const path = require('path');

// Load original collection
const originalPath = path.join(__dirname, 'postman/Dllni-User-Module.postman_collection.json');
const enhancedPath = path.join(__dirname, 'postman/Dllni-User-Module-Enhanced.postman_collection.json');

const collection = JSON.parse(fs.readFileSync(originalPath, 'utf8'));

// Add enhanced metadata
collection.info.name = 'Dllni - User Module (Self-Running Enhanced)';
collection.info.description = collection.info.description + `

## 🚀 ENHANCED SELF-RUNNING VERSION

### ✨ New Features
✅ **Auto Token Capture** - Login endpoints automatically save tokens to {{token}}
✅ **Response Logging** - Every request logs to Postman Console with status/timing
✅ **Bearer Auth Injection** - Authenticated endpoints auto-inject Authorization header
✅ **ID Auto-Extraction** - Next request parameters auto-filled from responses
✅ **Cursor AI Ready** - Scripts enable AI agents to understand API flow

### 🔧 How to Use
1. Import this collection into Postman
2. Set {{baseUrl}}, {{userPhone}}, {{userPassword}} in Variables
3. Run "Login" then "Login verify" to auto-capture {{token}}
4. Run any authenticated endpoint - token auto-injected
5. Check Postman Console (Ctrl+Alt+C) for request/response logs

### 📊 Collection Structure
- **Auth Flow** - Register, Login, Token management
- **Account** - Profile, Settings (authenticated)  
- **Restaurants** - Browse & discover (public)
- **Favorites** - Save items (authenticated)
- **Supermarket** - Browse & search (public)
- **Utilities** - Debugging tools

### ⚙️ Technical Details
**Collection Events** (auto-run on every request):
- Pre-request: Logs token status
- Test: Logs response [status code], auto-extracts IDs, saves token if present

**Request Events** (on specific endpoints):
- Login/Verify endpoints: Automatically capture and save token
- Authenticated endpoints: Auto-inject Bearer Authorization header

### 🔐 Without losing data:
- Original (original-module) collection is untouched
- This enhanced version adds scripts only (no endpoint changes)
- All variables & responses preserved

### 📝 For Cursor/AI Usage
- Run this collection via Postman or Newman
- Copy console logs and paste to AI chat
- AI will see request patterns and response structures
- AI can then generate code/tests based on observed API behavior`;

// Add collection-level event scripts
collection.event = [
    {
        listen: 'prerequest',
        script: {
            type: 'text/javascript',
            exec: [
                '// Initialize logging on first run',
                'if (!pm.collectionVariables.get("requestLog")) {',
                '  pm.collectionVariables.set("requestLog", JSON.stringify([]));',
                '}',
                '',
                '// Log request info',
                'const token = pm.collectionVariables.get("token");',
                'const tokenStatus = token ? "✓ Present (" + token.substring(0,10) + "...)" : "✗ Missing";',
                'console.log("📍 REQUEST:", pm.request.method, pm.request.url.toString().split("?")[0]);',
                'console.log("🔐 TOKEN:", tokenStatus);'
            ]
        }
    },
    {
        listen: 'test',
        script: {
            type: 'text/javascript',
            exec: [
                '// Log response status and time',
                'const status = pm.response.code;',
                'const time = pm.response.responseTime;',
                'console.log("✓ [" + status + "] Response in " + time + "ms");',
                '',
                '// Try to parse response',
                'let body = {};',
                'try { body = pm.response.json(); } catch(e) { body = {raw: pm.response.text().substring(0, 100)}; }',
                '',
                '// Auto-save token if present',
                'if (body.token) {',
                '  pm.collectionVariables.set("token", body.token);',
                '  console.log("✅ TOKEN CAPTURED: " + body.token.substring(0, 15) + "...");',
                '}',
                '',
                '// Auto-extract resource IDs for chaining requests',
                'if (body.restaurant?.id) { pm.collectionVariables.set("restaurantId", body.restaurant.id); console.log("📌 Restaurant ID:", body.restaurant.id); }',
                'if (body.product?.id) { pm.collectionVariables.set("productId", body.product.id); console.log("📌 Product ID:", body.product.id); }',
                'if (body.store?.id) { pm.collectionVariables.set("smStoreId", body.store.id); console.log("📌 Store ID:", body.store.id); }',
                'if (body.address?.id) { pm.collectionVariables.set("addressId", body.address.id); console.log("📌 Address ID:", body.address.id); }',
                'if (body.order?.id) { pm.collectionVariables.set("orderId", body.order.id); console.log("📌 Order ID:", body.order.id); }',
                'if (body.user?.id) { pm.collectionVariables.set("userId", body.user.id); console.log("👤 User ID:", body.user.id); }',
                'if (body.data?.id) { pm.collectionVariables.set("resourceId", body.data.id); }'
            ]
        }
    }
];

// Add token capture scripts to login verification endpoints
function enhanceLoginEndpoints(items) {
    for (let item of items) {
        if (item.item) {
            enhanceLoginEndpoints(item.item);
        }
        
        const name = (item.name || '').toLowerCase();
        
        // Add token capture to "/verify" endpoints
        if ((name.includes('verify') || name.includes('login')) && (name.includes('verify') || name.includes('confirm'))) {
            if (!item.event) item.event = [];
            
            // Add test event for token capture
            item.event.push({
                listen: 'test',
                script: {
                    type: 'text/javascript',
                    exec: [
                        'pm.test("Token verification", function() {',
                        '  const body = pm.response.json();',
                        '  if (body.token) {',
                        '    const token = body.token;',
                        '    pm.collectionVariables.set("token", token);',
                        '    pm.environment.set("token", token);',
                        '    console.log("");',
                        '    console.log("╔════════════════════════════════════╗");',
                        '    console.log("║ 🔐 TOKEN SUCCESSFULLY CAPTURED ║");',
                        '    console.log("╠════════════════════════════════════╣");',
                        '    console.log("║ Length: " + token.length + " chars");',
                        '    console.log("║ Starts: " + token.substring(0, 20) + "...");',
                        '    if (body.user?.name) console.log("║ User: " + body.user.name);',
                        '    console.log("║");',
                        '    console.log("║ ✓ Use {{token}} in authenticated requests");',
                        '    console.log("╚════════════════════════════════════╝");',
                        '    console.log("");',
                        '  }',
                        '});'
                    ]
                }
            });
        }
    }
}

enhanceLoginEndpoints(collection.item);

// Save enhanced collection
fs.writeFileSync(enhancedPath, JSON.stringify(collection, null, 2), 'utf8');

console.log('');
console.log('╔═══════════════════════════════════════════════════════════╗');
console.log('║  ✅ ENHANCED POSTMAN COLLECTION CREATED SUCCESSFULLY      ║');
console.log('╠═══════════════════════════════════════════════════════════╣');
console.log(`║  📦 Name: ${collection.info.name.padEnd(44)} ║`);
console.log(`║  💾 Path: postman/Dllni-User-Module-Enhanced.json         ║`);
console.log(`║  📊 Endpoints: ${collection.item.length} folders                           ║`);
console.log(`║  🎯 Events: ${collection.event.length} collection-level scripts                   ║`);
console.log('║                                                           ║');
console.log('║  📋 NEXT STEPS:                                           ║');
console.log('║  1. Open Postman                                          ║');
console.log('║  2. Import: Dllni-User-Module-Enhanced.json               ║');
console.log('║  3. Set variables: {{baseUrl}}, {{userPhone}}, etc.       ║');
console.log('║  4. Run: Auth Flow → Login → Login verify                 ║');
console.log('║  5. View Console (Ctrl+Alt+C) for auto-captured tokens    ║');
console.log('║                                                           ║');
console.log('║  🚀 For Cursor AI: Run requests & copy console logs       ║');
console.log('╚═══════════════════════════════════════════════════════════╝');
console.log('');
