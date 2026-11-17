<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function settings_schema( string $tab ): array {
	switch ( $tab ) {
		case 'general':
			$languages = array( '' => 'English (United States)' );
			foreach ( get_available_languages() as $locale ) {
				$languages[ $locale ] = 'fa_IR' === $locale ? 'فارسی' : ( class_exists( '\Locale' ) ? \Locale::getDisplayName( $locale, $locale ) : $locale );
			}
			$zones = array_combine( timezone_identifiers_list(), timezone_identifiers_list() );
			$zones = array( '' => __( 'Keep current UTC offset', 'dexo' ) ) + $zones;
			return array(
				'blogname' => array( __( 'Site title', 'dexo' ), 'text' ),
				'blogdescription' => array( __( 'Tagline', 'dexo' ), 'text' ),
				'WPLANG' => array( __( 'Site language', 'dexo' ), 'select', $languages ),
				'timezone_string' => array( __( 'Timezone', 'dexo' ), 'select', $zones ),
				'date_format' => array( __( 'Date format', 'dexo' ), 'text' ),
				'time_format' => array( __( 'Time format', 'dexo' ), 'text' ),
				'start_of_week' => array( __( 'Week starts on', 'dexo' ), 'select', array( 0 => __( 'Sunday' ), 1 => __( 'Monday' ), 2 => __( 'Tuesday' ), 3 => __( 'Wednesday' ), 4 => __( 'Thursday' ), 5 => __( 'Friday' ), 6 => __( 'Saturday' ) ) ),
			);
		case 'reading':
			return array(
				'show_on_front' => array( __( 'Homepage displays', 'dexo' ), 'select', array( 'posts' => __( 'Latest posts', 'dexo' ), 'page' => __( 'A static page', 'dexo' ) ) ),
				'page_on_front' => array( __( 'Homepage', 'dexo' ), 'select', page_choices() ),
				'page_for_posts' => array( __( 'Posts page', 'dexo' ), 'select', page_choices() ),
				'posts_per_page' => array( __( 'Posts per page', 'dexo' ), 'number', 1, 1000 ),
				'blog_public' => array( __( 'Allow search engines to index this site', 'dexo' ), 'checkbox' ),
			);
		case 'discussion':
			return array(
				'default_comment_status' => array( __( 'Allow comments on new posts', 'dexo' ), 'toggle-status' ),
				'require_name_email' => array( __( 'Comment author must fill out name and email', 'dexo' ), 'checkbox' ),
				'comment_registration' => array( __( 'Users must be registered and logged in to comment', 'dexo' ), 'checkbox' ),
				'comment_moderation' => array( __( 'Comments must be manually approved', 'dexo' ), 'checkbox' ),
				'comment_previously_approved' => array( __( 'Comment author must have a previously approved comment', 'dexo' ), 'checkbox' ),
				'close_comments_for_old_posts' => array( __( 'Automatically close comments on old posts', 'dexo' ), 'checkbox' ),
				'close_comments_days_old' => array( __( 'Close comments after this many days', 'dexo' ), 'number', 1, 3650 ),
				'thread_comments' => array( __( 'Enable threaded comments', 'dexo' ), 'checkbox' ),
				'thread_comments_depth' => array( __( 'Threaded comment levels', 'dexo' ), 'number', 2, 10 ),
				'comments_notify' => array( __( 'Email when a comment is posted', 'dexo' ), 'checkbox' ),
				'moderation_notify' => array( __( 'Email when a comment is held for moderation', 'dexo' ), 'checkbox' ),
				'moderation_keys' => array( __( 'Moderation words (one per line)', 'dexo' ), 'textarea' ),
				'disallowed_keys' => array( __( 'Disallowed words (one per line)', 'dexo' ), 'textarea' ),
			);
		case 'media-settings':
			return array(
				'thumbnail_size_w' => array( __( 'Thumbnail width', 'dexo' ), 'number', 0, 10000 ),
				'thumbnail_size_h' => array( __( 'Thumbnail height', 'dexo' ), 'number', 0, 10000 ),
				'thumbnail_crop' => array( __( 'Crop thumbnails to exact dimensions', 'dexo' ), 'checkbox' ),
				'medium_size_w' => array( __( 'Medium maximum width', 'dexo' ), 'number', 0, 10000 ),
				'medium_size_h' => array( __( 'Medium maximum height', 'dexo' ), 'number', 0, 10000 ),
				'large_size_w' => array( __( 'Large maximum width', 'dexo' ), 'number', 0, 10000 ),
				'large_size_h' => array( __( 'Large maximum height', 'dexo' ), 'number', 0, 10000 ),
				'uploads_use_yearmonth_folders' => array( __( 'Organize uploads into month and year folders', 'dexo' ), 'checkbox' ),
			);
	}
	return array();
}

