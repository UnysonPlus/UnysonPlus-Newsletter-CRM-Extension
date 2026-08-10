# Newsletter / Subscriber CRM — working rules

The storage + management layer under the existing `[newsletter]` shortcode. Read this
before editing anything in this folder.

## The layering is the design — do not shortcut it

```
Installer   framework/extensions/newsletter-crm/includes/class-fw-newsletter-crm-installer.php
Repository  …-subscribers.php · …-lists.php
Service     …-service.php
Providers   includes/providers/…
Admin/REST  …-admin-page.php · …-list-table.php · …-csv.php · …-rest.php
```

Two rules keep it honest, and every future feature (campaigns, automations, ESP
add-ons) depends on them:

- **Nothing above the repository writes SQL.** Need a new query? Add a repository
  method. Never `$wpdb->prepare()` in an admin class, a provider, or the service.
- **Nothing below the service fires a hook.** The repositories are dumb on purpose —
  they hold no business rules and emit no actions, so a bulk operation can call them
  in a loop without side effects.

The service is the only entry point outsiders (and our own admin screen, importer,
capture hook and REST controller) call. A rule such as "a re-signup reactivates rather
than duplicating" or "double opt-in means `pending`" exists there exactly once.

## Schema discipline (this is the plugin's FIRST custom-table extension)

`dbDelta` is picky by contract — **two spaces after `PRIMARY KEY`**, one field per
line, lowercase type keywords, `KEY` never `INDEX`, and the collation from
`$wpdb->get_charset_collate()`. Break any of those and it silently re-runs `ALTER`s on
every load.

- Guarded by the `fw_ext_newsletter_crm_db_version` option, compared against
  `FW_Newsletter_CRM_Installer::DB_VERSION`. **Bump that constant whenever you change
  the schema**, or the change never reaches an existing site.
- The check runs on every `_init()` — one autoloaded `get_option()` when current — so
  activation *and* plugin-update upgrades self-heal with no activation hook to miss.
- `dbDelta` adds columns and indexes; it **never drops or renames**. Anything
  destructive is a numbered, version-guarded block in `Installer::migrate()`.
- Tables use `$wpdb->prefix` (per-site), **not** `base_prefix` — on multisite each site
  keeps its own subscribers, which is right for the demos network and for real networks.
- **Deactivating never drops a table.** Data removal is the explicit, opt-in "Remove all
  data" action on the Settings tab, and nothing else may call `Installer::uninstall()`.

## Things that will bite you if you change them

- **`fw_newsletter_subscribe` is an ACTION fired BEFORE the result filter**, so the
  capture hook cannot reject a signup from inside it. It stores, stashes any `WP_Error`
  keyed by normalised email, and `fw_newsletter_subscribe_result` (hooked later) reports
  it. Don't "simplify" that into one callback.
- **Never delete a row on unsubscribe.** A forgotten address is silently re-subscribed
  by the next import. Opt-out = `status` + `unsubscribed_at`, row retained. Same reason
  the GDPR eraser anonymises in place instead of deleting.
- **Never `SELECT`-then-`INSERT` on email.** The `UNIQUE (email)` index is the dedupe
  point; `Subscribers::upsert()` already handles the lost-race case.
- **Emails are normalised (lowercase + trim) by `Subscribers::normalize_email()`** for
  storage, lookup and dedupe alike. Never compare raw input against a stored address.
- **CSV export must stay on the `load-` hook** — it streams headers and `exit`s, which
  is impossible once any HTML has been printed. Cells starting with `= + - @` are
  apostrophe-prefixed; keep `escape_cell()` in the path or you have reintroduced CSV
  injection into Excel.
- **Import defaults to skipping people whose stored status is `unsubscribed`.** The
  override is a deliberate, labelled checkbox. Don't make it the default.
- **Tokens are credentials, not data** — never expose `confirm_token` /
  `unsubscribe_token` through REST, CSV or the admin table.
- **The confirm link must not confirm on a bare GET.** Corporate mail scanners and
  security products auto-visit every URL in an inbound email, so a GET-confirms link
  lets a robot opt someone in — which defeats double opt-in entirely. The GET renders
  a page with a button that POSTs. `confirm_on_visit` exists for sites that knowingly
  trade that away; don't make it the default.
- **Unsubscribe MUST accept a bare POST with no confirmation step.** That is RFC 8058
  one-click, which the `List-Unsubscribe-Post` header promises and which Gmail/Yahoo
  require of bulk senders. A mail client cannot send a nonce, which is exactly why the
  token is the credential. Adding a confirmation step to the POST path would break it.
