# BTUSA Contact Acquisition

BTUSA Contact Acquisition connects the Better Together USA national contact,
newsletter and membership forms to FluentCRM without treating an operational
submission as marketing consent. It also provides restricted tag and list
classification for LAPDI Member Portal users. Install it on national only;
chapter sites link to national forms and do not run a local Fluent stack.

## Ownership

- Fluent Forms owns field validation and stored form entries.
- This plugin owns consent-safe FluentCRM contact updates, lifecycle preservation, interest routing, the welcome-trigger tag and restricted member classification.
- FluentCRM owns lists, tags, contact records, the `BTUSA Welcome` automation and its email.
- FluentCRM owns newsletter delivery and unsubscribe status. Mailchimp is a non-sending storage copy populated by the existing Fluent Forms feed.
- FluentSMTP and the configured transactional email provider own authenticated delivery.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- Fluent Forms
- FluentCRM
- LAPDI Member Portal for member classification (contact acquisition continues without it)

## Installation

Download the versioned ZIP from the repository's [Releases](https://github.com/wearebtusa/btusa-contact-acquisition/releases) page. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, upload the ZIP and activate it after Fluent Forms and FluentCRM.

Do not enable a second FluentCRM integration feed on the same form. This plugin is intended to be the sole CRM synchronization path for the configured BTUSA contact form.

## Configuration

The plugin reads these WordPress options:

- `btusa_contact_acquisition_form_id`: production Fluent Form ID.
- `btusa_membership_application_form_id`: Fluent Forms Pro membership application ID. Use the same ID in LAPDI Member Portal's workflow settings.
- `btusa_newsletter_form_id`: Fluent Forms Pro newsletter signup form ID. The form must use `first_name`, `email`, the optional visible `chapter_code` state/DC dropdown and required `marketing_consent=yes`, with Fluent Forms global and form-level double opt-in enabled.
- `btusa_contact_acquisition_test_mode`: `yes` restricts the welcome trigger tag to approved test emails; `no` enables it for all explicit opt-ins.
- `btusa_contact_acquisition_test_emails`: array of approved test email addresses.

Example WP-CLI configuration:

```sh
wp option update btusa_contact_acquisition_form_id 123
wp option update btusa_membership_application_form_id 456
wp option update btusa_newsletter_form_id 5
wp option update btusa_contact_acquisition_test_mode yes
wp option update btusa_contact_acquisition_test_emails '["approved@example.org"]' --format=json
```

Activation creates or reuses the required interest tags, the `Consent: BTUSA Updates` automation trigger tag, the `Test Contact` tag and BTUSA-prefixed contact custom fields. It does not delete or replace existing FluentCRM resources.

## Newsletter behavior

- The configured newsletter form is synchronized only after Fluent Forms double opt-in and explicit `marketing_consent=yes`.
- New newsletter contacts are `subscribed`, receive `Prospect` when no recognized lifecycle list exists and receive `Consent: BTUSA Updates` when the welcome trigger is allowed.
- A fresh confirmed newsletter opt-in may restore an ordinary `unsubscribed` contact. It never revives `bounced` or `complained` contacts.
- Existing lifecycle classifications and suppression state are otherwise preserved.
- A valid optional `chapter_code` is stored in the `btusa_chapter_interest`
  custom field. It represents requested news context only, creates no dynamic
  tag and grants no chapter role or access. A blank referral does not erase a
  previously stored value.
- Mailchimp may retain the existing form-feed copy, but it is not a sending or unsubscribe authority.

## Contact behavior

- New contacts without marketing consent are created with FluentCRM status `transactional`.
- New contacts with explicit consent are created as `subscribed`.
- Existing subscribed or suppressed statuses are not downgraded or forcefully revived.
- `Prospect` is attached only when no recognized lifecycle list is already present.
- Existing lists, tags and populated primary fields are preserved; a blank optional field never clears existing data.
- Interest tags are additive so a later inquiry does not destroy prior intent history.
- `Consent: BTUSA Updates` is attached only for an eligible explicit opt-in. FluentCRM's duplicate-safe tag attachment prevents repeated welcome enrollment.

