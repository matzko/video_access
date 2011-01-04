<?php
/*
Plugin Name: Filosofo JavaScript Library
Plugin URI:
Description: A library of common JS functionality used by Austin Matzko (filosofo) in WordPress plugins.
Author: Austin Matzko
Author URI: http://austinmatzko.com
Version: 1.0
*/

if ( ! function_exists( 'init_filosofo_js_common_library' ) ) {

	function init_filosofo_js_common_library()
	{
		wp_register_script( 
			'filosofo-common-js',
			plugin_dir_url( __FILE__ ) . 'client-files/js/filosofo-js.js',
			null,
			'1.0'
		);
	}
	
	function enqueue_filosofo_js_common_library()
	{
		wp_enqueue_script( 'filosofo-common-js' );
	}

	add_action( 'init', 'init_filosofo_js_common_library' );
	add_action( 'wp_enqueue_scripts', 'enqueue_filosofo_js_common_library' );
}

// eof
