# Content Change Email Alerts

Get an email when another user changes content on your WordPress site. The message includes the editor's name and username, content title, time, page URL and edit URL.

## What it watches

- Changes to a post, page, public custom post type or Elementor template.
- Relevant Elementor, ACF and WooCommerce product fields, plus taxonomy assignments.
- One email per recipient and content item during a single request. The user who made the change does not receive it.

Recipients are users with the **Administrator** or **Editor** role. Drafts and private content can trigger alerts, so send this only to trusted staff. Attachments, revisions, autosaves, trashed items and anonymous background jobs are excluded. Automated stock updates and order changes are not tracked.

The email is plain text for reliable delivery. It reports that a tracked value changed, not a field-by-field diff. WordPress must be able to send mail (`wp_mail`); SMTP or mail delivery setup is the site's responsibility.

## Install

### As a plugin

1. Create `wp-content/plugins/content-change-email-alerts/` and place `content-change-email-alerts.php` inside it.
2. Activate **Content Change Email Alerts** in Plugins.

### In Code Snippets

Copy the PHP file into a new PHP snippet, omit the opening `<?php` line and plugin header, and set it to run everywhere. Use **one** install method. Running both copies will register duplicate listeners.

Requires WordPress 5.6+ and PHP 7.4+. The listed integration fields should be checked against the versions of Elementor, ACF and WooCommerce on your site.

## Quick check

1. Make sure the site has two users with Administrator or Editor roles and working outgoing mail.
2. Edit and save an existing page as one user. The other user should receive one email; the editor should receive none.
3. Save the same page without changing it. No email should be sent.
4. Try an Elementor change, an ACF field change, a product price change and a category change if those features are installed.

Test on staging before adding to a production site. Some third-party plugins write metadata in separate requests, which can generate separate alerts. ACF fields without the usual `field_...` reference row may not be detected.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
