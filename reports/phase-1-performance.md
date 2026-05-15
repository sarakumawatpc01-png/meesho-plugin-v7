# Phase 1 — Performance Report

## Hot paths & bottlenecks
- **SEO scans:** synchronous loop with per‑post DB writes, multiple AI calls, and `sleep(1)` rate‑limit. Runs in cron or manual AJAX without background queueing.
- **Orders table render:** each row calls `wc_get_order()` and iterates items; for large order volumes this is heavy without pagination caching.
- **Analytics reports:** report generation queries across multiple tables and can send large HTML emails; no caching layer on report assembly.
- **Import description cleanup:** DOM parsing + regex cleanup runs per product; large HTML can be expensive.
- **Ranking/GA4 fetch:** external API calls are synchronous from admin UI.

## Known scaling risks
- Large datasets in `mm_orders`, `mm_products`, `mm_audit_log` will degrade list views and report generation.
- SEO scans can take long and block admin AJAX responses.

## Recommendations (Phase 10 candidates)
- Move SEO scanning and import enrichment into background jobs with progress + resumable queues.
- Add pagination + caching for orders and analytics widgets.
- Limit audit log retention and store summary counts (already partially done via `mm_purge_old_logs`).
- Cache heavy HTML report output with transient keys.
