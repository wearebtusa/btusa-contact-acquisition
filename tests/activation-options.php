<?php
/**
 * Minimal activation-option regression test without loading WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$btusa_test_options = array(
	'lapdi_contact_acquisition_form_id'     => 77,
	'lapdi_contact_acquisition_test_mode'   => 'no',
	'lapdi_contact_acquisition_test_emails' => array( 'test@example.org' ),
);

function add_action( ...$arguments ): void {}

function register_activation_hook( ...$arguments ): void {}

function get_option( string $name, $default = false ) {
	global $btusa_test_options;

	return array_key_exists( $name, $btusa_test_options ) ? $btusa_test_options[ $name ] : $default;
}

function add_option( string $name, $value, ...$arguments ): bool {
	global $btusa_test_options;

	if ( array_key_exists( $name, $btusa_test_options ) ) {
		return false;
	}

	$btusa_test_options[ $name ] = $value;

	return true;
}

require dirname( __DIR__ ) . '/btusa-contact-acquisition.php';

BTUSA_Contact_Acquisition::activate();

$expected = array(
	'btusa_contact_acquisition_form_id'     => 77,
	'btusa_contact_acquisition_test_mode'   => 'no',
	'btusa_contact_acquisition_test_emails' => array( 'test@example.org' ),
);

foreach ( $expected as $option => $value ) {
	if ( $value !== get_option( $option ) ) {
		fwrite( STDERR, "Legacy option migration failed for {$option}.\n" );
		exit( 1 );
	}
}

$btusa_test_options = array(
	'btusa_contact_acquisition_form_id' => 99,
	'lapdi_contact_acquisition_form_id' => 77,
);

BTUSA_Contact_Acquisition::activate();

if ( 99 !== get_option( 'btusa_contact_acquisition_form_id' ) ) {
	fwrite( STDERR, "Activation replaced a canonical option.\n" );
	exit( 1 );
}

if ( 'yes' !== get_option( 'btusa_contact_acquisition_test_mode' ) ) {
	fwrite( STDERR, "Activation did not enable safe test mode by default.\n" );
	exit( 1 );
}

echo "Activation option migration passed.\n";
