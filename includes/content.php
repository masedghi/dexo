<?php

namespace Dexo;

defined( 'ABSPATH' ) || exit;

function content_sections(): array {
	$definitions = array(
		'posts' => array( 'post', __( 'Posts', 'dexo' ), __( 'Post', 'dexo' ), __( 'New post', 'dexo' ), 'edit_posts' ),
		'pages' => array( 'page', __( 'Pages', 'dexo' ), __( 'Page', 'dexo' ), __( 'New page', 'dexo' ), 'edit_pages' ),
		'media' => array( 'attachment', __( 'Media library', 'dexo' ), __( 'Media file', 'dexo' ), __( 'Upload media', 'dexo' ), 'upload_files' ),
	);
	$sections = array();
	foreach ( $definitions as $tab => [ $type, $label, $record_label, $create_label, $capability ] ) {
		$sections[ $tab ] = array(
			'label' => $label,
			'record_label' => $record_label,
			'create_label' => $create_label,
			'group' => __( 'Content', 'dexo' ),
			'capability' => $capability,
			'render' => static fn( int $id ) => $id ? render_content_record( $type, $tab, $id ) : render_content_list( $type, $tab, $create_label ),
			'create' => static fn() => render_content_record( $type, $tab ),
			'submit' => static fn( int $id, array $form ) => save_content_record( $type, $id, $form ),
		);
	}
	return $sections;
}

function content_record( string $type, int $id ): ?\WP_Post {
	$post = get_post( $id );
	return $post && $type === $post->post_type && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true )
		&& current_user_can( 'edit_post', $id ) ? $post : null;
}

