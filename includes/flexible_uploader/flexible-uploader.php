<?php
/*
Plugin Name: Flexible Uploader
Plugin URI: http://austinmatzko.com/wordpress-plugins/flexible-uploader/
Description: Use an uploader in a flexible manner.
Author: Austin Matzko
Author URI: http://austinmatzko.com
Version: 1.0
*/


if ( version_compare( PHP_VERSION, '5.2.0') >= 0 ) {

	require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'core.php';
	
} else {
	
	function flexible_uploader_php_version_message()
	{
		?>
		<div id="flexible-uploader-warning" class="updated fade error">
			<p>
				<?php 
				printf(
					__('<strong>ERROR</strong>: Your WordPress site is using an outdated version of PHP, %s.  Version 5.2 of PHP is required to use the flexible uploader plugin. Please ask your host to update.', 'flexible-uploader'),
					PHP_VERSION
				);
				?>
			</p>
		</div>
		<?php
	}

	add_action('admin_notices', 'flexible_uploader_php_version_message');
}

function flexible_uploader_init_event()
{
	load_plugin_textdomain('flexible-uploader', null, dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'l10n');
}

add_action('init', 'flexible_uploader_init_event');
