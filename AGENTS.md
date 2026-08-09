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
- **Lists and tags are ONE table** discriminated by `type`, with ONE polymorphic pivot
  (`subscriber_id`, `object_id`, `object_type`). Do not add a parallel tags table, and
  do not add a `tags` column to the subscriber row — both were considered and rejected
  (see the Phase 0 report).
- **A segment is a saved query** (`segments.filters` JSON, in the same arg shape
  `Subscribers::query()` takes). Never denormalise membership into a table.

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

Double opt-in emails + the public confirm/unsubscribe endpoints, the segment builder UI,
campaigns, automations, the activity timeline, ESP add-ons. The **schema already supports
all of them** — the point of Phase 1 was that none of those needs a migration. Check the
Phase 0 report before designing any of them differently.
