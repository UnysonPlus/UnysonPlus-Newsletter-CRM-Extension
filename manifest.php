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

$manifest['version']    = '1.0.1';
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
