<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Provider_Google {
	public static function fetch_candidates( $query, $num ) {
		$options = Safaei_Image_Loader::get_settings();
		$api_key = $options['google_api_key'];
		$cx = $options['google_cx'];

		if ( empty( $api_key ) || empty( $cx ) || empty( $query ) ) {
			return array();
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			return new WP_Error( 'safaei_quota_reached', __( 'Daily quota reached', 'safaei-auto-image-loader' ) );
		}

		$url = add_query_arg(
			array(
				'key'        => $api_key,
				'cx'         => $cx,
				'q'          => $query,
				'searchType' => 'image',
				'num'        => min( 10, max( 1, absint( $num ) ) ),
				'safe'       => 'off',
			),
			'https://www.googleapis.com/customsearch/v1'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			Safaei_Queue::log( 'Google CSE error: ' . $response->get_error_message(), 'error' );
			Safaei_Usage::record_request( false );
			return array();
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			Safaei_Queue::log( 'Google CSE HTTP error: ' . $status, 'error' );
			Safaei_Usage::record_request( false );
			return array();
		}

		$data = json_decode( $body, true );
		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			if ( ! empty( $data['error']['message'] ) ) {
				Safaei_Queue::log( 'Google CSE API error: ' . $data['error']['message'], 'error' );
			}
			Safaei_Usage::record_request( false );
			return array();
		}

		$candidates = array();
		foreach ( $data['items'] as $item ) {
			$candidates[] = array(
				'image_url' => $item['link'] ?? '',
				'title'     => $item['title'] ?? '',
				'domain'    => $item['displayLink'] ?? '',
				'thumb_url' => $item['image']['thumbnailLink'] ?? '',
				'width'     => isset( $item['image']['width'] ) ? absint( $item['image']['width'] ) : 0,
				'height'    => isset( $item['image']['height'] ) ? absint( $item['image']['height'] ) : 0,
			);
		}

		Safaei_Usage::record_request( ! empty( $candidates ) );

		return $candidates;
	}
}