function settings_state( array $schema ): array {
	$state = array();
	foreach ( $schema as $key => $field ) { $state[ $key ] = get_option( $key ); }
	return $state;
}

function render_settings( int $id, string $tab ): void {
	$schema = settings_schema( $tab );
	begin_form( $tab );
	revision_input( settings_state( $schema ) );
	if ( 'discussion' === $tab ) {
		echo '<p class="muted">' . esc_html__( 'Default comment settings apply to new posts. Existing posts keep their own comment setting.', 'dexo' ) . '</p>';
	}
	if ( 'media-settings' === $tab ) {
		echo '<p class="muted">' . esc_html__( 'Image sizes apply to new uploads. Existing images keep their generated sizes.', 'dexo' ) . '</p>';
	}
	foreach ( $schema as $key => [ $label, $type ] ) {
		$value = get_option( $key );
		switch ( $type ) {
			case 'select':
				select_field( $key, $label, $value, $schema[ $key ][2] );
				break;
			case 'checkbox':
			case 'toggle-status':
				checkbox_field( $key, $label, 'toggle-status' === $type ? 'open' === $value : $value );
				break;
			case 'textarea':
				echo '<label>' . esc_html( $label ) . '<textarea name="' . esc_attr( $key ) . '" rows="4">' . esc_textarea( $value ) . '</textarea></label>';
				break;
			default:
				input_field( $key, $label, $value, $type, 'blogdescription' !== $key );
		}
	}
	end_form();
}

function save_settings( int $id, array $form, string $tab ): ?\WP_Error {
	$schema = settings_schema( $tab );
	if ( $error = check_revision( $form, settings_state( $schema ) ) ) { return $error; }
	$clean = array();
	foreach ( $schema as $key => [ $label, $type ] ) {
		$value = request_text( $form, $key );
		switch ( $type ) {
			case 'checkbox':
				$clean[ $key ] = '1' === $value ? 1 : 0;
				break;
			case 'toggle-status':
				$clean[ $key ] = '1' === $value ? 'open' : 'closed';
				break;
			case 'select':
				if ( ! array_key_exists( $value, $schema[ $key ][2] ) ) { return form_error( __( 'Select a valid option.', 'dexo' ) ); }
				$clean[ $key ] = $value;
				break;
			case 'number':
				$number = ascii_digits( $value );
				if ( ! ctype_digit( $number ) || (int) $number < $schema[ $key ][2] || (int) $number > $schema[ $key ][3] ) {
					return form_error( __( 'Enter a number within the allowed range.', 'dexo' ) );
				}
				$clean[ $key ] = (int) $number;
				break;
			case 'textarea':
				$clean[ $key ] = isset( $form[ $key ] ) && is_string( $form[ $key ] ) ? sanitize_textarea_field( wp_unslash( $form[ $key ] ) ) : '';
				break;
			default:
				if ( '' === $value && 'blogdescription' !== $key ) { return form_error( __( 'Complete all required fields.', 'dexo' ) ); }
				$clean[ $key ] = $value;
		}
	}
	if ( 'reading' === $tab && 'page' === $clean['show_on_front'] && ( ! $clean['page_on_front'] || ( $clean['page_for_posts'] && $clean['page_for_posts'] === $clean['page_on_front'] ) ) ) {
		return form_error( __( 'Select a homepage and a different posts page.', 'dexo' ) );
	}
	foreach ( $clean as $key => $value ) { update_option( $key, $value ); }
	return null;
}

function render_health(): void {
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
	$health = \WP_Site_Health::get_instance();
	global $wp_version, $wpdb;
	echo '<section class="card"><h2>' . esc_html__( 'Environment', 'dexo' ) . '</h2><dl class="facts">';
	foreach ( array( 'WordPress' => $wp_version, 'PHP' => PHP_VERSION, __( 'Database', 'dexo' ) => $wpdb->db_version(), __( 'Theme', 'dexo' ) => wp_get_theme()->get( 'Name' ), __( 'Environment', 'dexo' ) => wp_get_environment_type() ) as $name => $value ) {
		echo '<div><dt>' . esc_html( $name ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}
	echo '</dl></section>';
	foreach ( array( 'php_extensions', 'sql_server', 'php_default_timezone', 'php_sessions', 'is_in_debug_mode', 'available_updates_disk_space', 'http_requests' ) as $test ) {
		$result = $health->{ 'get_test_' . $test }();
		echo '<section class="card"><h3>' . esc_html( $result['label'] ) . '</h3><p class="badge">' . esc_html( $result['status'] ) . '</p><div>' . wp_kses_post( $result['description'] ) . '</div></section>';
	}
	echo '<a class="button secondary" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Open complete Site Health', 'dexo' ) . '</a>';
}
