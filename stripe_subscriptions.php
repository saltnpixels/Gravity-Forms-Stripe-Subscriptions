<?php


/*--------------------------------------------------------------
# subscription module
--------------------------------------------------------------*/



//subscribe for a user who already exists.
// Change number to your subscribe forms ID
add_action( 'gform_after_submission_10', 'pwm_subscription', 10, 2 );
function pwm_subscription( $entry, $form ) {
	//save the entry id to the current users meta when they subscribe for premium stuff
	//this way we can get the entry id of their submission when they use the cancel form
	$user_id = rgar( $entry, 'created_by');

	update_user_meta($user_id, 'pwm_entry_id', $entry['id']);
	update_user_meta($user_id, 'pwm_subscribe_date', date('n, d, Y') );
	update_user_meta($user_id, 'pwm_plan', rgar ( $entry, '8' ) );
	
	//upgrade their role on subscribe
	if ( ! user_can($user_id, 'manage_options') ) {
		wp_update_user( array( 'ID' => $entry['created_by'], 'role' => 'paid_subscriber' ) );
	}

}

//add stripe ID to user meta for logged in user
add_action( 'gform_stripe_customer_after_create', 'save_stripe_customer_id' );
function save_stripe_customer_id( $customer ) {
	if ( is_user_logged_in () ) {
		update_user_meta( get_current_user_id(), 'stripe_customer_id', $customer->id );
	}
}

//change billing of current stripe customer ID
add_filter( 'gform_stripe_customer_id', 'get_stripe_customer_id' );
function get_stripe_customer_id( $customer_id ) {
	if ( is_user_logged_in () &&  get_user_meta( get_current_user_id(), 'stripe_customer_id', true ) != ''){
		$customer_id = get_user_meta( get_current_user_id(), 'stripe_customer_id', true );
	}
	return $customer_id;
}

//Update Credit card
add_filter( 'gform_stripe_charge_authorization_only', 'stripe_charge_authorization_only', 10, 2 );
function stripe_charge_authorization_only( $authorization_only, $feed ) {
	$feed_name  = rgars( $feed, 'meta/feedName' );
	if ( $feed_name == 'Update Credit Card' ) {
		return true;
	}
	return $authorization_only;
}


//user can cancel subscription
//they can be set to engaged here, or by profile status set to engage
add_action( 'gform_after_submission_11', 'pwm_cancel_subscription', 10, 2 );
function pwm_cancel_subscription( $entry, $form ) {

	//SET SUBSCRIPTION TO CANCEL
	//get original current users entry id from form 10. NOT this entry id! We need subscription ID
	$entry_id = get_user_meta(get_current_user_id(), 'pwm_entry_id', true);

	//now cancel that old entry's subscription
	$old_entry = GFAPI::get_entry( $entry_id );
	$feed = is_wp_error( $old_entry ) || ! function_exists( 'gf_stripe' ) ? false : gf_stripe()->get_payment_feed( $old_entry );

	if ( is_array( $feed ) && rgar( $feed, 'addon_slug' ) == 'gravityformsstripe' && gf_stripe()->cancel( $old_entry, $feed ) ) {
		gf_stripe()->cancel_subscription( $old_entry, $feed );

		//destroy entry id so they cant cancel twice... not sure it does anything though if they try to
		update_user_meta(get_current_user_id(), 'pwm_entry_id', '');
		//set them to unsubscribed till period ends. in limbo state
		update_user_meta (get_current_user_id(), 'pwm_subscribed_till_end', true);
	}

}


//make sure the cancellation waits till end of the users period (monthly)
add_filter( 'gform_stripe_subscription_cancel_at_period_end', 'stripe_subscription_cancel_at_period_end', 10, 3 );
function stripe_subscription_cancel_at_period_end( $at_period_end, $entry, $feed ) {
	$feed_name  = rgars( $feed, 'meta/feedName' );
	if ( $feed_name == 'Subscribe' ) {
		remove_action( 'gform_post_payment_callback', 'remove_user_privileges' );
		return true;
	}
	return $at_period_end;
}


//once its actually cancelled at the end of the period, downgrade users role
//add_action( 'gform_subscription_canceled', 'remove_user_privileges', 10, 3 );
add_action( 'gform_post_payment_callback', 'remove_user_privileges', 10, 3 );
function remove_user_privileges( $entry, $action, $result ) {
	if ( ! $result && rgar( $action, 'type' ) == 'cancel_subscription' && strtolower( $entry['payment_status'] ) == 'cancelled' ) {
		pwm_downgrade_user ( $entry );
	}
}


function pwm_downgrade_user ($entry){
	if ( ! current_user_can('view_full_profiles') && ! current_user_can('manage_options') ) {
		$id = get_current_user_id ();

		wp_update_user( array( 'ID' => $entry['created_by'], 'role' => 'subscriber' ) );

		//they now have been subscribed once before. This makes them see a resubscribe as opposed to subscribe notice.
		update_user_meta($entry['created_by'], 'pwm_subscribed_before', true);
		//because they are no longer in a limbo state. period has ended
		update_user_meta ( $entry['created_by'], 'pwm_subscribed_till_end', false);
		//set to busy unless its already set to engaged
		$profile_id = get_user_meta($entry['created_by'], 'pwm_regular_profile.ID', true);
		if(get_post_meta($profile_id , 'pwm_profile_status',  true) != 'engaged'){
			update_post_meta($profile_id, 'pwm_profile_status', 'busy');
		}
		
	}
}
