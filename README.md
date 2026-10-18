# Brewed Delights — Coffee Shop Ordering & Management System

A working MVP built on the stack from the spec: **PHP (PDO) + MySQL + HTML/CSS/JS**,
meant to run under **XAMPP**.

## What's included

- Customer side: browse menu, cart, **GCash (reference-number) or Cash
  checkout**, loyalty points earn + redeem, live order-status tracking,
  feedback form, personal delivery with live rider tracking (or pickup).
- Admin side: dashboard with daily stats, menu CRUD, order status
  management, **payment verification** (confirm GCash reference numbers or
  cash collected), rider account management, staff account management,
  feedback review.
- Menu products can have selectable size/flavor prices. Create or edit a
  product in `admin/menu.php` and enter one option per line as `Label | price`;
  the customer menu, cart, and checkout update from the database automatically.
- Customer menu sections are grouped and searchable; toppings and syrups are
  optional drink extras and cannot be ordered by themselves.
- The admin menu editor supports image uploads and structured size/price rows.
- The admin dashboard shows the five most-ordered products over the trailing seven days, excluding cancelled orders.
- **Staff side**: a separate role from admin (per the capstone's use case
  diagram) that can process orders, verify payments, and review feedback —
  but can't touch the menu, riders, or other accounts.
- Rider side: a dashboard for delivery staff to see assigned orders, start/
  complete a delivery, share their live GPS location with the customer, and
  manage a rider-specific account profile, separate delivery history, and
  customer feedback received for deliveries.
- **A token-authenticated JSON API** (`/api`) for external clients (a future
  mobile app, or testing with Postman) — separate from the website's own
  session-based login.
- **Security hardening**: CSRF protection on every form, login rate limiting
  (5 fails → 15 min lockout), production-safe error handling, a self-locking
  admin setup script, and `.htaccess` rules blocking direct access to
  sensitive folders.
- All SQL uses PDO **prepared statements** — no string-concatenated queries.
- Passwords hashed with `password_hash()` / verified with `password_verify()`.
- Optional Google customer sign-in and transactional order emails (credentials
  are configured locally; Google sign-in never grants staff/admin/rider roles).
- Local username/email and password accounts require no third-party login or SMS service.
- Signed-in users can update their own phone number, delivery address, and password on the Account page.

## Setup (XAMPP)

1. Copy the whole `brewed-delights` folder into `htdocs`
   (e.g. `C:\xampp\htdocs\brewed-delights` or `/Applications/XAMPP/htdocs/brewed-delights`).
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. In phpMyAdmin, import either or both SQL files:
  - `sql/brewed_delights_current.sql` creates `brewed_delights_current` with
    the existing accounts, menu, orders, payments, and feedback.
  - `sql/brewed_delights_clean.sql` creates `brewed_delights_clean` with the
    full schema and current menu, but no accounts or orders.
4. The app connects to `brewed_delights_current` by default and does not fall
  back to another database. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`
  in the server environment to match your host. For local XAMPP, the defaults
  are `localhost`, `brewed_delights_current`, `root`, and a blank password.
  The imported databases have distinct names and do not overwrite the existing
  local `brewed_delights` database.
5. Copy `config/services.local.example.php` to `config/services.local.php`
  and configure real GCash payment details (`gcash_number` and `gcash_name`),
  or set the `GCASH_NUMBER` / `GCASH_NAME` environment variables. GCash stays
  disabled until valid details are set; Cash remains available.
6. Visit `http://localhost/brewed-delights/`. For the clean catalog, set the
  `DB_NAME` environment variable to `brewed_delights_clean`; it has no admin,
  so create its first admin from the same computer through
  `http://localhost/brewed-delights/setup_admin.php`, then delete that file.
  The bootstrap rejects non-local requests.

Each SQL file creates a separate database name. Reimporting a file replaces
the tables only in its own target database; export any newer data before doing
that. The current-data snapshot contains private account and order data. Keep
it private and never publish it in a public repository. The clean SQL file is
the one to share when a database with no existing customer/order data is needed.

## Deploying to a free PHP host

