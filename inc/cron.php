<?php

// set up scheduled jobs
register_activation_hook( dirname ( __FILE__ )."/content-audit.php", 'content_audit_cron_activate' );
register_deactivation_hook( dirname ( __FILE__ )."/content-audit.php", 'content_audit_cron_deactivate' );

// add custom time to cron
function content_audit_cron_schedules( $param ) {
	return array( 'weekly' => array( 
								'interval' => 60*60*24*7, 
								'display'  => __( 'Once a Week', 'content-audit' )
							 ) ,
				  'monthly' => array( 
								'interval' => 60*60*24*7*4, 
								'display'  => __( 'Once a Month', 'content-audit' )
							 ) 
				 );
}
add_filter( 'cron_schedules', 'content_audit_cron_schedules' );

add_action( 'content_audit_outdated_report', 'content_audit_mark_outdated' );
add_action( 'content_audit_outdated_email', 'content_audit_notify_owners' );

function content_audit_cron_activate() {
	$options = get_option( 'content_audit' );
	if ( !wp_next_scheduled( 'content_audit_outdated_report' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'content_audit_outdated_report' );
	}
	
	// prevent this from happening immediately
	switch ( $options['interval'] ) {
		case 'daily': $start = strtotime( '+1 day' ); break;
		case 'weekly': $start = strtotime( '+1 week' ); break;
		default: $start = strtotime( '+1 month' );
	}

	if ( !wp_next_scheduled( 'content_audit_outdated_email' ) ) {
		wp_schedule_event( $start, $options['interval'], 'content_audit_outdated_email' );
		if ( isset( $options['notify_now'] ) && $options['notify_now'] )
			wp_schedule_event( time(), $options['interval'], 'content_audit_outdated_email' );
	}
}

function content_audit_cron_deactivate() {
	wp_clear_scheduled_hook( 'content_audit_outdated_report' );
	wp_clear_scheduled_hook( 'content_audit_outdated_email' );
}

function content_audit_mark_outdated() {

	$options = get_option( 'content_audit' );
	// handle auto-outdated content
	if ( $options['mark_outdated'] ) {
		$oldposts = content_audit_get_outdated();
		if ( !empty( $oldposts ) ) {
			foreach ( $oldposts as $oldpost ) {
				wp_set_object_terms( $oldpost->ID, 'outdated', 'content_audit', true );
			}
		}
	}

	content_audit_mark_review_coming_up();

}

function content_audit_mark_review_coming_up() {

	$posts = content_audit_get_posts_to_review();
	$owner_reviews = [];
	$notify_review_coming_up = [];

	$outdated = time();
	$week = strtotime( '1 week' );
	$day = strtotime( '1 day' );

	if ( ! empty( $posts->posts ) && is_array( $posts->posts ) ) {
		foreach ( $posts->posts as $review_post ) {

			$expiration = get_post_meta( $review_post->ID, '_content_audit_expiration_date', true );
			$stored_owner = get_post_meta( $review_post->ID, '_content_audit_owner', true );
			$owner = ( ! empty( $stored_owner ) ) ? absint( $stored_owner ) : $review_post->post_author;

			if ( empty( $expiration ) ) {
				continue;
			}

			$term_to_add = '';
			$terms_to_remove = '';

			if ( $expiration <= $outdated ) {
				$term_to_add = 'outdated';
				$terms_to_remove = [ 'review-due-1-week', 'review-due-1-day' ];
			} elseif ( $expiration <= $day ) {
				$term_to_add = 'review-due-1-day';
				$terms_to_remove = 'review-due-1-week';
			} elseif ( $expiration <= $week ) {
				$term_to_add = 'review-due-1-week';
			}

			$owner_reviews[ $owner ][ $review_post->ID ] = [
				'status' => $term_to_add,
				'date' => $expiration,
			];

			if ( ! has_term( $term_to_add, 'content_audit', $review_post ) ) {
				$notify_review_coming_up[] = $owner;
				$owner_reviews[ $owner ][ $review_post->ID ]['new'] = true;
				wp_set_object_terms( $review_post->ID, $term_to_add, 'content_audit', true );
				wp_remove_object_terms( $review_post->ID, $terms_to_remove, 'content_audit' );
			}

		}
	}

	if ( ! empty( $notify_review_coming_up ) ) {
		$content_reviews = array_intersect_key( $owner_reviews, array_flip( $notify_review_coming_up ) );
		ca_dispatch_audit_notifications( $content_reviews, __( 'New resources in need of review', 'content-audit' ), true );
	}

}

function ca_dispatch_audit_notifications( $reviews_coming_up, $subject, $new = false ) {

	if ( empty( $reviews_coming_up ) || ! is_array( $reviews_coming_up ) ) {
		return;
	}

	foreach ( $reviews_coming_up as $owner => $update_data ) {
		$owner = get_user_by( 'ID', $owner );
		if ( ! is_a( $owner, 'WP_User' ) ) {
			continue;
		}

		$email = $owner->user_email;
		if ( ! empty( $owner->first_name ) && ! empty( $owner->last_name ) ) {
			$name = $owner->first_name . ' ' . $owner->last_name;
		} else {
			$name = $owner->display_name;
		}
		ca_send_email( $email, $name, $subject, $update_data, $new );

	}
}

