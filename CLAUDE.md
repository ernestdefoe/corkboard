# Corkboard: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center for review first. New extension: dev + ernestdefoe.dev only until Ernest has tested it.
- Scope and Ernest's decisions: `~/github/command-center/scopes/boards.md` (name Corkboard, all recommendations taken, 2026-11-13). Mockup: https://claude.ai/artifact/RXMrdRXp86WxSkQj7rSGut

## Design rules that exist for a reason

- **One read rule.** `Scope\BoardVisibility` (public / members / team / private; private boards ignore group rows) and `Scope\CardVisibility` (a card follows its board). The API, widgets, notifications (checked again at send time), the outbox, the discussion chip and the calendar feed all use it. Roles are `Access` (viewer < commenter < editor < manager; owner and moderators are managers; completed/archived boards are read-only except reopen). Its memo is a static WeakMap keyed by the actor (the container makes a new Access per class).
- **🚨 `Card::board()` ignores the soft-delete scope** (so `$card->board` is never null); controllers check `deleted_at`. `Board` has a global scope hiding deleted boards.
- **The board payload is slim and constant in queries** (`Presenter::board`: 10 queries for 1 or 300 cards, tested on SQLite, MySQL `dev_` and PostgreSQL). Descriptions, checklists and comments load only when a card opens. Boards over 500 open cards send each column's first 150.
- **Positions** are integers with gaps of 1024, renumbered in one CASE update when a gap closes (`Positions`), rows locked while placing.
- **Notifications**: `NotificationSyncer::sync()` hides matching alerts for everyone not in the new list, so every blueprint's data carries the activity id. 🚨 Core registers a type's email switch only for a mailable blueprint; ours aren't (email goes through `Mail\Outbox`, one email per person after updates stop for 2 minutes, at most 10), so extend.php registers the `notify_<type>_email` preferences itself. The digest's own switch stops all Corkboard email.
- **Realtime** pushes ids and a version only, to each team member's `private-user=<id>` channel (≤50 people; flarum/framework#5220 would allow a board channel). Everyone else polls `/boards/{id}/version` every 20 s while visible.
- **Drag**: `components/drag.ts` (pointer events, touch hold 180 ms, non-passive touchmove once lifted, edge auto-scroll). 🚨 scroll-snap is switched off while dragging (`.CorkboardDragging`) or the strip never scrolls on. Keyboard: Space/arrows/Space/Escape with an `aria-live` region; no `onkeydown` returns false.
- **Modal attrs**: never name one `state` (core passes its own modal manager as `state`); ours is `board`.
- **The discussion chip** (`Api\DiscussionCards`, deferred field, one query) only once `ernestdefoe-corkboard.linked` is set and only on the discussions endpoints.
- extend.php uses string literals for setting keys. Lazy chunk: one entry (`components/index.ts`), namespaced chunk ids and its own chunk-loading global.
- Renown's side lives in Renown (`Integration\Corkboard`): it listens to `CardCompleted` / `CardReopened` / `CardDeleted` and reads `corkboard_boards`, `corkboard_members`, `corkboard_assignees`. Keep those columns' meaning stable.

## Testing

- `composer test:setup` (SQLite: `touch /tmp/ck_test.sqlite`, `DB_DRIVER=sqlite DB_DATABASE=/tmp/ck_test.sqlite`) `&& composer test`, then `~/github/scopes/corkboard/dbtests.sh` (MySQL `dev_` + PostgreSQL in Docker; prints the driver). `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum `~/Herd/corkdev` (https://corkdev.test, MySQL `corkdev`, prefix `ck_`). Seed: `php ~/github/scopes/corkboard/seed.php` (runs the showcase seed `~/github/showcase/seeds/99-corkboard.php`). Browser checks: `interact.mjs` (keyboard, mouse, touch, typing), `shots.mjs`, `themes.mjs` (THEME=colored|bespoke), `widgets.mjs`, `project.mjs`. Devices: `proxy.mjs` (localhost:8789, signed in by a `claude-shots` remember token) for the iOS Simulator and Android emulator.