- **Confirm tokens are single-use and expire (48h, filterable); the unsubscribe token
  never expires** — it lives in the footer of every email ever sent, so expiring it
  would strand people with a dead opt-out link. Keep them in separate columns.
- **The public endpoints are query args on the home URL, not rewrite rules.** Rules
  need flushing on activation and Unyson extensions have no activation hook we can
  rely on — a stale rewrite cache would 404 every confirmation link, silently. Don't
  "improve" these into pretty permalinks without solving the flush properly.
- **All mail goes through `wp_mail()`.** The Mailer/SMTP extension governs transport,
  From address and authentication; a raw `mail()` call breaks SPF/DKIM alignment on
  every site that configured it.
- **The mail class hooks the lifecycle actions rather than being called from the
  service.** That is deliberate dogfooding — if `FW_Newsletter_CRM_Mail` were deleted
  the store would still work. Don't move sending into the service.
- **Lists and tags are ONE table** discriminated by `type`, with ONE polymorphic pivot
  (`subscriber_id`, `object_id`, `object_type`). Do not add a parallel tags table, and
  do not add a `tags` column to the subscriber row — both were considered and rejected
  (see the Phase 0 report).
- **A segment is a saved query** (`segments.filters` JSON, in the same arg shape
  `Subscribers::query()` takes). Never denormalise membership into a table. Keep
  `Service::sanitize_segment_filters()` in the save path — it strips paging, ordering
  and any explicit `ids`, which is what stops a segment silently becoming a frozen
  snapshot of whoever matched on the day it was saved.
- **`add_to_list()` returns TRUE only when a row was actually created.** It leans on
  `INSERT IGNORE`, and `$wpdb->query()` returns **0** affected rows on a duplicate —
  `0 !== false` is true, so the obvious `false !== query()` reports every no-op as a
  change. That bug made "tagged N subscribers" count people who already had the tag.
- **The Subscribers screen is ONE `method="get"` form** (core's `edit.php` pattern),
  because WP_List_Table's filter dropdowns live inside its own tablenav — split them
  across two forms, or make it POST, and filtering stops surviving into the URL while
  pagination links silently drop it. Bulk actions ride the same form, nonced under
  `bulk-subscribers`, and every handler PRG-redirects so nothing re-fires on refresh.

## Adding an ESP provider

Subclass `FW_Newsletter_CRM_Provider`, register on
`unysonplus_newsletter_crm_providers`. The contract was designed against Mailchimp,
MailerLite and Brevo — all three are **email-keyed upserts**, so `subscribe()` takes the
whole subscriber and the adapter decides how to be idempotent (Mailchimp's
`md5(lowercased email)` in the URL, MailerLite's POST-is-upsert, Brevo's
`updateEnabled`). Return `true` or a `WP_Error`, never a bare bool, so a caller can tell
"rejected" from "429, retry later". Remote list IDs are **mapped, never guessed** —
`map_list_id()`. A provider failing must never lose the local signup: the service records
the error in subscriber meta and carries on.

## Versioning + mirroring

Per the workspace rules: bump `manifest.php` on every meaningful change here, mirror to
every `D:\xampp\htdocs` install, and leave the CORE version (`unysonplus.php` +
`framework/manifest.php`) alone until the confirmed-milestone / release step. Repo:
`UnysonPlus-Newsletter-CRM-Extension` (repo root = this folder).

## Campaign sending — the rules that keep it correct

Everything about `FW_Newsletter_CRM_Sender` follows from three facts about WP-Cron.
None of them are negotiable; if you change this code, re-read them first.

1. **WP-Cron fires on page loads, not on a clock, and can fire the same event twice
   concurrently.** Hence the lock. It uses `add_option()` because that is **atomic** —
   `option_name` carries a UNIQUE index, so exactly one racer can create it. A
   transient is **not** atomic under an external object cache and both racers win.
   The lock is stolen after `LOCK_TTL`, or a worker that died mid-send would stall
   the queue for ever. `run()` releases it in a `finally`.
2. **A PHP timeout mid-batch is normal, not an edge case.** That is the entire reason
   `fw_crm_campaign_queue` exists — one row per recipient, flipped the moment it is
   handled. A campaign row alone cannot answer "who did we already send to?", so a
   killed request would either double-send or silently drop people. Never replace it
   with a counter.
3. **The ceiling is the mail host's rate limit, not PHP.** Batch size is a setting,
   small by default, capped at 500.

Also load-bearing:

- **Eligibility is re-checked at SEND time**, not only when the queue was built.
  Someone can unsubscribe in between, and mailing them anyway is exactly the
  complaint we must never earn. Those rows are marked `skipped`, not `sent`.
- **Only `subscribed` is ever queued.** `Campaigns::audience_args()` *forces*
  `status = 'subscribed'` over whatever the audience says — pending people never
  agreed, and unsubscribed/bounced/complained must never be mailed again.
- **A campaign is read-only once sending starts.** Editing mid-send means half the
  list got a different email from the other half.
- **Pause keeps the queue.** Resume continues; it does not restart.
- **The audience is a live query resolved ONCE, at send time**, then frozen into
  queue rows — a send needs a fixed, countable recipient set or progress is
  meaningless.
- **A body without `{{unsubscribe_url}}` gets an unsubscribe line appended.** Bulk
  email must always carry a visible opt-out; don't make that optional.
- Deleting the extension's data calls `Sender::unschedule()` **before** dropping
  tables — a scheduled tick against dropped tables fatals on the next page load.

## One body pipeline — never call `wpautop()` directly

`Mail::render_body()` is the single path every outgoing message takes: `wp_kses_post` →
`make_clickable` → `maybe_autop` → the shared HTML shell. Campaigns use it too, on
purpose — a second near-copy in the sender would drift.

`maybe_autop()` applies `wpautop()` **only when the body has no block-level markup of
its own**. The plain-text confirmation/welcome templates need autop; a campaign written
in `wp_editor` is already full of `<p>` tags and re-wrapping it double-paragraphs and
wrecks the spacing. That bug is exactly what the rich editor would have introduced, so
don't "simplify" this back to an unconditional `wpautop()`.

The editor ID is `fw_crm_body` — `wp_editor()` accepts **only lowercase letters and
underscores** in an ID, and a hyphen silently breaks TinyMCE with no error. A campaign
that has started sending renders a read-only preview instead of an editor, because
TinyMCE has no honest disabled state.

## The Email Builder — a compiler with a UI attached

`FW_Option_Type_Email_Builder` is the framework's **fourth** builder subclass (after
the Page Builder, the Forms extension's Form Builder and the Learning extension's Quiz
Builder). The canvas, item tray, reorder/clone/delete and options modal are all
inherited — read the Quiz Builder if you need the pattern, it is the closest analogue
because its items render their own markup rather than delegating to shortcodes.

