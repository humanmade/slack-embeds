<?php

namespace HM\SlackEmbeds\API;

use HM\SlackEmbeds;
use WP_Error;

const CACHE_LIFETIME = 3600;

/**
 * Send Slack web API request.
 *
 * @param string $method API method name
 * @param array $params Parameters for the API request (added as query arguments)
 * @return stdClass|WP_Error Object on success, error otherwise
 */
function request( $method, $params ) {
	$params['token'] = SlackEmbeds\get_token();

	$url = add_query_arg(
		urlencode_deep( $params ),
		sprintf( 'https://slack.com/api/%s', $method )
	);
	$response = wp_remote_get( $url );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error(
			'hm-slack-embeds.request.missing_body',
			__( 'Could not retrieve response body for request', 'hm-slack-embeds' ),
			compact( 'method', 'params', 'response' )
		);
	}

	$data = json_decode( $body );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error(
			'hm-slack-embeds.request.could_not_decode_json',
			sprintf(
				__( 'Could not decode JSON response: %s', 'hm-slack-embeds' ),
				json_last_error_msg()
			),
			compact( 'method', 'params', 'response' )
		);
	}

	if ( ! $data->ok ) {
		return new WP_Error(
			'hm-slack-embeds.request.not_ok',
			__( 'Slack returned a non-OK response', 'hm-slack-embeds' ),
			compact( 'method', 'params', 'response', 'data' )
		);
	}

	// Check for API warnings.
	if ( ! empty( $data->warning ) ) {
		SlackEmbeds\log(
			'warning',
			sprintf(
				__( 'Slack API warning: %s', 'hm-slack-embeds' ),
				$data->warning
			),
			compact( 'method', 'params' )
		);
	}

	return $data;
}

/**
 * Get a specific message from a channel.
 *
 * Messages in Slack are uniquely identified by timestamps, given to
 * microsecond-accuracy.
 *
 * @param string $channel Channel ID.
 * @param string $timestamp Message timestamp (in microseconds).
 * @return stdClass|WP_Error Message object on success, error otherwise.
 */
function get_message( $channel, $timestamp, $threaded_timestamp = null ) {
	$cache_key = sprintf( 'message:%s:%s', $channel, $timestamp );
	if ( $threaded_timestamp ) {
		$cache_key .= sprintf( ':thread_%s', $threaded_timestamp );
	}

	$cached = wp_cache_get( $cache_key, 'hm-slack-embeds' );
	if ( $cached ) {
		return $cached;
	}

	// Check we know how to deal with this channel type.
	$channel_type = substr( $channel, 0, 1 );
	if ( $channel_type !== 'C' ) {
		return new WP_Error(
			'hm-slack-embeds.get_message.invalid_channel_type',
			__( 'Invalid channel type', 'hm-slack-embeds' ),
			compact( 'channel', 'timestamp', 'channel_type' )
		);
	}

	// Build arguments for request.
	$ts = substr( $timestamp, 1, -6 ) . '.' . substr( $timestamp, -6 );
	$method = 'conversations.history';
	$args = [
		'channel' => $channel,
		'count' => 1,
		'latest' => $ts,
		'inclusive' => true,
	];

	// Swap out arguments for threads.
	if ( $threaded_timestamp ) {
		$method = 'conversations.replies';
		$args['ts'] = $ts;
		$args['latest'] = $threaded_timestamp;
	}

	$data = request( $method, $args );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( empty( $data->messages ) ) {
		return new WP_Error(
			'hm-slack-embeds.get_message.no_messages',
			__( 'No message found for given timestamp/channel', 'hm-slack-embeds' ),
			compact( 'channel', 'timestamp' )
		);
	}

	$message = $data->messages[0];
	wp_cache_set( $cache_key, $message, 'hm-slack-embeds', CACHE_LIFETIME );
	return $message;
}

/**
 * Get data for a user.
 *
 * @param string $id User ID.
 * @return stdClass|WP_Error User object on success, or error otherwise.
 */
function get_user( $id ) {
	$cached = wp_cache_get( 'user:' . $id, 'hm-slack-embeds' );
	if ( $cached ) {
		return $cached;
	}

	$user_data = request(
		'users.info',
		[
			'user' => $id,
		]
	);
	if ( is_wp_error( $user_data ) ) {
		return $user_data;
	}

	$user = $user_data->user;
	wp_cache_set( 'user:' . $id, $user, 'hm-slack-embeds', CACHE_LIFETIME );
	return $user;
}

/**
 * Get data for a bot.
 *
 * @param string $id Bot ID.
 * @return stdClass|WP_Error Bot object on success, or error otherwise.
 */
function get_bot( $id ) {
	$cached = wp_cache_get( 'bot:' . $id, 'hm-slack-embeds' );
	if ( $cached ) {
		return $cached;
	}

	$bot_data = request(
		'bots.info',
		[
			'bot' => $id,
		]
	);
	if ( is_wp_error( $bot_data ) ) {
		return $bot_data;
	}

	$bot = $bot_data->bot;
	wp_cache_set( 'bot:' . $id, $bot, 'hm-slack-embeds', CACHE_LIFETIME );
	return $bot;
}

/**
 * Get data for a channel.
 *
 * @param string $id Channel ID.
 * @return stdClass|WP_Error Channel object on success, or error otherwise.
 */
function get_channel( $id ) {
	$cached = wp_cache_get( 'channel:' . $id, 'hm-slack-embeds' );
	if ( $cached ) {
		return $cached;
	}

	$data = request(
		'conversations.info',
		[
			'channel' => $id,
		]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$channel = $data->channel;
	wp_cache_set( 'channel:' . $id, $channel, 'hm-slack-embeds', CACHE_LIFETIME );
	return $channel;
}
