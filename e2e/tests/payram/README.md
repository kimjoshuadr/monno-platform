# PayRam QAQC (Playwright)

End-to-end checks for the PayRam crypto onboarding, run against a **deployed**
Monno (staging) plus the PayRam gateway. Unlike the rest of the suite these do
not bootstrap an account through Mailpit — email verification is on there — so
credentials come from the environment and the console session is minted through
Monno's own SSO endpoint.

## Running

```bash
cd e2e
E2E_BASE_URL=https://staging.app.monno.io \
PAYRAM_BASE_URL=https://pay.monno.io \
E2E_PAYRAM_ORG_ID=29  E2E_PAYRAM_ORG_EMAIL=…  E2E_PAYRAM_ORG_PASSWORD=… \
E2E_PAYRAM_ORG2_ID=20 E2E_PAYRAM_ORG2_EMAIL=… E2E_PAYRAM_ORG2_PASSWORD=… \
E2E_PAYRAM_OPERATOR_EMAIL=… E2E_PAYRAM_OPERATOR_PASSWORD=… \
npx playwright test tests/payram --project=chromium
```

Tests `skip` when their credentials are absent, so the suite can run read-only
with just the primary organizer.

| Env var | Meaning |
|---|---|
| `E2E_PAYRAM_ORG_*` | An organizer with a **complete** PayRam setup (deposit + hot wallet). |
| `E2E_PAYRAM_ORG2_*` | An organizer with an **incomplete** setup (account, no wallets). |
| `E2E_PAYRAM_OPERATOR_*` | PayRam operator login, to verify the sync landed on the project. |
| `E2E_PAYRAM_EVENT_ID` / `E2E_PAYRAM_COMPLETED_ORDER` | Event + a completed crypto order for the return-page check. |

## What it covers

- **Monno card** — the account endpoint reports ready with settlement history, and
  the card renders `ACTIVE`, `Accepting payments on …`, and `Last settled …`.
- **Console overlay** — a configured organizer sees no banner and the operator
  surface is hidden; the buyer `/payments` page never shows the organizer banner;
  an unreadable/expired session degrades safely (no nag).
- **Incomplete setup** — the banner prompts (with a deposit-wallet link) and the
  organizer can add their own hot wallet (model A).
- **Resilience** — the account endpoint stays 200 through gateway/cache trouble.
- **Sync effect** — the organizer's qualified name and support email are reflected
  on their PayRam project.
- **Buyer return** — a completed crypto order returns without ever asking Stripe
  (no false "unable to confirm").
- **Crypto payment state** — the public order exposes the gateway state (expected
  vs received, `underpaid`/`overpaid`), an overpaid order shows the surplus note
  on the summary, and the return page states a shortfall plainly — waiting on a
  live order but not promising a ticket once it has expired. The backend
  `payment` block and the 30-minute wait are covered by backend tests.
- **Fee disclosure** — the organizer card states who pays which fee (Monno's
  2.5% on the organizer, PayRam's 1–5% on the buyer) and breaks the last
  settlement into its on-chain legs (collected → PayRam fee → Monno fee → net).
  The buyer's checkout names the PayRam settlement fee and its rate.

## Not here (covered elsewhere)

- Model A provisioning / hot-wallet assignment and the profile+logo sync logic:
  backend feature/unit tests (`backend/tests/**/PayRam*`).
- `monno:payram-health`, `monno:payram-shortfalls`: artisan commands, run directly.
- Logo upload: backend test (the multipart shape is asserted there).

## Notes

- `npx tsc --noEmit` has **pre-existing** errors in `tests/qaqc/**` (unrelated to
  PayRam). The PayRam specs typecheck clean.
- Writes are limited to reads plus the SSO mint; nothing mutates gateway state.
