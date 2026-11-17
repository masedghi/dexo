<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function manageable_comment( int $id ): ?\WP_Comment {
	$comment = get_comment( $id );
	return $comment && in_array( $comment->comment_type, array( '', 'comment', 'review' ), true )
		&& current_user_can( 'edit_comment', $id ) ? $comment : null;
}

function comment_state( \WP_Comment $comment ): array {
	return array( $comment->comment_approved, $comment->comment_content, $comment->comment_post_ID );
}

function render_comments(): void {
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$search = request_text( $_GET, 'q' );
	$status = request_text( $_GET, 'status' );
	$statuses = array( 'all' => __( 'All', 'dexo' ), 'hold' => __( 'Pending', 'dexo' ), 'approve' => __( 'Approved', 'dexo' ), 'spam' => __( 'Spam', 'dexo' ), 'trash' => __( 'Trash', 'dexo' ) );
	$status = isset( $statuses[ $status ] ) ? $status : 'all';
	$args = array( 'type__in' => array( '', 'comment', 'review' ), 'status' => $status, 'search' => $search );
	$comments = get_comments( $args + array( 'number' => 20, 'offset' => ( $page - 1 ) * 20 ) );
	$count = get_comments( $args + array( 'count' => true ) );
	echo '<form class="list-toolbar surface" method="get" action="' . esc_url( panel_url() ) . '">';
	hidden_route( 'comments' );
	input_field( 'q', __( 'Search', 'dexo' ), $search, 'search' );
	select_field( 'status', __( 'Status', 'dexo' ), $status, $statuses );
	echo '<button>' . esc_html__( 'Search', 'dexo' ) . '</button></form>';
	foreach ( $comments as $comment ) {
		if ( ! manageable_comment( $comment->comment_ID ) ) { continue; }
		begin_form( 'comments', $comment->comment_ID, 'moderate' );
		revision_input( comment_state( $comment ) );
		echo '<h3>' . esc_html( $comment->comment_author ) . ' · ' . esc_html( get_the_title( $comment->comment_post_ID ) ) . '</h3>';
		echo '<p>' . nl2br( esc_html( $comment->comment_content ) ) . '</p>';
		$actions = array( 'approve' => __( 'Approve', 'dexo' ), 'hold' => __( 'Hold for moderation', 'dexo' ), 'spam' => __( 'Mark as spam', 'dexo' ), 'trash' => __( 'Move to trash', 'dexo' ) );
		if ( 'spam' === $comment->comment_approved ) { $actions['unspam'] = __( 'Restore from spam', 'dexo' ); }
		if ( 'trash' === $comment->comment_approved ) { $actions['untrash'] = __( 'Restore from trash', 'dexo' ); }
		select_field( 'moderation', __( 'Action', 'dexo' ), '', array( '' => __( 'Select an action', 'dexo' ) ) + $actions );
		end_form( __( 'Apply', 'dexo' ) );
		if ( ! in_array( $comment->comment_approved, array( 'spam', 'trash' ), true ) ) {
			begin_form( 'comments', $comment->comment_ID, 'reply' );
			revision_input( comment_state( $comment ) );
			echo '<label>' . esc_html__( 'Reply', 'dexo' ) . '<textarea name="reply" rows="3" required></textarea></label>';
			end_form( __( 'Send reply', 'dexo' ) );
		}
	}
	pagination( array( 'tab' => 'comments', 'q' => $search, 'status' => $status ), $page, (int) ceil( $count / 20 ) );
}

