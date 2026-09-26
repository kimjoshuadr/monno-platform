# monno rebrand — working notes

## What this is

The Hi.Events codebase rebranded to **monno**: colours, logos, favicons, app name
and email branding, applied directly to the source (no separate HTML mockups).

- **Base**: Hi.Events `2.0.0-alpha.1` (git branch `develop`, HEAD `7dec84ca`).
- **Licence**: GNU AGPL v3. The **"Powered by Hi.Events"** attribution is retained
  at the footer of every web page and every email, and its link still points to
  <https://hi.events>. See "Attribution" below.
- **Scope of this change**: brand surface only — no product logic, API or data model
  changes. All edits are token/default/asset swaps.

## Brand source

Logo supplied by the customer: a rounded-square mark with a spectrum ring around a
light plate holding two near-black pills; brand name **monno** (lowercase). Colours
were sampled from the supplied raster, not guessed. Full token table:
`../…/brand-spec.md` (also summarised below).

## Palette

| Token | Hex | Use |
|---|---|---|
| ink | `#0B0B0C` | primary fill, header, text, the two pills |
| canvas | `#F5F6F8` | app background |
| surface | `#FFFFFF` | cards / sheets |
| muted | `#6E7178` | secondary text |
| border | `#E4E6EA` | hairlines |
| accent | `#1478C9` | focus / interactive — spectrum cyan-blue |
| spectrum | `#0FCFF6 · #2BE09D · #B2D64A · #F6D52C · #FEB52A · #FD7E2B · #FD5A3C` | logo ring, single flourish |

**Design principle** — ink carries the interface; the spectrum is the one colour
moment per screen (logo ring, focus ring). No gradient page washes.

## Files changed

### Theme tokens
- `frontend/src/utilites/themeColors.ts` — monno ink ramp as the Mantine `primary`
  palette (shade 8 = `#1B1C1F`), spectrum cyan as `secondary`; env override preserved.
- `frontend/src/utilites/themeUtils.ts` — default event accent `#0B0B0C`, background
  `#F5F6F8`; derived light/dark text + border colours; soft/muted accent fallbacks.
- `frontend/src/styles/global.scss` — `--monno-*` tokens, pill buttons, `:focus-visible`
  ring, canvas/grey values.
- `frontend/src/App.tsx` — `defaultRadius: md`, pill `Button`/`Badge`, name fallback.
- `frontend/index.html` — `theme-color`, title, (favicons now monno).
- `frontend/public/site.webmanifest` — name / theme / background.

### Hardcoded accent defaults
- `components/layouts/Checkout/CheckoutThemeProvider.tsx` (fallback ramp + palettes)
- `components/layouts/Checkout/index.tsx`
- `components/modals/JoinWaitlistModal/index.tsx`
- `components/common/ThemeColorControls/index.tsx`
- `components/layouts/OrganizerHomepage/EventCard/index.tsx`
- `components/routes/event/HomepageDesigner/index.tsx`
- `components/routes/organizer/OrganizerHomepageDesigner/index.tsx`
- `components/common/CookieConsentBanner/CookieConsentBanner.module.scss`

### Logos / favicons
- Added `frontend/public/logos/monno-*.svg` (+ `monno-stacked-light.png` for email)
  and `frontend/public/manifest-icons/favicon.svg` + regenerated PNG/ICO/apple icons.
- Swapped every `VITE_APP_LOGO_*` / favicon reference in
  `Header`, `AppLayout/Sidebar`, `AppLayout/Topbar`, `AuthLayout`, `welcome`,
  `GenericErrorPage`, `ErrorDisplay`, `CheckInInfoModal`.
- Removed the now-unused `hi-events-*` logo assets and `favicon-text-dot.svg`.

### Backend / config / env
- `backend/config/app.php` (`APP_NAME` default `monno`), `config/mail.php`
  (`MAIL_FROM_NAME` default `monno`, address default a neutral `hello@example.com`),
  `config/scramble.php`, `config/webhook-server.php`.
- `backend/resources/views/vendor/mail/html/message.blade.php` — email logo → monno;
  attribution line unchanged.
- `backend/resources/views/welcome.blade.php`, `emails/orders/order-failed.blade.php`,
  `app/Http/Actions/Reports/ExportOrganizerReportAction.php`.
- `frontend/.env.example`, `backend/.env.example`, `docker/development/.env`,
  `docker/all-in-one/.env.example`, `docker/e2e/.env`,
  `docker/e2e/docker-compose.e2e.yml`.

## Attribution (AGPL §7b) — retained, now co-branded with monno

