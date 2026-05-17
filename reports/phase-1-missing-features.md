# Phase 1 — Missing Features Report (By Phase)

## Phase 2: Product import fixes
- Import queue/progress/retry/logging are not implemented.
- Category/tag mapping and image optimization/validation are missing.
- Description cleanup is present but needs final allowlist/formatter review.

## Phase 3: Blog system rebuild
- Missing slug/status/tags/featured image controls.
- No live streaming generation or progress state.
- No publish scheduling, revisions, or schema controls.

## Phase 4: Orders
- No backfill/repair flow for existing WooCommerce orders into `mm_orders`.
- No SKU → URL mapping or quick actions.
- Missing exports/bulk actions and failure logs.

## Phase 5: SEO AI agent
- No autonomous flow with approval/rollback history in UI.
- Internal linking engine modes not exposed.
- Monitoring/rollback metrics not surfaced.

## Phase 6: Analytics
- WooCommerce/RankMath integration surfaces are incomplete.
- Dashboards do not auto‑refresh.

## Phase 7: Copilot workflow
- Approval gates are partially enforced, but no explicit action queue status.
- Auto‑mode logging/rollback UI needs expansion.

## Phase 8: Logging
- Logs are not centralized across all modules and lack severity/export/retention controls.

## Phase 9: Settings
- Missing toggles for streaming, internal linking, retries, log retention, analytics, cron, cache controls.

## Phase 10: Performance & Security
- No background job system for heavy workloads.
- Full audit of escaping/sanitization is still pending.

## Phase 11: Docs & testing
- User/dev/setup/troubleshooting documentation not present.
- No documented workflow test checklist.