function ca_send_email( $to, $name, $subject, $posts, $new = false ) {

	$message = '
	<style>
	  table {
	    border-collapse: collapse;
	  }
	  table td {
	    border: 1px black solid;
	    padding: 6px 10px;
	    margin: 0;
	  }
	  table th {
	  	font-weight: bold;
	  }
	</style>';

	$message .= '<p>Hello ' . $name . ',  ';
	if ( true === $new ) {
		$message .= 'you have some new updates to review.';
	} else {
		$message .= 'you have some outdated content that has to be reviewed.';
	}
	$message .='</p>';

	if ( ! empty( $new_posts ) ) {
		$new_posts = wp_list_filter( $posts, array( 'new' => true ) );
		$message .= '<p>Here are your <strong>new</strong> updates that need to be reviewed</p>';
		$message .= ca_build_table_html( $new_posts );
		$posts = array_diff_key( $posts, $new_posts );
	}

	if ( ! empty( $posts ) ) {
		if ( true === $new ) {
			$message .= '<p>Here are all of the other resources you need to review</p>';
		} else {
			$message .= '<p>Here are the resources that are overdue for review</p>';
		}
		$message .= ca_build_table_html( $posts );
	}

	$from = get_option( 'admin_email' );

	// now send the emails
	$headers = "MIME-Version: 1.0\n"
	           . 'From: '.$from. "\r\n"
	           . 'sendmail_from: '.$from. "\r\n"
	           . "Content-Type: text/html; charset=\"" . get_option( 'blog_charset' ) . "\"\n";

	wp_mail( $to, $subject, $message, $headers );

}

function ca_build_table_html( $outdated_posts ) {
	$html = '<table>';
	$html .= '<tr class="header"><th>Resource</th><th>Warning</th><th>Due Date</th></tr>';
	foreach ( $outdated_posts as $post_id => $outdated_post ) {
		$post_obj = get_post( $post_id );
		$url = add_query_arg( array( 'post' => $post_id, 'action' => 'edit' ), admin_url( 'post.php' ) );
		$html .= '<tr>';
		$html .= '<td><a target="_blank" href="' . esc_url( $url ) . '">' . $post_obj->post_title . '</a></td>';
		$html .= '<td>' . $outdated_post['status'] . '</td>';
		$html .= '<td>' . date( 'm/d/y', $outdated_post['date'] ) . '</td>';
		$html .= '</tr>';
	}
	$html .= '</table>';
	return $html;
}

function content_audit_get_posts_to_review( $time = '' ) {

	if ( empty( $time ) ) {
		$time = strtotime( '+1 week' );
	}

	$args = array(
		'post_type' => 'any',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'meta_query' => array(
			array(
				'key' => '_content_audit_expiration_date',
				'value' => $time,
				'compare' => '<=',
			)
		)
	);

	return new WP_Query( $args );

}

function content_audit_notify_owners() {

	$options = get_option( 'content_audit' );
	$owner_reviews = [];
	if ( $options['notify'] ) {

		$posts = content_audit_get_posts_to_review( time() );

		if ( ! empty( $posts->posts ) && is_array( $posts->posts ) ) {
			foreach ( $posts->posts as $post ) {
				$expiration = get_post_meta( $post->ID, '_content_audit_expiration_date', true );
				$stored_owner = get_post_meta( $post->ID, '_content_audit_owner', true );
				$owner = ( ! empty( $stored_owner ) ) ? absint( $stored_owner ) : $post->post_author;

				if ( empty( $expiration ) ) {
					continue;
				}

				$owner_reviews[ $owner ][ $post->ID ] = [
					'status' => 'Outdated',
					'date' => $expiration,
				];
			}
		}

		update_option( '_test_ca_outdated_posts', $owner_reviews );
		if ( ! empty( $owner_reviews ) ) {
			ca_dispatch_audit_notifications( $owner_reviews, __( 'Outdated content report', 'content-audit' ) );
		}

	} // if ( $options['notify'] )	
}


function content_audit_get_outdated() {
	global $wpdb;
	$options = get_option( 'content_audit' );
	
	if ( empty( $options['post_types'] ) ) 
		return false;

	$safe_posttypes = array();
	foreach( $options['post_types'] as $type )
		$safe_posttypes[] = $wpdb->prepare( '%s', $type );
	$types = implode( ',', $safe_posttypes );
	$longago = date( 'Y-m-d', strtotime( '-'.$options['outdate'].' '.$options['outdate_unit'] ) );
	$oldposts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_author, post_type, post_modified 
			FROM $wpdb->posts WHERE post_type IN ( {$types} ) AND post_modified <= %s
			ORDER BY post_type, post_modified ASC", $longago ) );

	return $oldposts;
}