function save_comments( int $id, array $form ): ?\WP_Error {
	$comment = manageable_comment( $id );
	if ( ! $comment ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
	if ( $error = check_revision( $form, comment_state( $comment ) ) ) { return $error; }
	if ( 'reply' === request_text( $form, 'action' ) ) {
		$reply = isset( $form['reply'] ) && is_string( $form['reply'] ) ? wp_kses_post( wp_unslash( $form['reply'] ) ) : '';
		if ( ! trim( wp_strip_all_tags( $reply ) ) || in_array( $comment->comment_approved, array( 'trash', 'spam' ), true ) ) {
			return form_error( __( 'Enter a reply to an active comment.', 'dexo' ) );
		}
		$user = wp_get_current_user();
		$result = wp_new_comment( wp_slash( array(
			'comment_post_ID' => $comment->comment_post_ID, 'comment_parent' => $id, 'comment_content' => $reply,
			'comment_type' => 'comment', 'user_id' => $user->ID, 'comment_author' => $user->display_name,
			'comment_author_email' => $user->user_email, 'comment_author_url' => $user->user_url,
		) ), true );
		return is_wp_error( $result ) ? admin_error( $result ) : null;
	}
	$action = request_text( $form, 'moderation' );
	if ( ! in_array( $action, array( 'approve', 'hold', 'spam', 'trash', 'unspam', 'untrash' ), true ) ) {
		return form_error( __( 'Select a valid option.', 'dexo' ) );
	}
	$targets = array( 'approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash' );
	if ( isset( $targets[ $action ] ) && $targets[ $action ] === $comment->comment_approved ) { return null; }
	if ( 'unspam' === $action ) { $result = wp_unspam_comment( $id ); }
	elseif ( 'untrash' === $action ) { $result = wp_untrash_comment( $id ); }
	elseif ( 'spam' === $action ) { $result = wp_spam_comment( $id ); }
	elseif ( 'trash' === $action ) { $result = wp_trash_comment( $id ); }
	else { $result = wp_set_comment_status( $id, $action, true ); }
	if ( is_wp_error( $result ) ) { return admin_error( $result ); }
	return $result ? null : form_error( __( 'WordPress could not save this record.', 'dexo' ), 500 );
}

function term_taxonomy( string $tab ): string {
	return 'categories' === $tab ? 'category' : 'post_tag';
}

function term_values( \WP_Term $term ): array {
	return array( $term->name, $term->slug, $term->description, $term->parent );
}

function render_terms( int $id, string $tab ): void {
	$taxonomy = term_taxonomy( $tab );
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$search = request_text( $_GET, 'q' );
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 20, 'offset' => ( $page - 1 ) * 20, 'search' => $search ) );
	echo '<form class="list-toolbar surface" method="get" action="' . esc_url( panel_url() ) . '">';
	hidden_route( $tab );
	input_field( 'q', __( 'Search', 'dexo' ), $search, 'search' );
	echo '<button>' . esc_html__( 'Search', 'dexo' ) . '</button></form>';
	if ( ! is_wp_error( $terms ) ) {
		echo '<section class="surface"><table class="data-table"><thead><tr><th>' . esc_html__( 'Name', 'dexo' ) . '</th><th>' . esc_html__( 'Slug', 'dexo' ) . '</th><th>' . esc_html__( 'Parent category', 'dexo' ) . '</th></tr></thead><tbody>';
		foreach ( $terms as $term ) {
			$parent = $term->parent ? get_term( $term->parent, $taxonomy ) : null;
			echo '<tr><th><a href="' . esc_url( panel_url( array( 'tab' => $tab, 'id' => $term->term_id ) ) ) . '">' . esc_html( $term->name ) . '</a></th><td>' . esc_html( $term->slug ) . '</td><td>' . esc_html( $parent instanceof \WP_Term ? $parent->name : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}
	$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'search' => $search ) );
	pagination( array( 'tab' => $tab, 'q' => $search ), $page, is_wp_error( $count ) ? 1 : (int) ceil( $count / 20 ) );
	$term = $id ? get_term( $id, $taxonomy ) : null;
	if ( $id && ! $term instanceof \WP_Term ) { return; }
	begin_form( $tab, $id, 'save' );
	echo '<h2>' . esc_html( $id ? __( 'Edit term', 'dexo' ) : __( 'Add term', 'dexo' ) ) . '</h2>';
	if ( $term ) { revision_input( term_values( $term ) ); }
	input_field( 'name', __( 'Name', 'dexo' ), $term->name ?? '', 'text', true );
	input_field( 'slug', __( 'Slug', 'dexo' ), $term->slug ?? '' );
	echo '<label>' . esc_html__( 'Description', 'dexo' ) . '<textarea name="description" rows="3">' . esc_textarea( $term->description ?? '' ) . '</textarea></label>';
	if ( 'category' === $taxonomy ) {
		$parents = array( 0 => __( 'None', 'dexo' ) );
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) as $parent ) {
			if ( $id !== $parent->term_id && ( ! $id || ! term_is_ancestor_of( $id, $parent->term_id, $taxonomy ) ) ) { $parents[ $parent->term_id ] = $parent->name; }
		}
		select_field( 'parent', __( 'Parent category', 'dexo' ), $term->parent ?? 0, $parents );
	}
	end_form();
	if ( $term && current_user_can( 'delete_term', $id ) && ( 'category' !== $taxonomy || $id !== (int) get_option( 'default_category' ) ) ) {
		begin_form( $tab, $id, 'delete' );
		revision_input( term_values( $term ) );
		checkbox_field( 'confirm', __( 'Confirm deletion', 'dexo' ), false );
		end_form( __( 'Delete term', 'dexo' ) );
	}
}

function save_terms( int $id, array $form, string $tab ): int|\WP_Error|null {
	$taxonomy = term_taxonomy( $tab );
	$tax = get_taxonomy( $taxonomy );
	$term = $id ? get_term( $id, $taxonomy ) : null;
	if ( $id && ( ! $term instanceof \WP_Term || ! current_user_can( 'edit_term', $id ) ) ) { return form_error( __( 'This record is unavailable.', 'dexo' ), 404 ); }
	if ( ! current_user_can( $tax->cap->manage_terms ) || ( ! $id && ! current_user_can( $tax->cap->edit_terms ) ) ) { return form_error( __( 'You cannot make this change.', 'dexo' ), 403 ); }
	if ( $term && ( $error = check_revision( $form, term_values( $term ) ) ) ) { return $error; }
	if ( 'delete' === request_text( $form, 'action' ) ) {
		if ( ! $id || ! current_user_can( 'delete_term', $id ) || '1' !== request_text( $form, 'confirm' ) || ( 'category' === $taxonomy && $id === (int) get_option( 'default_category' ) ) ) {
			return form_error( __( 'Confirm an allowed deletion.', 'dexo' ), 403 );
		}
		$result = wp_delete_term( $id, $taxonomy );
		return is_wp_error( $result ) ? admin_error( $result ) : ( $result ? 0 : form_error( __( 'WordPress could not save this record.', 'dexo' ), 500 ) );
	}
	$name = request_text( $form, 'name' );
	$parent = 'category' === $taxonomy ? request_id( $form, 'parent' ) : 0;
	if ( '' === $name || ( $parent && ( ! get_term( $parent, $taxonomy ) instanceof \WP_Term || $parent === $id || ( $id && term_is_ancestor_of( $id, $parent, $taxonomy ) ) ) ) ) {
		return form_error( __( 'Enter a name and a valid parent.', 'dexo' ) );
	}
	$data = array( 'name' => $name, 'slug' => sanitize_title( request_text( $form, 'slug' ) ), 'description' => request_text( $form, 'description' ), 'parent' => $parent );
	$result = $id ? wp_update_term( $id, $taxonomy, wp_slash( $data ) ) : wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $data ) );
	return is_wp_error( $result ) ? admin_error( $result ) : (int) $result['term_id'];
}
