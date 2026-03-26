# PARASITE Backend — Setup Guide

## What This Does
Real PHP backend that connects to UBA (and other banks) via Mono API.
When a user links their bank account, PARASITE:
1. Fetches their real transactions
2. Scans for overcharges, subscriptions, cashback opportunities
3. Auto-files disputes and claims
4. Detects swarm patterns across multiple users

---

## Files
```
parasite/
├── index.php       ← Main API router (all endpoints)
├── config.php      ← API keys and settings
├── db.php          ← Database connection + helpers
├── mono.php        ← Mono API wrapper
├── scanner.php     ← The brain — scans transactions
├── schema.sql      ← Database tables
├── .htaccess       ← Clean URL routing
└── README.md       ← This file
```

---

## Step 1 — Get Mono Sandbox Keys (5 minutes, free)

1. Go to https://app.mono.co and sign up (free)
2. Create an app — name it "PARASITE"
3. Go to Settings → API Keys
4. Copy:
   - **Secret Key** (starts with `test_sk_...`)
   - **Public Key** (starts with `test_pk_...`)
   - **Webhook Secret** (starts with `test_wh_...`)
5. Open `config.php` and paste them in

---

## Step 2 — Database Setup

1. Open http://localhost/phpmyadmin
2. Click the **SQL** tab
3. Paste the entire contents of `schema.sql` and click Go
4. You should see all 8 tables created

---

## Step 3 — Put files in Apache

1. Create folder: `C:/xampp/htdocs/parasite/`
2. Copy all files into that folder
3. Make sure Apache is running in XAMPP

---

## Step 4 — Test it works

Open your browser and go to:
```
http://localhost/parasite/
```
You should see:
```json
{ "success": true, "name": "PARASITE API", "status": "running" }
```

---

## Step 5 — Add Mono widget to your frontend

In your frontend HTML, add this script tag:
```html
<script src="https://connect.withmono.com/connect.js"></script>
```

Then when user clicks "Connect Bank":
```javascript
const connect = new Connect({
    key: "test_pk_xxxxxxxxxxxxxxxx",  // your Mono public key
    onSuccess: function(data) {
        // data.code is the auth code
        // Send it to your backend
        fetch('http://localhost/parasite/link-account', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + userToken
            },
            body: JSON.stringify({ code: data.code })
        })
        .then(r => r.json())
        .then(result => {
            console.log('Bank linked:', result);
            // Now sync transactions
            return fetch('http://localhost/parasite/sync', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + userToken }
            });
        })
        .then(r => r.json())
        .then(scan => {
            console.log('Scan results:', scan);
        });
    }
});
connect.open();
```

---

## API Endpoints

### Register
```
POST http://localhost/parasite/register
Body: { "name": "REVENANT", "email": "you@email.com", "password": "secret123" }
Response: { "token": "abc123...", "user_id": 1 }
```

### Login
```
POST http://localhost/parasite/login
Body: { "email": "you@email.com", "password": "secret123" }
Response: { "token": "abc123...", "name": "REVENANT" }
```

### Link Bank (after Mono widget gives you a code)
```
POST http://localhost/parasite/link-account
Headers: Authorization: Bearer abc123...
Body: { "code": "mono_auth_code_here" }
Response: { "account_id": 1, "bank": "UBA", "balance": 45000 }
```

### Sync Transactions (fetches + scans everything)
```
POST http://localhost/parasite/sync
Headers: Authorization: Bearer abc123...
Response: {
    "transactions_fetched": 87,
    "transactions_new": 12,
    "scan_results": {
        "subscriptions_found": 3,
        "overcharges_found": 5,
        "cashback_found": 2,
        "total_recoverable": 4750
    }
}
```

### Dashboard
```
GET http://localhost/parasite/dashboard
Headers: Authorization: Bearer abc123...
```

### All Transactions
```
GET http://localhost/parasite/transactions
GET http://localhost/parasite/transactions?flag=1   ← flagged only
```

### Subscriptions Found
```
GET http://localhost/parasite/subscriptions
```

### Disputes Filed
```
GET http://localhost/parasite/disputes
```

### Cashback Claims
```
GET http://localhost/parasite/cashback
```

### Active Swarms (public)
```
GET http://localhost/parasite/swarms
```

---

## Mono Sandbox Test Banks

In sandbox mode Mono gives you test credentials.
Use these to simulate a UBA account:

- **Bank:** UBA
- **Username:** `0000000000` (10 zeros)
- **Password:** `password`
- **OTP:** `123456`

This gives you fake but realistic transaction data to test the scanner.

---

## How the Scanner Works

After sync, `scanner.php` reads every transaction and:

**Subscription Detection**
- Checks narration for known services (Netflix, Spotify, DSTV, MTN, etc.)
- If found → saves to `subscriptions` table, flags the transaction

**Overcharge Detection**
- Looks for keywords: CHARGE, FEE, MAINTENANCE, STAMP DUTY etc.
- Checks for repeated identical amounts from same merchant
- If found → saves to `disputes` table, flags the transaction

**Cashback Detection**
- Checks for known cashback merchants (Jumia, Konga, Uber, etc.)
- Calculates 2% estimated cashback
- Saves to `cashback_claims` table

**Swarm Detection**
- After individual scanning, checks if 5+ users (100 in production)
  have the same overcharge from the same merchant
- If yes → creates a `swarm_clusters` entry
- Links all related disputes to the swarm

---

## Webhook (automatic real-time scanning)

When Mono detects new transactions on a linked account, it calls:
```
POST http://yoursite.com/parasite/webhook/mono
```
PARASITE automatically fetches and scans the new transactions.

To set this up:
1. Go to Mono dashboard → Webhooks
2. Add URL: `http://yoursite.com/parasite/webhook/mono`
3. Select event: `mono.events.account_updated`

For local testing use ngrok:
```
ngrok http 80
```
Then use the ngrok URL as your webhook.
