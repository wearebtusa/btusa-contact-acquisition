<?php
/**
 * Plugin Name:       BTUSA Contact Acquisition
 * Plugin URI:        https://github.com/wearebtusa/btusa-contact-acquisition
 * Description:       Connects Better Together USA contact and membership forms to FluentCRM with consent-safe lifecycle and interest routing.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  fluentform, fluent-crm
 * Author:            Better Together USA
 * Author URI:        https://wearebtusa.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       btusa-contact-acquisition
 */

defined( 'ABSPATH' ) || exit;

final class BTUSA_Contact_Acquisition {
	private const FORM_ID_OPTION = 'btusa_contact_acquisition_form_id';

	private const MEMBERSHIP_FORM_ID_OPTION = 'btusa_membership_application_form_id';

	private const TEST_MODE_OPTION = 'btusa_contact_acquisition_test_mode';

	private const TEST_EMAILS_OPTION = 'btusa_contact_acquisition_test_emails';

	private const LEGACY_FORM_ID_OPTION = 'lapdi_contact_acquisition_form_id';

	private const LEGACY_TEST_MODE_OPTION = 'lapdi_contact_acquisition_test_mode';

	private const LEGACY_TEST_EMAILS_OPTION = 'lapdi_contact_acquisition_test_emails';

	private const PROSPECT_LIST_TITLE = 'Prospect';

	private const WELCOME_TAG_TITLE = 'Consent: BTUSA Updates';

	private const TEST_TAG_TITLE = 'Test Contact';

	private const LIFECYCLE_LIST_TITLES = array(
		'Donor',
		'Attendee',
		'Volunteer',
		'Prospect',
		'Partner',
		'Member',
		'Participant',
		'Internal',
	);

	private const INTEREST_TAGS = array(
		'membership'             => 'Interest: Membership',
		'volunteering'           => 'Interest: Volunteer',
		'community_partnerships' => 'Interest: Community Partnership',
		'events'                 => 'Interest: Events',
		'donations_sponsorships' => 'Interest: Donations & Sponsorships',
		'media_press'            => 'Interest: Media',
		'general_questions'      => 'Interest: General',
	);

