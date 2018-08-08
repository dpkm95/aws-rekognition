<?php

namespace HM\AWS_Rekognition\Admin;

use HM\AWS_Rekognition;
use WP_Post;

function bootstrap() {
	add_meta_box( 'hm_aws_rekognition_image_labels', __( 'Detected Image Labels', 'hm-aws-rekognition' ), __NAMESPACE__ . '\\output_metabox', 'attachment', 'side' );
}

function output_metabox( WP_Post $post ) {
	wp_enqueue_script( 'hm-aws-rekognition', plugins_url( '/assets/admin.js', dirname( dirname( __FILE__ ) ) ), [ 'jquery' ] );
	wp_localize_script( 'hm-aws-rekognition', 'HMAWSRekognition', [ 'update_labels_nonce' => wp_create_nonce( 'hm-aws-rekognition-update-labels-' . $post->ID ), 'post_id' => $post->ID ] );

	$labels = AWS_Rekognition\get_attachment_labels( $post->ID );
	$labels = array_map( function ( $label ) {
		return sprintf( '%s (%d%%)', $label['Name'], round( $label['Confidence'], 2 ) );
	}, $labels );

	?>
	<pre style="white-space: pre-wrap" id="hm-aws-rekognition-labels"><?php echo esc_html( implode( ', ', $labels ) ) ?></pre>
	<?php
}
