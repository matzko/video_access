<?php


$dir = dirname(dirname(dirname(dirname(dirname( __FILE__ )))));
$config_contents = file_get_contents( $dir . DIRECTORY_SEPARATOR . 'wp-config.php' );
if ( preg_match( '#define.*[\'"]DOMAIN_CURRENT_SITE[\'"].+?[\'"]([^\'"]*)[\'"]#', $config_contents, $matches ) ) {
	$_SERVER['HTTP_HOST'] = $matches[1];
}

$path = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
chdir( $dir );

if ( file_exists( $path ) ) {
	// require $path;
	require 'wp-load.php';
} else {
	error_log( sprintf(
		'The WP init file wp-load.php does not appear to exist at %s',
		$path
	) );
	exit;
}

ignore_user_abort();

$blog_id = (int) $_SERVER['argv'][1];
$video_id = (int) $_SERVER['argv'][2];
$user_id = (int) $_SERVER['argv'][3];

error_log( sprintf( 
	'Transcoder has received a request to encode video ID %1$d on blog %2$d for user %3$d',
	$video_id,
	$blog_id,
	$user_id
) );

if ( function_exists( 'switch_to_blog' ) ) {	
	switch_to_blog( $blog_id );
}

global $video_transcoding_control;

$video_transcoding_control->transcode_video( $blog_id, $video_id, $user_id );
