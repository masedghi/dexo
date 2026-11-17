<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function user_state( \WP_User $user ): array {
	return array( $user->user_email, $user->display_name, $user->first_name, $user->last_name, $user->roles );
}

function editable_roles(): array {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$out = array();
	foreach ( get_editable_roles() as $slug => $role ) { $out[ $slug ] = role_specs()[ $slug ][0] ?? translate_user_role( $role['name'] ); }
	return $out;
}

function render_users( int $id ): void {
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$search = request_text( $_GET, 'q' );
	$query = new \WP_User_Query( array( 'search' => $search ? '*' . $search . '*' : '', 'number' => 20, 'paged' => $page, 'orderby' => 'display_name' ) );
	echo '<section class="surface">';
	echo '<form class="list-toolbar" method="get" action="' . esc_url( panel_url() ) . '">';
	hidden_route( 'users' );
	input_field( 'q', __( 'Search users', 'dexo' ), $search, 'search' );
	echo '<button class="secondary">' . esc_html__( 'Search', 'dexo' ) . '</button></form>';
	echo '<div class="table-wrap"><table class="data-table mobile-records"><thead><tr><th>' . esc_html__( 'Name', 'dexo' ) . '</th><th>' . esc_html__( 'Email', 'dexo' ) . '</th><th>' . esc_html__( 'Roles', 'dexo' ) . '</th></tr></thead><tbody>';
	foreach ( $query->get_results() as $user ) {
		echo '<tr><th><a href="' . esc_url( panel_url( array( 'tab' => 'users', 'id' => $user->ID ) ) ) . '">' . esc_html( $user->display_name ) . '</a></th><td>' . esc_html( $user->user_email ) . '</td><td>' . esc_html( implode( ', ', array_map( static fn( $role ) => role_specs()[ $role ][0] ?? translate_user_role( wp_roles()->roles[ $role ]['name'] ?? $role ), $user->roles ) ) ) . '</td></tr>';
	}
	echo '</tbody></table></div></section>';
	pagination( array( 'tab' => 'users', 'q' => $search ), $page, (int) ceil( $query->get_total() / 20 ) );
	$user = $id ? get_userdata( $id ) : false;
	if ( $id && ( ! $user || ! current_user_can( 'edit_user', $id ) ) ) { return; }
	if ( ! $id && ! current_user_can( 'create_users' ) ) { return; }
	begin_form( 'users', $id, $id ? 'edit' : 'create' );
	echo '<h2>' . esc_html( $id ? __( 'Edit user', 'dexo' ) : __( 'Add user', 'dexo' ) ) . '</h2>';
	if ( $user ) { revision_input( user_state( $user ) ); }
	if ( ! $id ) { input_field( 'login', __( 'Username', 'dexo' ), '', 'text', true ); }
	input_field( 'display_name', __( 'Display name', 'dexo' ), $user ? $user->display_name : '', 'text', true );
	input_field( 'email', __( 'Email', 'dexo' ), $user ? $user->user_email : '', 'email', true );
	input_field( 'first_name', __( 'First name', 'dexo' ), $user ? $user->first_name : '' );
	input_field( 'last_name', __( 'Last name', 'dexo' ), $user ? $user->last_name : '' );
	if ( current_user_can( 'promote_users' ) && ( ! $id || current_user_can( 'promote_user', $id ) ) ) {
		$roles = editable_roles();
		// Keep multiple-role accounts intact unless the operator explicitly replaces them.
		select_field( 'role', __( 'Role', 'dexo' ), '', array( '' => __( 'Keep existing roles / use default', 'dexo' ) ) + $roles );
	}
	end_form( $id ? '' : __( 'Create user and send setup email', 'dexo' ) );
	if ( $id ) {
		begin_form( 'users', $id, 'reset' );
		echo '<p>' . esc_html__( 'Send this user a password reset link.', 'dexo' ) . '</p>';
		end_form( __( 'Send password reset', 'dexo' ) );
	}
}

