<?php
/**
 * Restricted FluentCRM classification controls for LAPDI Member Portal users.
 *
 * @package BTUSA_Contact_Acquisition
 */

defined( 'ABSPATH' ) || exit;

final class BTUSA_Contact_Classification_Admin {
	private const PAGE_SLUG = 'btusa-member-crm-classifications';

	private const PROTECTED_TAGS_OPTION = 'btusa_contact_classification_protected_tag_ids';

	private const PROTECTED_LISTS_OPTION = 'btusa_contact_classification_protected_list_ids';

	private const RESULT_TRANSIENT_PREFIX = 'btusa_contact_classification_result_';

	private const PAGE_SIZE = 25;

	private const MAX_BATCH_SIZE = 100;

	/** Registers WordPress administration hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_btusa_update_contact_classifications', array( __CLASS__, 'handle_classification_update' ) );
		add_action( 'admin_post_btusa_save_contact_classification_protection', array( __CLASS__, 'handle_protection_update' ) );
	}

	/** Loads the feature stylesheet and lightweight selection helper only here. */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'users_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'btusa-contact-classifications',
			plugins_url( 'assets/admin-classifications.css', dirname( __DIR__ ) . '/btusa-contact-acquisition.php' ),
			array(),
			BTUSA_Contact_Acquisition::VERSION
		);
		wp_enqueue_script(
			'btusa-contact-classifications',
			plugins_url( 'assets/admin-classifications.js', dirname( __DIR__ ) . '/btusa-contact-acquisition.php' ),
			array(),
			BTUSA_Contact_Acquisition::VERSION,
			true
		);
	}

	/** Adds the restricted screen below WordPress Users. */
	public static function register_menu(): void {
		add_users_page(
			__( 'Member CRM Classifications', 'btusa-contact-acquisition' ),
			__( 'CRM Classifications', 'btusa-contact-acquisition' ),
			BTUSA_Contact_Acquisition::CLASSIFICATION_CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Renders the portal-user classification interface. */
	public static function render_page(): void {
		self::require_classification_capability();

		$portal_roles = self::portal_roles();
		$crm_ready    = self::crm_ready();
		$tags         = $crm_ready ? self::tags() : array();
		$lists        = $crm_ready ? self::lists() : array();
		$protected    = self::protected_ids( $tags, $lists );
		$result       = get_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id() );
		if ( false !== $result ) {
			delete_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id() );
		}

		?>
		<div class="wrap btusa-crm-page">
			<header class="btusa-crm-hero">
				<div>
					<p class="btusa-crm-eyebrow"><?php esc_html_e( 'Member management', 'btusa-contact-acquisition' ); ?></p>
					<h1><?php esc_html_e( 'CRM Classifications', 'btusa-contact-acquisition' ); ?></h1>
					<p class="btusa-crm-lede"><?php esc_html_e( 'Choose portal users, then add or remove approved FluentCRM lists and tags.', 'btusa-contact-acquisition' ); ?></p>
				</div>
				<div class="btusa-crm-safeguard">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<p><strong><?php esc_html_e( 'Lifecycle protected', 'btusa-contact-acquisition' ); ?></strong><br><?php esc_html_e( 'Consent, Prospect, and Member cannot be changed here.', 'btusa-contact-acquisition' ); ?></p>
				</div>
			</header>

			<?php self::render_result_notice( $result ); ?>

			<?php if ( ! $portal_roles ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'LAPDI Member Portal is unavailable or has no configured portal roles.', 'btusa-contact-acquisition' ); ?></p></div>
			<?php elseif ( ! $crm_ready ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'FluentCRM is unavailable. No classifications can be changed.', 'btusa-contact-acquisition' ); ?></p></div>
			<?php else : ?>
				<?php self::render_classification_form( $portal_roles, $tags, $lists, $protected ); ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<?php self::render_protection_form( $tags, $lists, $protected ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles an add/remove operation for one or more explicitly selected users.
	 */
	public static function handle_classification_update(): void {
		self::require_classification_capability();
		check_admin_referer( 'btusa_update_contact_classifications' );

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['user_ids'] ?? array() ) ) ) ) );
		if ( ! $user_ids || count( $user_ids ) > self::MAX_BATCH_SIZE ) {
			wp_die( esc_html__( 'Select between one and 100 portal users.', 'btusa-contact-acquisition' ) );
		}

		$operation = sanitize_key( wp_unslash( $_POST['classification_operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'add', 'remove' ), true ) ) {
			wp_die( esc_html__( 'Select a valid classification operation.', 'btusa-contact-acquisition' ) );
		}

		$tags      = self::tags();
		$lists     = self::lists();
		$protected = self::protected_ids( $tags, $lists );
		$tag_ids   = self::validated_requested_ids( $_POST['tag_ids'] ?? array(), $tags, $protected['tags'] );
		$list_ids  = self::validated_requested_ids( $_POST['list_ids'] ?? array(), $lists, $protected['lists'] );
		if ( ! $tag_ids && ! $list_ids ) {
			wp_die( esc_html__( 'Select at least one permitted tag or list.', 'btusa-contact-acquisition' ) );
		}

		$summary = array(
			'changed'       => 0,
			'unchanged'     => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'skipped_users' => array(),
		);

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User || ! self::user_has_portal_role( $user ) ) {
				++$summary['skipped'];
				continue;
			}

			try {
				$contact = self::contact_for_user( $user, true );
				if ( ! $contact ) {
					++$summary['skipped'];
					$summary['skipped_users'][] = $user->display_name;
					continue;
				}

				self::change_contact_classifications( $contact, $user, 'tag', $tag_ids, $operation, $summary );
				self::change_contact_classifications( $contact, $user, 'list', $list_ids, $operation, $summary );
			} catch ( Throwable $throwable ) {
				++$summary['failed'];
			}
		}

		do_action(
			'btusa_contact_classification_batch_completed',
			array_merge(
				$summary,
				array(
					'actor_user_id' => get_current_user_id(),
					'operation'     => $operation,
					'target_count'  => count( $user_ids ),
				)
			)
		);

		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $summary, 5 * MINUTE_IN_SECONDS );
		self::redirect_to_page();
	}

	/** Saves additional owner-selected protected classifications. */
	public static function handle_protection_update(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change classification protection.', 'btusa-contact-acquisition' ) );
		}
		check_admin_referer( 'btusa_save_contact_classification_protection' );

		$tags    = self::tags();
		$lists   = self::lists();
		$tag_ids = self::validated_requested_ids( $_POST['protected_tag_ids'] ?? array(), $tags, array() );
		$list_ids = self::validated_requested_ids( $_POST['protected_list_ids'] ?? array(), $lists, array() );

		update_option( self::PROTECTED_TAGS_OPTION, $tag_ids, false );
		update_option( self::PROTECTED_LISTS_OPTION, $list_ids, false );
		set_transient(
			self::RESULT_TRANSIENT_PREFIX . get_current_user_id(),
			array( 'protection_saved' => true ),
			5 * MINUTE_IN_SECONDS
		);
		self::redirect_to_page();
	}

	/** Returns mandatory and owner-selected protected IDs. */
	public static function protected_ids( array $tags, array $lists ): array {
		$tag_ids  = array_map( 'absint', (array) get_option( self::PROTECTED_TAGS_OPTION, array() ) );
		$list_ids = array_map( 'absint', (array) get_option( self::PROTECTED_LISTS_OPTION, array() ) );

		foreach ( $tags as $tag ) {
			if ( 'Consent: BTUSA Updates' === $tag->title ) {
				$tag_ids[] = (int) $tag->id;
			}
		}
		foreach ( $lists as $list ) {
			if ( in_array( $list->title, array( 'Prospect', 'Member' ), true ) ) {
				$list_ids[] = (int) $list->id;
			}
		}

		return array(
			'tags'  => array_values( array_unique( array_filter( $tag_ids ) ) ),
			'lists' => array_values( array_unique( array_filter( $list_ids ) ) ),
		);
	}

	/** Renders the main selection and operation form. */
	private static function render_classification_form( array $portal_roles, array $tags, array $lists, array $protected ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$query = new WP_User_Query(
			array(
				'role__in' => array_keys( $portal_roles ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => self::PAGE_SIZE,
				'offset'   => ( $page - 1 ) * self::PAGE_SIZE,
				'search'   => $search ? '*' . $search . '*' : '',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			)
		);
		$users = $query->get_results();
		?>
		<section class="btusa-crm-card btusa-crm-members">
			<div class="btusa-crm-card__header">
				<div>
					<p class="btusa-crm-step"><?php esc_html_e( 'Step 1', 'btusa-contact-acquisition' ); ?></p>
					<h2><?php esc_html_e( 'Select portal users', 'btusa-contact-acquisition' ); ?></h2>
					<p><?php echo esc_html( sprintf( _n( '%d portal user found', '%d portal users found', $query->get_total(), 'btusa-contact-acquisition' ), $query->get_total() ) ); ?></p>
				</div>
				<form class="btusa-crm-search" method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label class="screen-reader-text" for="btusa-member-search"><?php esc_html_e( 'Search portal users', 'btusa-contact-acquisition' ); ?></label>
					<input id="btusa-member-search" name="s" type="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email', 'btusa-contact-acquisition' ); ?>">
					<button class="button"><?php esc_html_e( 'Search', 'btusa-contact-acquisition' ); ?></button>
					<?php if ( $search ) : ?><a class="button-link" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'users.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'btusa-contact-acquisition' ); ?></a><?php endif; ?>
				</form>
			</div>

			<form id="btusa-crm-classification-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="btusa_update_contact_classifications">
			<?php wp_nonce_field( 'btusa_update_contact_classifications' ); ?>
			<div class="btusa-crm-table-wrap">
			<table class="widefat striped btusa-crm-table">
				<thead><tr><td class="check-column"><input id="btusa-select-all-users" type="checkbox" aria-label="<?php esc_attr_e( 'Select all users on this page', 'btusa-contact-acquisition' ); ?>"></td><th><?php esc_html_e( 'Portal user', 'btusa-contact-acquisition' ); ?></th><th><?php esc_html_e( 'CRM status', 'btusa-contact-acquisition' ); ?></th><th><?php esc_html_e( 'Current lists', 'btusa-contact-acquisition' ); ?></th><th><?php esc_html_e( 'Current tags', 'btusa-contact-acquisition' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) : $contact = self::contact_for_user( $user, false ); ?>
					<tr>
						<th class="check-column"><input class="btusa-user-checkbox" type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $user->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'btusa-contact-acquisition' ), $user->display_name ) ); ?>"></th>
						<td><strong class="btusa-crm-user-name"><?php echo esc_html( $user->display_name ); ?></strong><span class="btusa-crm-email"><?php echo esc_html( $user->user_email ); ?></span></td>
						<td><span class="btusa-crm-status btusa-crm-status--<?php echo esc_attr( $contact ? sanitize_html_class( $contact->status ) : 'missing' ); ?>"><?php echo esc_html( $contact ? ucfirst( $contact->status ) : __( 'Not connected', 'btusa-contact-acquisition' ) ); ?></span></td>
						<td><?php $contact ? self::render_relationship_badges( $contact->lists, 'list' ) : self::render_empty_value(); ?></td>
						<td><?php $contact ? self::render_relationship_badges( $contact->tags, 'tag' ) : self::render_empty_value(); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $users ) : ?><tr><td class="btusa-crm-empty-row" colspan="5"><?php esc_html_e( 'No portal users matched your search.', 'btusa-contact-acquisition' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
			</div>

			<?php self::render_pagination( $query, $page, $search ); ?>

			<div class="btusa-crm-action-panel">
				<div class="btusa-crm-card__header btusa-crm-action-heading">
					<div><p class="btusa-crm-step"><?php esc_html_e( 'Step 2', 'btusa-contact-acquisition' ); ?></p><h2><?php esc_html_e( 'Choose the change', 'btusa-contact-acquisition' ); ?></h2></div>
					<p class="btusa-crm-selection-count" aria-live="polite"><strong id="btusa-selected-user-count">0</strong> <?php esc_html_e( 'users selected', 'btusa-contact-acquisition' ); ?></p>
				</div>
				<div class="btusa-crm-operation" role="radiogroup" aria-label="<?php esc_attr_e( 'Classification operation', 'btusa-contact-acquisition' ); ?>">
					<label><input type="radio" name="classification_operation" value="add" required><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><strong><?php esc_html_e( 'Add', 'btusa-contact-acquisition' ); ?></strong><small><?php esc_html_e( 'Attach the chosen classifications', 'btusa-contact-acquisition' ); ?></small></label>
					<label><input type="radio" name="classification_operation" value="remove" required><span class="dashicons dashicons-minus" aria-hidden="true"></span><strong><?php esc_html_e( 'Remove', 'btusa-contact-acquisition' ); ?></strong><small><?php esc_html_e( 'Detach the chosen classifications', 'btusa-contact-acquisition' ); ?></small></label>
				</div>
				<div class="btusa-crm-resource-grid">
					<?php self::render_resource_checkboxes( __( 'Lists', 'btusa-contact-acquisition' ), 'list_ids', $lists, $protected['lists'], false ); ?>
					<?php self::render_resource_checkboxes( __( 'Tags', 'btusa-contact-acquisition' ), 'tag_ids', $tags, $protected['tags'], false ); ?>
				</div>
				<div class="btusa-crm-submit"><p><?php esc_html_e( 'Only the selected users and classifications will be changed.', 'btusa-contact-acquisition' ); ?></p><?php submit_button( __( 'Apply Changes', 'btusa-contact-acquisition' ), 'primary', 'submit', false ); ?></div>
			</div>
		</form>
		</section>
		<?php
	}

	/** Renders owner-only additional protection controls. */
	private static function render_protection_form( array $tags, array $lists, array $protected ): void {
		$mandatory = self::mandatory_ids( $tags, $lists );
		?>
		<details class="btusa-crm-card btusa-crm-owner-settings">
			<summary><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Owner settings', 'btusa-contact-acquisition' ); ?></strong><small><?php esc_html_e( 'Protect additional classifications from chapter-administrator changes', 'btusa-contact-acquisition' ); ?></small></span></summary>
			<div class="btusa-crm-owner-settings__content">
				<p><?php esc_html_e( 'Protected classifications remain visible as current CRM state but cannot be added or removed from this interface. Consent, Prospect, and Member are always protected.', 'btusa-contact-acquisition' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="btusa_save_contact_classification_protection">
					<?php wp_nonce_field( 'btusa_save_contact_classification_protection' ); ?>
					<div class="btusa-crm-resource-grid">
						<?php self::render_resource_checkboxes( __( 'Protected lists', 'btusa-contact-acquisition' ), 'protected_list_ids', $lists, $protected['lists'], true, $mandatory['lists'] ); ?>
						<?php self::render_resource_checkboxes( __( 'Protected tags', 'btusa-contact-acquisition' ), 'protected_tag_ids', $tags, $protected['tags'], true, $mandatory['tags'] ); ?>
					</div>
					<?php submit_button( __( 'Save Protection Settings', 'btusa-contact-acquisition' ), 'secondary' ); ?>
				</form>
			</div>
		</details>
		<?php
	}

	/** Renders a compact checkbox group for tags or lists. */
	private static function render_resource_checkboxes( string $heading, string $name, array $resources, array $selected_ids, bool $show_protected, array $mandatory_ids = array() ): void {
		$visible_count = 0;
		foreach ( $resources as $resource ) {
			if ( $show_protected || ! in_array( (int) $resource->id, $selected_ids, true ) ) {
				++$visible_count;
			}
		}
		?>
		<fieldset class="btusa-crm-resource-group"><legend><strong><?php echo esc_html( $heading ); ?></strong><span><?php echo esc_html( sprintf( _n( '%d option', '%d options', $visible_count, 'btusa-contact-acquisition' ), $visible_count ) ); ?></span></legend>
			<div class="btusa-crm-resource-options">
			<?php foreach ( $resources as $resource ) : ?>
				<?php
				$id        = (int) $resource->id;
				$protected = in_array( $id, $selected_ids, true );
				$mandatory = in_array( $id, $mandatory_ids, true );
				if ( ! $show_protected && $protected ) {
					continue;
				}
				?>
				<label class="btusa-crm-resource-option<?php echo $mandatory ? ' is-required' : ''; ?>"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $show_protected && $protected ); ?> <?php disabled( $mandatory ); ?>><span><?php echo esc_html( $resource->title ); ?><?php if ( $mandatory ) : ?> <small><?php esc_html_e( 'Always protected', 'btusa-contact-acquisition' ); ?></small><?php endif; ?></span></label>
			<?php endforeach; ?>
			<?php if ( 0 === $visible_count ) : ?><p class="btusa-crm-no-options"><?php esc_html_e( 'No classifications are available in this group.', 'btusa-contact-acquisition' ); ?></p><?php endif; ?>
			</div>
		</fieldset>
		<?php
	}

	/** Applies a validated classification diff and emits one hook per change. */
	private static function change_contact_classifications( $contact, WP_User $user, string $type, array $requested_ids, string $operation, array &$summary ): void {
		if ( ! $requested_ids ) {
			return;
		}

		$relationship = 'tag' === $type ? $contact->tags : $contact->lists;
		$current_ids  = array_map( 'intval', $relationship->pluck( 'id' )->toArray() );
		$change_ids   = 'add' === $operation ? array_diff( $requested_ids, $current_ids ) : array_intersect( $requested_ids, $current_ids );
		$summary['unchanged'] += count( $requested_ids ) - count( $change_ids );

		if ( ! $change_ids ) {
			return;
		}

		$method = 'tag' === $type
			? ( 'add' === $operation ? 'attachTags' : 'detachTags' )
			: ( 'add' === $operation ? 'attachLists' : 'detachLists' );
		$contact->{$method}( array_values( $change_ids ) );
		$relationship = 'tag' === $type ? $contact->tags : $contact->lists;
		$final_ids    = array_map( 'intval', $relationship->pluck( 'id' )->toArray() );
		$successful   = 'add' === $operation ? array_intersect( $change_ids, $final_ids ) : array_diff( $change_ids, $final_ids );
		$summary['failed'] += count( $change_ids ) - count( $successful );

		foreach ( $successful as $classification_id ) {
			++$summary['changed'];
			do_action(
				'btusa_contact_classification_changed',
				array(
					'actor_user_id'       => get_current_user_id(),
					'target_user_id'      => (int) $user->ID,
					'crm_contact_id'      => (int) $contact->id,
					'classification_type' => $type,
					'classification_id'   => (int) $classification_id,
					'operation'           => $operation,
					'result'              => 'changed',
				)
			);
		}
	}

	/** Returns a FluentCRM contact mapped to a portal user. */
	private static function contact_for_user( WP_User $user, bool $backfill ) {
		if ( ! self::crm_ready() ) {
			return false;
		}

		$contact_id = absint( get_user_meta( $user->ID, BTUSA_Contact_Acquisition::CRM_CONTACT_USER_META, true ) );
		$contact    = $contact_id ? \FluentCrm\App\Models\Subscriber::find( $contact_id ) : false;
		if ( ! $contact && is_email( $user->user_email ) ) {
			$contact = FluentCrmApi( 'contacts' )->getContact( $user->user_email );
			if ( $contact && $backfill ) {
				update_user_meta( $user->ID, BTUSA_Contact_Acquisition::CRM_CONTACT_USER_META, (int) $contact->id );
			}
		}

		return $contact ?: false;
	}

	/** Returns only known, existing, non-protected resource IDs. */
	private static function validated_requested_ids( $raw_ids, array $resources, array $protected_ids ): array {
		$requested = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw_ids ) ) ) );
		$existing  = array_map( static fn( $resource ) => (int) $resource->id, $resources );

		return array_values( array_diff( array_intersect( $requested, $existing ), $protected_ids ) );
	}

	/** Returns mandatory protected resource IDs without owner additions. */
	private static function mandatory_ids( array $tags, array $lists ): array {
		$tag_ids  = array();
		$list_ids = array();
		foreach ( $tags as $tag ) {
			if ( 'Consent: BTUSA Updates' === $tag->title ) {
				$tag_ids[] = (int) $tag->id;
			}
		}
		foreach ( $lists as $list ) {
			if ( in_array( $list->title, array( 'Prospect', 'Member' ), true ) ) {
				$list_ids[] = (int) $list->id;
			}
		}

		return array( 'tags' => $tag_ids, 'lists' => $list_ids );
	}

	/** Returns configured portal roles from LAPDI Member Portal. */
	private static function portal_roles(): array {
		return function_exists( 'lapdi_portal_roles' ) ? (array) lapdi_portal_roles() : array();
	}

	/** Returns whether a user currently belongs to any configured portal role. */
	private static function user_has_portal_role( WP_User $user ): bool {
		return (bool) array_intersect( array_keys( self::portal_roles() ), (array) $user->roles );
	}

	/** Returns existing FluentCRM tags. */
	private static function tags(): array {
		return self::crm_ready() ? \FluentCrm\App\Models\Tag::orderBy( 'title', 'ASC' )->get()->all() : array();
	}

	/** Returns existing FluentCRM lists. */
	private static function lists(): array {
		return self::crm_ready() ? \FluentCrm\App\Models\Lists::orderBy( 'title', 'ASC' )->get()->all() : array();
	}

	/** Returns whether the required FluentCRM runtime is available. */
	private static function crm_ready(): bool {
		return function_exists( 'FluentCrmApi' )
			&& class_exists( '\\FluentCrm\\App\\Models\\Subscriber' )
			&& class_exists( '\\FluentCrm\\App\\Models\\Tag' )
			&& class_exists( '\\FluentCrm\\App\\Models\\Lists' );
	}

	/** Renders FluentCRM relationship titles as compact badges. */
	private static function render_relationship_badges( $relationship, string $type ): void {
		$titles = $relationship->pluck( 'title' )->toArray();
		if ( ! $titles ) {
			self::render_empty_value();
			return;
		}

		echo '<div class="btusa-crm-badges">';
		foreach ( $titles as $title ) {
			echo '<span class="btusa-crm-badge btusa-crm-badge--' . esc_attr( $type ) . '">' . esc_html( (string) $title ) . '</span>';
		}
		echo '</div>';
	}

	/** Renders a consistent empty table value. */
	private static function render_empty_value(): void {
		echo '<span class="btusa-crm-empty-value">—</span>';
	}

	/** Displays a result notice retained only for redirect-after-post. */
	private static function render_result_notice( $result ): void {
		if ( ! is_array( $result ) ) {
			return;
		}
		if ( ! empty( $result['protection_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Classification protection settings saved.', 'btusa-contact-acquisition' ) . '</p></div>';
			return;
		}

		$message = sprintf(
			/* translators: 1: changed count, 2: unchanged count, 3: skipped count, 4: failed count. */
			__( 'Classification operation completed: %1$d changed, %2$d already in the requested state, %3$d skipped, %4$d failed.', 'btusa-contact-acquisition' ),
			absint( $result['changed'] ?? 0 ),
			absint( $result['unchanged'] ?? 0 ),
			absint( $result['skipped'] ?? 0 ),
			absint( $result['failed'] ?? 0 )
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p>';
		if ( ! empty( $result['skipped_users'] ) ) {
			echo '<p>' . esc_html__( 'No matching CRM contact:', 'btusa-contact-acquisition' ) . ' ' . esc_html( implode( ', ', array_map( 'sanitize_text_field', $result['skipped_users'] ) ) ) . '</p>';
		}
		echo '</div>';
	}

	/** Renders native WordPress pagination. */
	private static function render_pagination( WP_User_Query $query, int $page, string $search ): void {
		$total_pages = (int) ceil( $query->get_total() / self::PAGE_SIZE );
		if ( $total_pages < 2 ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 's' => $search, 'paged' => '%#%' ), admin_url( 'users.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** Enforces the feature capability on rendered and state-changing requests. */
	private static function require_classification_capability(): void {
		if ( ! current_user_can( BTUSA_Contact_Acquisition::CLASSIFICATION_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM classifications.', 'btusa-contact-acquisition' ) );
		}
	}

	/** Redirects to the feature page after a state-changing request. */
	private static function redirect_to_page(): void {
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'users.php' ) ) );
		exit;
	}
}