- Web: `frontend/src/components/common/PoweredByFooter/index.tsx` renders the monno mark
  (`VITE_APP_FAVICON`, default `/favicon.svg`) beside **"monno · Powered by Hi.Events"** —
  the exact "Powered by Hi.Events" notice is kept and still links to <https://hi.events>.
  The monno prefix + mark is the rephrasing §7(b) permits. This footer appears on the
  event page, organizer homepage, checkout, auth, error pages, check-in modal and ticket.
  It is flex-wrapped and centred (`FloatingPoweredBy.module.scss`) so it stays on one line
  down to 320px and wraps gracefully for longer translations.
- Email: `backend/resources/views/vendor/mail/html/message.blade.php` renders
  **"© {year} {app.name} | Powered by Hi.Events"** (app.name = monno), link to
  <https://hi.events>.
- No `VITE_I_HAVE_PURCHASED_A_LICENCE` / `iHavePurchasedALicence()` flag was set, so
  the notice stays visible. Do **not** remove it without a commercial licence.

## Email branding

All 30 transactional emails render through `vendor/mail/html/message.blade.php`, so the
monno mark + co-branded footer appear on every one.

- **Logo**: a transparent monno lockup (mark + wordmark) at
  `frontend/public/logos/monno-email-logo.png`, referenced by the mail layout and sized by
  the theme's `.logo` rule (`max-width: 240px; max-height: 56px; width/height: auto`, so a
  square mark or a wide lockup both keep their aspect).
- **Theme**: `vendor/mail/html/themes/default.css` was still purple-branded — every purple
  neutral is now monno (canvas `#F5F6F8`, ink headings `#0B0B0C`/`#1B1C1F`, body `#3F4247`,
  muted `#6E7178`, hairlines `#E4E6EA`, footer `#8A8E96`, ink shadows, ink primary button).
  Green/red stay for success/error states.
- **In-template colours**: purple hexes in `emails/event/message.blade.php` and the info
  panel in `emails/orders/summary.blade.php` (`#EDF6FD`/`#0A4F86`) were updated too;
  `#e5e7eb`/`#eeeeee` neutrals are now `#E4E6EA`.
