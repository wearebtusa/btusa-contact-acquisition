<?php
/** Minimal newsletter double-opt-in and lifecycle regression test. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

eval( 'namespace FluentForm\\App\\Helpers; class Helper { public static function getFormMeta($form_id, $key, $default = array()) { return array("status" => "yes"); } }' );

final class BTUSA_Test_Collection {
	public function __construct( private array $items = array() ) {}
	public function pluck( string $key ): self { return new self( array_map( static fn( $item ) => is_object( $item ) ? $item->{$key} : $item, $this->items ) ); }
	public function map( string $callback ): self { return new self( array_map( $callback, $this->items ) ); }
	public function toArray(): array { return $this->items; }
}

final class BTUSA_Test_Query {
	public function __construct( private string $type, private string $title = '' ) {}
	public function pluck( string $key ): BTUSA_Test_Collection { return new BTUSA_Test_Collection( array() ); }
	public function first() { return (object) array( 'id' => 'list' === $this->type ? 10 : 20, 'title' => $this->title ); }
}

eval( 'namespace FluentCrm\\App\\Models; class Lists { public static function whereIn($key, $values) { return new \\BTUSA_Test_Query("list"); } public static function where($key, $value) { return new \\BTUSA_Test_Query("list", $value); } } class Tag { public static function where($key, $value) { return new \\BTUSA_Test_Query("tag", $value); } }' );

final class BTUSA_Test_Contact {
	public int $id = 42;
	public string $status;
	public BTUSA_Test_Collection $lists;
	public array $attached_lists = array();
	public array $attached_tags = array();

	public function __construct( string $status ) {
		$this->status = $status;
		$this->lists = new BTUSA_Test_Collection();
	}
	public function attachLists( array $ids ): void { $this->attached_lists = array_merge( $this->attached_lists, $ids ); }
	public function attachTags( array $ids ): void { $this->attached_tags = array_merge( $this->attached_tags, $ids ); }
}

final class BTUSA_Test_Contacts_Api {
	public $existing = false;
	public array $last_data = array();
	public int $updates = 0;
	public $last_contact = false;

	public function getContact( string $email ) { return $this->existing; }
	public function createOrUpdate( array $data, ...$arguments ) {
		$this->last_data = $data;
		++$this->updates;
		$status = $data['status'] ?? ( $this->existing ? $this->existing->status : 'transactional' );
		$this->last_contact = new BTUSA_Test_Contact( $status );
		return $this->last_contact;
	}
}

$btusa_newsletter_api = new BTUSA_Test_Contacts_Api();
$btusa_newsletter_options = array(
	'btusa_newsletter_form_id'        => 5,
	'_fluentform_double_optin_settings' => array( 'enabled' => 'yes' ),
	'btusa_contact_acquisition_test_mode' => 'no',
);

function add_action( ...$arguments ): void {}
function add_shortcode( ...$arguments ): void {}
function register_activation_hook( ...$arguments ): void {}
function apply_filters( string $name, $value ) { return $value; }
function get_option( string $name, $default = false ) { global $btusa_newsletter_options; return $btusa_newsletter_options[ $name ] ?? $default; }
function sanitize_email( string $value ): string { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
function sanitize_title( string $value ): string { return strtolower( str_replace( ' ', '-', $value ) ); }
function wp_unslash( $value ) { return $value; }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function current_time( string $type ): string { return '2026-08-25 12:00:00'; }
function absint( $value ): int { return abs( (int) $value ); }
function do_action( ...$arguments ): void {}
function FluentCrmApi( string $resource ) { global $btusa_newsletter_api; return $btusa_newsletter_api; }

require dirname( __DIR__ ) . '/btusa-contact-acquisition.php';

$form = (object) array( 'id' => 5 );
$data = array( 'first_name' => 'News', 'email' => 'news@example.org', 'marketing_consent' => array( 'yes' ) );
BTUSA_Contact_Acquisition::process_submission( 12, $data, $form );

if ( 1 !== $btusa_newsletter_api->updates || 'subscribed' !== $btusa_newsletter_api->last_data['status'] ) {
	fwrite( STDERR, "Confirmed newsletter signup was not subscribed.\n" );
	exit( 1 );
}
if ( array( 10 ) !== $btusa_newsletter_api->last_contact->attached_lists || array( 20 ) !== $btusa_newsletter_api->last_contact->attached_tags ) {
	fwrite( STDERR, "Newsletter Prospect or welcome classification was not applied.\n" );
	exit( 1 );
}

$btusa_newsletter_options['_fluentform_double_optin_settings']['enabled'] = 'no';
BTUSA_Contact_Acquisition::process_submission( 13, $data, $form );
if ( 1 !== $btusa_newsletter_api->updates ) {
	fwrite( STDERR, "Newsletter routing did not fail closed without double opt-in.\n" );
	exit( 1 );
}

$btusa_newsletter_options['_fluentform_double_optin_settings']['enabled'] = 'yes';
$btusa_newsletter_api->existing = new BTUSA_Test_Contact( 'unsubscribed' );
BTUSA_Contact_Acquisition::process_submission( 14, $data, $form );
if ( 'subscribed' !== $btusa_newsletter_api->last_data['status'] ) {
	fwrite( STDERR, "Fresh confirmed consent did not restore an ordinary unsubscribe.\n" );
	exit( 1 );
}

$btusa_newsletter_api->existing = new BTUSA_Test_Contact( 'bounced' );
BTUSA_Contact_Acquisition::process_submission( 15, $data, $form );
if ( isset( $btusa_newsletter_api->last_data['status'] ) || 'bounced' !== $btusa_newsletter_api->last_contact->status ) {
	fwrite( STDERR, "Newsletter routing revived a bounced contact.\n" );
	exit( 1 );
}

echo "Newsletter routing policy passed.\n";
