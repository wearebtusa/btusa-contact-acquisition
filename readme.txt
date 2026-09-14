=== BTUSA Contact Acquisition ===
Contributors: sharrondenice
Tags: fluent-forms, fluentcrm, crm, consent, email-marketing
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects Better Together USA contact, newsletter and membership forms to FluentCRM with consent-safe lifecycle routing and restricted portal-user classification.

== Description ==

BTUSA Contact Acquisition synchronizes configured Fluent Forms contact and membership forms with FluentCRM. It preserves existing lifecycle classifications and interests, distinguishes operational contacts from explicit marketing subscribers, and applies the tag that triggers the BTUSA welcome automation only after eligible consent.

Fluent Forms and FluentCRM are required.

== Installation ==

1. Install and activate Fluent Forms and FluentCRM.
2. Upload and activate BTUSA Contact Acquisition.
3. Set `btusa_contact_acquisition_form_id` to the Fluent Forms form ID.
4. Set `btusa_membership_application_form_id` to the Fluent Forms Pro membership application ID and use the same ID in LAPDI Member Portal.
5. Keep `btusa_contact_acquisition_test_mode` set to `yes` until delivery testing succeeds.
6. Configure `btusa_contact_acquisition_test_emails` with approved external test addresses.

== Upgrade Notice ==

= 1.3.0 =
Adds the Chapter news interest custom field during the normal plugin upgrade.

= 1.0.0 =
Replaces LAPDI Contact Acquisition. Existing LAPDI configuration options are copied to BTUSA option names on activation when needed.

== Changelog ==

= 1.3.0 =
* Retain validated national-newsletter chapter interest in a dedicated FluentCRM custom field.
* Document national-only form and CRM deployment; chapter sites link to national forms.

= 1.2.1 =
* Redesign CRM Classifications with a clearer two-step workflow, compact status badges and responsive controls.
* Add select-all behavior and a live selected-user count for safer bulk changes.
* Move owner protection settings into a focused collapsible panel.

= 1.2.0 =
* Add restricted individual and bulk FluentCRM tag/list classification for LAPDI Member Portal users.
* Protect the consent trigger and Prospect-to-Member lifecycle from manual changes.
* Synchronize confirmed newsletter signups to FluentCRM while preserving suppression safeguards.
* Add stable WordPress-user to FluentCRM-contact mapping and integration hooks.

= 1.1.0 =
* Add a dedicated Fluent Forms Pro membership application integration and stable Join-page shortcode.
* Keep non-consenting applicants out of marketing CRM during application review.
* Convert approved applicants to Member while preserving unrelated FluentCRM lists, tags, and suppression status.
* Keep application answers and review records out of FluentCRM.

= 1.0.0 =
* Initial standalone BTUSA release.
* Rebrands plugin code, hooks, settings and CRM custom fields for BTUSA.
* Migrates legacy LAPDI configuration options without deleting rollback data.
