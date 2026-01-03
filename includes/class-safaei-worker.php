<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Worker {
	public static function init() {
		add_action( Safaei_Image_Loader::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
	}

	public static function process_queue() {
		$options = Safaei_Image_Loader::get_settings();
		if ( empty( $options['enabled'] ) ) {
			return;
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			Safaei_Queue::log( 'Quota reached. Stopping queue processing.' );
			return;
		}

		global $wpdb;
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;
		$batch_size = max( 1, absint( $options['batch_size'] ) );

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$jobs_table} WHERE status = 'queued' ORDER BY created_at ASC LIMIT %d",
				$batch_size
			)
		);

		foreach ( $jobs as $job ) {
			if ( Safaei_Usage::is_quota_reached() ) {
				Safaei_Queue::log( 'Quota reached. Stopping queue processing.' );
				break;
			}
			self::process_job( $job, $options );
		}
	}

	private static function process_job( $job, $options ) {
		global $wpdb;
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;

		if ( Safaei_Usage::is_quota_reached() ) {
			Safaei_Queue::log( 'Quota reached. Stopping job processing.' );
			return;
		}

		$wpdb->update(
			$jobs_table,
			array(
				'status'     => 'running',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$product = wc_get_product( $job->product_id );
		if ( ! $product ) {
			self::mark_failed( $job->id, 'Product not found.' );
			return;
		}

		if ( ! empty( $options['skip_if_has_image'] ) && $product->get_image_id() ) {
			self::mark_done( $job->id, $product->get_image_id(), 'Already has image.' );
			return;
		}

		$refcode = get_post_meta( $job->product_id, '_safaei_refcode', true );
		if ( empty( $refcode ) ) {
			self::retry_or_fail( $job, 'Missing refcode.' );
			return;
		}

		$queries = self::build_queries( $product, $refcode, $options );
		$candidates = array();

		foreach ( $queries as $query ) {
			if ( Safaei_Usage::is_quota_reached() ) {
				self::requeue_job( $job->id, 'Quota reached.' );
				Safaei_Queue::log( 'Quota reached. Stopping job processing.' );
				return;
			}

			$new_candidates = Safaei_Provider_Google::fetch_candidates( $query, $options['max_results_per_product'] );
			if ( is_wp_error( $new_candidates ) ) {
				if ( 'safaei_quota_reached' === $new_candidates->get_error_code() ) {
					self::requeue_job( $job->id, $new_candidates->get_error_message() );
					Safaei_Queue::log( 'Quota reached. Stopping job processing.' );
					return;
				}
				self::retry_or_fail( $job, $new_candidates->get_error_message() );
				return;
			}
			if ( $new_candidates ) {
				foreach ( $new_candidates as $candidate ) {
					$candidate['query'] = $query;
					$candidates[] = $candidate;
				}
			}

			if ( count( $candidates ) >= $options['max_results_per_product'] ) {
				break;
			}
		}

		if ( empty( $candidates ) ) {
			self::retry_or_fail( $job, 'No candidates found.' );
			return;
		}

		$candidates = self::filter_and_score_candidates( $candidates, $options );
		if ( empty( $candidates ) ) {
			self::retry_or_fail( $job, 'No candidates after filtering.' );
			return;
		}

		$chosen = array_shift( $candidates );
		Safaei_Queue::log( 'Chosen candidate: ' . $chosen['image_url'] );

		$attachment_id = self::download_and_attach( $chosen['image_url'], $product->get_id() );
		if ( ! $attachment_id ) {
			self::retry_or_fail( $job, 'Download failed.' );
			return;
		}

		set_post_thumbnail( $product->get_id(), $attachment_id );

		update_post_meta( $product->get_id(), '_safaei_img_last_attachment_id', $attachment_id );
		update_post_meta( $product->get_id(), '_safaei_img_last_source_url', esc_url_raw( $chosen['image_url'] ) );
		update_post_meta( $product->get_id(), '_safaei_img_last_run_at', time() );
		update_post_meta( $product->get_id(), '_safaei_img_last_status', 'done' );

		$wpdb->update(
			$jobs_table,
			array(
				'status'              => 'done',
				'last_error'          => null,
				'last_query'          => sanitize_text_field( $chosen['query'] ?? '' ),
				'last_source_url'     => esc_url_raw( $chosen['image_url'] ),
				'last_attachment_id'  => $attachment_id,
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $job->id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( ! empty( $options['set_gallery'] ) && ! empty( $options['gallery_count'] ) ) {
			self::set_gallery_images( $product->get_id(), $candidates, $options['gallery_count'] );
		}
	}

	public static function build_queries( $product, $refcode, $options ) {
		$name = $product->get_name();
		$brand = self::get_product_brand( $product );

		$base_query = strtr(
			$options['query_template'],
			array(
				'{refcode}' => $refcode,
				'{brand}'   => $brand,
				'{name}'    => $name,
			)
		);

		$queries = array( trim( $base_query ) );

		$fallbacks = preg_split( '/\r\n|\r|\n/', $options['fallback_queries'] );
		foreach ( $fallbacks as $fallback ) {
			$fallback = trim( $fallback );
			if ( '' === $fallback ) {
				continue;
			}
			$query = strtr(
				$fallback,
				array(
					'{refcode}' => $refcode,
					'{brand}'   => $brand,
					'{name}'    => $name,
				)
			);
			$queries[] = trim( $query );
		}

		$queries = array_filter( array_unique( $queries ) );

		return $queries;
	}

	private static function get_product_brand( $product ) {
		$brand = $product->get_attribute( 'brand' );
		if ( ! $brand ) {
			$brand = $product->get_attribute( 'pa_brand' );
		}
		return $brand ? $brand : '';
	}

	public static function filter_and_score_candidates( $candidates, $options ) {
		$allowed_domains = array();
		if ( ! empty( $options['allowed_domains'] ) ) {
			$allowed_domains = array_filter( array_map( 'trim', preg_split( '/\s*,\s*|\r\n|\r|\n/', $options['allowed_domains'] ) ) );
		}

		$min_width = absint( $options['min_image_width'] );

		$filtered = array();
		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['image_url'] ) ) {
				continue;
			}

			$domain = strtolower( $candidate['domain'] ?? '' );
			$width = absint( $candidate['width'] ?? 0 );
			$score = 0;

			if ( $allowed_domains ) {
				$matched_domain = false;
				foreach ( $allowed_domains as $allowed_domain ) {
					$allowed_domain = strtolower( $allowed_domain );
					if ( $allowed_domain && self::ends_with( $domain, $allowed_domain ) ) {
						$matched_domain = true;
						break;
					}
				}
				if ( ! $matched_domain ) {
					continue;
				}
				$score += 50;
			}

			if ( $width > 0 ) {
				$score += min( 100, $width / 50 );
				if ( $width < $min_width ) {
					$score -= 20;
				}
			} else {
				$score -= 10;
			}

			if ( self::starts_with( $candidate['image_url'], 'https://' ) ) {
				$score += 5;
			}

			$extension = strtolower( pathinfo( wp_parse_url( $candidate['image_url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( $extension && ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				continue;
			}
			if ( ! $extension ) {
				$score -= 5;
			}

			$candidate['score'] = $score;
			$filtered[] = $candidate;
		}

		usort(
			$filtered,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $filtered;
	}

	public static function download_and_attach( $image_url, $product_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $image_url );
		if ( is_wp_error( $temp_file ) ) {
			Safaei_Queue::log( 'Download error: ' . $temp_file->get_error_message(), 'error' );
			return false;
		}

		$filename = basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
		if ( ! $filename ) {
			$filename = 'safaei-image-' . time() . '.jpg';
		}

		$file = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file, $product_id );
		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			Safaei_Queue::log( 'Media sideload error: ' . $attachment_id->get_error_message(), 'error' );
			return false;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			wp_delete_attachment( $attachment_id, true );
			Safaei_Queue::log( 'Unsupported mime type: ' . $mime, 'error' );
			return false;
		}

		return $attachment_id;
	}

	private static function set_gallery_images( $product_id, $candidates, $gallery_count ) {
		$gallery_ids = array();
		$gallery_count = max( 1, absint( $gallery_count ) );

		foreach ( $candidates as $candidate ) {
			if ( count( $gallery_ids ) >= $gallery_count - 1 ) {
				break;
			}
			$attachment_id = self::download_and_attach( $candidate['image_url'], $product_id );
			if ( $attachment_id ) {
				$gallery_ids[] = $attachment_id;
			}
		}

		if ( $gallery_ids ) {
			$current = get_post_meta( $product_id, '_product_image_gallery', true );
			$current_ids = $current ? array_map( 'absint', explode( ',', $current ) ) : array();
			$merged = array_unique( array_merge( $current_ids, $gallery_ids ) );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $merged ) );
		}
	}

	private static function retry_or_fail( $job, $error ) {
		global $wpdb;
		$options = Safaei_Image_Loader::get_settings();
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;

		$attempts = absint( $job->attempts ) + 1;
		$status = ( $attempts < absint( $options['max_retries'] ) ) ? 'queued' : 'failed';

		$wpdb->update(
			$jobs_table,
			array(
				'attempts'   => $attempts,
				'status'     => $status,
				'last_error' => sanitize_text_field( $error ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job->id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		update_post_meta( $job->product_id, '_safaei_img_last_run_at', time() );
		update_post_meta( $job->product_id, '_safaei_img_last_status', $status );

		Safaei_Queue::log( 'Job ' . $job->id . ' error: ' . $error, 'error' );
	}

	private static function requeue_job( $job_id, $message ) {
		global $wpdb;
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;

		$wpdb->update(
			$jobs_table,
			array(
				'status'     => 'queued',
				'last_error' => sanitize_text_field( $message ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function mark_failed( $job_id, $error ) {
		global $wpdb;
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;

		$wpdb->update(
			$jobs_table,
			array(
				'status'     => 'failed',
				'last_error' => sanitize_text_field( $error ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function mark_done( $job_id, $attachment_id, $message ) {
		global $wpdb;
		$jobs_table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;

		$wpdb->update(
			$jobs_table,
			array(
				'status'             => 'done',
				'last_error'         => sanitize_text_field( $message ),
				'last_attachment_id' => $attachment_id,
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}
	private static function starts_with( $haystack, $needle ) {
		return $needle !== '' && strpos( $haystack, $needle ) === 0;
	}

	private static function ends_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

}
