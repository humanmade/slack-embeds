<?php

namespace HM\SlackEmbeds\Embed;

use function HM\SlackEmbeds\log;
use HM\SlackEmbeds;
use HM\SlackEmbeds\API;
use WP_Error;

/**
 * Regular expression to match Slack message URLs.
 *
 * Slack message URLs are in one of the following formats:
 *
 * Channel messages:
 *   https://yourteam.slack.com/archives/C0123ABYZ/p1234567890123456
 *
 * Direct messages:
 *   https://yourteam.slack.com/archives/D0123ABYZ/p1234567890123456
 *
 * Group messages:
 *   https://yourteam.slack.com/archives/G0123ABYZ/p1234567890123456
 */
const MESSAGE_REGEX = '#https?://([a-zA-Z0-9]+).slack.com/archives/([^/]+)/([^/]+?)(\?.+)?$#';

/**
 * Bootstrap the plugin.
 */
function bootstrap() {
	wp_embed_register_handler( 'hm_slack_embed', MESSAGE_REGEX, __NAMESPACE__ . '\\handle_message_embed' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles', 100 );
}

/**
 * Enqueue the embed styles.
 */
function enqueue_styles() {
	wp_enqueue_style( 'hm-slack-embed-fonts', 'https://fonts.googleapis.com/css?family=Lato' );
	wp_enqueue_style( 'hm-slack-embed', plugins_url( 'assets/style.css', SlackEmbeds\FILE ), [ 'hm-slack-embed-fonts' ] );
}

/**
 * Handle embedding a Slack message.
 *
 * @param array $matches Regular expression matches
 * @param string $url Embed URL
 * @param array $attr Shortcode attributes
 */
function handle_message_embed( $matches, $attr, $url, $rawattr ) {
	list( $matched, $team, $channel, $message ) = $matches;
	$params = wp_parse_args( wp_parse_url( $url, PHP_URL_QUERY ) );

	$threaded_message = $params['thread_ts'] ?? null;

	// Generate default embed to use if we have any errors.
	$default_embed = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $url ),
		esc_html( $url )
	);

	if ( ! SlackEmbeds\get_token() ) {
		log( 'warning', 'No token set for HM Slack Embeds' );
		return $default_embed;
	}

	// Fetch the message by timestamp.
	$message = API\get_message( $channel, $message, $threaded_message );
	if ( is_wp_error( $message ) ) {
		log( 'warning', $message, compact( 'url', 'channel', 'timestamp' ) );
		return $default_embed;
	}

	// Fetch the user too.
	if ( ! empty( $message->user ) ) {
		$user = API\get_user( $message->user );
		if ( is_wp_error( $user ) ) {
			log( 'warning', $user, compact( 'url', 'message' ) );
			return $default_embed;
		}
	} elseif ( ! empty( $message->bot_id ) ) {
		// Bot user.
		$user = API\get_bot( $message->bot_id );
		if ( is_wp_error( $user ) ) {
			log( 'warning', $user, compact( 'url', 'message' ) );
			return $default_embed;
		}
	}

	// Fetch the channel data.
	$channel = API\get_channel( $channel );
	if ( is_wp_error( $channel ) ) {
		log( 'warning', $channel, compact( 'url', 'channel' ) );
		return $default_embed;
	}

	return render_message( $team, $message, $channel, $user, $threaded_message );
}

/**
 * Format a message into the HTML for embedding.
 *
 * @param stdClass $message Message object from Slack API
 * @param stdClass $channel Channel object from Slack API
 * @param stdClass $user User object from Slack API for message author
 * @param string|null $thread Thread timestamp, if available
 * @return string HTML for embed
 */
