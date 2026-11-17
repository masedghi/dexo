<?php
if ( 'local' !== wp_get_environment_type() ) { return; }
add_filter( 'pre_wp_mail', static function( $return, $args ) {
	$emails = (array) $args['to'];
	foreach ( $emails as $email ) {
		if ( ! str_ends_with( $email, '@example.test' ) ) { return $return; }
	}
	update_option( 'dexo_fixture_mail', $args, false );
	return true;
}, 10, 2 );
add_action( 'after_setup_theme', static function() { register_nav_menu( 'dexo-test-primary', 'Dexo primary menu' ); } );
add_filter( 'wp_privacy_personal_data_exporters', static function( $exporters ) {
	if ( ! is_admin() ) { return $exporters; }
	$exporters['dexo-fixture'] = array(
		'exporter_friendly_name' => 'Dexo fixture exporter',
		'callback' => static function( $email, $page ) {
			update_option( 'dexo_fixture_export_page', $page, false );
			return array( 'done' => $page >= 2, 'data' => array( array(
				'group_id' => 'dexo-fixture', 'group_label' => 'Dexo fixture', 'item_id' => 'test-' . $page,
				'data' => array( array( 'name' => 'Fixture', 'value' => 'Sample value' ) ),
			) ) );
		},
	);
	return $exporters;
} );
add_filter( 'wp_privacy_personal_data_erasers', static function( $erasers ) {
	if ( ! is_admin() ) { return $erasers; }
	$erasers['dexo-fixture'] = array(
		'eraser_friendly_name' => 'Dexo fixture eraser',
		'callback' => static function( $email, $page ) {
			update_option( 'dexo_fixture_erase_page', $page, false );
			return array( 'done' => $page >= 2, 'items_removed' => false, 'items_retained' => false, 'messages' => array() );
		},
	);
	return $erasers;
} );
