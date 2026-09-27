# TradeJournal

A responsive personal trading journal built with Express, PostgreSQL and plain JavaScript. Existing accounts, trades, strategies and monthly capital remain in the same database.

## Run locally

Requires Node.js 20+ and PostgreSQL.

1. `npm install`
2. Copy `.env.example` to `.env` only for a fresh installation. Keep your existing `.env` for upgrades.
3. Set `DATABASE_URL` and a long random `SESSION_SECRET`. Existing `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PORT`, and `DB_NAME` variables are supported too.
4. `npm run migrate` applies the repeatable, additive schema upgrade.
5. `npm run dev`, then open http://localhost:3000. This restarts the server automatically when server files change; refresh the browser after frontend edits. Use `npm start` to run without watching for changes.

The landing page offers a clearly labelled, read-only sample dashboard. Sample trades never enter your database. Quick-add links redirect to authenticated trade entry.

## What changed

- New navy/blue design with an original SVG mark, glossy cards, native responsive charts, Light/Dark/System themes and phone bottom navigation.
- Net P&L, capital return, win rate, profit factor, drawdown, outcomes, annual performance, trade highlights, streaks and a clickable daily calendar.
- Searchable monthly trade ledger with edit/delete actions and escaped user content.
- Trade validation, long/short positions, fees, contract size, derivative expiry, stop loss, target, times, notes and live net P&L/risk previews.
- Profit challenges with a start date, calendar-day duration, and progress calculated from net trades in the period. Changes to trades update progress automatically. Overlapping challenges independently include eligible trades.
- Account preferences for display name, default lot size, risk reference, email opt-in; strategy and monthly capital management.
- Server-generated paginated PDF reports, with a clean summary and complete monthly ledger.
- PostgreSQL-backed sessions, session regeneration, HTTP-only same-site cookies, same-origin mutation checks, auth rate limiting and user ownership checks.
- Missing schema columns repaired by the migration. Old trades retain historical P&L and default to Long with zero recorded fees; edit them to correct direction or add actual fees.

All journal amounts are INR. Dates, challenge boundaries and weekly reports use Asia/Kolkata. Quantity multiplied by lot size is the number of underlying units. For equity use shares as quantity and lot size 1. Only closed trades are supported; enter the actual completed exit. Entry and exit times are informational and do not determine dates.

## Weekly email summaries

Set `RESEND_API_KEY` and `EMAIL_FROM` in the server environment. The sender must use a verified Resend domain. Restart the app, then enable **Settings → Weekly email summary** for the account. The destination is the account's registered email, never a browser-supplied recipient.

The running server checks on startup and hourly. After Monday 00:00 IST it sends the previous complete Monday–Sunday summary, including trade count, net P&L, win rate, fees and average result. One delivery is recorded per user/week. Empty weeks receive a zero-trade summary. A stopped server catches up the immediately previous week on restart; it does not send every historical week.

For deployments that sleep, run `npm run email:weekly` from your host's scheduler (hourly recommended). PostgreSQL advisory locking prevents overlapping worker runs; persisted payloads and Resend idempotency keys protect retries. The worker stops retrying ambiguous deliveries after 23 hours, before provider idempotency expires. Check server logs and reconcile such rows in `email_deliveries` with the provider before any manual retry. Turn the preference off to stop future sends. Settings shows configuration availability and the last successful send. It does not claim to verify inbox delivery.

No emails are sent without configuration and account opt-in. Tests stub the provider and do not send actual messages.

Provider documentation: https://resend.com/docs/api-reference/emails/send-email and https://resend.com/docs/dashboard/emails/idempotency-keys.

## Appearance and navigation

Use the top-right account menu for Settings, sign-out and Light / Dark / System appearance. The choice is saved on this device and System responds to OS changes. The market ticker has been removed. Strategy creation accepts an optional description, displayed beneath its name. Deleting trades, strategies and challenges uses an accessible confirmation dialog.

## Verification

- `npm test` runs isolated financial/date validation tests.
- `RUN_INTEGRATION=1 npm test` also runs the authenticated HTTP workflow against the configured database. It creates uniquely named `@example.invalid` test accounts and deletes only its own accounts/data afterward. Run against a development database.
- Integration covers auth, account isolation, strategies, capital, trade CRUD, challenges, settings, and PDF response headers.
- Desktop and 390px phone layouts were reviewed in a browser. Short-position preview, filtering, page navigation and dialogs were checked.
- The PDF was rendered and visually reviewed, including multi-page tables and page numbers.

## Deployment notes