Rules that are load-bearing:

- **Blocks compile themselves to nested tables with INLINE styles**, and each returns a
  **self-contained table**, never a bare `<tr>` — the compiler places a block either in a
  full-width row or inside a column, and only a self-contained table works in both.
  (Same reason MJML makes every component a table.) Use `wrap_block()`.
- **No flex, no grid, no float — ever.** But note the nuance: **divs and one class ARE
  used, for columns only.** The hybrid column pattern is an MSO ghost table with a real
  `<td>` per column for Outlook, plus inline-block `<div class="fw-crm-col">` for
  everyone else, plus a media query that stacks them on mobile. MJML's own output does
  exactly this. Layout still never *depends* on `<style>`: the inline `max-width` does
  the splitting, and the media query only stacks.
- **The column container sets `font-size:0`, and each column resets it.** HTML whitespace
  *between* inline-block elements renders as a visible gap and breaks the column maths.
  Don't remove it.
- **Outlook will not stack columns on mobile** — it ignores media queries. That is
  correct and expected: the Word engine is desktop-only.
- Gmail strips `<style>` in clipped views, so anything in there is *enhancement only* —
  never make it load-bearing.
- **The Button block needs its VML fallback and an explicit width.** A CSS-styled `<a>`
  collapses to a bare link in classic Outlook, and VML cannot size itself.
- **`body_json` is the source of truth; `body` holds the compiled HTML**, written on
  save. That is what keeps the entire sending stack (queue, batching, test sends,
  `render_body()`) unaware the builder exists, and why visual-editor campaigns still
  work — they simply have no block tree. Do not make the sender read `body_json`.
- **Switching to the visual editor clears `body_json`**, so the tree and the HTML can
  never disagree about what is actually sent.
- **Two value shapes exist and both must keep working.** The framework's builder stores
  a block's values under `options`; hand-authored trees and fixtures use `atts`.
  `Compiler::block_atts()` accepts either, and `to_option_value()` normalises `atts` →
  `options` on the way into the canvas (otherwise an imported tree loads as a row of
  empty blocks).
