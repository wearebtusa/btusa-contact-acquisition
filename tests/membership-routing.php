<?php
/** Minimal membership routing and consent regression tests. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$btusa_membership_crm_calls = 0;

function add_action( ...$arguments ): void {}
function add_shortcode( ...$arguments ): void {}
function register_activation_hook( ...$arguments ): void {}
function apply_filters( string $name, $value ) { return $value; }
function get_option( string $name, $default = false ) { return 'btusa_membership_application_form_id' === $name ? 456 : $default; }
function shortcode_exists( string $name ): bool { return 'fluentform' === $name; }
function do_shortcode( string $shortcode ): string { return 'rendered:' . $shortcode; }
function current_user_can( string $capability ): bool { return false; }
function esc_html__( string $text, string $domain ): string { return $text; }
function sanitize_email( string $value ): string { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
function wp_unslash( $value ) { return $value; }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }

function FluentCrmApi( string $resource ) {
	global $btusa_membership_crm_calls;
	++$btusa_membership_crm_calls;
	return null;
}

require dirname( __DIR__ ) . '/btusa-contact-acquisition.php';

$form = (object) array( 'id' => 456 );
BTUSA_Contact_Acquisition::process_submission(
	12,
	array(
		'first_name' => 'Test',
		'last_name'  => 'Applicant',
		'email'      => 'applicant@example.org',
	),
	$form
);

if ( 0 !== $btusa_membership_crm_calls ) {
	fwrite( STDERR, "A membership application without marketing consent called FluentCRM.\n" );
	exit( 1 );
}

if ( 'rendered:[fluentform id="456"]' !== BTUSA_Contact_Acquisition::membership_form_shortcode() ) {
	fwrite( STDERR, "The membership shortcode did not resolve the configured form ID.\n" );
	exit( 1 );
}

echo "Membership consent and shortcode routing passed.\n";