Before choosing a host, confirm its free plan supports a currently supported
PHP release (PHP 8.3 or newer is recommended), PDO MySQL, `fileinfo`, and
`mbstring`, plus a MySQL/MariaDB database. The code requires PHP 8.1 or newer;
Composer checks this requirement when dependencies are installed.

1. Create a database and a dedicated database user in the hosting control panel.
2. Start from `sql/brewed_delights_clean.sql`, not the `current` snapshot. The
  current snapshot contains private account and order data. If the host's
  phpMyAdmin does not allow `CREATE DATABASE` or `USE`, remove those statements
  from a private copy of the clean SQL file and import it into the database
  created in the hosting panel. Never upload SQL dumps into the public webroot.
3. Create the first admin account before deployment. Import the clean SQL file
  into a local XAMPP database, open `setup_admin.php` through localhost, then
  export that database for a private transfer to the host. The setup page
  intentionally rejects remote requests. Do not include `setup_admin.php` or
  the database export in the uploaded website files.
4. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` using the host's private
  environment-variable settings. Use the exact database name shown in the
  hosting panel (it may include an account prefix). Do not use MySQL's `root`
  account or a blank password on a public host. Set optional service settings
  the same way; environment variables take precedence over
  `config/services.local.php`.
5. Upload the application files to the host's document root over SFTP or its
  private deployment tool, excluding `sql/`, `setup_admin.php`,
  `config/services.local.php`, `logs/`, and local-only files. Keep `config/`,
  `logs/`, and uploaded files inaccessible for direct browsing; the included
  `.htaccess` rules require Apache-compatible hosting. If the host uses Nginx,
  configure equivalent server rules. Allow PHP to write to `logs/` and
  `Products Images/`.
6. Enable HTTPS and test registration, login, menu, cart, checkout, and admin
  access on the hosted site before announcing it. Remove any temporary export
  or setup files from the server after importing the database.
7. Run `composer install --no-dev --optimize-autoloader` if enabling Google
  sign-in or email notifications, then configure those service credentials
  privately. Some free hosts block outbound SMTP.

Free-host features and limits vary. Confirm the provider's PHP version, MySQL
limits, HTTPS support, environment-variable support, storage permissions, and
outbound mail policy before uploading customer data.

## Optional Google sign-in and email notifications

Google sign-in has no per-login fee. Gmail SMTP may be free for low-volume
transactional mail, but sending limits and account eligibility are controlled
by Google; it is not a bulk-mail service. The app sends only to email addresses
Google has verified. Existing password customers can link their account by
signing in with Google using the same verified email. Google sign-in creates
customer accounts only; admin, staff, and rider accounts continue using their
existing password login.

1. Install Composer for Windows, open a terminal in this project folder, and
  run `composer install` to install the Google ID-token verifier and PHPMailer.
2. Copy `config/services.local.example.php` to `config/services.local.php`.
  This local file is blocked from direct web access by `config/.htaccess` and
  should never be committed or shared.
3. In Google Cloud Console, create an OAuth **Web application** client ID. Add
  `http://localhost` as an authorized JavaScript origin for local testing,
  then place the client ID in `services.local.php`. Production needs your
  real HTTPS domain and its own authorized origin.
4. For Gmail SMTP, enable 2-Step Verification on the sender Google account and
  create an App Password if that account is eligible. Set `smtp_username` and
  `mail_from` to that Gmail address, `smtp_password` to the App Password, and
  keep `smtp_host` as `smtp.gmail.com`, port `587`, encryption `tls`. Never
  use or paste your normal Google password into the project.
5. The app sends an order confirmation after checkout, order status updates,
  payment-failure notices, and a payment-confirmed receipt. If email is not
  configured or sending fails, the order still succeeds; details are logged
  in `logs/error.log`. Delivery is attempted synchronously after database
  updates, so a production/high-volume launch should add a retryable mail queue.

The database snapshot includes the schema required for Google sign-in and
email verification. Locally registered password accounts have unverified email
until they link a Google account with that same verified email, so receipts are
not sent to unverified addresses.

## Free local customer accounts

The site works without Google, SMS, Composer, or any external login provider.
The imported snapshot includes username login and account profile fields.
Customers can register with a username, email, and password, then sign in
using either username or email. They can open **Account** from the site
navigation, update their phone/address, and change a local password. The
address remains editable for each order and the account email is read-only.