- **The option value itself is `array( 'json' => '<string>' )`**, not a bare array.
  `normalize()` unwraps it; `to_option_value()` re-wraps it. Hand a bare array to
  `render_options()` and the canvas comes up empty.
- **Every block must be registered in JS as well as PHP**, or the canvas logs "Cannot
  detect Item type" and renders nothing for a saved tree. One generic registration in
  `static/js/email-builder.js` is driven by localized data, rather than four
  near-identical scripts.
- **Columns come from per-block widths, not a nested container.** Each block carries a
  `width` (`1_2`, `1_3`, …) set by the FRAMEWORK's `FwBuilderComponents.ItemView.WidthChanger`
  — the same component the Learning extension's quiz builder uses. `Compiler::pack_rows()`
  groups *consecutive* blocks while their widths still fit; a full-width block always
  stands alone. Our width vocabulary is registered through the
  `fw_builder_item_widths:email-builder` filter and is deliberately coarse — the page
  builder's twelfths give a ~50px column in a 600px email.
- **Unknown block types are skipped, never guessed** — a tree saved by a newer version
  must not email somebody garbage. They also never join a column row.
- Keep the output-size estimator honest: Gmail clips beyond ~102 KB and table structure
  can break mid-render.

### The 14 blocks, and what each one is actually for

Tray order is deliberate — top-of-email first, then structure, then chrome:

`logo`, `heading`, `text`, `image`, `button`, `divider`, `spacer`, `menu`, `social`,
`hero`, `video`, `table`, `footer`, `html`.

Non-obvious decisions worth not re-litigating:

- **Social ships NO icon set.** SVG is stripped by Gmail and ignored by Outlook, so the
  only working alternative would be bundling raster brand marks — third-party trademarks
  we would be redistributing, plus a re-cut every rebrand. Each link therefore renders as
  a styled **text link** (100% reliable everywhere) and takes an **optional uploaded
  icon** for sites with their own set. Same one-row/one-cell-per-link layout as `menu`,
  because it is the only arrangement email keeps on a line without floats.
- **`video` never embeds video.** No client plays it. It renders a linked poster image
  with a play overlay; the caption + URL carry it in the plain-text part.
- **`hero` emits a VML `<v:rect>`/`<v:fill>` fallback** for its background image, for the
  same reason the button does — Outlook drops CSS backgrounds.
- **`logo` falls back**: uploaded image → the Customizer logo → the site title as text.
  It has no `alt` option of its own; it always uses the site name, and the plain-text
  renderer does the same.
- **`html` honours `unfiltered_html` at COMPILE time** (i.e. the saving user's caps),
  which only works because compilation happens on save. If compilation ever moves to
  send time, that check has no user to check against — revisit it before changing when
  the compile runs.
- **`footer` exists mostly to prompt for the postal address** CAN-SPAM/GDPR require, and
  to keep the unsubscribe link a first-class, styleable thing rather than a bolt-on.
- **`table` takes pipe-separated rows**, which are already readable plain text — so
  `to_plain_text()` passes them straight through rather than converting anything.

When you add a block: implement `compile()`, `get_type()`, `get_thumbnails()` and
**`get_preview_keys()`** (the canvas summary is data-driven from those keys — no JS edit
needed), register it in `Email_Builder::_init()`'s `$items` list, and add a case to
`Compiler::to_plain_text()`. A block with no plain-text case silently vanishes from the
text part of every campaign that uses it.

## CSV import is chunked — keep it resumable

`CSV::import()` takes `offset` / `line` / `max_rows` / `max_seconds` and returns the
byte offset it stopped at plus `done`. The offset is taken **after a completed row**,
so resuming can neither re-import nor skip one — don't move it. `line` is threaded
through so error messages name the real line in the file rather than restarting per
chunk. Both limits default to `0` (= no limit) so a direct call behaves as it always
did; the admin screen opts in via `_ajax_import_step()`.

## Not built yet (deliberately)

Automations, the contact profile + activity timeline, bounce/complaint handling (the
statuses exist but nothing writes them — that needs a feedback loop or an ESP webhook),
public webhooks, and the ESP provider add-ons. Check the Phase 0 report before designing
any of them differently.

Track record worth keeping: double opt-in (1.0.2) and tags/segments (1.0.3) both shipped
with **no migration** because the Phase 1 schema anticipated them. Campaigns (1.0.5) was
the first change that genuinely needed new tables — and it added tables rather than
altering existing ones, which is the cheap kind.
