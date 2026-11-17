<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function render_privacy(): void {
	begin_form( 'privacy', 0, 'policy' );
	revision_input( get_option( 'wp_page_for_privacy_policy' ) );
	select_field( 'policy_page', __( 'Privacy policy page', 'dexo' ), get_option( 'wp_page_for_privacy_policy' ), page_choices() );
	end_form();
	$actions = array();
	if ( current_user_can( 'export_others_personal_data' ) ) { $actions['export_personal_data'] = __( 'Export personal data', 'dexo' ); }
	if ( current_user_can( 'erase_others_personal_data' ) && current_user_can( 'delete_users' ) ) { $actions['remove_personal_data'] = __( 'Erase personal data', 'dexo' ); }
	if ( ! $actions ) { return; }
	begin_form( 'privacy', 0, 'request' );
	echo '<h2>' . esc_html__( 'New privacy request', 'dexo' ) . '</h2>';
	input_field( 'email', __( 'Email', 'dexo' ), '', 'email', true );
	select_field( 'request_action', __( 'Request type', 'dexo' ), '', $actions );
	echo '<p class="muted">' . esc_html__( 'WordPress emails a confirmation link to the person before processing.', 'dexo' ) . '</p>';
	end_form( __( 'Send confirmation request', 'dexo' ) );
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$query = new \WP_Query( array( 'post_type' => 'user_request', 'post_status' => array( 'request-pending', 'request-confirmed', 'request-completed', 'request-failed' ), 'posts_per_page' => 20, 'paged' => $page ) );
	foreach ( $query->posts as $post ) {
		$request = wp_get_user_request( $post->ID );
		if ( ! $request || ! isset( $actions[ $request->action_name ] ) ) { continue; }
		echo '<section class="card privacy-request" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"><h3>' . esc_html( $request->email ) . '</h3><p>' . esc_html( $actions[ $request->action_name ] ) . ' · ' . esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ) . '</p>';
		if ( 'request-confirmed' === $post->post_status ) {
			$export = 'export_personal_data' === $request->action_name;
			$action = $export ? 'wp-privacy-export-personal-data' : 'wp-privacy-erase-personal-data';
			echo '<form class="privacy-process edit" data-kind="' . ( $export ? 'exporter' : 'eraser' ) . '" data-discovery="' . esc_attr( wp_create_nonce( 'dexo-privacy-' . $post->ID ) ) . '" data-id="' . esc_attr( $post->ID ) . '" data-action="' . esc_attr( $action ) . '" data-nonce="' . esc_attr( wp_create_nonce( $action . '-' . $post->ID ) ) . '">';
			if ( ! $export ) {
				echo '<p class="notice">' . esc_html__( 'Erasure changes personal data according to WordPress and plugin retention rules. This does not delete the user account.', 'dexo' ) . '</p>';
				echo '<label class="check-field"><input type="checkbox" required><span>' . esc_html__( 'I confirm processing this verified erasure request.', 'dexo' ) . '</span></label>';
			}
			echo '<button>' . esc_html( $export ? __( 'Generate export', 'dexo' ) : __( 'Process erasure', 'dexo' ) ) . '</button>';
			echo '<p class="privacy-progress" role="status" aria-live="polite" data-working="' . esc_attr__( 'Processing…', 'dexo' ) . '" data-done="' . esc_attr__( 'Processing completed.', 'dexo' ) . '" data-error="' . esc_attr__( 'Processing failed. Try again or review the request.', 'dexo' ) . '"></p>';
			echo '<a class="privacy-download button secondary" hidden>' . esc_html__( 'Download export', 'dexo' ) . '</a></form>';
		} elseif ( 'request-pending' === $post->post_status || 'request-failed' === $post->post_status ) {
			begin_form( 'privacy', $post->ID, 'resend' );
			end_form( __( 'Resend confirmation', 'dexo' ) );
		}
		echo '</section>';
	}
	pagination( array( 'tab' => 'privacy' ), $page, $query->max_num_pages );
}

function save_privacy( int $id, array $form ): int|\WP_Error|null {
	$action = request_text( $form, 'action' );
	if ( 'policy' === $action ) {
		if ( $error = check_revision( $form, get_option( 'wp_page_for_privacy_policy' ) ) ) { return $error; }
		$page = request_id( $form, 'policy_page' );
		if ( ! isset( page_choices()[ $page ] ) ) { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
		update_option( 'wp_page_for_privacy_policy', $page );
		return null;
	}
	$request_action = request_text( $form, 'request_action' );
	$existing = $id ? wp_get_user_request( $id ) : false;
	if ( 'resend' === $action && $existing ) { $request_action = $existing->action_name; }
	$export = 'export_personal_data' === $request_action;
	$erase = 'remove_personal_data' === $request_action;
	if ( ! ( $export && current_user_can( 'export_others_personal_data' ) ) && ! ( $erase && current_user_can( 'erase_others_personal_data' ) && current_user_can( 'delete_users' ) ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	if ( 'resend' === $action && $existing && in_array( $existing->status, array( 'request-pending', 'request-failed' ), true ) ) {
		$result = wp_send_user_request( $id );
		return is_wp_error( $result ) ? admin_error( $result ) : null;
	}
	if ( 'request' !== $action ) { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
	$email = request_text( $form, 'email' );
	if ( ! is_email( $email ) ) { return form_error( __( 'Enter a valid email address.', 'dexo' ) ); }
	$request_id = wp_create_user_request( $email, $request_action );
	if ( is_wp_error( $request_id ) ) { return admin_error( $request_id ); }
	$result = wp_send_user_request( $request_id );
	if ( is_wp_error( $result ) ) { return admin_error( $result ); }
	return null;
}

// Native AJAX actions call registered exporters and erasers in bounded pages.
foreach ( array( 'wp-privacy-export-personal-data', 'wp-privacy-erase-personal-data' ) as $action ) {
	add_action( 'wp_ajax_' . $action, static function (): void {
		if ( '1' !== ( $_SERVER['HTTP_X_DEXO_PRIVACY'] ?? '' ) ) { return; }
		$request = wp_get_user_request( request_id( $_POST ) );
		if ( ! $request || 'request-confirmed' !== $request->status ) {
			wp_send_json_error( __( 'Confirm this privacy request before processing.', 'dexo' ), 409 );
		}
	}, 1 );
}

add_action( 'wp_ajax_dexo_privacy_processors', static function (): void {
	$id = request_id( $_POST );
	check_ajax_referer( 'dexo-privacy-' . $id, 'security' );
	$request = wp_get_user_request( $id );
	if ( ! $request || 'request-confirmed' !== $request->status ) {
		wp_send_json_error( __( 'Confirm this privacy request before processing.', 'dexo' ), 409 );
	}
	$export = 'export_personal_data' === $request->action_name;
	$allowed = $export ? current_user_can( 'export_others_personal_data' ) :
		current_user_can( 'erase_others_personal_data' ) && current_user_can( 'delete_users' );
	if ( ! $allowed ) { wp_send_json_error( __( 'You cannot make this change.', 'dexo' ), 403 ); }
	$processors = apply_filters( $export ? 'wp_privacy_personal_data_exporters' : 'wp_privacy_personal_data_erasers', array() );
	if ( ! is_array( $processors ) ) { wp_send_json_error( __( 'Processing failed. Try again or review the request.', 'dexo' ), 500 ); }
	wp_send_json_success( array( 'count' => count( $processors ) ) );
} );