## Membership behavior

- Publish `[btusa_membership_application]` on the Join page. The shortcode resolves the configured form ID, so page content does not contain an environment-specific ID.
- A submitted application creates or updates a CRM contact only when `updates_consent` explicitly contains `yes`. Operational application email is not marketing consent.
- An application without marketing consent remains solely in Fluent Forms and Member Portal until a decision is made.
- On the `lapdi_member_application_approved` event, the plugin creates or updates a minimal FluentCRM contact, attaches `Member`, removes only `Prospect`, and preserves all other lists and tags.
- The eight reflective answers, reviewer identities, rationales, recommendations, and workflow audit are never copied to FluentCRM.
- Existing suppressed CRM statuses are never revived. A new approved member without marketing consent is `transactional`; an eligible explicit opt-in may be `subscribed`.
- The approved WordPress user stores the corresponding FluentCRM contact ID for stable classification after an email change.

## Member CRM classification

The plugin adds **Users → CRM Classifications** for accounts with the `manage_btusa_contact_classifications` capability. WordPress Administrators receive that capability automatically. Do not grant this capability to chapter administrators; their chapter-site access is limited to Pages and Events.

- Only users assigned to a configured Member Portal role are displayed.
- An existing FluentCRM contact is required; the screen never creates one.
- Administrators may add or remove existing, permitted tags and lists for one user or up to 100 selected users.
- `Consent: BTUSA Updates`, `Prospect` and `Member` are always protected. Owners may protect additional classifications on the same screen.
- The interface does not grant FluentCRM manager permissions or expose contact editing, deletion, export, email, campaign or automation controls.
- Successful changes fire `btusa_contact_classification_changed`; completed operations fire `btusa_contact_classification_batch_completed`. The plugin retains no classification audit log.

The screen uses a two-step workflow: select one or more portal users, then choose an add/remove operation and the permitted classifications. Owner-only protection settings remain collapsed until needed.

The membership application requires Fluent Forms Pro for four steps and save/resume. LAPDI Member Portal—not Fluent Forms User Registration or Admin Approval—remains the approval and account-access authority.

## Required form keys

- `first_name`
- `last_name`
- `email`
- `phone`
- `contact_interest`
- `message`
- `updates_consent` with checked value `yes`

The membership form additionally uses the keys documented in the website repository's membership application deployment guide. Its name and email keys must remain `first_name`, `last_name`, and `email`; its independent marketing checkbox must remain `updates_consent` with checked value `yes`.

Supported `contact_interest` values:

- `membership`
- `volunteering`
- `community_partnerships`
- `events`
- `donations_sponsorships`
- `media_press`
- `general_questions`

## Upgrade from LAPDI Contact Acquisition

Deactivate the LAPDI plugin before activating BTUSA Contact Acquisition. On activation, this plugin copies the three legacy `lapdi_contact_acquisition_*` configuration options to their canonical `btusa_contact_acquisition_*` names when the new options do not already exist. The legacy settings are left intact for rollback safety.

Previously collected LAPDI-prefixed FluentCRM custom fields are not deleted. New submissions use BTUSA-prefixed custom fields.

## Verification

After changing delivery configuration:

1. Submit the public form while signed out.
2. Confirm its Fluent Forms entry and consent value.
3. Confirm one FluentCRM contact, the expected lifecycle list and additive interest tag.
4. For an explicit opt-in, confirm the consent tag, one automation subscriber and one campaign email record.
5. For a non-opt-in, confirm there is no consent tag, automation subscriber or marketing email.
6. Inspect the FluentSMTP log and verify delivery at the approved test inbox.
7. Confirm an authorized portal manager can classify portal users but cannot change Consent, Prospect or Member and cannot open FluentCRM administration.

## License

Licensed under the GPL, version 2 or later.
