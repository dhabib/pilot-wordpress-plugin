<?php
/**
 * Creates, updates, and trashes WordPress posts from Pilot WMS webhook payloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pilot_Post_Handler {

	/** @var Pilot_Image_Handler */
	private $image_handler;

	public function __construct( Pilot_Image_Handler $image_handler ) {
		$this->image_handler = $image_handler;
	}

	/**
	 * Handle content.published event.
	 *
	 * @param array $payload Full webhook payload.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_publish( array $payload ) {
		$content = $payload['content'] ?? array();
		$projection_id = sanitize_text_field( $content['projection_id'] ?? '' );

		if ( empty( $projection_id ) ) {
			return new WP_Error( 'pilot_wms_missing_id', 'Missing projection_id.', array( 'status' => 400 ) );
		}

		// Idempotency: if a post with this projection_id already exists, delegate to update.
		$existing = $this->find_post_by_projection_id( $projection_id );
		if ( $existing ) {
			$payload['content']['external_id'] = (string) $existing;
			return $this->handle_update( $payload );
		}

		$author_id = $this->get_or_create_staff_user();
		$category_id = $this->resolve_category( $content );

		$post_data = array(
			'post_title'    => sanitize_text_field( $content['title'] ?? '' ),
			'post_name'     => sanitize_title( $content['slug'] ?? '' ),
			'post_content'  => wp_kses_post( $content['body'] ?? '' ),
			'post_excerpt'  => sanitize_text_field( $content['summary'] ?? '' ),
			'post_status'   => get_option( 'pilot_wms_post_status', 'draft' ),
			'post_author'   => $author_id,
			'post_category' => array( $category_id ),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'pilot_wms_insert_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Marker tag.
		$tag = get_option( 'pilot_wms_tag', PILOT_WMS_TAG );
		wp_set_post_tags( $post_id, array( $tag ), true );

		// Post meta.
		$this->save_meta( $post_id, $content, $payload );

		// Featured image.
		$this->maybe_sideload_image( $post_id, $content );

		return new WP_REST_Response( array(
			'status'       => 'ok',
			'external_id'  => (string) $post_id,
			'external_url' => get_permalink( $post_id ),
		), 200 );
	}

	/**
	 * Handle content.updated event.
	 *
	 * @param array $payload Full webhook payload.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update( array $payload ) {
		$content = $payload['content'] ?? array();
		$post_id = $this->resolve_post_id( $content );

		if ( ! $post_id ) {
			return new WP_Error( 'pilot_wms_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$update_data = array(
			'ID'           => $post_id,
			'post_title'   => sanitize_text_field( $content['title'] ?? '' ),
			'post_name'    => sanitize_title( $content['slug'] ?? '' ),
			'post_content' => wp_kses_post( $content['body'] ?? '' ),
			'post_excerpt' => sanitize_text_field( $content['summary'] ?? '' ),
		);

		// Re-map category if topic changed.
		$category_id = $this->resolve_category( $content );
		$update_data['post_category'] = array( $category_id );

		$result = wp_update_post( $update_data, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'pilot_wms_update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Update meta.
		$this->save_meta( $post_id, $content, $payload );

		// Re-sideload image only if URL changed.
		$current_image_url = get_post_meta( $post_id, PILOT_WMS_META_PREFIX . 'image_url', true );
		$new_image_url     = $content['image_url'] ?? '';
		if ( ! empty( $new_image_url ) && $new_image_url !== $current_image_url ) {
			$this->maybe_sideload_image( $post_id, $content );
		}

		return new WP_REST_Response( array(
			'status'       => 'ok',
			'external_id'  => (string) $post_id,
			'external_url' => get_permalink( $post_id ),
		), 200 );
	}

	/**
	 * Handle content.unpublished event.
	 *
	 * @param array $payload Full webhook payload.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_unpublish( array $payload ) {
		$content = $payload['content'] ?? array();
		$post_id = $this->resolve_post_id( $content );

		// Idempotent: if post not found, return success.
		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'draft',
		) );

		return new WP_REST_Response( array(
			'status'      => 'ok',
			'external_id' => (string) $post_id,
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Find a WP post by _pilot_projection_id meta.
	 *
	 * @param string $projection_id
	 * @return int|null Post ID or null.
	 */
	private function find_post_by_projection_id( string $projection_id ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => PILOT_WMS_META_PREFIX . 'projection_id',
			'meta_value'     => $projection_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Resolve WP post ID from payload: try external_id first, then projection_id meta.
	 *
	 * @param array $content The content portion of the payload.
	 * @return int|null
	 */
	private function resolve_post_id( array $content ) {
		// Try external_id (the WP post ID we returned earlier).
		if ( ! empty( $content['external_id'] ) ) {
			$post_id = (int) $content['external_id'];
			if ( get_post( $post_id ) ) {
				return $post_id;
			}
		}

		// Fallback: look up by projection_id meta.
		$projection_id = $content['projection_id'] ?? '';
		if ( ! empty( $projection_id ) ) {
			return $this->find_post_by_projection_id( $projection_id );
		}

		return null;
	}

	/**
	 * Get or create the pilot-staff author user.
	 *
	 * @return int User ID.
	 */
	private function get_or_create_staff_user(): int {
		$user = get_user_by( 'login', 'pilot-staff' );
		if ( $user ) {
			return $user->ID;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => 'pilot-staff',
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'pilot-staff@localhost',
			'display_name' => 'Staff',
			'role'         => 'author',
		) );

		return is_wp_error( $user_id ) ? 1 : $user_id;
	}

	/**
	 * Resolve a WP category ID from payload metadata.
	 *
	 * Tries to match metadata.topic_region as a category slug, falls back to configured default.
	 *
	 * @param array $content
	 * @return int Category ID.
	 */
	private function resolve_category( array $content ): int {
		$default = (int) get_option( 'pilot_wms_default_category', 1 );
		$metadata = $content['metadata'] ?? array();

		if ( ! empty( $metadata['topic_region'] ) ) {
			$slug = sanitize_title( $metadata['topic_region'] );
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		return $default;
	}

	/**
	 * Save Pilot-specific post meta.
	 *
	 * @param int   $post_id
	 * @param array $content
	 * @param array $payload
	 */
	private function save_meta( int $post_id, array $content, array $payload ) {
		$prefix = PILOT_WMS_META_PREFIX;

		update_post_meta( $post_id, $prefix . 'projection_id', sanitize_text_field( $content['projection_id'] ?? '' ) );
		update_post_meta( $post_id, $prefix . 'delivery_id', sanitize_text_field( $payload['delivery_id'] ?? '' ) );
		update_post_meta( $post_id, $prefix . 'tenant_id', sanitize_text_field( $payload['tenant']['id'] ?? '' ) );

		$source_ids = array_map( function ( $a ) {
			return sanitize_text_field( $a['id'] ?? '' );
		}, $content['source_artifacts'] ?? array() );
		update_post_meta( $post_id, $prefix . 'source_artifact_ids', $source_ids );
	}

	/**
	 * Sideload featured image if image_url is present.
	 *
	 * @param int   $post_id
	 * @param array $content
	 */
	private function maybe_sideload_image( int $post_id, array $content ) {
		$image_url = $content['image_url'] ?? '';
		if ( empty( $image_url ) ) {
			return;
		}

		$alt = sanitize_text_field( $content['image_alt'] ?? $content['title'] ?? '' );
		$this->image_handler->sideload( $image_url, $alt, $post_id );
	}
}
