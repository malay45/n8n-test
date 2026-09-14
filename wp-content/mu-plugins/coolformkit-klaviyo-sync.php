<?php
/**
 * Plugin Name: CoolFormKit -> Klaviyo Contact Sync
 * Description: Syncs Cool FormKit / Elementor form submissions to a Klaviyo list using the coolformkit_forms_send_form action.
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set these in wp-config.php: define('KLAVIYO_PRIVATE_API_KEY', 'pk_xxx'); define('KLAVIYO_LIST_ID', 'XXXXXX');
if ( ! defined( 'KLAVIYO_PRIVATE_API_KEY' ) ) {
	define( 'KLAVIYO_PRIVATE_API_KEY', '' );
}
if ( ! defined( 'KLAVIYO_LIST_ID' ) ) {
	define( 'KLAVIYO_LIST_ID', '' );
}

const KLAVIYO_API_REVISION = '2024-10-15';

add_action( 'coolformkit_forms_send_form', 'cfk_klaviyo_sync_contact', 10, 10 );

/**
 * Cool FormKit doesn't publish a fixed signature for this hook, so accept
 * whatever gets passed (Elementor's Form_Record object, a raw fields array,
 * or a fields object) and pull contact data out of it defensively.
 */
function cfk_klaviyo_sync_contact() {
	if ( empty( KLAVIYO_PRIVATE_API_KEY ) ) {
		error_log( '[CoolFormKit->Klaviyo] Skipped sync: KLAVIYO_PRIVATE_API_KEY is not configured.' );
		return;
	}

	$fields = cfk_klaviyo_extract_fields( func_get_args() );

	if ( empty( $fields['email'] ) ) {
		error_log( '[CoolFormKit->Klaviyo] Skipped sync: no email field found in the form submission.' );
		return;
	}

	$profile_id = cfk_klaviyo_upsert_profile( $fields );

	if ( $profile_id && ! empty( KLAVIYO_LIST_ID ) ) {
		cfk_klaviyo_subscribe_to_list( $profile_id, $fields['email'] );
	}
}

/**
 * Normalizes the hook's raw arguments into a flat contact array.
 */
function cfk_klaviyo_extract_fields( array $args ) {
	$raw = array();

	foreach ( $args as $arg ) {
		if ( is_object( $arg ) && method_exists( $arg, 'get_formatted_data' ) ) {
			// Elementor Pro Form_Record object.
			$raw = array_merge( $raw, (array) $arg->get_formatted_data() );
		} elseif ( is_object( $arg ) && method_exists( $arg, 'get_raw_fields' ) ) {
			foreach ( (array) $arg->get_raw_fields() as $field ) {
				if ( isset( $field['id'], $field['value'] ) ) {
					$raw[ $field['id'] ] = $field['value'];
				}
			}
		} elseif ( is_array( $arg ) ) {
			$raw = array_merge( $raw, cfk_klaviyo_flatten_array( $arg ) );
		} elseif ( is_object( $arg ) && isset( $arg->fields ) ) {
			$raw = array_merge( $raw, cfk_klaviyo_flatten_array( (array) $arg->fields ) );
		}
	}

	$contact = array(
		'email'      => '',
		'first_name' => '',
		'last_name'  => '',
		'phone'      => '',
	);

	foreach ( $raw as $key => $value ) {
		if ( is_array( $value ) ) {
			// Some field shapes are ['id' => ..., 'value' => ...].
			$value = $value['value'] ?? reset( $value );
		}
		if ( ! is_scalar( $value ) ) {
			continue;
		}

		$key = strtolower( (string) $key );

		if ( '' === $contact['email'] && ( false !== strpos( $key, 'email' ) || is_email( $value ) ) ) {
			$contact['email'] = sanitize_email( $value );
		} elseif ( '' === $contact['first_name'] && ( false !== strpos( $key, 'first_name' ) || false !== strpos( $key, 'firstname' ) ) ) {
			$contact['first_name'] = sanitize_text_field( $value );
		} elseif ( '' === $contact['last_name'] && ( false !== strpos( $key, 'last_name' ) || false !== strpos( $key, 'lastname' ) ) ) {
			$contact['last_name'] = sanitize_text_field( $value );
		} elseif ( '' === $contact['first_name'] && false !== strpos( $key, 'name' ) && false === strpos( $key, 'last' ) ) {
			$contact['first_name'] = sanitize_text_field( $value );
		} elseif ( '' === $contact['phone'] && ( false !== strpos( $key, 'phone' ) || false !== strpos( $key, 'tel' ) ) ) {
			$contact['phone'] = sanitize_text_field( $value );
		}
	}

	return $contact;
}

function cfk_klaviyo_flatten_array( array $data ) {
	$flat = array();
	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) && isset( $value['id'] ) && isset( $value['value'] ) ) {
			$flat[ $value['id'] ] = $value['value'];
		} else {
			$flat[ $key ] = $value;
		}
	}
	return $flat;
}

/**
 * Creates or updates the Klaviyo profile. Returns the Klaviyo profile ID on success.
 */
function cfk_klaviyo_upsert_profile( array $fields ) {
	$attributes = array( 'email' => $fields['email'] );

	if ( ! empty( $fields['first_name'] ) ) {
		$attributes['first_name'] = $fields['first_name'];
	}
	if ( ! empty( $fields['last_name'] ) ) {
		$attributes['last_name'] = $fields['last_name'];
	}
	if ( ! empty( $fields['phone'] ) ) {
		$attributes['phone_number'] = $fields['phone'];
	}

	$response = wp_remote_post(
		'https://a.klaviyo.com/api/profile-import/',
		array(
			'timeout' => 15,
			'headers' => cfk_klaviyo_headers(),
			'body'    => wp_json_encode(
				array(
					'data' => array(
						'type'       => 'profile',
						'attributes' => $attributes,
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[CoolFormKit->Klaviyo] Profile import failed: ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		error_log( '[CoolFormKit->Klaviyo] Profile import returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
		return null;
	}

	return $body['data']['id'] ?? null;
}

/**
 * Subscribes the profile to the configured list (double opt-in follows the list's own settings in Klaviyo).
 */
function cfk_klaviyo_subscribe_to_list( $profile_id, $email ) {
	$response = wp_remote_post(
		'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/',
		array(
			'timeout' => 15,
			'headers' => cfk_klaviyo_headers(),
			'body'    => wp_json_encode(
				array(
					'data' => array(
						'type'          => 'profile-subscription-bulk-create-job',
						'attributes'    => array(
							'profiles' => array(
								'data' => array(
									array(
										'type'       => 'profile',
										'id'         => $profile_id,
										'attributes' => array(
											'email'                => $email,
											'subscriptions' => array(
												'email' => array(
													'marketing' => array( 'consent' => 'SUBSCRIBED' ),
												),
											),
										),
									),
								),
							),
						),
						'relationships' => array(
							'list' => array(
								'data' => array(
									'type' => 'list',
									'id'   => KLAVIYO_LIST_ID,
								),
							),
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[CoolFormKit->Klaviyo] List subscribe failed: ' . $response->get_error_message() );
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( '[CoolFormKit->Klaviyo] List subscribe returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
	}
}

function cfk_klaviyo_headers() {
	return array(
		'Authorization' => 'Klaviyo-API-Key ' . KLAVIYO_PRIVATE_API_KEY,
		'Content-Type'  => 'application/json',
		'Accept'        => 'application/json',
		'revision'      => KLAVIYO_API_REVISION,
	);
}