function save_users( int $id, array $form ): int|\WP_Error|null {
	$action = request_text( $form, 'action' );
	$user = $id ? get_userdata( $id ) : false;
	if ( $id && ( ! $user || ! current_user_can( 'edit_user', $id ) ) ) { return form_error( __( 'You cannot make this change.', 'dexo' ), 403 ); }
	if ( 'reset' === $action && $id ) {
		$result = retrieve_password( $user->user_login );
		return is_wp_error( $result ) ? admin_error( $result ) : null;
	}
	if ( ! in_array( $action, array( 'create', 'edit' ), true ) || ( ! $id && ! current_user_can( 'create_users' ) ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	if ( $user && ( $error = check_revision( $form, user_state( $user ) ) ) ) { return $error; }
	$email = request_text( $form, 'email' );
	$name = request_text( $form, 'display_name' );
	if ( ! is_email( $email ) || '' === $name ) { return form_error( __( 'Enter a name and valid email address.', 'dexo' ) ); }
	$role = request_text( $form, 'role' );
	if ( $role && ( ! current_user_can( 'promote_users' ) || ( $id && ! current_user_can( 'promote_user', $id ) ) || ! isset( editable_roles()[ $role ] ) ) ) {
		return form_error( __( 'You cannot assign this role.', 'dexo' ), 403 );
	}
	if ( $id === get_current_user_id() && $role && $user->roles !== array( $role ) ) {
		return form_error( __( 'Use another administrator account to change your own role.', 'dexo' ) );
	}
	$data = array( 'ID' => $id, 'user_email' => $email, 'display_name' => $name, 'first_name' => request_text( $form, 'first_name' ), 'last_name' => request_text( $form, 'last_name' ) );
	if ( $role ) { $data['role'] = $role; }
	if ( ! $id ) {
		$login = request_text( $form, 'login' );
		if ( ! validate_username( $login ) || '' === $login ) { return form_error( __( 'Enter a valid username.', 'dexo' ) ); }
		$data['user_login'] = $login;
		$data['user_pass'] = wp_generate_password( 32 );
		$data['role'] = $role ?: get_option( 'default_role', 'subscriber' );
		if ( ! isset( editable_roles()[ $data['role'] ] ) ) { return form_error( __( 'You cannot assign this role.', 'dexo' ), 403 ); }
		$result = wp_insert_user( wp_slash( $data ) );
	} else {
		$result = wp_update_user( wp_slash( $data ) );
	}
	if ( is_wp_error( $result ) ) { return admin_error( $result ); }
	if ( ! $id ) { wp_new_user_notification( $result, null, 'user' ); }
	return $result;
}

function render_profile(): void {
	$user = wp_get_current_user();
	begin_form( 'profile' );
	revision_input( user_state( $user ) );
	input_field( 'display_name', __( 'Display name', 'dexo' ), $user->display_name, 'text', true );
	input_field( 'first_name', __( 'First name', 'dexo' ), $user->first_name );
	input_field( 'last_name', __( 'Last name', 'dexo' ), $user->last_name );
	input_field( 'email', __( 'Email', 'dexo' ), $user->user_email, 'email', true );
	input_field( 'password', __( 'New password (leave empty to keep it)', 'dexo' ), '', 'password' );
	input_field( 'password_confirm', __( 'Confirm new password', 'dexo' ), '', 'password' );
	input_field( 'current_password', __( 'Current password (required for email or password changes)', 'dexo' ), '', 'password' );
	$pending = get_user_meta( $user->ID, '_dexo_email_change', true );
	if ( is_array( $pending ) ) { echo '<p class="notice">' . esc_html__( 'An email confirmation is pending.', 'dexo' ) . ' ' . esc_html( $pending['email'] ) . '</p>'; }
	end_form();
}

function save_profile( int $id, array $form ): ?\WP_Error {
	$user = wp_get_current_user();
	if ( $error = check_revision( $form, user_state( $user ) ) ) { return $error; }
	$email = request_text( $form, 'email' );
	$name = request_text( $form, 'display_name' );
	$password = isset( $form['password'] ) && is_string( $form['password'] ) ? wp_unslash( $form['password'] ) : '';
	$current = isset( $form['current_password'] ) && is_string( $form['current_password'] ) ? wp_unslash( $form['current_password'] ) : '';
	if ( ! $name || ! is_email( $email ) || ( email_exists( $email ) && (int) email_exists( $email ) !== $user->ID ) ) {
		return form_error( __( 'Enter a name and unused valid email address.', 'dexo' ) );
	}
	if ( ( $password || $email !== $user->user_email ) && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
		return form_error( __( 'Your current password is incorrect.', 'dexo' ) );
	}
	if ( $password && ( mb_strlen( $password ) < 12 || strlen( $password ) > 4096 || $password !== wp_unslash( $form['password_confirm'] ?? '' ) ) ) {
		return form_error( __( 'Use a password of at least 12 characters and repeat it exactly.', 'dexo' ) );
	}
	$data = array( 'ID' => $user->ID, 'display_name' => $name, 'first_name' => request_text( $form, 'first_name' ), 'last_name' => request_text( $form, 'last_name' ) );
	$data = wp_slash( $data );
	if ( $password ) { $data['user_pass'] = $password; }
	$result = wp_update_user( $data );
	if ( is_wp_error( $result ) ) { return admin_error( $result ); }
	if ( $email !== $user->user_email ) {
		$token = wp_generate_password( 40, false );
		$pending = array( 'email' => $email, 'hash' => wp_hash( $token ), 'expires' => time() + DAY_IN_SECONDS );
		update_user_meta( $user->ID, '_dexo_email_change', $pending );
		$link = panel_url( array( 'tab' => 'profile', 'confirm_email' => $token ) );
		if ( ! wp_mail( $email, __( 'Confirm your email change', 'dexo' ), __( 'Confirm your email change using this link:', 'dexo' ) . "\n" . $link ) ) {
			return form_error( __( 'Your profile was saved, but the confirmation email could not be sent. Try the email change again.', 'dexo' ), 500 );
		}
	}
	return null;
}

function confirm_profile_email(): void {
	$token = request_text( $_GET, 'confirm_email' );
	if ( ! $token || ! is_user_logged_in() ) { return; }
	$user = wp_get_current_user();
	$pending = get_user_meta( $user->ID, '_dexo_email_change', true );
	if ( ! is_array( $pending ) || $pending['expires'] < time() || ! hash_equals( $pending['hash'], wp_hash( $token ) ) ) {
		wp_die( esc_html__( 'This confirmation link is invalid or expired.', 'dexo' ), '', array( 'response' => 400 ) );
	}
	$result = wp_update_user( wp_slash( array( 'ID' => $user->ID, 'user_email' => $pending['email'] ) ) );
	if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
	delete_user_meta( $user->ID, '_dexo_email_change' );
	wp_safe_redirect( panel_url( array( 'tab' => 'profile', 'saved' => '1' ) ) );
	exit;
}