function render_message( $team, $message, $channel, $user, $thread = null ) {
	$message_link = sprintf( 'https://%s.slack.com/archives/%s/p%s', $team, $channel->id, str_replace( '.', '', $message->ts ) );
	if ( $thread ) {
		$message_link = add_query_arg( 'thread_ts', urlencode( $thread ), $message_link );
	}
	$user_link = sprintf( 'https://%s.slack.com/team/%s', $team, $user->id );

	$avatar = sprintf(
		'<img
			class="hm-slack-embed__author_icon"
			alt="%s"
			src="%s"
			width="16"
			height="16"
		/>',
		empty( $user->real_name ) ? esc_attr( $user->name ) : esc_attr( $user->real_name ),
		empty( $user->profile ) ? esc_url( $user->icons->image_36 ) : esc_url( $user->profile->image_32 )
	);
	$header = sprintf(
		'<div class="hm-slack-embed__author"><a href="%s">%s%s</a></div>',
		esc_url( $user_link ),
		$avatar,
		empty( $user->real_name ) ? esc_attr( $user->name ) : esc_attr( $user->real_name )
	);
	$text = sprintf(
		'<p class="hm-slack-embed__text">%s</p>',
		format_message_text( $team, $message->text )
	);
	// $icon = sprintf(
	// 	'<img
	// 		class="hm-slack-embed__footer_icon"
	// 		alt="Slack logo"
	// 		src="%s"
	// 		width="16"
	// 		height="16"
	// 	/>',
	// 	plugins_url( 'assets/slack_mark.svg', FILE )
	// );
	$icon = '<span class="hm-slack-embed__footer_icon">&nbsp;</span>';
	$posted_in = sprintf(
		$thread ? '<a href="%s">From a thread in #%s</a>' : '<a href="%s">Posted in #%s</a>',
		esc_url( $message_link ),
		esc_html( $channel->name )
	);
	$footer = sprintf(
		'<div class="hm-slack-embed__footer">%s<time class="hm-slack-embed__timestamp"><a href="%s">%s</a></time></div>',
		$icon . $posted_in,
		esc_url( $message_link ),
		date_i18n( 'M jS \a\t H:i (T)', $message->ts )
	);

	return sprintf(
		'<div class="hm-slack-embed">%s%s%s</div>',
		$header,
		$text,
		$footer
	);
}

/**
 * Convert raw Slack message text to HTML.
 *
 * Converts Slack's Markdown-like format (mrkdwn) and links to HTML.
 *
 * @link https://api.slack.com/docs/message-formatting
 *
 * @param string $team Team ID.
 * @param string $message Message text.
 * @return string Message HTML (safe for output)
 */
function format_message_text( $team, $message ) {
	$linkify = function ( $matches ) use ( $team ) {
		list( $text, $link ) = $matches;
		$label = $matches[2] ?? null;

		$prefix = substr( $link, 0, 2 );

		// Channel mentions.
		if ( $prefix === '#C' ) {
			$id = substr( $link, 1 );
			$url = sprintf(
				'https://%s.slack.com/archives/%s',
				$team,
				$id
			);

			if ( empty( $label ) || true ) {
				$channel = API\get_channel( $id );
				$label = $channel->name;
			}

			return sprintf(
				'<a href="%s">#%s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		// User mentions.
		if ( $prefix === '@U' || $prefix === '@W' ) {
			$id = substr( $link, 1 );
			$url = sprintf(
				'https://%s.slack.com/team/%s',
				$team,
				$id
			);

			if ( empty( $label ) ) {
				$user = API\get_user( $id );
				$label = $user->name;
			}

			return sprintf(
				'<a href="%s">@%s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		// Special commands.
		if ( substr( $link, 0, 1 ) === '!' ) {
			$command = substr( $link, 0, strpos( $link, '^' ) );
			switch ( $command ) {
				case '!here':
				case '!channel':
				case '!everyone':
					return esc_html( '@' . substr( $link, 1 ) );

				case '!subteam':
					return esc_html( '@' . $label );

				case '!date':
					return esc_html( $label );

				default:
					return esc_html( $link );
			}
		}

		// Link fallback.
		if ( empty( $label ) ) {
			$label = $link;
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $link ),
			$label
		);
	};

	// Replace links.
	$linked = preg_replace_callback( '#<(.*?)(?:\|(.+?))?>#', $linkify, $message );

	// Replace mrkdwn.
	$formatted = preg_replace(
		[
			'#\B\*(.+?)\*\B#',
			'#\b_(.+?)_\b#',
			'#\B~(.+?)~\B#',
			'#\B`(.+?)`\B#',
		],
		[
			'<strong>$1</strong>',
			'<em>$1</em>',
			'<strike>$1</strike>',
			'<code>$1</code>',
		],
		$linked
	);

	// Replace newlines, and avoid wpautop later.
	$content = str_replace( [ "\r\n", "\n" ], '<br data-hm-slack-no-wpautop />', $formatted );
	return $content;
}
