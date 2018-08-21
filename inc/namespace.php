<?php

namespace HM\SlackEmbeds;

use WP_Error;

const TOKEN_OPTION = 'hm-slack-embeds-token';

/**
 * Bootstrap the plugin.
 */
function bootstrap() {
	Embed\bootstrap();
	register_setting( 'writing', TOKEN_OPTION );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_setting_field' );
}

/**
 * Get the access token for Slack.
 *
 * @return string|null Token if set, null otherwise.
 */
function get_token() {
	$value = get_option( TOKEN_OPTION, null );
	return apply_filters( 'hm.slack-embeds.token', $value );
}

/**
 * Register the token setting field.
 */
function register_setting_field() {
	add_settings_section( 'hm-slack-embeds', 'HM Slack Embeds', '__return_null', 'writing' );
	add_settings_field(
		TOKEN_OPTION,
		__( 'Slack access token', 'hm-slack-embeds' ),
		__NAMESPACE__ . '\\render_settings_field',
		'writing',
		'hm-slack-embeds',
		[
			'label_for' => TOKEN_OPTION,
		]
	);
}

/**
 * Render the settings field.
 */
function render_settings_field( $args ) {
	printf(
		'<input type="text" id="%1$s" name="%1$s" value="%2$s" /><p class="description">%3$s</p>',
		esc_attr( TOKEN_OPTION ),
		esc_attr( get_token() ),
		esc_html__( 'Access token to use when embedding Slack messages in content', 'hm-slack-embeds' )
	);
}

/**
 * Log a notice/warning/error message.
 *
 * This uses Query Monitor's PSR-3 logging support.
 *
 * @param string $level One of 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
 * @param string|WP_Error $message Message to log, or error object.
 * @param array $context Context to log with the message
 */
function log( $level, $message, $context = [] ) {
	if ( is_wp_error( $message ) ) {
		$context['error_data'] = $message->get_error_data();
		$message = sprintf( '%s [%s]', $message->get_error_message(), $message->get_error_code() );
	}

	do_action( 'qm/log', $level, $message, $context );
}
