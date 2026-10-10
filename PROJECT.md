# Peanut Booker project starting point

Connect performers and event organizers through WordPress profiles, bookings, bids, reviews, and payments.

## Direction

Documented intent: Connect performers and event organizers through WordPress profiles, bookings, bids, reviews, and payments.

Owner confirmation needed: ultimate scope, launch or completion acceptance criteria, and the next priority. This brief does not mark any feature or project complete.

## Source baseline

- Inspected: 2026-10-09
- Local branch: `feat/signed-update-gate`
- Local HEAD: `9188fb26a03ec52588154d0f699967f5b2a4acf5`
- Lifecycle registry: `maintenance`; classification: `distributed-plugin` (registry reviewed 2026-08-29; current lifecycle not independently confirmed).
- Working tree at inspection: contains local changes.

## What it is built with

- frontend/package.json: react ^18.3.1; tailwindcss ^4.0.0; typescript ^5.7.2; vite ^6.0.3; vitest ^4.0.16
- composer.json: php >=8.0
- peanut-booker.php: WordPress plugin Peanut Booker; WordPress 6.0; PHP 8.0

## Build and verification entry points

These are source-declared entry points, not commands executed by this audit. Install dependencies and configure a local environment according to the linked setup documentation before running them. Release/hosting actions remain subject to the project’s deployment rules.

- frontend/package.json: `npm run dev`
- frontend/package.json: `npm run build`
- frontend/package.json: `npm test`
- frontend/package.json: `npm run typecheck`
- composer.json: `composer test`

## Structure and relationships

- `public/` is present in this checkout.
- `tests/` is present in this checkout.
- Cross-project integration details belong in the linked architecture and API documents; folder similarity alone does not establish a dependency.

## Gaps to resolve

- Working tree has uncommitted changes; this baseline includes local declarations beyond the recorded HEAD.
- Owner acceptance criteria and current priority order are not yet recorded in this brief.

## Source documents

- [README.md](README.md)
- [docs/](docs/)

## Keeping this current

Update this starting point when product direction, architecture, dependencies, or build/release entry points change. Recheck source facts against the current checkout. Date stamps describe inspection, not live production verification.

<!-- dino-source-audit {"date":"2026-10-09","head":"9188fb26a03ec52588154d0f699967f5b2a4acf5","sources":{"README.md":"8b3ccd201ef92be96a24a7757bdae0d9c84c2dac74306b41dbd799c23923b569","composer.json":"64f29db07cf00e75d7c0726756537a942904ff6d886b85c26547deece4ca5b00","frontend/package.json":"7e8d7572a2706786f7a9491f199e5bb21ad5f14d2abc61ec5b5785643c26ce28","peanut-booker.php":"396ce7da26dcf3ed9d2d3fc34702b6cb6bb218a8225e3ccbe31706e0ed7eef1d"}} -->
