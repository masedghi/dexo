<?php
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WP_CLI' ) ) {
	exit( 1 );
}
function dexo_assert( bool $ok, string $message ): void {
	if ( ! $ok ) { WP_CLI::error( $message ); }
}
$fixture = json_decode( file_get_contents( getenv( 'DX_CONTENT_FIXTURE' ) ), true );
$author = get_user_by( 'login', 'dexo_content_author' );
$editor = get_user_by( 'login', 'dexo_content_editor' );
wp_set_current_user( $author->ID );
$other = get_post( $fixture['other'] );
$result = \Dexo\save_content_record( 'post', $other->ID, array(
	'title' => 'Unauthorized', 'body' => '<p>Changed</p>', 'status' => 'draft', 'revision' => \Dexo\content_revision( $other ),
) );
dexo_assert( is_wp_error( $result ) && 404 === $result->get_error_data(), 'Author must not edit another author.' );

wp_set_current_user( $editor->ID );
$block = get_post( $fixture['blocks'] );
$result = \Dexo\save_content_record( 'page', $block->ID, array(
	'title' => 'Overwrite blocks', 'body' => '<p>Changed</p>', 'status' => 'publish', 'revision' => \Dexo\content_revision( $block ),
) );
dexo_assert( is_wp_error( $result ) && $block->post_content === get_post( $block->ID )->post_content, 'Block contents must stay unchanged.' );
dexo_assert( null === \Dexo\content_record( 'post', $block->ID ), 'Page IDs must not cross into post handlers.' );
$result = \Dexo\save_content_record( 'post', 0, wp_slash( array(
	'title' => 'Escaped content test', 'body' => "<p>A quote ' and backslash \\</p><script>alert(1)</script>", 'status' => 'draft',
) ) );
dexo_assert( is_int( $result ), 'Editor must create a draft.' );
$body = get_post( $result )->post_content;
dexo_assert( false === strpos( $body, '<script' ) && false !== strpos( $body, '\\' ), 'Sanitize scripts and preserve slashes.' );
echo "Passed object permissions, type boundaries, block preservation, and input sanitization.\n";
