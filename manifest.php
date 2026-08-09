<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Newsletter / Subscriber CRM', 'fw' );
$manifest['slug']        = 'unysonplus-newsletter-crm';
$manifest['description'] = __(
	'Stores and manages the people who sign up through the [newsletter] element. Adds a Subscribers screen (search, filter, sort, bulk actions, manual add, CSV import & export), lists and tags, and saved segments. Built on a repository + service + provider architecture, so email-service integrations (Mailchimp, MailerLite, Brevo) drop in later as add-ons without touching the core. Works even without the shortcodes extension — import a CSV or post to the REST endpoint.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.2 - Double opt-in, end to end. New signups are stored as Pending, emailed
 *         a tokened confirmation link, and only become Subscribed once they
 *         click it; an optional welcome email follows. Two public endpoints
 *         (query args on the home URL, so there are no rewrite rules to flush
 *         and nothing to break on a permalink change) handle confirming and
 *         unsubscribing. The confirm link deliberately opens a page with a
 *         button rather than confirming on the GET itself — corporate mail
 *         scanners visit every link in an inbound email and would otherwise
 *         opt people in for them; `confirm_on_visit` turns that guard off.
 *         Unsubscribe implements RFC 8058 one-click (a bare POST unsubscribes
 *         with no confirmation step, which is what the List-Unsubscribe and
 *         List-Unsubscribe-Post headers now on every email promise, and what
 *         Gmail and Yahoo require of bulk senders); a GET still shows a normal
 *         page for clients without one-click support. Confirmation tokens are
 *         single-use and expire after 48h (filterable); the unsubscribe token
 *         never expires, because it lives in the footer of every email ever
 *         sent. Adds a "Resend confirmation" row action that rotates the token
 *         so a leaked link dies, editable email templates with placeholders,
 *         and refuses to send a fresh confirmation to someone who unsubscribed.
 *         All mail goes through wp_mail(), so the Mailer extension's SMTP
 *         settings govern transport and SPF/DKIM alignment.
 *
 * 1.0.1 - Thumbnail redrawn to the house icon convention.
 *
 * 1.0.0 - Initial release. Subscriber store (five custom tables), capture off
 *         the [newsletter] element's existing hooks, admin list table with CSV
 *         import/export, provider interface, lifecycle hooks, REST and GDPR.
 */

$manifest['version']    = '1.0.2';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// No hard requirements on purpose: the capture hook simply never fires when the
// shortcodes extension is off, and the store is still useful for imports + REST.

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Newsletter-CRM-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Newsletter-CRM-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