Use HTTPS and `NODE_ENV=production`; set `TRUST_PROXY=1` only when there is one trusted reverse proxy. Set a stable high-entropy session secret. Run migrations before starting the app. Ensure your host keeps the server/weekly worker active. Back up PostgreSQL before production upgrades.

The original repository already tracked `.env`, `key.pem`, a database dump, and `node_modules`. The new `.gitignore` prevents newly added copies but does **not** remove files already tracked or erase history. Before sharing/deploying this repository, rotate any credentials/private key that were exposed, untrack sensitive files and dependencies, and coordinate history cleanup if needed. This update does not delete those originals or rewrite Git history.

The web app can be added to a phone's home screen using its manifest. It requires a network connection; private account data is not cached for offline use.

## Challenge reviews and account email

Each challenge offers **Daily P&L & trades** with daily net results, running totals, and expandable trade lists. All closed trades within the inclusive challenge dates count automatically, even if other challenges overlap. No-trade days show zero. The monthly dashboard filter does not limit challenge details.

Use **Edit once** to change a challenge's name, target, or dates. A warning and explicit acknowledgement appear before saving. An atomic database update allows only one successful edit, including concurrent requests. Once saved, the card shows **Edit locked**. Trade corrections can still change the computed daily results.

In Settings, use **Email address & delivery test** to request a verified email change. Enter the current password and new address, then enter the six-digit code delivered to the new address. Codes expire after ten minutes, allow five wrong attempts, and can only be used once. The old address remains active until verification succeeds. **Send test email** always sends to the saved registered address and does not enable weekly summaries. Provider configuration is required; requests are rate limited. The test confirms provider acceptance, not inbox delivery.

The footer remains fixed above mobile navigation. Text is at least 12px, with locally hosted Bootstrap Icons. Navigation uses URL fragments so refresh and browser Back/Forward preserve the current page.

## September 2026 trade-entry update

- `/quick-add.html` verifies a registered email using a single-use six-digit code (10-minute expiry, five verification attempts). A verified session can add trades for 20 minutes, but cannot read the journal, edit/delete trades or change the account. Request limits apply per IP and email. Requires `RESEND_API_KEY` and `EMAIL_FROM`; provider failures are recorded in server logs. Verification replaces the current browser session with a restricted quick-entry session.
- Broker estimates include brokerage, STT/CTT, stamp duty, exchange charges, SEBI, applicable IPFT, allocated DP charges and GST. Reviewed schedules and limitations are linked from the form at `/charge-rates.html`. Rates are a versioned snapshot, not a live feed or contract-note import. Updating schedules requires updating `public/charges.js` and its tests. Saved itemised charges are retained in `charge_details`.
- Supported automatic estimates: NSE ordinary equity/F&O and MCX non-agricultural futures/options from September 2026. Zerodha, Groww (equity/F&O), Angel Plus, Dhan, Sahi regular, ICICI iValue (except delivery), Kotak Trade Free regular and Other are included. Promotional/custom plans support a brokerage-total override. Other defaults to ₹20 per executed order. Older trades and unsupported products use actual charges.
- Delivery DP fees must be allocated explicitly before GST; enter zero when charged against another entry. Standard brokerage assumes one executed order per side; enter total brokerage for multiple orders. Estimates round individual charges to paise; contract notes may aggregate/round differently. No automated financing, settlement, subscription or penalty allocation.
- Net P&L remains the stored `pnl`. Gross P&L is `pnl + fees`, including legacy trades. Dashboard, ledger, challenges, PDFs and weekly emails show the distinction. Goals and calendar outcomes use net P&L.
- Deletion is allowed only in the six hours after the server records a new trade. The API enforces this atomically. Editing never resets this timestamp. Legacy entries with no reliable `recorded_at` stay locked rather than receiving a new deletion window; editing remains available.

### Production update

Back up the database first. Deploy the updated files, install dependencies, then apply `schema.sql` **as the database table owner**, before restarting the app. For the existing Ubuntu installation with postgres-owned tables:

```sh
cd /home/ubuntu/tradingjournal
sudo -u postgres pg_dump -d trading_journal -Fc > ~/trading-journal-before-charges-$(date +%Y%m%d-%H%M%S).dump
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d trading_journal -f schema.sql
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d trading_journal -c 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO krishnakanth; GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO krishnakanth;'
pm2 restart trading-journal --update-env
pm2 logs trading-journal --lines 0
```

The migration adds columns and the quick-entry verification table; it does not recompute existing trades. Deploying code without this migration will cause missing-column/table errors. Locally, `npm run migrate` is sufficient when the configured role owns the tables.
