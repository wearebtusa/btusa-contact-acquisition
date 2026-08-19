=== BTUSA Contact Acquisition ===
Contributors: sharrondenice
Tags: fluent-forms, fluentcrm, crm, consent, email-marketing
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a Better Together USA Fluent Form to FluentCRM with consent-safe lifecycle and interest routing.

== Description ==

BTUSA Contact Acquisition synchronizes one configured Fluent Forms contact form with FluentCRM. It preserves existing lifecycle classifications and interests, distinguishes transactional contacts from explicit marketing subscribers, and applies the tag that triggers the BTUSA welcome automation only after eligible consent.

Fluent Forms and FluentCRM are required.

== Installation ==

1. Install and activate Fluent Forms and FluentCRM.
2. Upload and activate BTUSA Contact Acquisition.
3. Set `btusa_contact_acquisition_form_id` to the Fluent Forms form ID.
4. Keep `btusa_contact_acquisition_test_mode` set to `yes` until delivery testing succeeds.
5. Configure `btusa_contact_acquisition_test_emails` with approved external test addresses.

== Upgrade Notice ==

= 1.0.0 =
Replaces LAPDI Contact Acquisition. Existing LAPDI configuration options are copied to BTUSA option names on activation when needed.

== Changelog ==

= 1.0.0 =
* Initial standalone BTUSA release.
* Rebrands plugin code, hooks, settings and CRM custom fields for BTUSA.
* Migrates legacy LAPDI configuration options without deleting rollback data.
