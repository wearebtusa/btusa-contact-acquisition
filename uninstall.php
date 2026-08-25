<?php
/** Removes plugin-owned configuration without deleting FluentCRM records. */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( wp_roles()->role_objects as $role ) {
	if ( $role instanceof WP_Role ) {
		$role->remove_cap( 'manage_btusa_contact_classifications' );
	}
}

delete_option( 'btusa_contact_acquisition_version' );
delete_option( 'btusa_contact_classification_protected_tag_ids' );
delete_option( 'btusa_contact_classification_protected_list_ids' );
delete_metadata( 'user', 0, '_btusa_fluentcrm_contact_id', '', true );
