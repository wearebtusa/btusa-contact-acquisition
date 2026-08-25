<?php
/** Minimal protected-classification policy regression test. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$btusa_policy_options = array(
	'btusa_contact_classification_protected_tag_ids'  => array( 4 ),
	'btusa_contact_classification_protected_list_ids' => array( 8 ),
);

function add_action( ...$arguments ): void {}
function add_shortcode( ...$arguments ): void {}
function register_activation_hook( ...$arguments ): void {}
function absint( $value ): int { return abs( (int) $value ); }
function get_option( string $name, $default = false ) {
	global $btusa_policy_options;
	return $btusa_policy_options[ $name ] ?? $default;
}

require dirname( __DIR__ ) . '/btusa-contact-acquisition.php';

$tags = array(
	(object) array( 'id' => 2, 'title' => 'Consent: BTUSA Updates' ),
	(object) array( 'id' => 4, 'title' => 'Owner Protected' ),
	(object) array( 'id' => 5, 'title' => 'Interest: Volunteer' ),
);
$lists = array(
	(object) array( 'id' => 6, 'title' => 'Prospect' ),
	(object) array( 'id' => 7, 'title' => 'Member' ),
	(object) array( 'id' => 8, 'title' => 'Owner Protected List' ),
	(object) array( 'id' => 9, 'title' => 'Volunteer' ),
);

$protected = BTUSA_Contact_Classification_Admin::protected_ids( $tags, $lists );
sort( $protected['tags'] );
sort( $protected['lists'] );
if ( array( 2, 4 ) !== $protected['tags'] || array( 6, 7, 8 ) !== $protected['lists'] ) {
	fwrite( STDERR, "Mandatory or owner-selected classification protection failed.\n" );
	exit( 1 );
}

echo "Classification protection policy passed.\n";
