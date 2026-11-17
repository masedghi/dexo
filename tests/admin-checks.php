<?php
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) { exit( 1 ); }
function dx_assert( bool $ok, string $message ): void { if ( ! $ok ) { WP_CLI::error( $message ); } }
$f = json_decode( file_get_contents( getenv( 'DX_ADMIN_FIXTURE' ) ), true );
wp_set_current_user( $f['users']['dexo_admin_test'] );
$menu = \Dexo\save_menus( 0, array( 'action' => 'create', 'menu_kind' => 'block', 'name' => 'Dexo block fixture' ) );
dx_assert( is_int( $menu ), 'Create block menu.' );
function dx_block_write( int $menu, array $form ): mixed {
	$post = get_post( $menu );
	return \Dexo\save_menus( $menu, wp_slash( $form + array(
		'menu_kind' => 'block', 'revision' => \Dexo\revision( array( $post->post_title, $post->post_content ) ),
	) ) );
}
$result = dx_block_write( $menu, array( 'action' => 'block-item', 'node' => 'new', 'title' => 'Parent', 'url' => home_url( '/' ), 'parent_key' => '', 'position' => '1' ) );
dx_assert( ! is_wp_error( $result ), 'Add first block link.' );
$result = dx_block_write( $menu, array( 'action' => 'block-item', 'node' => 'new', 'title' => 'Child', 'url' => home_url( '/child/' ), 'parent_key' => '0', 'position' => '1' ) );
dx_assert( ! is_wp_error( $result ), 'Nest block link.' );
$blocks = parse_blocks( get_post( $menu )->post_content );
dx_assert( 'core/navigation-submenu' === $blocks[0]['blockName'] && 'Child' === $blocks[0]['innerBlocks'][0]['attrs']['label'], 'Serialize nested navigation.' );
ob_start();
\Dexo\render_block_menu( $menu );
$html = ob_get_clean();
preg_match_all( '~<form\b[^>]*>(.*?)</form>~s', $html, $rendered_forms );
$child_position_is_correct = false;
foreach ( $rendered_forms[1] as $rendered_form ) {
	if ( str_contains( $rendered_form, 'name="node" value="0.0"' ) ) {
		$child_position_is_correct = 1 === preg_match( '~name="position"[^>]*value="1"~', $rendered_form );
	}
}
dx_assert( $child_position_is_correct, 'Display a nested item position among siblings.' );
$result = dx_block_write( $menu, array( 'action' => 'block-item', 'node' => '0', 'title' => 'Cycle', 'url' => home_url( '/' ), 'parent_key' => '0.0', 'position' => '1' ) );
dx_assert( is_wp_error( $result ), 'Reject navigation cycles.' );
$result = dx_block_write( $menu, array( 'action' => 'block-item', 'node' => 'new', 'title' => 'First', 'url' => home_url( '/first/' ), 'parent_key' => '', 'position' => '1' ) );
$blocks = parse_blocks( get_post( $menu )->post_content );
dx_assert( ! is_wp_error( $result ) && 'First' === $blocks[0]['attrs']['label'] && 'Parent' === $blocks[1]['attrs']['label'], 'Order block siblings.' );
$advanced = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Advanced', 'post_content' => '<!-- wp:search /-->' ) );
$original = get_post( $advanced )->post_content;
$result = dx_block_write( $advanced, array( 'action' => 'block-item', 'node' => 'new', 'title' => 'Test', 'url' => home_url( '/' ), 'parent_key' => '', 'position' => '1' ) );
dx_assert( is_wp_error( $result ) && get_post( $advanced )->post_content === $original, 'Preserve advanced navigation blocks.' );

$classic = wp_create_nav_menu( 'Dexo preserved fixture ' . $menu );
$link = wp_update_nav_menu_item( $classic, 0, array( 'menu-item-title' => 'Stored page', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $f['page'], 'menu-item-status' => 'publish', 'menu-item-classes' => 'existing-class', 'menu-item-target' => '_blank' ) );
$result = \Dexo\save_menus( $classic, array(
	'action' => 'item', 'item_id' => (string) $link, 'title' => 'Updated page link', 'parent' => '0', 'position' => '1',
	'revision' => \Dexo\revision( \Dexo\classic_menu_state( $classic ) ),
) );
$item = wp_setup_nav_menu_item( get_post( $link ) );
dx_assert( ! is_wp_error( $result ) && 'post_type' === $item->type && (int) $item->object_id === $f['page'] && in_array( 'existing-class', $item->classes, true ) && '_blank' === $item->target, 'Preserve classic link identity and attributes.' );

$siteurl = get_option( 'siteurl' );
dx_assert( ! str_contains( $siteurl, 'attacker' ), 'Ignore unlisted options.' );
$target = $f['users']['dexo_profile_test'];
dx_assert( ! in_array( 'administrator', get_userdata( $target )->roles, true ), 'Profile save must not promote an account.' );
$default = get_term( get_option( 'default_category' ), 'category' );
$result = \Dexo\save_terms( $default->term_id, array( 'action' => 'delete', 'confirm' => '1', 'revision' => \Dexo\revision( \Dexo\term_values( $default ) ) ), 'categories' );
dx_assert( is_wp_error( $result ), 'Retain default category.' );

$user = get_user_by( 'login', 'dexo_editor_test' );
$user->add_cap( 'list_users' );
$user->add_cap( 'create_users' );
wp_set_current_user( $user->ID );
$result = \Dexo\admin_submit( 'users', 'list_users', 'save_users', 0, array(
	'action' => 'create', 'login' => 'unauthorized-role', 'display_name' => 'Test', 'email' => 'unauthorized-role@example.test', 'role' => 'administrator',
) );
dx_assert( is_wp_error( $result ) && 403 === $result->get_error_data(), 'Reject role assignment without promotion capability.' );
$restrict_default = static function( $roles ) { unset( $roles[ get_option( 'default_role', 'subscriber' ) ] ); return $roles; };
add_filter( 'editable_roles', $restrict_default );
$result = \Dexo\admin_submit( 'users', 'list_users', 'save_users', 0, array(
	'action' => 'create', 'login' => 'blocked-default-role', 'display_name' => 'Test', 'email' => 'blocked-default-role@example.test', 'role' => '',
) );
dx_assert( is_wp_error( $result ) && 403 === $result->get_error_data(), 'Apply editable-role restrictions to the default role.' );
remove_filter( 'editable_roles', $restrict_default );
$user->remove_cap( 'list_users' );
$user->remove_cap( 'create_users' );
dx_assert( \Dexo\valid_menu_url( '/shop/' ) && \Dexo\valid_menu_url( '#contact' ) && \Dexo\valid_menu_url( 'mailto:hello@example.test' ) && ! \Dexo\valid_menu_url( 'javascript:alert(1)' ), 'Accept standard internal and contact links, reject script URLs.' );
echo "Passed block navigation hierarchy, ordering, cycle checks, advanced-block preservation, classic link attributes, default category, and privilege escalation checks.\n";