	public static function init(): void {
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'process_submission' ), 20, 3 );
		add_action( 'lapdi_member_application_approved', array( __CLASS__, 'process_membership_approval' ), 10, 3 );
		add_shortcode( 'btusa_membership_application', array( __CLASS__, 'membership_form_shortcode' ) );
	}

	public static function activate(): void {
		self::migrate_legacy_options();

		if ( false === get_option( self::TEST_MODE_OPTION, false ) ) {
			add_option( self::TEST_MODE_OPTION, 'yes', '', false );
		}

		if ( function_exists( 'FluentCrmApi' ) ) {
			self::ensure_crm_resources();
		}
	}

	/**
	 * Create or update the CRM contact after Fluent Forms stores a valid entry.
	 *
	 * @param int                 $insert_id Stored Fluent Forms entry ID.
	 * @param array<string,mixed> $form_data Submitted field data.
	 * @param object              $form Fluent Forms form model.
	 */
	public static function process_submission( $insert_id, $form_data, $form ): void {
		$membership_form_id = (int) apply_filters(
			'btusa_membership_application_form_id',
			get_option( self::MEMBERSHIP_FORM_ID_OPTION, 0 )
		);

		if ( $membership_form_id && (int) $form->id === $membership_form_id ) {
			self::process_membership_submission( (int) $insert_id, (array) $form_data );
			return;
		}

		$form_id = (int) apply_filters(
			'btusa_contact_acquisition_form_id',
			self::option_value( self::FORM_ID_OPTION, self::LEGACY_FORM_ID_OPTION, 0 )
		);

		if ( ! $form_id || (int) $form->id !== $form_id || ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		$email    = sanitize_email( self::field_value( $form_data, 'email' ) );
		$interest = sanitize_key( self::field_value( $form_data, 'contact_interest' ) );

		if ( ! is_email( $email ) || ! isset( self::INTEREST_TAGS[ $interest ] ) ) {
			return;
		}

		try {
			$contacts = FluentCrmApi( 'contacts' );
			$existing = $contacts->getContact( $email );
			$consent  = self::has_marketing_consent( $form_data );
			$data     = self::contact_data( $form_data, $email, $interest, $consent, $existing, (int) $insert_id );

			$contact = $contacts->createOrUpdate( $data, false, false );
			if ( ! $contact ) {
				return;
			}

			self::apply_lifecycle( $contact );
			self::apply_tag( $contact, self::INTEREST_TAGS[ $interest ] );

			if ( self::is_test_email( $email ) ) {
				self::apply_tag( $contact, self::TEST_TAG_TITLE );
			}

			if ( $consent && 'subscribed' === $contact->status && self::welcome_is_allowed( $email ) ) {
				self::apply_tag( $contact, self::WELCOME_TAG_TITLE );
			}

			do_action( 'btusa_contact_acquisition_processed', $contact, (int) $insert_id, $consent, $interest );
		} catch ( Throwable $throwable ) {
			// Do not log contact data. The Fluent Forms entry remains the recoverable source record.
			error_log( 'BTUSA Contact Acquisition failed for Fluent Forms entry ' . absint( $insert_id ) . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Renders the configured Fluent Forms Pro application without exposing an
	 * environment-specific form ID in page content.
	 */
	public static function membership_form_shortcode(): string {
		$form_id = (int) apply_filters(
			'btusa_membership_application_form_id',
			get_option( self::MEMBERSHIP_FORM_ID_OPTION, 0 )
		);
		if ( ! $form_id || ! shortcode_exists( 'fluentform' ) ) {
			return current_user_can( 'manage_options' )
				? '<p>' . esc_html__( 'Configure the BTUSA membership application form ID before publishing this page.', 'btusa-contact-acquisition' ) . '</p>'
				: '<p>' . esc_html__( 'Membership applications are temporarily unavailable.', 'btusa-contact-acquisition' ) . '</p>';
		}

		return do_shortcode( '[fluentform id="' . $form_id . '"]' );
	}

	/**
	 * Adds a membership prospect to CRM only when general marketing consent was
	 * explicitly given. Operational application email does not imply consent.
	 *
	 * @param int                 $entry_id Fluent Forms entry ID.
	 * @param array<string,mixed> $form_data Submitted application values.
	 */
	private static function process_membership_submission( int $entry_id, array $form_data ): void {
		if ( ! function_exists( 'FluentCrmApi' ) || ! self::has_marketing_consent( $form_data ) ) {
			return;
		}
		$email = sanitize_email( self::field_value( $form_data, 'email' ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		try {
			$contacts = FluentCrmApi( 'contacts' );
			$existing = $contacts->getContact( $email );
			$data     = self::membership_contact_data( $form_data, $email, true, $existing, $entry_id );
			$contact  = $contacts->createOrUpdate( $data, false, false );
			if ( ! $contact ) {
				return;
			}
			self::apply_lifecycle( $contact );
			self::apply_tag( $contact, self::INTEREST_TAGS['membership'] );
			if ( 'subscribed' === $contact->status && self::welcome_is_allowed( $email ) ) {
				self::apply_tag( $contact, self::WELCOME_TAG_TITLE );
			}
			do_action( 'btusa_membership_interest_processed', $contact, $entry_id );
		} catch ( Throwable $throwable ) {
			error_log( 'BTUSA Contact Acquisition failed for membership entry ' . absint( $entry_id ) . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Converts an approved applicant to Member without copying application or
	 * reviewer answers into FluentCRM.
	 *
	 * @param int                 $application_id Member Portal application ID.
	 * @param array<string,mixed> $form_data Fluent Forms entry values.
	 * @param int                 $user_id Approved WordPress user ID.
	 */
	public static function process_membership_approval( $application_id, $form_data, $user_id ): void {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}
		$form_data = (array) $form_data;
		$email     = sanitize_email( self::field_value( $form_data, 'email' ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		try {
			$contacts = FluentCrmApi( 'contacts' );
			$existing = $contacts->getContact( $email );
			$consent  = self::has_marketing_consent( $form_data );
			$contact  = $contacts->createOrUpdate( self::membership_contact_data( $form_data, $email, $consent, $existing, 0 ), false, false );
			if ( ! $contact ) {
				return;
			}
			self::apply_member_lifecycle( $contact );
			self::apply_tag( $contact, self::INTEREST_TAGS['membership'] );
			do_action( 'btusa_membership_contact_approved', $contact, absint( $application_id ), absint( $user_id ) );
		} catch ( Throwable $throwable ) {
			error_log( 'BTUSA Contact Acquisition failed for approved membership application ' . absint( $application_id ) . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Builds a minimal membership CRM payload. */
	private static function membership_contact_data( array $form_data, string $email, bool $consent, $existing, int $entry_id ): array {
		$data = array(
			'email'         => $email,
			'custom_values' => array(
				'btusa_contact_interest' => 'membership',
			),
		);
		$first_name = sanitize_text_field( self::field_value( $form_data, 'first_name' ) );
		$last_name  = sanitize_text_field( self::field_value( $form_data, 'last_name' ) );
		if ( '' !== $first_name ) {
			$data['first_name'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$data['last_name'] = $last_name;
		}
		if ( ! $existing ) {
			$data['source'] = 'Fluent Forms: BTUSA Membership Application';
			$data['status'] = $consent ? 'subscribed' : 'transactional';
		} elseif ( $consent && in_array( $existing->status, array( 'pending', 'transactional' ), true ) ) {
			$data['status'] = 'subscribed';
		}
		if ( $consent ) {
			$data['custom_values']['btusa_updates_consent']        = 'yes';
			$data['custom_values']['btusa_updates_consent_at']     = current_time( 'mysql' );
			$data['custom_values']['btusa_updates_consent_source'] = 'Better Together USA membership application';
		}
		if ( $entry_id ) {
			$data['custom_values']['btusa_contact_entry_id']   = (string) $entry_id;
			$data['custom_values']['btusa_contact_entry_date'] = current_time( 'mysql' );
		}

		return $data;
	}

	/**
	 * Build a non-destructive FluentCRM update payload.
	 *
	 * @param array<string,mixed> $form_data Submitted values.
	 * @param string              $email Valid email address.
	 * @param string              $interest Valid interest value.
	 * @param bool                $consent Whether updates consent was explicitly checked.
	 * @param object|false        $existing Existing FluentCRM contact, if any.
	 * @param int                 $insert_id Fluent Forms entry ID.
	 * @return array<string,mixed>
	 */
	private static function contact_data( array $form_data, string $email, string $interest, bool $consent, $existing, int $insert_id ): array {
		$data = array(
			'email'         => $email,
			'custom_values' => array(
				'btusa_contact_interest'   => $interest,
				'btusa_contact_entry_id'   => (string) $insert_id,
				'btusa_contact_entry_date' => current_time( 'mysql' ),
			),
		);

		$first_name = sanitize_text_field( self::field_value( $form_data, 'first_name' ) );
		$last_name  = sanitize_text_field( self::field_value( $form_data, 'last_name' ) );
		$phone      = sanitize_text_field( self::field_value( $form_data, 'phone' ) );

		if ( '' !== $first_name ) {
			$data['first_name'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$data['last_name'] = $last_name;
		}
		if ( '' !== $phone ) {
			$data['phone'] = $phone;
		}

		if ( ! $existing ) {
			$data['source'] = 'Fluent Forms: BTUSA Contact';
			$data['status'] = $consent ? 'subscribed' : 'transactional';
		} elseif ( $consent && in_array( $existing->status, array( 'pending', 'transactional' ), true ) ) {
			$data['status'] = 'subscribed';
		}

		if ( $consent ) {
			$data['custom_values']['btusa_updates_consent']        = 'yes';
			$data['custom_values']['btusa_updates_consent_at']     = current_time( 'mysql' );
			$data['custom_values']['btusa_updates_consent_source'] = 'Better Together USA contact form';
		} elseif ( ! $existing ) {
			$data['custom_values']['btusa_updates_consent'] = 'no';
		}

		return $data;
	}

	/**
	 * Apply Prospect only when the contact has no existing lifecycle classification.
	 *
	 * @param object $contact FluentCRM subscriber model.
	 */
	private static function apply_lifecycle( $contact ): void {
		$lifecycle_ids = \FluentCrm\App\Models\Lists::whereIn( 'title', self::LIFECYCLE_LIST_TITLES )
			->pluck( 'id' )
			->map( 'intval' )
			->toArray();

		$current_ids = $contact->lists->pluck( 'id' )->map( 'intval' )->toArray();
		if ( array_intersect( $lifecycle_ids, $current_ids ) ) {
			return;
		}

		$prospect = \FluentCrm\App\Models\Lists::where( 'title', self::PROSPECT_LIST_TITLE )->first();
		if ( $prospect ) {
			$contact->attachLists( array( (int) $prospect->id ) );
		}
	}

	/** Attaches Member, removes only Prospect, and preserves every other list. */
	private static function apply_member_lifecycle( $contact ): void {
		$member = \FluentCrm\App\Models\Lists::where( 'title', 'Member' )->first();
		if ( $member ) {
			$contact->attachLists( array( (int) $member->id ) );
		}
		$prospect = \FluentCrm\App\Models\Lists::where( 'title', self::PROSPECT_LIST_TITLE )->first();
		if ( $prospect ) {
			$contact->detachLists( array( (int) $prospect->id ) );
		}
	}

	/**
	 * Apply a tag by exact title. Activation ensures required tags exist.
	 *
	 * @param object $contact FluentCRM subscriber model.
	 * @param string $title Exact tag title.
	 */
	private static function apply_tag( $contact, string $title ): void {
		$tag = \FluentCrm\App\Models\Tag::where( 'title', $title )->first();
		if ( $tag ) {
			$contact->attachTags( array( (int) $tag->id ) );
		}
	}

	/**
	 * Ensure required tags and custom fields exist without replacing existing resources.
	 */
	private static function ensure_crm_resources(): void {
		$tags   = array_values( self::INTEREST_TAGS );
		$tags[] = self::WELCOME_TAG_TITLE;
		$tags[] = self::TEST_TAG_TITLE;

		$tag_rows = array();
		foreach ( $tags as $title ) {
			$tag_rows[] = array(
				'title' => $title,
				'slug'  => sanitize_title( $title ),
			);
		}
		FluentCrmApi( 'tags' )->importBulk( $tag_rows );

		$model         = new \FluentCrm\App\Models\CustomContactField();
		$global_fields = $model->getGlobalFields();
		$fields        = isset( $global_fields['fields'] ) && is_array( $global_fields['fields'] ) ? $global_fields['fields'] : array();
		$slugs         = wp_list_pluck( $fields, 'slug' );
		$needed        = array(
			array( 'label' => 'Latest contact interest', 'slug' => 'btusa_contact_interest', 'type' => 'text', 'group' => 'default' ),
			array( 'label' => 'Latest contact form entry ID', 'slug' => 'btusa_contact_entry_id', 'type' => 'number', 'group' => 'default' ),
			array( 'label' => 'Latest contact form entry date', 'slug' => 'btusa_contact_entry_date', 'type' => 'date_time', 'group' => 'default' ),
			array( 'label' => 'BTUSA updates consent', 'slug' => 'btusa_updates_consent', 'type' => 'text', 'group' => 'default' ),
			array( 'label' => 'BTUSA updates consent date', 'slug' => 'btusa_updates_consent_at', 'type' => 'date_time', 'group' => 'default' ),
			array( 'label' => 'BTUSA updates consent source', 'slug' => 'btusa_updates_consent_source', 'type' => 'text', 'group' => 'default' ),
		);

		foreach ( $needed as $field ) {
			if ( ! in_array( $field['slug'], $slugs, true ) ) {
				$fields[] = $field;
			}
		}

		$model->saveGlobalFields( $fields );
	}

	/**
	 * Copy legacy LAPDI settings to their canonical BTUSA names once.
	 */
	private static function migrate_legacy_options(): void {
		$option_pairs = array(
			self::FORM_ID_OPTION     => self::LEGACY_FORM_ID_OPTION,
			self::TEST_MODE_OPTION   => self::LEGACY_TEST_MODE_OPTION,
			self::TEST_EMAILS_OPTION => self::LEGACY_TEST_EMAILS_OPTION,
		);

		foreach ( $option_pairs as $canonical => $legacy ) {
			if ( false !== get_option( $canonical, false ) ) {
				continue;
			}

			$value = get_option( $legacy, false );
			if ( false !== $value ) {
				add_option( $canonical, $value, '', false );
			}
		}
	}

	/**
	 * Read a canonical setting with a legacy fallback during upgrades.
	 *
	 * @param string $canonical Canonical BTUSA option name.
	 * @param string $legacy Legacy LAPDI option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function option_value( string $canonical, string $legacy, $default ) {
		$value = get_option( $canonical, null );
		if ( null !== $value ) {
			return $value;
		}

		return get_option( $legacy, $default );
	}

	/**
	 * Return a scalar field value.
	 *
	 * @param array<string,mixed> $form_data Submitted values.
	 * @param string              $key Field key.
	 */
	private static function field_value( array $form_data, string $key ): string {
		$value = $form_data[ $key ] ?? '';
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) wp_unslash( $value ) : '';
	}

	/**
	 * Determine whether the optional updates checkbox was explicitly checked.
	 *
	 * @param array<string,mixed> $form_data Submitted values.
	 */
	private static function has_marketing_consent( array $form_data ): bool {
		$value = $form_data['updates_consent'] ?? array();
		$value = array_map( 'sanitize_key', (array) $value );

		return in_array( 'yes', $value, true );
	}

	private static function is_test_email( string $email ): bool {
		$emails = array_map(
			'sanitize_email',
			(array) self::option_value( self::TEST_EMAILS_OPTION, self::LEGACY_TEST_EMAILS_OPTION, array() )
		);

		return in_array( strtolower( $email ), array_map( 'strtolower', $emails ), true );
	}

	private static function welcome_is_allowed( string $email ): bool {
		$test_mode = 'yes' === self::option_value( self::TEST_MODE_OPTION, self::LEGACY_TEST_MODE_OPTION, 'yes' );

		return ! $test_mode || self::is_test_email( $email );
	}
}

register_activation_hook( __FILE__, array( 'BTUSA_Contact_Acquisition', 'activate' ) );
BTUSA_Contact_Acquisition::init();