## Moving the project to another computer

For a complete migration, copy the whole `brewed-delights` folder, including
`uploads/` and any photos in `assets/images/`, then copy both files in `sql/`.
The current-data file preserves accounts, menu changes, orders, payments, and
feedback. The clean file is a separate empty starting database with the
current catalog.

On the new computer:

1. Copy the project folder into the new XAMPP `htdocs` directory.
2. Start Apache and MySQL, then import `brewed_delights_current.sql` and/or
   `brewed_delights_clean.sql` in phpMyAdmin. These create distinct database
   names and can coexist.
3. The app connects to `brewed_delights_current` by default. To run the clean
  catalog instead, set the `DB_NAME` environment variable to
  `brewed_delights_clean`.
4. Log in with an existing account in the current database. For the clean
   database, create an admin with `setup_admin.php` once.

The SQL files are point-in-time exports. Regenerate the current-data export
before moving again if accounts, orders, payments, or menu data have changed.
The current-data file contains private customer information; share it only
through a trusted private channel. The clean file contains no customer/order
records and is suitable for sharing as a starter database.

## How the pieces map to the spec

| Requirement | Where |
|---|---|
| Menu CRUD (admin) | `admin/menu.php` |
| Cart / checkout engine | `cart.php`, `add_to_cart.php`, `checkout.php` |
| GCash payment (reference-number verification) | `checkout.php` collects the reference number; `admin/orders.php` shows it to staff/admin, who confirm it against the shop's own GCash app |
| Cash payment | `checkout.php` (Pickup = pay at counter, Delivery = pay the rider); staff/admin confirm pickup cash, riders confirm delivery cash when marking delivered |
| Payment records / transaction reference numbers | `payments` table (customer-supplied GCash reference) + `orders.reference_code` (system-generated, e.g. `BD-260904-00123`, shown on every order everywhere) |
| Real-time order status and new orders | `admin/orders.php` (staff/admin update) + `orders.php` / `order_status.php` (customer view polls every 8s via `fetch`, including newly placed orders) |
| Order status audit trail | `order_status_history` table, written by `log_order_status()` on every transition. Viewable in `admin/orders.php` via the "View status history" expander on each order |
| Loyalty points — earn | `includes/functions.php::award_loyalty_points()`, called from `admin/orders.php` / `rider/index.php` when status → Completed |
| Loyalty points — redeem | `checkout.php` (redeem points as a ₱-for-point discount), `includes/functions.php::redeem_loyalty_points()` |
| Feedback | Completed orders in `orders.php` accept product and overall reviews, plus rider reviews for assigned deliveries; `admin/feedback.php` shows all review types |
| Staff role (separate from admin) | `admin/staff.php` (admin creates accounts); staff can access `admin/orders.php` + `admin/feedback.php` only |
| Prepared statements everywhere | every query in every file uses `$pdo->prepare()` / bound parameters |
| Personal delivery option | `checkout.php` (Pickup/Delivery toggle + address + optional GPS pin) |
| Rider accounts | `admin/riders.php` (admin creates rider logins) |
| Assigning a rider to an order | `admin/orders.php` suggests the rider with the fewest active deliveries; staff/admin confirm or choose another rider |
| Rider delivery workflow | `rider/index.php` (Start delivery → Mark delivered) |
| Live rider location sharing | `rider/location.php` receives GPS pings from the rider's browser (`navigator.geolocation.watchPosition`) |
| Customer sees rider approaching | `orders.php` + `order_status.php`, rendered as a self-drawn SVG mini-map in `assets/js/script.js` (no Google Maps, no API key) |
| Homepage highlight sections | `index.php` — Featured (`menu.is_featured`, admin-set) and Best Sellers (computed live from `order_items`, no admin input needed) |

## Payment flow: GCash reference-number verification + Cash

No merchant account or payment gateway API is required. This is the same
approach most small PH businesses use without a DTI-registered merchant
account:

