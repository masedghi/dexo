<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

foreach ( array( 'settings', 'users', 'community', 'menus', 'privacy' ) as $module ) {
	require_once __DIR__ . '/' . $module . '.php';
}

function admin_sections(): array {
	$definitions = array(
		'profile' => array( __( 'My account', 'dexo' ), 'read', 'render_profile', 'save_profile' ),
		'users' => array( __( 'Users', 'dexo' ), 'list_users', 'render_users', 'save_users' ),
		'comments' => array( __( 'Comments', 'dexo' ), 'moderate_comments', 'render_comments', 'save_comments' ),
		'categories' => array( __( 'Categories', 'dexo' ), 'manage_categories', 'render_terms', 'save_terms' ),
		'tags' => array( __( 'Tags', 'dexo' ), 'manage_categories', 'render_terms', 'save_terms' ),
		'menus' => array( __( 'Menus', 'dexo' ), 'edit_theme_options', 'render_menus', 'save_menus' ),
		'general' => array( __( 'General settings', 'dexo' ), 'manage_options', 'render_settings', 'save_settings' ),
		'reading' => array( __( 'Reading settings', 'dexo' ), 'manage_options', 'render_settings', 'save_settings' ),
		'discussion' => array( __( 'Discussion settings', 'dexo' ), 'manage_options', 'render_settings', 'save_settings' ),
		'media-settings' => array( __( 'Media settings', 'dexo' ), 'manage_options', 'render_settings', 'save_settings' ),
		'health' => array( __( 'Site health', 'dexo' ), 'view_site_health_checks', 'render_health', null ),
		'privacy' => array( __( 'Privacy', 'dexo' ), 'manage_privacy_options', 'render_privacy', 'save_privacy' ),
	);
	$out = array();
	foreach ( $definitions as $tab => [ $label, $capability, $render, $submit ] ) {
		$out[ $tab ] = array(
			'label' => $label, 'capability' => $capability, 'group' => __( 'Site management', 'dexo' ),
			'render' => static fn( int $id ) => ( __NAMESPACE__ . '\\' . $render )( $id, $tab ),
		);
		if ( $submit ) {
			$out[ $tab ]['submit'] = static fn( int $id, array $form ) => admin_submit( $tab, $capability, $submit, $id, $form );
		}
	}
	return $out;
}

function admin_submit( string $tab, string $capability, string $submit, int $id, array $form ): int|\WP_Error|null {
	if ( ! current_user_can( $capability ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	$lock = 'dexo_admin_lock_' . $tab;
	if ( ! add_option( $lock, '1', '', false ) ) {
		return form_error( __( 'This record is being updated. Try again in a moment.', 'dexo' ), 409 );
	}
	try {
		return ( __NAMESPACE__ . '\\' . $submit )( $id, $form, $tab );
	} finally {
		delete_option( $lock );
	}
}

function revision( mixed $state ): string {
	return hash( 'sha256', serialize( $state ) );
}

function check_revision( array $form, mixed $state ): ?\WP_Error {
	return hash_equals( revision( $state ), request_text( $form, 'revision' ) )
		? null : form_error( __( 'This record changed. Review it and try again.', 'dexo' ), 409 );
}

function revision_input( mixed $state ): void {
	echo '<input type="hidden" name="revision" value="' . esc_attr( revision( $state ) ) . '">';
}

function begin_form( string $tab, int $id = 0, string $action = 'save' ): void {
	echo '<form class="card edit admin-form" method="post" action="' . esc_url( panel_url( array( 'tab' => $tab, 'id' => $id ) ) ) . '">';
	edit_form_nonce( $tab, $id );
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
}

function end_form( string $label = '' ): void {
	echo '<button>' . esc_html( $label ?: __( 'Save changes', 'dexo' ) ) . '</button></form>';
}

function input_field( string $key, string $label, mixed $value, string $type = 'text', bool $required = false ): void {
	echo '<label>' . esc_html( $label ) . '<input name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '"' . ( $required ? ' required' : '' ) . '></label>';
}

function select_field( string $key, string $label, mixed $value, array $choices ): void {
	echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $key ) . '">';
	foreach ( $choices as $choice => $text ) {
		echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( (string) $value, (string) $choice, false ) . '>' . esc_html( $text ) . '</option>';
	}
	echo '</select></label>';
}

function checkbox_field( string $key, string $label, mixed $value ): void {
	echo '<label class="check-field"><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
}

function admin_error( \WP_Error $error ): \WP_Error {
	return form_error( wp_strip_all_tags( $error->get_error_message() ) );
}

function page_choices(): array {
	$choices = array( 0 => __( 'Select a page', 'dexo' ) );
	foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 0 ) ) as $page ) {
		$choices[ $page->ID ] = $page->post_title ?: '#' . $page->ID;
	}
	return $choices;
}
