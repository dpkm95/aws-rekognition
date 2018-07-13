<?php

namespace HM\AWS_Rekognition;

use Aws\Rekognition\RekognitionClient;
use Exception;
use WP_Error;

const CRON_NAME = 'hm_aws_rekognition_update_image';

/**
 * Register hooks here.
 */
function setup() {
	add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\\on_update_attachment_metadata', 10, 2 );
	add_filter( 'pre_get_posts', __NAMESPACE__ . '\\filter_query' );
	add_filter( 'admin_init', __NAMESPACE__ . '\\Admin\\bootstrap' );
	add_action( CRON_NAME, __NAMESPACE__ . '\\update_attachment_data' );
	add_action( 'init', __NAMESPACE__ . '\\attachment_taxonomies', 1000 );
}

/**
 * Use the wp_update_attachment_metadata to make sure the image
 * is (re)processed when the image meta data is changed.
 *
 * @param array $data
 * @param int   $id
 * @return $data
 */
function on_update_attachment_metadata( array $data, int $id ) : array {
	$image_types = [ IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP ];
	$mime        = exif_imagetype( get_attached_file( $id ) );
	if ( ! in_array( $mime, $image_types, true ) ) {
		return $data;
	}
	wp_schedule_single_event( time(), CRON_NAME, [ $id ] );
	return $data;
}

/**
 * Update the image keyworks etc from a given attachment id.
 *
 * @param int $id
 * @return bool|WP_Error
 */
