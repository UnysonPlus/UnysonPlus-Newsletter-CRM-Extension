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

## Not built yet (deliberately)

The segment builder UI, campaigns, automations, the activity timeline, ESP add-ons. The
**schema already supports all of them** — the point of Phase 1 was that none of those
needs a migration, and Phase 2 (double opt-in end to end) shipped without one, which is
the evidence that held. Check the Phase 0 report before designing any of them differently.

When campaigns arrive they need a queue table with **per-recipient state** — a campaign
row alone cannot answer "who did we already send to?", so a PHP timeout mid-batch either
double-sends or silently drops people. Reuse `FW_Newsletter_CRM_Mail::unsubscribe_headers()`
on every campaign email; it is public for exactly that.