1. **Customer** chooses GCash or Cash at checkout.
   - **GCash**: shown the shop's GCash number/name, sends payment manually
     in their own GCash app, then types the reference number from their
     receipt into the form. Order is created with `payment_status =
     'Pending Verification'`.
   - **Cash**: no reference number needed. Order is created `'Unpaid'` —
     pay at the counter (Pickup) or hand it to the rider (Delivery).
2. **Staff or admin**, in `admin/orders.php`, sees the submitted reference
   number and checks it against the shop's own GCash app. One click marks
   it `'Paid'` (or `'Failed'` if it doesn't match).
3. If marked `'Failed'`, the customer sees this on `orders.php` and can
   resubmit a corrected reference number without placing a new order.
4. For Cash pickup orders, staff/admin confirm collection in `admin/orders.php`.
  For Cash delivery orders, the rider confirms collection when marking the
  delivery complete; the order and payment are marked Paid together.

Every attempt is logged in the `payments` table (method, reference number,
status, who verified it, when) — this is what the capstone's Chapter 3
"payment table" describes.

## Staff vs. Admin

Per the capstone's use case diagram, these are two separate roles:

- **Admin** — full access: dashboard, menu, orders, payment verification,
  riders, staff accounts, feedback. Created via `setup_admin.php` (once).
- **Staff** — day-to-day operations only: orders, payment verification,
  feedback. Can't touch the menu, riders, or other accounts. Created by an
  admin via `admin/staff.php`, logs in at the same `login.php`, lands on
  `admin/orders.php`.

## Loyalty points: earning and redeeming

- **Earn**: 1 point per ₱50 spent, credited automatically when an order is
  marked "Completed" (`award_loyalty_points()`).
- **Redeem**: 1 point = ₱1 off. At checkout, customers with a balance see a
  "Points to redeem" field, capped at both their balance and the order's
  pre-discount total. Deducted immediately and logged in `loyalty_history`
  the moment the order is placed.

## How delivery tracking works (no third-party map/courier APIs)

1. **Customer** picks "Personal delivery" at checkout, types an address, and
   optionally taps "Use my current location as the drop-off pin" — this uses
   the browser's built-in **Geolocation API** to capture a precise lat/lng,
   stored in `orders.delivery_lat` / `delivery_lng`.
2. **Admin** assigns a staff member (created via `admin/riders.php`) to the
   order in `admin/orders.php`, then marks it "Out for Delivery" (or the
   rider does this themselves — see next step).
3. **Rider** logs in at the same `login.php`, lands on `rider/index.php`,
   taps "Start delivery," then flips on **Share my live location**. Their
   browser calls `navigator.geolocation.watchPosition()` and POSTs
   lat/lng to `rider/location.php` every time it changes, which updates
   `orders.rider_lat` / `rider_lng` for any of their orders currently
   "Out for Delivery."
4. **Customer's** `orders.php` page polls `order_status.php` every 8
   seconds. Once it sees `rider_lat`/`rider_lng`, Leaflet displays the
   delivery address and rider on an OpenStreetMap street map with their
   straight-line distance. If the Leaflet library cannot load, the page falls
   back to a simple relative-position diagram.
5. Rider taps **Mark delivered** → status becomes "Completed," which also
   fires the existing loyalty-points trigger.

**Cost and deployment:** Leaflet and OpenStreetMap's standard map tiles do
not require an API key or charge per map view. The map uses OpenStreetMap's
public tile service, which has a fair-use policy and no uptime guarantee;
high-traffic deployments should use a tile provider or hosting arrangement
that meets their traffic needs. Production must use HTTPS: browsers only allow
geolocation on secure origins, and riders must keep the dashboard open and
grant location permission while sharing.

## Adding real photos (optional but recommended)

The menu cards and hero now support real photography — if no photo file
exists yet, they gracefully fall back to a soft gradient tile (menu cards)
or the illustrated cup (hero), so nothing breaks either way.

**Where files go:**
- Hero background: `assets/images/hero/hero-coffee.jpg`
- "Our Story" section background: `assets/images/story/our-story.jpg`
- Each menu item: `assets/images/menu/<filename>.jpg` (exact filenames below)

**Recommended sources** (both free for commercial use, no attribution
required): [unsplash.com](https://unsplash.com) and
[pexels.com](https://pexels.com). Search, download, rename to match, drop
into the folder — no code changes needed.

**Recommended crop:** roughly 4:3 for menu photos (e.g. 800×600px),
landscape/wide for the hero (e.g. 1600×900px). Anything close is fine —
the CSS crops to fit automatically.

| Search term | Save as |
|---|---|
| "coffee shop counter warm light" or "latte art close up" | `assets/images/hero/hero-coffee.jpg` |
| "roastery interior" or "barista pouring latte art" | `assets/images/story/our-story.jpg` |
| "espresso shot" | `assets/images/menu/espresso.jpg` |
| "americano coffee cup" | `assets/images/menu/americano.jpg` |
| "cafe latte" | `assets/images/menu/cafe-latte.jpg` |
| "cappuccino foam" | `assets/images/menu/cappuccino.jpg` |
| "iced latte" or "spanish latte" | `assets/images/menu/spanish-latte.jpg` |
| "matcha latte" | `assets/images/menu/matcha-latte.jpg` |
| "hot chocolate mug" | `assets/images/menu/hot-chocolate.jpg` |
| "croissant" | `assets/images/menu/croissant.jpg` |
| "blueberry muffin" | `assets/images/menu/blueberry-muffin.jpg` |
| "banana bread slice" | `assets/images/menu/banana-bread.jpg` |

Adding a photo for any item you create later works the same way: put the
file in `assets/images/menu/`, then paste its path (e.g.
`assets/images/menu/new-item.jpg`) into the **Image path** field when
adding/editing that item in `admin/menu.php`.

## Security hardening included

| Concern | What's done | Where |
|---|---|---|
| SQL injection | Every query uses PDO prepared statements | throughout |
| Password storage | bcrypt via `password_hash()` / `password_verify()` | `login.php`, `register.php`, `admin/riders.php`, `setup_admin.php` |
| CSRF | Every state-changing form includes a hidden token; every handler calls `verify_csrf()` before touching the database. AJAX calls (`add_to_cart.php`, `rider/location.php`) send the token via an `X-CSRF-Token` header instead. | `includes/functions.php` (`csrf_token()`, `csrf_field()`, `verify_csrf()`) |
| Brute-force login attempts | 5 failed attempts (same email+IP) locks that pair out for 15 minutes | `login_attempts` table, `includes/functions.php` |
| Open admin-creation door | `setup_admin.php` refuses to run once ANY admin account exists — safe even if you forget to delete it | `setup_admin.php` |
| Error information leakage | `APP_ENV` defaults to `production`, which hides PHP errors from visitors and logs them to `logs/error.log`; use `development` only on a local server | `config/app.php` |
| Session cookie theft | Cookies set `httponly` (JS can't read them) and `samesite=Lax` | `config/app.php` |
| Direct access to source/config files | `.htaccess` blocks browser access to `config/`, `includes/`, `sql/`, `logs/`, and any `.sql`/`.md`/`.log`/`.env` file anywhere in the project | `.htaccess`, `config/.htaccess`, `includes/.htaccess`, `sql/.htaccess`, `logs/.htaccess` |
| Malicious file execution via uploads | `uploads/.htaccess` refuses to execute `.php` files even if one somehow ended up there | `uploads/.htaccess` |
| Directory listing | Disabled at the project root | `.htaccess` |

**Before a real launch, still do these** (not automatable from here):
- Configure the shop's verified GCash number and account name before enabling GCash checkout.
- Get an SSL certificate and serve the whole site over HTTPS — required for
  `navigator.geolocation` (rider tracking) to work on a real domain, and for
  the `samesite`/session cookie protections to be meaningful.
- Set a real MySQL password in `config/db.php` and set up automated backups.
- Replace the simulated payment gateway in `checkout.php` / `api/orders.php`
  with a real provider (Stripe, PayMongo, etc.).
- Add a password-reset flow — there currently isn't one.

## API reference (`/api`)

**Status: future work, not used by the current system.** This capstone's
Chapter 1 Scope explicitly excludes a dedicated mobile app, and nothing in
the website itself calls this API — it's a working foundation, built ahead
of time, for the mobile app recommended as future development in Chapter 5.
If that recommendation changes, this folder can be removed without
affecting anything else; nothing else in the project depends on it.

A small token-authenticated JSON API, separate from the website's own
session/cookie login. Every endpoint returns JSON with an `"ok"` field.

### Authenticate

```
POST /api/auth.php
Content-Type: application/json

{ "email": "you@example.com", "password": "yourpassword", "label": "my phone" }
```
Response:
```json
{
  "ok": true,
  "token": "9f3a...64 hex chars...",
  "expires_in_days": 30,
  "user": { "user_id": 3, "name": "...", "email": "...", "role": "customer" }
}
```
Save the `token` — it won't be shown again (only its hash is stored, same
principle as password hashing). Use the same rate limiting as the website:
5 failed logins from the same email+IP locks out for 15 minutes.

Send it on every later request as:
```
Authorization: Bearer 9f3a...
```

### Revoke a token (API "logout")
```
POST /api/revoke.php
Authorization: Bearer <token>
```

### Get the menu (public, no token needed)
```
GET /api/menu.php
```

### List your orders
```
GET /api/orders.php
Authorization: Bearer <token>
```

### Get one order's live tracking detail
```
GET /api/orders.php?order_id=12
Authorization: Bearer <token>
```
Includes `rider_lat`/`rider_lng`/`delivery_lat`/`delivery_lng` and a computed
`rider_distance_km` when a rider is out for delivery and sharing location —
everything a mobile app would need to draw its own live tracking screen.

### Place an order
```
POST /api/orders.php
Authorization: Bearer <token>
Content-Type: application/json

{
  "order_type": "Delivery",
  "items": [ { "item_id": 1, "qty": 2 }, { "item_id": 8, "qty": 1 } ],
  "delivery_address": "123 Sample St., Majayjay",
  "delivery_lat": 14.1441,
  "delivery_lng": 121.4747,
  "payment_method": "GCash",
  "reference_number": "1234567890123",
  "points_to_redeem": 20
}
```
`payment_method` is `"GCash"` (requires `reference_number`, 6+ chars) or
`"Cash"`. `points_to_redeem` is optional — 1 point = ₱1 off, capped at the
account's balance and the order total. Omit `delivery_*` fields entirely
for `"order_type": "Pickup"`.

### Notes on the API
- Tokens expire after 30 days (`expires_at` in `api_tokens`) — clients should
  re-authenticate and store the new token.
- This API is a starting point, not a full mobile backend: it doesn't cover
  feedback submission, loyalty point history, or admin/staff/rider actions
  yet. Follow the same pattern (`api_authenticate()` + prepared statements)
  to add more endpoints as you need them.
- CORS isn't configured — a native mobile app doesn't need it, but if you
  ever call this API from a separate website's JavaScript, you'll need to
  add `Access-Control-Allow-Origin` headers to each endpoint.

## Notes / things to swap out for production

- **order_items table**: added beyond the original schema list so a single
  order can hold multiple menu items (a real ordering engine needs this —
  a bare `orders` table with one `item_id` can't represent a multi-item cart).
- **Rider location**: stored directly on the `orders` row (`rider_lat`/`rider_lng`)
  rather than a separate live-tracking table, since a rider typically has one
  active delivery at a time in this MVP. For multi-drop routes at scale, move
  this to its own `rider_locations` table keyed by `rider_id` with its own
  polling, independent of any single order.
- **Location accuracy**: `navigator.geolocation` needs the site served over
  HTTPS in production (or `localhost`, which is exempt) and the user's
  permission — browsers block it silently on plain HTTP.
- **Real-time updates**: implemented via polling (`fetch` every 8s), which
  is simple and works everywhere. For true push updates, swap
  `order_status.php` polling for WebSockets or Server-Sent Events.
- Change `DB_PASS` to a real password before deploying anywhere beyond
  local development, and put `config/db.php` outside version control.
- **GCash verification is manual**, not an API integration — a staff/admin
  visually checks the reference number against the shop's own GCash app.
  This needs no merchant account, but doesn't scale past a small operation;
  a real PayMongo/Xendit GCash integration would automate this step.
