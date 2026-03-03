<?php
/**
 * Sideloads images from URLs into the WordPress media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pilot_Image_Handler {

	/**
	 * Download an image from a URL, add it to the media library, and set it as the post's featured image.
	 *
	 * @param string $image_url Remote image URL.
	 * @param string $alt_text  Alt text for the image.
	 * @param int    $post_id   Post to attach the image to.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function sideload( string $image_url, string $alt_text, int $post_id ) {
		// Ensure required WP admin functions are available.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download to a temp file.
		$tmp = download_url( $image_url );
		if ( is_wp_error( $tmp ) ) {
			return false;
		}

		// Build the file array for media_handle_sideload.
		$file_array = array(
			'name'     => $this->filename_from_url( $image_url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			// Clean up temp file on failure.
			@unlink( $file_array['tmp_name'] );
			return false;
		}

		// Set alt text.
		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		// Set as featured image.
		set_post_thumbnail( $post_id, $attachment_id );

		// Store the source URL for change detection on updates.
		update_post_meta( $post_id, PILOT_WMS_META_PREFIX . 'image_url', $image_url );

		return $attachment_id;
	}

	/**
	 * Extract a usable filename from a URL, falling back to a generic name.
	 *
	 * @param string $url
	 * @return string
	 */
	private function filename_from_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $path ? basename( $path ) : '';

		// Ensure the filename has a recognized image extension.
		$ext = pathinfo( $basename, PATHINFO_EXTENSION );
		if ( in_array( strtolower( $ext ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			return sanitize_file_name( $basename );
		}

		return 'pilot-image-' . substr( md5( $url ), 0, 8 ) . '.png';
	}
}