function render_content_list( string $type, string $tab, string $create_label ): void {
	$media = 'attachment' === $type;
	$can_create = current_user_can( $media ? 'upload_files' : get_post_type_object( $type )->cap->create_posts );
	$page = max( 1, request_id( $_GET, 'dx_page' ) );
	$search = request_text( $_GET, 'q' );
	$args = array(
		'post_type' => $type,
		'post_status' => $media ? array( 'inherit', 'private' ) : array( 'draft', 'pending', 'publish', 'private', 'future' ),
		'posts_per_page' => 20,
		'paged' => $page,
		's' => $search,
		'perm' => 'editable',
	);
	if ( ! current_user_can( get_post_type_object( $type )->cap->edit_others_posts ) ) {
		$args['author'] = get_current_user_id();
	}
	$query = new \WP_Query( $args );
	$records = array_filter( $query->posts, static fn( \WP_Post $record ) => content_record( $type, $record->ID ) );
	?>
	<section class="surface">
		<form method="get" action="<?php echo esc_url( panel_url() ); ?>" class="list-toolbar">
			<?php hidden_route( $tab ); ?>
			<div class="search-field">
				<?php icon( 'search' ); ?>
				<input name="q" type="search" aria-label="<?php esc_attr_e( 'Search by title', 'dexo' ); ?>" placeholder="<?php esc_attr_e( 'Search by title', 'dexo' ); ?>" value="<?php echo esc_attr( $search ); ?>">
			</div>
			<div class="toolbar-actions">
				<button class="secondary"><?php esc_html_e( 'Search', 'dexo' ); ?></button>
				<?php if ( $can_create ) : ?><a class="button" href="<?php echo esc_url( panel_url( array( 'tab' => $tab, 'new' => '1' ) ) ); ?>"><?php echo esc_html( $create_label ); ?></a><?php endif; ?>
			</div>
		</form>
		<?php if ( ! $records ) : ?>
			<p class="empty"><?php esc_html_e( 'No records found.', 'dexo' ); ?></p>
		<?php else : ?>
			<div class="table-wrap"><table class="data-table mobile-records">
				<thead><tr><th scope="col"><?php esc_html_e( 'Title', 'dexo' ); ?></th><th scope="col"><?php echo esc_html( $media ? __( 'File type', 'dexo' ) : __( 'Status', 'dexo' ) ); ?></th><th scope="col"><?php esc_html_e( 'Date', 'dexo' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $records as $record ) : ?>
					<tr>
						<th scope="row"><a class="record-title" href="<?php echo esc_url( panel_url( array( 'tab' => $tab, 'id' => $record->ID ) ) ); ?>">
							<?php if ( $media && wp_attachment_is_image( $record->ID ) ) { echo wp_get_attachment_image( $record->ID, 'thumbnail', false, array( 'alt' => '' ) ); } ?>
							<span><?php echo esc_html( $record->post_title ?: __( '(Untitled)', 'dexo' ) ); ?></span>
						</a></th>
						<td><span class="badge <?php echo 'publish' === $record->post_status ? 'status-completed' : ''; ?>"><?php echo esc_html( $media ? $record->post_mime_type : ( get_post_status_object( $record->post_status )->label ?? $record->post_status ) ); ?></span></td>
						<td data-label="<?php esc_attr_e( 'Date', 'dexo' ); ?>"><?php echo esc_html( get_the_date( '', $record ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</section>
	<?php
	pagination( array( 'tab' => $tab, 'q' => $search ), $page, (int) $query->max_num_pages );
}

function editable_body( \WP_Post $post ): bool {
	return ! has_blocks( $post ) && wp_kses_post( $post->post_content ) === $post->post_content;
}

function content_statuses( string $type, ?\WP_Post $record ): array {
	$statuses = array( 'draft', 'pending' );
	if ( current_user_can( get_post_type_object( $type )->cap->publish_posts ) ) {
		$statuses[] = 'publish';
	}
	if ( $record && ! in_array( $record->post_status, $statuses, true ) ) {
		return array( $record->post_status );
	}
	return $statuses;
}

function content_revision( \WP_Post $record ): string {
	return hash( 'sha256', serialize( array(
		$record->post_title, $record->post_content, $record->post_excerpt, $record->post_status,
		'attachment' === $record->post_type ? get_post_meta( $record->ID, '_wp_attachment_image_alt', true ) : '',
	) ) );
}

function render_content_record( string $type, string $tab, int $id = 0 ): void {
	if ( ! $id && ! current_user_can( 'attachment' === $type ? 'upload_files' : get_post_type_object( $type )->cap->create_posts ) ) {
		echo '<p class="empty">' . esc_html__( 'This record is unavailable.', 'dexo' ) . '</p>';
		return;
	}
	$record = $id ? content_record( $type, $id ) : null;
	if ( $id && ! $record ) {
		echo '<p class="empty">' . esc_html__( 'This record is unavailable.', 'dexo' ) . '</p>';
		return;
	}
	$media = 'attachment' === $type;
	$args = array( 'tab' => $tab ) + ( $id ? array( 'id' => $id ) : array( 'new' => '1' ) );
	if ( ! $media && $record && ! editable_body( $record ) ) :
		?>
		<h3><?php echo esc_html( $record->post_title ?: __( '(Untitled)', 'dexo' ) ); ?></h3>
		<p class="notice"><?php esc_html_e( 'Use the WordPress editor for this record to preserve its blocks or custom markup.', 'dexo' ); ?></p>
		<a class="button secondary" href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>"><?php esc_html_e( 'Open WordPress editor', 'dexo' ); ?></a>
		<?php
		return;
	endif;
	?>
	<form class="edit" method="post" action="<?php echo esc_url( panel_url( $args ) ); ?>"<?php echo $media && ! $id ? ' enctype="multipart/form-data"' : ''; ?>>
		<?php edit_form_nonce( $tab, $id ); ?>
		<?php if ( $record ) : ?><input type="hidden" name="revision" value="<?php echo esc_attr( content_revision( $record ) ); ?>"><?php endif; ?>
		<?php if ( $media && ! $id ) : ?>
			<label><?php esc_html_e( 'Image or PDF', 'dexo' ); ?><input name="dexo_file" type="file" required accept="image/jpeg,image/png,image/gif,image/webp,image/avif,application/pdf"></label>
			<button><?php esc_html_e( 'Upload media', 'dexo' ); ?></button>
		<?php else : ?>
			<label><?php esc_html_e( 'Title', 'dexo' ); ?><input name="title" required value="<?php echo esc_attr( $record->post_title ?? '' ); ?>"></label>
			<?php if ( $media ) : ?>
				<?php if ( wp_attachment_is_image( $id ) ) : ?>
					<label><?php esc_html_e( 'Alternative text', 'dexo' ); ?><input name="alt" value="<?php echo esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ); ?>"></label>
				<?php endif; ?>
				<label><?php esc_html_e( 'Caption', 'dexo' ); ?><textarea name="caption" rows="3"><?php echo esc_textarea( $record->post_excerpt ); ?></textarea></label>
				<a href="<?php echo esc_url( wp_get_attachment_url( $id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open file', 'dexo' ); ?></a>
			<?php else : ?>
				<label><?php esc_html_e( 'Body (text or basic HTML)', 'dexo' ); ?><textarea name="body" rows="12"><?php echo esc_textarea( $record->post_content ?? '' ); ?></textarea></label>
				<label><?php esc_html_e( 'Status', 'dexo' ); ?>
					<select name="status">
						<?php foreach ( content_statuses( $type, $record ) as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $record->post_status ?? 'draft', $status ); ?>><?php echo esc_html( get_post_status_object( $status )->label ?? $status ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<button><?php esc_html_e( 'Save changes', 'dexo' ); ?></button>
		<?php endif; ?>
	</form>
	<?php
}

function upload_content_media(): int|\WP_Error {
	if ( ! current_user_can( 'upload_files' ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	$file = $_FILES['dexo_file'] ?? null;
	if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['tmp_name'] )
		|| UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) ) {
		return form_error( __( 'Select an image or PDF to upload.', 'dexo' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$mimes = array_intersect( get_allowed_mime_types(), array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf' ) );
	$result = media_handle_upload( 'dexo_file', 0, array(), array( 'test_form' => false, 'mimes' => $mimes ) );
	return is_wp_error( $result ) ? form_error( $result->get_error_message() ) : $result;
}

function save_content_record( string $type, int $id, array $form ): int|\WP_Error|null {
	$media = 'attachment' === $type;
	if ( $media && ! $id ) {
		return upload_content_media();
	}
	if ( ! current_user_can( $media ? 'upload_files' : get_post_type_object( $type )->cap->edit_posts ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	if ( ! $id && ! current_user_can( get_post_type_object( $type )->cap->create_posts ) ) {
		return form_error( __( 'You cannot make this change.', 'dexo' ), 403 );
	}
	$record = $id ? content_record( $type, $id ) : null;
	if ( $id && ! $record ) {
		return form_error( __( 'This record is unavailable.', 'dexo' ), 404 );
	}
	if ( $record && ! hash_equals( content_revision( $record ), request_text( $form, 'revision' ) ) ) {
		return form_error( __( 'This record changed. Review it and try again.', 'dexo' ), 409 );
	}

	$title = request_text( $form, 'title' );
	if ( '' === $title ) {
		return form_error( __( 'Enter a title.', 'dexo' ) );
	}
	$data = array( 'ID' => $id, 'post_type' => $type, 'post_title' => $title );
	if ( $media ) {
		$data['post_excerpt'] = isset( $form['caption'] ) && is_string( $form['caption'] ) ? wp_kses_post( wp_unslash( $form['caption'] ) ) : '';
	} else {
		if ( $record && ! editable_body( $record ) ) {
			return form_error( __( 'Use the WordPress editor for this record to preserve its blocks or custom markup.', 'dexo' ), 409 );
		}
		$status = request_text( $form, 'status' );
		if ( ! in_array( $status, content_statuses( $type, $record ), true ) ) {
			return form_error( __( 'You cannot use this publication status.', 'dexo' ), 403 );
		}
		if ( ! isset( $form['body'] ) || ! is_string( $form['body'] ) ) {
			return form_error( __( 'Enter the content in the body field.', 'dexo' ) );
		}
		$data['post_status'] = $status;
		$data['post_content'] = wp_kses_post( wp_unslash( $form['body'] ) );
	}
	$result = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
	if ( is_wp_error( $result ) || ! $result ) {
		return form_error( __( 'WordPress could not save this record.', 'dexo' ), 500 );
	}
	if ( $media && wp_attachment_is_image( $id ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( request_text( $form, 'alt' ) ) );
	}
	return $result;
}