- **Watermark**: the footer reads `© {year} monno | Powered by Hi.Events` (link to
  <https://hi.events>), covering every email.
- **Preview**: `monno-email-preview.html` / `.png` in the Design Files render the layout.
  After changing mail views run `php artisan view:clear`.

## How to run

```bash
# frontend
cd frontend && yarn install
yarn dev:csr        # http://localhost:5678
# or SSR: yarn dev:ssr

# set branding at build time (optional; defaults already point at monno)
#   VITE_APP_NAME=monno
#   VITE_APP_PRIMARY_COLOR=#0B0B0C
#   VITE_APP_SECONDARY_COLOR=#0FCFF6
```

Backend: see `INSTALL_WITHOUT_DOCKER.md` / `docker/development`.

### Local native dev (no Docker)

Docker is not required — the backend runs on the local PHP + Postgres:

```bash
# 1. backend deps + database
cd backend
composer install
cp .env.example .env            # then set DB_* to your local Postgres
php artisan migrate --force

# 2. backend API  ->  http://localhost:8080
php artisan serve --host 0.0.0.0 --port 8080

# 3. frontend -> http://localhost:5678
cd ../frontend
# .env (gitignored) must point the SPA at the backend, no /api suffix:
#   VITE_API_URL_CLIENT=http://localhost:8080
#   VITE_API_URL_SERVER=http://localhost:8080
#   VITE_FRONTEND_URL=http://localhost:5678
yarn dev:csr
```

Backend `.env` also needs `CORS_ALLOWED_ORIGINS=http://localhost:5678` and
`APP_FRONTEND_URL=http://localhost:5678`. Note the API is served at the **root**
(`/auth/register`), not under `/api` — the `/api` prefix only exists behind the
nginx reverse proxy in the Docker setup.

## Known gaps / follow-ups

- **Build**: `yarn build-strict:csr` (tsc + vite) now passes. The 46 pre-existing
  TypeScript errors it used to report have been fixed — see "TypeScript fixes" below.
- **Lint**: `yarn lint` still fails on pre-existing repo-wide debt (737 errors, mostly
  `lingui/no-unlocalized-strings` and `no-explicit-any`); the rebrand added none. Run with
  `NODE_ENV=development` so yarn installs devDependencies (eslint/vite/tsc).
- Note: the shell default `NODE_ENV=production` makes a plain `yarn install` skip
  devDependencies — use `NODE_ENV=development yarn install`.
- `MAIL_FROM_ADDRESS` default is a neutral placeholder (`hello@example.com`) — set a real
  monno address via env.
- The monno wordmark is a monoline mark derived from the pill geometry; if the customer
  has an official wordmark file, drop it into `public/logos/*` and point the env vars at it.
- Upstream `README*.md` docs still describe Hi.Events (repo documentation, not product UI).

## TypeScript fixes (build-strict:csr green)

The 46 pre-existing `tsc` errors were fixed without changing product behaviour:

- Removed unused imports/params (`ActionMenu`, `OrganizerForm`, `EditAffiliateModal`,
  `CreateEventModal`, `EmailTemplateSettings`, `useEditQuestionAnswer`,
  `queryParamsHelper`).
- Added the missing `Window.chatwootSDK` / `window.$chatwoot` typings (`globals.d.ts`).
- `DatePickerInput` range handlers now use the Mantine `[string | null, string | null]`
  signature and parse with dayjs (`ReportTable`, `OrganizerReportTable`).
- Added `downloadFileName` to `OrganizerReportTable` and used it for the CSV export.
- Replaced `null` with `undefined` where `IdParam` is required (`products.tsx`,
  `TicketDesigner`), and made optional collections null-safe (`ProductCategory.products`,
  `social_media_handles`).
- Typed the sort payload correctly (`order`, not `sort_order`) to match
  `SortProductsRequest`; the backend reads position, so behaviour is unchanged.
- Corrected the attendee tax request shape to `{tax_or_fee_id, amount, name}`
  (`AttendeeTaxAndFeeRequest`) to match `CreateAttendeeRequest`.
- Fixed mock/preview data in `TicketDesigner` to satisfy `Product`/`Event`/`Attendee`.
- Removed the dead `src/stores/app.store.ts` (unused; imported a `zustand` dependency
  that is not in `package.json`).

## Event page: the rail is the ticket picker (2026-09-26)

The event page (`/event/{id}/{slug}`) and the designer preview (`/event/{id}/preview`)
render the same `EventRoom`. That page is now the website's page end to end, and the
place it sells from is the rail.

- **One picker, in the rail.** The full-width `#tickets` section is gone. `SelectProducts`
  renders inside `aside.register-rail` inside `.ticket-card`, under the price row and the
  capacity bar, followed by save / share / calendar and the who's-coming panel. The rail's
  old "Get Tickets" scroll CTA — and `get_tickets_button_text` — are gone.
- **Styled, not forked.** The widget is untouched; `event-page-glue.scss` styles it for the
  rail with the same class names monno's site uses (`.hi-product-row`, `.hi-price-tier-row`,
  `.hi-promo-code-*`), and every selector carries the widget container so it wins on
  specificity against the component-imported widget stylesheet.
- **Promo moved above the checkout button** in `SelectProducts` (a hoisted `promoSection()`
  renders inside the product section), so the code field reads in the order a buyer expects.
- **`NumberSelector` always draws its stepper** (a disabled "−" and a "0" at rest) instead of
  appearing on the first click. It is used only by the product widget.
- **monno's chrome**, header and footer, with content links pointed at
  `VITE_MONNO_SITE_URL`; the legal row keeps privacy/terms/cookie settings and the
  `PoweredByFooter` attribution the licence requires.
- **Missing pieces added**: share sheet (`EventRoomShare`), `.ics` download, who's-coming
  (count + anonymized stack, gated by the ATTENDEES block), `MIRROR_COVER_IMAGE` background,
  and the per-category accent (the 218°→33° oklch ramp, from `constants/eventCategories.ts`).
- **The hosted-by panel draws the organizer's logo** (`ORGANIZER_LOGO`), falling back to a
  two-letter monogram — previously only the cover-column host card looked for it, so the
  panel always showed a single letter. Its button is a real **Follow** toggle for a signed-in
  visitor (`me/follows`, via the existing follow queries/mutations); a signed-out visitor gets
  a link to the host's room, since following is account-scoped.
- **Dates use the event's timezone**, not the visitor's (`dayjs.utc(...).tz(event.timezone)`).
- **Builder**: TICKETS is no longer offered as a section (the registry entry stays so stored
  blocks resolve); the Button Text panel keeps the continue label only.
- **API**: `ProductPriceResourcePublic` now exposes `quantity_sold` — a count of tickets sold,
  never of buyers — which is what the page's "N going" and capacity bar are derived from.
- **QA**: `tests/qaqc/{rail-purchase,website-parity,website-purchase}.spec.ts` are new;
  `event-room` and `builder-surfaces` were updated. The parity seed lives in
  `tests/setup/seed-parity.spec.ts` (run on demand; prints the event id for monno's
  `NEXT_PUBLIC_PLATFORM_EVENT_NIGHT_SESSIONS`).
