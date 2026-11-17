<?php
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) {
	exit( 1 );
}
$users = array();
foreach ( array( 'author', 'editor', 'contributor' ) as $role ) {
	$login = 'dexo_content_' . $role;
	$user = get_user_by( 'login', $login );
	$users[ $role ] = $user ? $user->ID : wp_insert_user( array(
		'user_login' => $login,
		'user_pass' => 'dexo-content-local-test-password',
		'user_email' => $login . '@example.test',
		'role' => $role,
	) );
	if ( is_wp_error( $users[ $role ] ) ) {
		WP_CLI::error( $users[ $role ]->get_error_message() );
	}
}
$fixture = array();
foreach ( array(
	'own' => array( 'post', $users['author'], 'draft', '<p>Draft content with a backslash \\ and a quote.</p>' ),
	'other' => array( 'post', $users['editor'], 'draft', '<p>Other author content</p>' ),
	'published' => array( 'post', $users['author'], 'publish', '<p>Published content</p>' ),
	'contributor' => array( 'post', $users['contributor'], 'draft', '<p>Contributor draft</p>' ),
	'page' => array( 'page', $users['editor'], 'draft', '<p>About the sample shop</p>' ),
	'blocks' => array( 'page', $users['editor'], 'publish', '<!-- wp:paragraph --><p>Existing block content</p><!-- /wp:paragraph -->' ),
) as $key => [ $type, $author, $status, $body ] ) {
	$fixture[ $key ] = wp_insert_post( wp_slash( array(
		'post_type' => $type,
		'post_author' => $author,
		'post_status' => $status,
		'post_title' => 'Dexo content test ' . $key,
		'post_content' => $body,
	) ) );
}
echo wp_json_encode( $fixture ), PHP_EOL;
