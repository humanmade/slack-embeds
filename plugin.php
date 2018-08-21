<?php
/**
 * Plugin Name: Slack Embeds for WordPress
 * Description: Embed Slack messages in WordPress posts.
 * Author: Human Made
 * Author URI: https://humanmade.com/
 * Version: 0.1
 */

namespace HM\SlackEmbeds;

const FILE = __FILE__;

require __DIR__ . '/inc/api/namespace.php';
require __DIR__ . '/inc/embed/namespace.php';
require __DIR__ . '/inc/namespace.php';

bootstrap();
