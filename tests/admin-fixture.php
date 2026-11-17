<?php
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) { exit( 1 ); }
$users = array();
foreach ( array( 'dexo_admin_test' => 'administrator', 'dexo_editor_test' => 'editor', 'dexo_profile_test' => 'subscriber' ) as $login => $role ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) { wp_update_user( array( 'ID' => $user->ID, 'user_pass' => 'Dexo-admin-fixture-local-123!', 'user_email' => $login . '@example.test' ) ); }
	$users[ $login ] = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'Dexo-admin-fixture-local-123!', 'user_email' => $login . '@example.test', 'role' => $role ) );
}
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Dexo homepage sample', 'post_author' => $users['dexo_admin_test'] ) );
$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Dexo discussion sample', 'post_author' => $users['dexo_admin_test'], 'comment_status' => 'open' ) );
$comment = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Test reader', 'comment_author_email' => 'reader-' . $page . '@example.test', 'comment_content' => 'A sample comment', 'comment_approved' => 0 ) );
$export = wp_create_user_request( 'export-' . $page . '@example.test', 'export_personal_data' );
$erase = wp_create_user_request( 'reader-' . $page . '@example.test', 'remove_personal_data' );
foreach ( array( $export, $erase ) as $request ) {
	if ( is_wp_error( $request ) ) { WP_CLI::error( $request->get_error_message() ); }
	wp_update_post( array( 'ID' => $request, 'post_status' => 'request-confirmed' ) );
}
echo wp_json_encode( array( 'users' => $users, 'page' => $page, 'post' => $post, 'comment' => $comment, 'export' => $export, 'erase' => $erase ) ), PHP_EOL;
