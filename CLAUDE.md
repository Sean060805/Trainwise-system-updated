# TrainWise PHP App — Status & Handoff

This is the PHP/MySQL side of TrainWise (LSPU capstone project). It's a
**separate codebase from the ML service** — see the sibling folder
`trainwise-ml` (a FastAPI + XGBoost + SBERT microservice). This app
calls that service over HTTP; it does not implement ML itself.

**Read `../trainwise-ml/CLAUDE.md` too if it's in your workspace** — it
has the ML side's design rationale (synthetic bootstrap labels for
XGBoost, model choice for SBERT, etc.).

## Current status (re-verified 2026-09-21)

**The ML integration works end to end.** The old "recommendations aren't
appearing" blocker (FK type mismatch, see bug 3 below) is fixed. Checked
directly on 2026-09-21: `training_recommendations.user_id` is
`int(10) unsigned` (matches `users.id`), PHP's curl reaches the ML API,
`/recommend` returns HTTP 200 in well under a second, and the live log
shows successful refreshes for real users on Sep 17-18.

**The one recurring failure mode is uvicorn not running.** Every "no
recommendations" symptom seen since the fix was the ML API being down
(`ML API call failed for user N: Failed to connect to 127.0.0.1 port
8000`). Check that first, always.

### If a user reports "no recommendations", check in this order

1. **Is the ML API up?** `curl http://127.0.0.1:8000/health` should return
   `{"status":"ok","programs_indexed":N}`. If it's refused, uvicorn isn't
   running — see "Running everything together" below.

2. **Read the live PHP log at `C:\xampp\htdocs\php-errors.log`** — NOT the
   `php-errors.log` inside this project folder. `config.php` builds the
   log path from `$_SERVER['DOCUMENT_ROOT']`, which under XAMPP is
   `C:\xampp\htdocs`, one level above this project. The copy inside the
   project is an old leftover (last written Aug 19) and will mislead you
   into thinking nothing is being logged. Look for lines starting
   "ML API call failed", "ML API returned HTTP", or
   "refreshMLRecommendations:".

3. **Check the gating — a refresh only fires when ALL of these hold**
   (same in `training_recommendations.php` and `user_page.php`):
   - `$hasProfile` — every profile field filled in.
   - `$hasSubmitted` — the user has an assessment for the *current active
     deadline specifically* (`assessments.deadline_id = <current
     deadline>`), not just any assessment ever. A user whose only
     submission belongs to an older deadline gets no refresh once a new
     deadline opens, until they submit again.
   - `needsMLRefresh()` — has a submission, and either no prior
     recommendation exists yet or the latest submission is newer than the
     latest recommendation batch. It intentionally returns `false` for a
     user with no submission at all (adviser feedback: recommendations are
     assessment-driven only; a completed profile alone must never
     generate one). This is not the old "dead-end" bug — that's gone.

4. **A user can legitimately see fewer cards than the ML returned.**
   `refreshMLRecommendations()` only replaces rows whose status is
   `Recommended`, and skips any title the user already has as Accepted /
   Training Available / Confirmed / Not Selected / Completed. `Declined`
   titles can come back in a later cycle, on purpose.

5. **Table schema**, if the table is suspected missing:
   `SHOW TABLES LIKE 'training_recommendations'; DESCRIBE
   training_recommendations;` — `user_id` must be `int(10) unsigned`.

To see one specific call's payload/response, add temporary `error_log()`
lines inside `refreshMLRecommendations()` and **remove them afterwards** —
they were left in permanently once before and were writing every user's
profile and assessment text into the log. All failure paths in that
function already log by default.

## Bugs found and fixed so far (for institutional memory / your paper's documentation)

1. **SQL dump corruption** — original DB export was missing columns,
   indexes, and AUTO_INCREMENT values for the `assessments` and
   `evaluation_ratings` tables. Fixed by patching the dump directly.
2. **Filename mismatch** — `training_recommendations.php`'s JS called
   `update_training_status.php`, which didn't exist; the real file was
   `update_training_recommendation.php`. Every Start/Decline/Complete
   button was silently 404ing. Fixed.
3. **Foreign key type mismatch** — `training_recommendations.user_id` was
   plain `INT` while `users.id` is `int(10) UNSIGNED`, so the `CREATE
   TABLE` failed silently with errno 150 and no recommendations could
   ever be stored. Fixed to `INT UNSIGNED`; the table is now defined in
   `ensureTrainingRecommendationsTable()` in `ml_recommendations.php`.
   Any new table with an FK to `users.id` must match that type exactly.
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
  call, the self-healing `ensure*` schema functions, staleness check,
  and the training_demand pipeline helpers)
- `training_recommendations.php` — standalone recommendations page
- `user_page.php` — main dashboard, "Recommended For You" card
- `config.php` — has `ML_API_BASE_URL`, only defined for localhost; real
  DB credentials live in `db_credentials.php` (gitignored — see
  `db_credentials.example.php`)

## Running everything together

You need three things running simultaneously to test:
1. XAMPP: Apache + MySQL
2. `trainwise-ml`: from that folder,
   `venv\Scripts\python.exe -m uvicorn app.main:app --reload --port 8000`
   (or activate `venv` first). Use `venv`, **not** `.venv` — `.venv` is a
   near-empty stray environment with none of the ML dependencies. Startup
   takes a few seconds while the SBERT model loads; `/health` answers once
   it's ready. If uvicorn is already running and you retrain XGBoost,
   restart it — `--reload` doesn't watch the `.joblib` model file.
3. Browser, logged into the PHP app

## Where the project is now

Per the ML repo's CLAUDE.md, Phases 1-6 (including model evaluation and
tuning) are done. Dated comments throughout `ml_recommendations.php`
reference an ISO 25010 audit (2026-09-06 / 2026-09-17) and feedback from
real testers, so the project is in the ISO 25010 / TAM testing and paper
phase for the capstone defense. Bugs found during that testing should be
fixed here or in the ML repo as appropriate — the integration itself is
no longer the thing under suspicion.
