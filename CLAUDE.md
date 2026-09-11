# TrainWise PHP App — Status & Handoff

This is the PHP/MySQL side of TrainWise (LSPU capstone project). It's a
**separate codebase from the ML service** — see the sibling folder
`trainwise-ml` (a FastAPI + XGBoost + SBERT microservice). This app
calls that service over HTTP; it does not implement ML itself.

**Read `../trainwise-ml/CLAUDE.md` too if it's in your workspace** — it
has the ML side's design rationale (synthetic bootstrap labels for
XGBoost, model choice for SBERT, etc.).

## Current blocker (read this first)

Training recommendations still aren't appearing on either
`training_recommendations.php` or the dashboard's "Recommended For
You" card, even after fixing a foreign-key type mismatch that was
previously causing `training_recommendations` table creation to fail
silently (errno 150 — `user_id` was plain `INT`, `users.id` is
`int(10) UNSIGNED`, now fixed to match in both files that define this
table: `training_recommendations.php` and `user_page.php`).

**Debug checklist, in order — don't skip ahead:**

1. **Confirm the table now exists with the right schema.** In
   phpMyAdmin or via CLI: `SHOW TABLES LIKE 'training_recommendations';`
   then `DESCRIBE training_recommendations;`. Confirm `user_id` shows
   as `int(10) unsigned`. If the table still doesn't exist, the fix
   didn't get applied, or table creation is still failing for a
   different reason — check `php-errors.log` for a *current* error,
   not the old cached one.

2. **Confirm the ML API is actually reachable from PHP's execution
   context**, not just from your browser. `curl` and browser requests
   don't always share the same network path/config. From a terminal:
   `curl http://127.0.0.1:8000/health` — if that fails from a plain
   terminal too, uvicorn isn't running (check the terminal it's in for
   a crash). If it succeeds from a terminal but PHP still can't reach
   it, check XAMPP's `php.ini` for `allow_url_fopen` / any disabled
   `curl_*` functions, and check Windows Firewall isn't blocking
   Apache's outbound connections specifically.

3. **Confirm the gating conditions are actually true for your test
   account.** Both `training_recommendations.php` and `user_page.php`
   only call the ML refresh if `$hasProfile` is true (all profile
   fields filled) — and the dashboard card additionally requires
   `$hasSubmitted` (an assessment submitted for the *current active*
   deadline specifically, not just any assessment ever). Add a
   temporary `error_log(json_encode(['hasProfile' => $hasProfile,
   'hasSubmitted' => $hasSubmitted]));` right before the ML refresh
   call if it's unclear which condition is failing, check the log,
   then remove it.

4. **Add temporary logging inside `refreshMLRecommendations()`** (in
   `ml_recommendations.php`) right before and after the `curl_exec()`
   call — log the payload being sent, the raw response, and the HTTP
   code — to see exactly what's happening in that specific call. Every
   failure path in that function already calls `error_log()`, so check
   `php-errors.log` for lines starting with "ML API call failed" or
   "ML API returned HTTP" or "refreshMLRecommendations:" first — one of
   those should already be firing if this is the failure point.

5. **Check `needsMLRefresh()` isn't the blocker** — it returns `false`
   (skip refresh) if there's no assessment submission at all AND no
   prior ML recommendation exists yet, which is a dead-end case: never
   refreshes because there's nothing to compare dates against, but
   also never had a first refresh. Look at the exact logic in
   `ml_recommendations.php` if recommendations never appear even for a
   fresh account with zero prior attempts.

## Bugs found and fixed so far (for institutional memory / your paper's documentation)

1. **SQL dump corruption** — original DB export was missing columns,
   indexes, and AUTO_INCREMENT values for the `assessments` and
   `evaluation_ratings` tables. Fixed by patching the dump directly.
2. **Filename mismatch** — `training_recommendations.php`'s JS called
   `update_training_status.php`, which didn't exist; the real file was
   `update_training_recommendation.php`. Every Start/Decline/Complete
   button was silently 404ing. Fixed.
3. **Foreign key type mismatch** — see "Current blocker" above.
4. **Pre-existing architecture issue, not a bug but worth documenting**:
   `user_page.php` originally had its own separate "AI recommendations"
   system that called the Anthropic Claude API directly with a
   free-text prompt — not XGBoost/SBERT, and not what the capstone
   paper claims. Replaced with a call into the real ML pipeline so the
   dashboard and the standalone page both reflect the actual system.

## Architecture recap

```
Browser -> training_recommendations.php OR user_page.php (dashboard)
             |
             v
       ml_recommendations.php (this repo)
             | HTTP POST /recommend
             v
       trainwise-ml (separate FastAPI service, port 8000)
             |
             v
       Same MySQL database (users, assessments, training_programs read;
       training_recommendations read/write)
```

Key files in this repo:
- `ml_recommendations.php` — all the ML integration logic (the refresh
  call, column self-healing, staleness check)
- `training_recommendations.php` — standalone recommendations page,
  no `$hasSubmitted` gate (works once profile is complete)
- `user_page.php` — main dashboard, "Recommended For You" card is
  gated on `$hasSubmitted` (assessment submitted for current deadline)
- `config.php` — has `ML_API_BASE_URL`, only defined for localhost

## Running everything together

You need three things running simultaneously to test:
1. XAMPP: Apache + MySQL
2. `trainwise-ml`: `venv\Scripts\activate` then
   `uvicorn app.main:app --reload --port 8000`
3. Browser, logged into the PHP app

## What comes after this works

Per the project owner: once recommendations are confirmed working
end-to-end and dependable, next steps are Phase 6 (evaluate/tune the
ML models properly) and then ISO 25010 / TAM testing for the capstone
defense — don't start those until this integration is solid.