function fetch_data_for_attachment( int $id ) {
	$file   = get_attached_file( $id );
	$client = get_rekognition_client();

	// Set up image argument.
	if ( preg_match( '#s3://(?P<bucket>[^/]+)/(?P<path>.*)#', $file, $matches ) ) {
		$image_args = [
			'S3Object' => [
				'Bucket' => $matches['bucket'],
				'Name'   => $matches['path'],
			],
		];
	} else {
		$image_args = [
			'Bytes' => file_get_contents( $file ),
		];
	}

	// Collect responses & errors.
	$responses = [];

	/**
	 * Allows you to toggle fetching labels from rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_labels
	 * @param int  $id
	 */
	$get_labels = apply_filters( 'hm.aws.rekognition.labels', true, $id );

	if ( $get_labels ) {
		try {
			$labels_response = $client->detectLabels( [
				'Image'         => $image_args,
				'MinConfidence' => 80,
			] );

			$responses['labels'] = wp_list_pluck( $labels_response['Labels'], 'Name' );
		} catch ( Exception $e ) {
			$responses['labels'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle fetching moderation labels from rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_moderation_labels
	 * @param int  $id
	 */
	$get_moderation_labels = apply_filters( 'hm.aws.rekognition.moderation_labels', false, $id );

	if ( $get_moderation_labels ) {
		try {
			$moderation_response = $client->detectModerationLabels( [
				'Image'         => $image_args,
				'MinConfidence' => 80,
			] );

			$responses['moderation'] = $moderation_response['ModerationLabels'];
		} catch ( Exception $e ) {
			$responses['moderation'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle fetching faces from Rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_faces
	 */
	$get_faces = apply_filters( 'hm.aws.rekognition.faces', false, $id );

	$face_attributes = [ 'BoundingBox', 'Confidence', 'Emotions', 'AgeRange', 'Gender' ];

	/**
	 * Filters the face attributes returned by Rekognition.
	 * Defaults to BoundingBox, Confidence, Emotions, AgeRange, Gender
	 *
	 * You can find the full list here:
	 * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rekognition-2016-06-27.html#detectfaces
	 *
	 * @param array $face_attributes Array of attributes to return.
	 * @param int   $id              The attachment ID.
	 */
	$face_attributes = apply_filters( 'hm.aws.rekognition.faces.attributes', $face_attributes, $id );

	if ( $get_faces ) {
		try {
			$faces_response = $client->detectFaces( [
				'Image'      => $image_args,
				'Attributes' => $face_attributes,
			] );

			$responses['faces'] = $faces_response['FaceDetails'];
		} catch ( Exception $e ) {
			$responses['faces'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle searching for celebrities in images.
	 *
	 * @param bool $get_celebrities
	 * @param int  $id
	 */
	$get_celebrities = apply_filters( 'hm.aws.rekognition.celebrities', false, $id );

	if ( $get_celebrities ) {
		try {
			$celebrities_response = $client->recognizeCelebrities( [
				'Image' => $image_args,
			] );

			$responses['celebrities'] = $celebrities_response['CelebrityFaces'];
		} catch ( Exception $e ) {
			$responses['celebrities'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle detecting text in images.
	 *
	 * @param bool $get_text
	 * @param int  $id
	 */
	$get_text = apply_filters( 'hm.aws.rekognition.text', false, $id );

	if ( $get_text && method_exists( $client, 'detectText' ) ) {
		try {
			$text_response = $client->detectText( [
				'Image' => $image_args,
			] );

			$responses['text'] = $text_response['TextDetections'];
		} catch ( Exception $e ) {
			$responses['text'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allow a convenient place to hook in and use the Rekognition client instance.
	 *
	 * @param Aws\Rekognition\RekognitionClient $client The Rekognition client.
	 * @param int                               $id     The attachment ID.
	 */
	do_action( 'hm.aws.rekognition.process', $client, $id );

	return $responses;
}

function update_attachment_data( int $id ) {
	$data = fetch_data_for_attachment( $id );

	// Collect keywords to factor into searches.
	$keywords = [];

	foreach ( $data as $type => $response ) {
		if ( is_wp_error( $response ) ) {
			update_post_meta( $id, "hm_aws_rekogition_error_{$type}", $response );
			continue;
		}

		// Save the metadata.
		update_post_meta( $id, "hm_aws_rekogition_{$type}", $response );

		// Carry out custom handling & processing.
		switch ( $type ) {
			case 'labels':
				wp_set_object_terms( $id, $response, 'rekognition_labels', true );
				$keywords += $response;
				break;
			case 'moderation':
				$keywords += wp_list_pluck( $response, 'Name' );
				break;
			case 'faces':
				foreach ( $response as $face ) {
					if ( isset( $face['Gender'] ) ) {
						$keywords[] = $face['Gender']['Value'];
					}
					if ( isset( $face['Emotions'] ) ) {
						$emotions  = wp_list_pluck( $face['Emotions'], 'Type' );
						$keywords += $emotions;
					}
				}
				break;
			case 'celebrities':
				$keywords += wp_list_pluck( $response, 'Name' );
				break;
			case 'text':
				$keywords += wp_list_pluck( $response, 'DetectedText' );
				break;
		}
	}

	$keywords = array_filter( $keywords );
	$keywords = array_unique( $keywords );

	/**
	 * Filter the keywords array used to enhance the media library search results.
	 *
	 * @param array $keywords The current keywords array.
	 * @param array $data     The full data collection returned.
	 * @param int   $id       The attachment ID.
	 */
	$keywords = apply_filters( 'hm.aws.rekognition.keywords', $keywords, $data, $id );

	// Store keywords for use with search queries.
	update_post_meta( $id, 'hm_aws_rekogition_keywords', implode( "\n", $keywords ) );
}

function get_attachment_labels( int $id ) : array {
	return get_post_meta( $id, 'hm_aws_rekogition_labels', true ) ?: [];
}

/**
 * Get the AWS Rekognition client.
 *
 * @return \Aws\Rekognition\RekognitionClient
 */
function get_rekognition_client() : RekognitionClient {
	if ( defined( 'S3_UPLOADS_KEY' ) && defined( 'S3_UPLOADS_SECRET' ) ) {
		$credentials = [
			'key'    => S3_UPLOADS_KEY,
			'secret' => S3_UPLOADS_SECRET,
		];
	} else {
		$credentials = null;
	}
	return RekognitionClient::factory( [
		'version'     => '2016-06-27',
		'region'      => S3_UPLOADS_REGION,
		'credentials' => $credentials,
	] );
}

/**
 * Filter the SQL clauses of an attachment query to include keywords.
 *
 * @param array $clauses An array including WHERE, GROUP BY, JOIN, ORDER BY,
 *                       DISTINCT, fields (SELECT), and LIMITS clauses.
 * @return array The modified clauses.
 */
function filter_query_attachment_keywords( array $clauses ) : array {
	global $wpdb;
	remove_filter( 'posts_clauses', __FUNCTION__ );

	// Add a LEFT JOIN of the postmeta table so we don't trample existing JOINs.
	$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS sq_hm_aws_rekogition_keywords ON ( {$wpdb->posts}.ID = sq_hm_aws_rekogition_keywords.post_id AND sq_hm_aws_rekogition_keywords.meta_key = 'hm_aws_rekogition_keywords' )";

	$clauses['groupby'] = "{$wpdb->posts}.ID";

	$clauses['where'] = preg_replace(
		"/\({$wpdb->posts}.post_content (NOT LIKE|LIKE) (\'[^']+\')\)/",
		'$0 OR ( sq_hm_aws_rekogition_keywords.meta_value $1 $2 )',
		$clauses['where']
	);

	return $clauses;
}

/**
 * Register / add attachment taxonomies here.
 */
function attachment_taxonomies() {
	$labels = [
		'name'              => _x( 'Labels', 'taxonomy general name', 'hm-aws-rekognition' ),
		'singular_name'     => _x( 'Label', 'taxonomy singular name', 'hm-aws-rekognition' ),
		'search_items'      => __( 'Search Labels', 'hm-aws-rekognition' ),
		'all_items'         => __( 'All Labels', 'hm-aws-rekognition' ),
		'parent_item'       => __( 'Parent Label', 'hm-aws-rekognition' ),
		'parent_item_colon' => __( 'Parent Label:', 'hm-aws-rekognition' ),
		'edit_item'         => __( 'Edit Label', 'hm-aws-rekognition' ),
		'update_item'       => __( 'Update Label', 'hm-aws-rekognition' ),
		'add_new_item'      => __( 'Add New Label', 'hm-aws-rekognition' ),
		'new_item_name'     => __( 'New Label Name', 'hm-aws-rekognition' ),
		'menu_name'         => __( 'Label', 'hm-aws-rekognition' ),
	];

	$args = [
		'hierarchical'      => false,
		'labels'            => $labels,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'public'            => false,
		'rewrite'           => [ 'slug' => 'label' ],
	];

	register_taxonomy( 'rekognition_labels', $args );

	register_taxonomy_for_object_type( 'rekognition_labels', 'attachment' );
}
