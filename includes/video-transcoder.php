<?php

class Video_Transcoding_Control
{
	public $faststart = '/usr/bin/qt-faststart';
	public $ffmpeg_binary = '/usr/local/bin/ffmpeg';
	public $ffmpeg2theory_binary = '/usr/local/bin/ffmpeg2theora'; 
	public $php_exe = '/usr/bin/php'; 
	public $user_id = 0;
	public $video_auth_secret;
	public $video_debug = true;
	public $video_file_server;
	public $video_transcoder;

	public function __construct()
	{
		$this->video_auth_secret = AUTH_SALT . 'change this in your particular instantiation';
		$this->video_file_server = apply_filters( 'video_transcoding_video_file_server', get_site_url( null, '/' ) );
		$this->video_transcoder = apply_filters( 'video_transcoding_video_transcoder', get_site_url( null, '/' ) );
		$this->user_id = get_current_user_id();
	}

	/** 
	 * return the next next scrubber thumbnail name with next highest suffix
	 */
	public function get_next_scrubber_thumbnail_name( $info )
	{
		
		if ( empty( $info ))
			return ''; 
			
		$v_name  = preg_replace( '/\.\w+/', '', basename( $info->path ) ); 
		
		if ( empty( $info->thumbnail_files ) ) {
			$next_name = $v_name . '_scruberthumbnail_0.jpg'; 
			return $next_name; 
		}
		
		$files = unserialize( $info->thumbnail_files ); 
		
		$max = -1; 
		foreach( $files as $f ){
			
			preg_match( '/_scruberthumbnail_(\d+)\.jpg/', $f, $m ); 
			$num = (int)$m[1]; 
			
			if ( $max < $num )
				$max = $num; 
		}
		$max++; 
		
		$next_name = $v_name . '_scruberthumbnail_' . $max . '.jpg'; 
		
		return $next_name; 
	}

	public function is_video( $post_id )
	{
		return ( 0 === strpos( get_post_mime_type( $post_id ), 'video/' ) );
	}
	
	/**
	 * Constructs named arguments array for a function
	 *
	 * @param array $args The actual arguments given to the function
	 * @param array $required List of argument names, which are required for the function
	 * @param array $defaults Associative array of default values for some arguments
	 * @param array $null_defaults List of argument names, whose default value will be null
	 *
	 * @return mixed The array with the defaults set to missing arguments. If a required
	 * argument is missing, WP_Error is returned.
	 */
	public function named_args($args, $required = array(), $defaults = array(), $null_defaults = array() )
	{
	    $missing_required = array_diff( $required, array_keys( $args ) );
	    if ( $missing_required )
		return new WP_Error('missing_required_args', 'There are missing arguments: '. implode( ', ', $missing_required ) );
		foreach( $null_defaults as $null )
			if ( !isset( $defaults[$null] ) ) $defaults[$null] = null;
	    $args = array_merge($defaults, $args);
	    return $args;
	}

	/*
	 * encode the raw video into theora/ogg 
	 * No need to produce images. Then send it to file server
	 * return true if successful, false otherwise
	 */ 
	function ogg_transcode_and_send( $format, $job, $para_array )
	{
		global $wpdb, $video_file_servers; 
		
		extract( $para_array );  
		
		if ( !$this->_video_exists( $blog_id, $video_id ) )
			return false; 
			
		if ( $format == 'fmt1_ogg' ){ 
			
			$video_output_width = 400;
		} 
		
		$video_file = $file . '_fmt1.ogv'; 
		
		/*
		 * use the default videoquality and audioquality when clip dimension is small
		 * the default rate is already pretty high (~1300 kbps according to my tests)
		 * however, when original dimension is large, need to specify the quality paras
		 */
		 $cmd = $this->ffmpeg2theory_binary . " $file -o $video_file --width $video_output_width "; 
		 if ( $width >= 1000 ){
			$cmd .= ' --videoquality 9 --audioquality 6 '; 
		 }
		
		exec( $cmd, $lines, $r ); 
		
		if ( $this->video_debug )
			$this->_log_error( "cmd = $cmd "); 

		if ( $r !== 0 && $r != 1 ){
			$status = 'error_ffmpeg2theora_binary_transcode'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$msg = "video($bp): $status $video_url $format"; 
			$this->_log_error( $msg );
			return false; 
		}
		
		if ( !file_exists( $video_file ) || filesize( $video_file ) < 100 ) {
			$status = 'error_cannot_transcode'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$this->_log_error( "video($bp): $status $video_url $format" );
			return false; 
		}
			
		$para2_array = array( 'video_file' => $video_file ); 
							  
		$para3_array = array_merge( $para_array, $para2_array ); 
		
		/*
		 * sending the transcoded clips to file server
		 * since one particular file server can be busy at any time, 
		 * wpcom tries multiple file servers for maximum reliability.
		 */
		if ( defined('IS_WPCOM') && IS_WPCOM ) {
			
			$all_dc = array_keys( $video_file_servers ); 
			$dc = DATACENTER; 
			$external_dc = array_diff( $all_dc, array( $dc ) );

			$r = $this->_send_to_fileserver( $dc, $format, $para3_array ); 

			// the logic below is intentionally kept verbose to help identify potential last step issue
			if ( !$r ) { 
				//try the second dc
				$next = array_slice( $external_dc, 0, 1 ); 
				$dc = $next[0];
				$r = $this->_send_to_fileserver( $dc, $format, $para3_array ); 
			}

			if ( !$r ) { 
				//try the third dc
				$next = array_slice( $external_dc, 1, 1 ); 
				$dc = $next[0];
				$r = $this->_send_to_fileserver( $dc, $format, $para3_array ); 
			}

			if ( !$r ){
				$status = 'error_cannot_sendto_fileserver'; 
				$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
				$msg = "video($bp): $format $status after $pass_number passes" ;  
				$this->_log_error( $msg ); 
				$this->_video_cleanup( $file );
				return false; 	
			} 
			
		} else { //open source framework 
			
			$r = $this->_send_to_fileserver( '', $format, $para3_array ); 
			if ( !$r ) { 
				$status = 'error_cannot_sendto_fileserver'; 
				$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
				$msg = "video($bp): $format $status" ;  
				$this->_log_error( $msg ); 
				
				$this->_video_cleanup( $file );
				return false; 
			} 
		}
		
		return true; 
	}
		
	/**
	 * Sends raw video, and other info to remote video transcoding server for processing
	 * @param int $video_id The ID of the video attachment object.
	 */
	public function remote_transcode_one_video( $video_id = 0 )
	{
		global $wpdb, $current_blog, $current_user; 
		
		$blog_id = $current_blog->blog_id; 
		$video_id = (int) $video_id;
		
		/* 
		 * sanity check, make sure the type is video, 
		 * the attachment exists and it has not been transcoded already
		 */
		if ( ! $post = get_post( $video_id ) )
			return false; 

		if ( false === strpos( get_post_mime_type( $video_id ), 'video/' ) ) 
			return false;
		
		$info = $this->get_video_formats( $blog_id, $video_id ); 
		if ( ! empty( $info ) && 'done' == $this->video_format_status( $info, 'fmt_std' ) )
			return false; 
			
		$dc = DATACENTER; 
			
		/* 
		 * video_url should indicate the current file server 
		 * so that the video is immediately available for download, 
		 * right after the initial upload
		 * eg: http://files1.luv.wordpress.com/wp-content/blogs.dir/8e7/2168894/files/2008/04/clip5-matt.mp4
		 */
		$path = get_attached_file( $video_id ); 

		preg_match( '|/wp-content/blogs.dir\S+?files(.+)$|i', $path, $matches ); 
		
		$fileserver = $this->video_file_server; 
		
		$video_url = $fileserver . $matches[0]; 
		$short_path = $matches[1]; 
		
		$this->_video_create_info( $blog_id, $video_id, $short_path, $dc ); 
		
		// @todo determine whether to remove this, whether it's necessary
		sleep( 3 ); //allow db write to complete
		
		// fork a background child process to handle the request
		$cmd = $this->php_exe . ' ' . plugin_dir_path( __FILE__ ) . "do-transcoding.php $blog_id $video_id $this->user_id > /dev/null 2>&1 &"; 
		
		if ( $this->video_debug )
			$this->_log_debug_message( "cmd=$cmd" ); 
	
		exec( $cmd ); 
	}

	public function transcode_video( $blog_id = 0, $video_id = 0, $user_id = 0 )
	{
		global $wpdb; 
		$job = func_get_args();
		
		$blog_id     = (int) $blog_id; 
		$video_id     = (int) $video_id; 
		$user_id = (int) $user_id;
		
		if ( ! $this->_video_exists( $blog_id, $video_id ) ) {
			$this->_log_error( sprintf(
				'Video ID %1$d for blog %2$d does not exist.',
				$video_id,
				$blog_id
			) );
			return false; 
		}

		if ( $this->_is_video_finished( $blog_id, $video_id ) ) {
			$this->_log_error( sprintf(
				'Video ID %1$d for blog %2$d is already finished.',
				$video_id,
				$blog_id
			) );
			return false; 
		}
		
		$video_path   = get_attached_file( $video_id ); 
		
		$bp = "blog:$blog_id, post:$video_id"; 
		
		$this->_update_video_info( $blog_id, $video_id, 'fmt_std', 'transcoder_received_request' ); 

		/* 
		 * create a random file (eg, /tmp/video_clip1-hiking_7fEd98yC)
		 * to hold the video, which is to be downloaded
		 */
		preg_match( '|([^/]+)\.\w+$|', $video_path, $m ); 

		$file = '/tmp/video_'. $m[1] . '_' . $this->_video_generate_id(); 

		if ( ! copy( $video_path, $file ) ) {
			$status = 'error_transcoder_cannot_download_video'; 
			$this->_update_video_info( $blog_id, $video_id, 'fmt_std', $status ); 
			
			$msg = "video($bp): $status from $video_url after $pass_number passes" ; 
			$this->_log_error( $msg ); 
			return false;		
		}
			
		/*
		 * try to get video dimensions
		 * obtain the width and height from line. eg, 
		 * Stream #0.0: Video: mjpeg, yuvj422p, 640x480 [PAR 0:1 DAR 0:1], 10.00 tb(r)
		 * Also obtain the duration from line: " Duration: 00:02:41.5, start: 0.000000, bitrate: 3103 kb/s";
		 */ 
		$cmd = 'export LD_LIBRARY_PATH=/usr/local/lib; ' . $this->ffmpeg_binary . ' -i ' . $file  . ' 2>&1'; 
		$this->_log_debug_message(
			sprintf( 'executing cmd: %s', $cmd )
		);
		
		$lines = array(); 
		exec( $cmd, $lines, $r ); 
		
		if ( $r !== 0 && $r !== 1 ){
			//internal ffmpeg configuration issue
			$status = 'error_ffmpeg_binary_info'; 
			$this->_update_video_info( $blog_id, $video_id, 'fmt_std', $status ); 
			$this->_video_cleanup( $file ); 
			$msg = "video($bp): $status $video_id"; 
			$this->_log_error( $msg );

			return false;
		}

		$width = $height = 0; 
		$thumbnail_width = $thumbnail_height = 0; 

		foreach ( $lines as $line ) {
		// Stream #0.1(eng): Video: h264, yuv420p, 512x384, 38 kb/s, 9.07 fps, 30 tbr, 3k tbn, 6k tbc
			if ( preg_match( '/Stream.*Video:.* (\d+)x(\d+).* (\d+\.\d+) tb/', $line, $matches ) ) {
				$width      = $matches[1]; 
				$height     = $matches[2]; 
				$frame_rate = $matches[3];
			} elseif ( preg_match( '/Stream.*Video:.* (\d+)x(\d+).* ([0-9.]) [a-z]b/', $line, $matches ) ) {
				$width      = $matches[1]; 
				$height     = $matches[2]; 
				$frame_rate = $matches[3];
			} elseif ( preg_match( '/Stream.*Video:.* (\d+)x(\d+).* ([0-9.]+) [a-z]b/', $line, $matches ) ) {
				$width      = $matches[1]; 
				$height     = $matches[2]; 
				$frame_rate = $matches[3];
			}

			if ( preg_match( '/Duration:\s*([\d:.]+),/', $line, $matches ) ) 
				$duration = $matches[1]; 
		}

		if ( $width == 0 || $height == 0 ) {
			$status = 'error_cannot_obtain_width_height'; 
			$this->_update_video_info( $blog_id, $video_id, 'fmt_std', $status ); 
			$this->_video_cleanup( $file ); 
			$this->_log_error("video($bp): $status $video_id");
			return true; 
		} 

		$this->_update_video_info( $blog_id, $video_id, 'width',  $width );
		$this->_update_video_info( $blog_id, $video_id, 'height', $height );

		$n = preg_match( '/(\d+):(\d+):(\d+)./', $duration, $match); 
		if ( $n == 0) { 
			$status = 'error_cannot_obtain_duration'; 
			$this->_update_video_info( $blog_id, $video_id, 'fmt_std', $status ); 
			$this->_video_cleanup( $file ); 
			$this->_log_error("video($bp): $status $video_url");
			return true; 
		}

		$this->_update_video_info( $blog_id, $video_id, 'duration', $duration );

		$total_seconds = 3600 * $match[1] + 60 * $match[2] + $match[3]; 

		//user may delete video by now
		if ( ! $this->_video_exists( $blog_id, $video_id ) ){
			$this->_video_cleanup( $file ); 
			return false; 
		}
		
		$para_array = array( 
			'file'          => $file, 
			'video_path'     => $video_path, 
			'bp'            => $bp,
			'blog_id'       => $blog_id,
			'video_id'       => $video_id,
			'total_seconds' => $total_seconds,
			'width'         => $width,
			'height'        => $height,
			'frame_rate'    => $frame_rate,
			'user_id' => $user_id,
		); 
		
		/*
		 * 1 hour of fmt_std ~= 350 MB, fmt_dvd ~=700 MB, fmt_hd~= 1.5 G
		 * due to server limits, produce at most 2 hours of fmt_dvd, 1 hour of fmt_hd
		 */
		if ( $width >= 1280 && $height >= 720 ) {
		
			$r1 = $this->transcode_and_send( 'fmt_std', $job,  $para_array );
			if ( !$r1 ){
				$this->_video_cleanup( $file ); 
				return false; 	
			}
			
			if ( $total_seconds <= 2*60*60 ) {
				$r2 = $this->transcode_and_send( 'fmt_dvd', $job,  $para_array );
				if ( !$r2 ){
					$this->_video_cleanup( $file ); 
					return false; 	
				}
			}
			
			if ( $total_seconds <= 60*60 ){ 
				$r3 = $this->transcode_and_send( 'fmt_hd', $job,  $para_array );
				if ( !$r3 ){
					$this->_video_cleanup( $file ); 
					return false; 	
				}
			}
			
		} else if ( $width >= 640 && $height >= 360 ) {
		
			$r1 = $this->transcode_and_send( 'fmt_std', $job,  $para_array );
			if ( !$r1 ){
				$this->_video_cleanup( $file ); 
				return false; 	
			}
		
			if ( $total_seconds <= 2*60*60 ) {
				$r2 = $this->transcode_and_send( 'fmt_dvd', $job,  $para_array );
				if ( !$r2 ){
					$this->_video_cleanup( $file ); 
					return false; 	
				}
			}
			
		} else {
			$r1 = $this->transcode_and_send( 'fmt_std', $job,  $para_array );
			if ( !$r1 ){
				$this->_video_cleanup( $file ); 
				return false; 	
			}
		} 

		$r1 = $this->ogg_transcode_and_send( 'fmt1_ogg', $job,  $para_array );
		if ( !$r1 ){
			$this->_video_cleanup( $file ); 
			return false; 	
		}
			
		$this->_video_cleanup( $file ); 
		return true; 
	}

	/*
	 * encode the raw video into h.264 standard, dvd or hd format,
	 * also produce images. Then send them to file server
	 * return true if successful, false otherwise
	 */ 
	public function transcode_and_send( $format, $job, $para_array )
	{
		global $wpdb, $video_file_servers; 
		
		extract( $para_array );  
		
		if ( ! $this->_video_exists( $blog_id, $video_id ) )
			return false; 
			
		if ( $format == 'fmt_std' ){ 
			
			$video_output_width  = 400;
			$video_output_height = (int)( 400 * ($height/$width) );
			$thumbnail_width     = 256;
			$thumbnail_height    = (int)( 256 * ($height/$width) );
			$bitrate = ' -b 668k '; 
			 
		} else if ( $format == 'fmt_dvd' ){
			
			$video_output_width  = 640;
			$video_output_height = (int)( 640 * ($height/$width) );
			$thumbnail_width     = 256;
			$thumbnail_height    = (int)( 256 * ($height/$width) );
			$bitrate = ' -b 1400k '; 
			
		} else if ( $format == 'fmt_hd' ){
			
			$video_output_width  = 1280;
			$video_output_height = (int)( 1280 * ($height/$width) );
			$thumbnail_width     = 256;
			$thumbnail_height    = (int)( 256 * ($height/$width) );
			$bitrate = ' -b 3000k '; 
			
		} else {
			$status = 'wrong parameter: $format in transcode_and_send'; 
			$this->_video_cleanup( $file ); 
			$this->_log_error("video($bp): $status $video_url $format");
			return false; 
		}
		
		//frame size has to be multiple of 2
		if ( $video_output_width %2 == 1 )  $video_output_width--; 
		if ( $video_output_height %2 == 1 ) $video_output_height--; 
		if ( $thumbnail_width  %2 == 1 )    $thumbnail_width--; 
		if ( $thumbnail_height %2 == 1 )    $thumbnail_height--; 

		$temp_video_file = $file . '_temp.mp4';
		$video_file      = $file . '.mp4'; 
		$thumbnail_jpg   = $file . '.thumbnail.jpg'; 
		$original_jpg    = $file . '.original.jpg'; 
		
		$log_file = tempnam( sys_get_temp_dir(), 'video_processing_' . $video_id );
		chmod( $log_file, 0777 );
		update_post_meta( $video_id, 'log_file', $log_file );
		
		$cmd = 'export LD_LIBRARY_PATH=/usr/local/lib; ' . $this->ffmpeg_binary . " -i $file -y -acodec libfaac -ar 48000 -ab 128k -async 1 -s {$video_output_width}x{$video_output_height} -vcodec libx264 -threads 2 $bitrate -flags +loop -cmp +chroma -partitions +parti4x4+partp8x8+partb8x8 -flags2 +mixed_refs -me_method  epzs -subq 5 -trellis 1 -refs 5 -bf 3 -b_strategy 1 -coder 1 -me_range 16 -g 250 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 5 -qmax 51 -qdiff 4 "; 
		
		if ( $frame_rate > 100 ) //correct wrong frame rate resulted from corrupted meta data
			$cmd .= ' -r 30 '; 
		
		// $cmd .= $temp_video_file . " >>{$log_file} 2>&1"; 

		// ffmpeg outputs CR with no LF to make the progress lines overwrite each other in stdout
		// so we're converting to line breaks for better reading
		$cmd .= $temp_video_file . " 2>&1 | tr '\r' '\n' >{$log_file}"; 
		
		exec( $cmd, $lines, $r ); 

		if ( $this->video_debug )
			$this->_log_debug_message( "cmd = $cmd "); 

		if ( $r !== 0 && $r != 1 ){
			$status = 'error_ffmpeg_binary_transcode'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$msg = "video($bp): $status $video_url $format"; 
			$this->_log_error( $msg );
			die();
		} else {
			$this->_update_video_info( $blog_id, $video_id, $format . '_width', $video_output_width ); 
			$this->_update_video_info( $blog_id, $video_id, $format . '_height', $video_output_height ); 
		}
		
		if ( ! file_exists( $temp_video_file ) || filesize( $temp_video_file ) < 100 ) {
			$status = 'error_cannot_transcode'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$this->_log_error( "video($bp): $status $video_id $format" );
			return false; 
		}

		$cmd = $this->faststart . " $temp_video_file $video_file";
		exec( $cmd, $lines, $r ); 
		
		if ( $r !== 0 && $r != 1 ){
			$status = 'error_ffmpeg_binary_faststart'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$msg = "video($bp): $status $video_url $format"; 
			$this->_log_error( $msg );
			die();
		}

		// $thumbnail_jpg  = $this->_safe_get_thumbnail($file, $total_seconds, $thumbnail_jpg,  $thumbnail_width, $thumbnail_height ); 
		$original_jpg = $this->_safe_get_thumbnail($file, $total_seconds, $original_jpg,  $video_output_width, $video_output_height, $video_id, $user_id ); 

		if ( !($thumbnail_jpg && $original_jpg) ) {
			$status = 'error_cannot_get_thumbnail'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			$this->_log_error("video($bp): $status $video_url $format"); 
			return false; 
		}
		
		//user may delete video by now
		if ( ! $this->_video_exists( $blog_id, $video_id ) ){
			$this->_video_cleanup( $file ); 
			return false; 
		}
			
		$para2_array = array( 
			'video_file'    => $video_file,
			'thumbnail_jpg' => $thumbnail_jpg,
			'original_jpg'  => $original_jpg,
		); 
							  
		$para3_array = array_merge( $para_array, $para2_array ); 
		
		$r = $this->_send_to_fileserver( '', $format, $para3_array ); 
		if ( !$r ) { 
			$status = 'error_cannot_sendto_fileserver'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$msg = "video($bp): $format $status" ;  
			$this->_log_error( $msg ); 
			$this->_video_cleanup( $file );
			return false; 
		}
		return true; 
	}

	/**
	 * Create initial data values for the video attachment.
	 *
	 * @param int $blog_id blog id of the attachment
	 * @param int $video_id post_id of the attachment
	 * @param string $path short attachment file path in blog, like /2008/07/video 1.avi
	 */
	protected function _video_create_info( $blog_id = 0, $video_id = 0, $path = '' )
	{
		// $sql =  $wpdb->prepare( "INSERT INTO videos SET guid=%s, domain=%s, blog_id=%d, post_id=%d, path=%s, date_gmt=%s, dc=%s, fmt_std=%s ", $guid, $domain, $blog_id, $video_id, $path, $date_gmt, $dc, 'initiated' );
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		switch_to_blog( $blog_id );
		
		global $wpdb;

		// generate a unique guid 
		do {
			$guid = $this->_video_generate_id();
			$query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'video_guid' AND meta_value = %s LIMIT 1";
			$r = $wpdb->get_var( $wpdb->prepare( $query, $guid ) );
		} while ( ! empty( $r ) );	
		
		update_post_meta( $video_id, 'video_domain', $domain );
		update_post_meta( $video_id, 'guid', $guid );
		update_post_meta( $video_id, 'path', $path );
		update_post_meta( $video_id, 'fmt_std', 'initiated' );
		update_post_meta( $video_id, '_parent_site_video', $video_id );

		restore_current_blog();
	}

	protected function _video_finaltouch( 
		$blog_id = 0,
		$video_id = 0,
		$format = '',
		$video_file = '',
		$original_jpg = null,
		$thumbnail_jpg = null
	) {
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		switch_to_blog( $blog_id );
		
		$this->_update_video_info( $blog_id, $video_id, $format, 'fileserver_received_request' ); 

		$pathname = $file = get_attached_file( $video_id );

		$dir = dirname( $file );


		if ( $format == 'fmt_std' ){
			
			$video_pathname         = preg_replace( '/\.[^.]+$/', "_std.mp4", $pathname ); 
			$thumbnail_jpg_pathname = preg_replace( '/\.[^.]+$/', '_std.thumbnail.jpg', $pathname ); 
			$original_jpg_pathname  = preg_replace( '/\.[^.]+$/', '_std.original.jpg', $pathname ); 
			$files_col = 'std_files'; 
			
		} else if ( $format == 'fmt_dvd' ){
			
			$video_pathname         = preg_replace( '/\.[^.]+$/', "_dvd.mp4", $pathname ); 
			$thumbnail_jpg_pathname = preg_replace( '/\.[^.]+$/', '_dvd.thumbnail.jpg', $pathname ); 
			$original_jpg_pathname  = preg_replace( '/\.[^.]+$/', '_dvd.original.jpg', $pathname ); 
			$files_col = 'dvd_files'; 
			
		} else if ( $format == 'fmt_hd' ){
			
			$video_pathname         = preg_replace( '/\.[^.]+$/', "_hd.mp4", $pathname ); 
			$thumbnail_jpg_pathname = preg_replace( '/\.[^.]+$/', '_hd.thumbnail.jpg', $pathname ); 
			$original_jpg_pathname  = preg_replace( '/\.[^.]+$/', '_hd.original.jpg', $pathname ); 
			$files_col = 'hd_files'; 
			
		} else if ( $format == 'flv' ){
			
			$video_pathname         = preg_replace( '/\.[^.]+$/', ".flv", $pathname ); 
			$thumbnail_jpg_pathname = preg_replace( '/\.[^.]+$/', '.thumbnail.jpg', $pathname ); 
			$original_jpg_pathname  = preg_replace( '/\.[^.]+$/', '.original.jpg', $pathname ); 
			$files_col = 'flv_files'; 
			
		} else if ( $format == 'fmt1_ogg' ){
			
			$video_pathname = preg_replace( '/\.[^.]+$/', "_fmt1.ogv", $pathname ); 
		}

		$r1 = $r2 = $r3 = true; 
		$r1 = copy( $video_file, $video_pathname ); 

		if ( $format == 'flv' || $format == 'fmt_std' || $format == 'fmt_dvd' || $format == 'fmt_hd' ){ 
			// $r2 =  copy( $thumbnail_jpg, $thumbnail_jpg_pathname ); 
			$r3 =  copy( $original_jpg, $original_jpg_pathname ); 
		} 

		if ( !$r1 || !$r2 || !$r3 ) {
			$status = 'error_move_uploaded_file'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status );
			$this->_log_error("video($bp): $status $format"); 
			exit;
		}

		// initiate file replication, do video last

		if ( $format == 'flv' ) 
			$video_type = 'video/x-flv'; 
		else if ( $format == 'fmt_std' || $format == 'fmt_dvd' || $format == 'fmt_hd' )
			$video_type = 'video/mp4'; 
		else if ( $format == 'fmt1_ogg' ) 
			$video_type = 'video/ogg'; 
			
		if ( $format == 'flv' || $format == 'fmt_std' || $format == 'fmt_dvd' || $format == 'fmt_hd' ){ 
			
			$files_info = array( 
				'video_file'    => basename( $video_pathname ), 
				'original_img'  => basename( $original_jpg_pathname ), 
				'thumbnail_img' => basename( $thumbnail_jpg_pathname),
			); 
							 
			$video_file_hash = md5( md5( $video_pathname . get_current_user_id() . uniqid() ) ); 

			$this->_update_video_info( $blog_id, $video_id, $files_col, serialize( $files_info ) ); 
			$this->_update_video_info( $blog_id, $video_id, 'video_format', $format ); 
			$this->_update_video_info( $blog_id, $video_id, 'video_file', basename( $video_pathname ) ); 
			$this->_update_video_info( $blog_id, $video_id, 'video_file_full', $video_pathname ); 
			$this->_update_video_info( $blog_id, $video_id, 'video_file_hash', $video_file_hash );
			$this->_update_video_info( $blog_id, $video_id, 'original_img', basename( $original_jpg_pathname ) ); 
			$this->_update_video_info( $blog_id, $video_id, 'thumbnail_img', basename( $thumbnail_jpg_pathname ) ); 

			do_action( 'video_transcode_complete', $video_id );
		} 

		$this->_update_video_info( $blog_id, $video_id, $format, 'done' );

		$finish_date_gmt = gmdate( 'Y-m-d H:i:s' );
		$this->_update_video_info( $blog_id, $video_id, 'finish_date_gmt', $finish_date_gmt );

		restore_current_blog();
	}

	/* 
	 * given a file url, it downloads a file and saves it to file_target
	 * returns true if file is downloaded successfully
	 */
	public function video_file_download($file_source, $file_target) 
	{
		
		$rh = fopen($file_source, 'rb');
		$wh = fopen($file_target, 'wb');
		if ($rh===false || $wh===false) {
			return false;
		}
		
		while (!feof($rh)) {
			if (fwrite($wh, fread($rh, 1024)) === FALSE) {
				// 'Download error: Cannot write to file ('.$file_target.')';
				return false;
			}
		}
		fclose($rh);
		fclose($wh);
		
		if ( file_exists($file_target) )
			return true;
		else 
			return false; 
	}

	/* 
	 * wrapper function to return the status of a particular clip
	 * return '' if it does not exist; return its status otherwise
	 */
	public function video_format_status( $info, $format )
	{
		
		if ( empty( $info ) || empty( $format ) )
			return ''; 

		if ( is_object( $info ) ) {
			$info = get_object_vars( $info );
		}

		if ( $format == 'flv' || $format == 'fmt_std'  || $format == 'fmt_dvd' || $format == 'fmt_hd' ) {
			
			return isset( $info[ $format ] ) ? $info[ $format ] : ''; 

		} else if ( $format == 'fmt1_ogg' ){
			
			if ( empty( $info['fmts_ogg'] ) )
				return ''; 
			
			$r = preg_match( '/fmt1_ogg:([\w-]+);/', $info['fmts_ogg'], $m ); 
			if ( $r === 0 || $r === false )
				return ''; 
			else 
				return $m[1]; 
		} 
	}

	public function get_video_height( $blog_id = 0, $video_id = 0 )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		switch_to_blog( $blog_id );
		$height = get_post_meta( $video_id, 'height', true );
		restore_current_blog();
		return $height;
	}

	public function get_video_width( $blog_id = 0, $video_id = 0 )
	{
		switch_to_blog( $blog_id );
		$width = get_post_meta( $video_id, 'width', true );
		restore_current_blog();
		return $width;
	}

	/**
	 * Retrieves the corresponding formats for the given blog and video.
	 *
	 * @param int $blog_id The ID of the blog in question.
	 * @param int $video_id The ID of the video attachment string $blog_id and $post_id
	 * @return array List of formats of that video attachment.
	 */
	public function get_video_formats( $blog_id = 0, $video_id = 0 )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;	

		switch_to_blog( $blog_id );
		
		$key = 'video-info-by-' . $blog_id . '-' . $video_id; 
		if ( ! $info = wp_cache_get( $key, 'video-format-info' ) ) {
			$info = array_filter( (array) get_post_meta( $video_id, 'video_format' ) );
			wp_cache_set( $key, $info, 'video-format-info', 12*60*60 ); 
		}

		restore_current_blog();
		
		return $info; 
	}

	/**
	 * Retrieves the corresponding row in videos table, given the guid
	 *
	 * @param string $guid video guid
	 * @return mixed object or false on failure
	 */
	public function video_get_info_by_guid( $guid )
	{
		global $wpdb;
		
		$key = 'video-info-by-' . $guid; 
		
		$info = wp_cache_get( $key, 'video-info' ); 
		
		if ( $info == false ) {
			
			$info = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM videos WHERE guid=%s", $guid ) );
			
			if ( is_null( $info ) )
				$info = false; 
			else 
				wp_cache_set( $key, $info, 'video-info', 12*60*60 ); 
		} 
		
		return $info; 
	} 

	public function video_post_form( $action, $form, $args = '' ) 
	{

	    $defaults = array( 'CURLOPT_REFERER' => get_option( 'home' ), 'CURLOPT_RETURNTRANSFER' => 1, 'CURLOPT_TIMEOUT' => 1*60*60 ); 
	    
	    $args = wp_parse_args( $args, $defaults );
	    
	    $ch = curl_init($action);
	    foreach ( $args as $k => $v )
	    
		curl_setopt($ch, constant($k), $v);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
		$r = curl_exec($ch);
		curl_close($ch);
		
		return $r;
	}

	/**
	 * determine the preview image filename. 
	 * give priority to scrubber bar generated files
	 */
	public function video_preview_image_name( $format, $info )
	{
		
		//pick the latest scrubbed image file
		if ( !empty( $info->thumbnail_files ) ){ 
			
			$files = unserialize( $info->thumbnail_files ); 
			
			$max = -1; 
			foreach( $files as $f ){
				
				preg_match( '/_scruberthumbnail_(\d+)\.jpg/', $f, $m ); 
				$num = (int)$m[1]; 
				
				if ( $max < $num ){ 
					$max = $num; 
					$name = $f; 
				} 
			}
			return $name; 
		}
		
		//pick from default images
		if ( 'done' == $this->video_format_status( $info, 'flv' ) )
			$types[] = 'flv'; 
		if ( 'done' == $this->video_format_status( $info, 'fmt_std' ) )
			$types[] = 'fmt_std';
		if ( 'done' == $this->video_format_status( $info, 'fmt_dvd' ) )
			$types[] = 'fmt_dvd';
		if ( 'done' == $this->video_format_status( $info, 'fmt_hd' ) )
			$types[] = 'fmt_hd';
		
		if ( !in_array( $format, $types ) ){ 
			$format = 'fmt_std'; 
		} 
		
		if ( $format == 'flv' ){ 
			$files = unserialize( $info->flv_files ); 
		} else if ( $format == 'fmt_std' ){ 
			$files = unserialize( $info->std_files ); 
		} else if ( $format == 'fmt_dvd' ){ 
			$files = unserialize( $info->dvd_files ); 
		} else if ( $format == 'fmt_hd' ){ 
			$files = unserialize( $info->hd_files ); 
		}
		
		$name = $files[ 'original_img' ]; 
		
		return $name; 
	}

	protected function _get_thumbnail($file, $seek, $thumbnail_jpg, $thumbnail_width, $thumbnail_height)
	{ 
		
		$cmd = 'export LD_LIBRARY_PATH=/usr/local/lib; ' . $this->ffmpeg_binary . ' -y -i ' . $file . ' -f mjpeg ' . ' -vframes 1 -r 1 ' . ' -ss ' . $seek . ' -s ' . $thumbnail_width . 'x' . $thumbnail_height . ' -an ' . $thumbnail_jpg; 
		exec( $cmd, $lines, $r ); 

		clearstatcache();
		if ( file_exists($thumbnail_jpg) && filesize($thumbnail_jpg) > 0 )
			return true; 
		else { 
			return false;
		}
	}

	protected function _log_debug_message( $msg = '' )
	{
		error_log( $msg );
	}

	protected function _log_error( $err = '' )
	{
		error_log( $err );
	}

	/*
	 * handle boundary case when the video codec is malformed such as when 
	 * stream 1 codec frame rate differs from container frame rate: 1498.50 (2997/2) -> 29.97 (30000/1001)
	 * we have to give a smaller seek position and try to obtain the thumbnail 
	 */
	protected function _safe_get_thumbnail($file, $position, $thumbnail_jpg, $thumbnail_width, $thumbnail_height, $video_id = 0, $user_id = 0 )
	{
		$try = 0; 
		$seek = $position; 
		
		while ( $try++ < 10 ) {

			$seek = max ( (int)($seek/2), 0 ); 
			
			$r = $this->_get_thumbnail($file, $seek, $thumbnail_jpg, $thumbnail_width, $thumbnail_height); 
			if ( $r ) {
				return apply_filters( 'video_transcoding_create_thumbnail_path', $thumbnail_jpg, $thumbnail_width, $thumbnail_height, $user_id, $video_id );
			}
		}
		return false; 
	}

	/*
	 * POST video file and images to fileserver for final processing.
	 * Ogg video only has .ogv file alone
	 * return true if successful or video has been deleted;  false if not
	 */
	protected function _send_to_fileserver( $dc, $format, $para_array )
	{
		global $wpdb; 
		
		extract( $para_array ); 
		
		// if user deleted the video by this step, don't process it further
		if ( ! $this->_video_exists( $blog_id, $video_id ) )
			return true; 
		
		$this->_update_video_info( $blog_id, $video_id, $format, 'sending_to_fileserver' ); 
	 
		$form = array(); 
		$form['blog_id']       = $blog_id; 
		$form['video_id']       = $video_id; 
		$form['format']        = $format; 
		$form['auth']          = trim( 'saltedmd5' . md5( $this->video_auth_secret ) );
		$form['video_file']    = "@$video_file"; 
		
		if ( $format == 'flv' || $format == 'fmt_std' ||$format == 'fmt_dvd' ||$format == 'fmt_hd' ){
			$form['thumbnail_jpg'] = "@$thumbnail_jpg"; 
			$form['original_jpg']  = "@$original_jpg"; 
		} 
		
		$fileserver = $this->video_file_server; 

		if ( empty($fileserver) ) { 
			$status = 'error_no_fileserver'; 
			$this->_update_video_info( $blog_id, $video_id, $format, $status ); 
			$this->_video_cleanup( $file ); 
			
			$msg = "video($bp): $status $video_url $format" ;
			$this->_log_error( $msg ); 
			return false; 
		}

		$this->_video_finaltouch( $blog_id, $video_id, $format, $video_file, $original_jpg, $thumbnail_jpg );

		// if user deleted the video by this step, don't process it further
		if ( ! $this->_video_exists( $blog_id, $video_id ) )
			return true; 
			
		//check the db to make sure indeed everything is successful 
		sleep( 5 ); //wait for db write to take effect
		
		
		$info = $this->get_video_formats( $blog_id, $video_id ); 
		if ( 'done' == $this->video_format_status( $info, $format ) )
			return true;
		else { 
			$st = $this->video_format_status( $info, $format ); 
			$msg = "video($bp): $format sending to final_touch failed: $st" ;
			$this->_log_error( $msg ); 
			return false; 
		}
	}

	//clean up the residual files
	protected function _video_cleanup( $file = '' )
	{
		$cmd = 'rm ' . $file . '*'; 
		$this->_log_debug_message( "cmd: {$cmd}" );
		exec( $cmd ); 
		
		//clean up residues from crash, etc over 4 days ago
		$cmd2 = 'find /tmp -name video_* -ctime 4  -print | xargs /bin/rm -f'; 
		$this->_log_debug_message( "cmd 2: {$cmd2}" );
		exec( $cmd2 ); 
	}

	protected function _video_exists( $blog_id = 0, $video_object_id = 0 )
	{
		global $wpdb;
		$blog_id = (int) $blog_id;
		$video_object_id = (int) $video_object_id;

		$prefix = $wpdb->get_blog_prefix( $blog_id );

		$query = "SELECT COUNT(p.post_id)
			FROM {$prefix}postmeta AS p
			WHERE 
				p.meta_key = 'fmt_std' AND
				p.post_id = {$video_object_id}
		";

		return (bool) ( 0 < $wpdb->get_var( $query ) );
	}

	/**
	 * Generates random video id.
	 *
	 * Generates random alphanumeric id and DOESN'T make sure it is unique. 
	 *
	 * @param int $length length of the id (default: 8)
	 */
	protected function _video_generate_id( $length = 8 ) 
	{
	    $allowed = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	    $guid = '';
	    for ( $i = 0; $i < $length; ++$i )
		$guid .= $allowed[mt_rand(0, 61)];
	    return $guid;
	}

	protected function _video_is_transcoded( $blog_id = 0, $video_object_id = 0 )
	{
		global $wpdb;
		$blog_id = (int) $blog_id;
		$video_object_id = (int) $video_object_id;

		$prefix = $wpdb->get_blog_prefix( $blog_id );

		$query = "SELECT t.meta_value
			FROM {$prefix}postmeta AS p
			JOIN {$prefix}postmeta AS t
				ON p.post_id = t.post_id
			WHERE 
				p.meta_key = '_parent_site_video' AND
				p.meta_value = {$video_object_id} AND
				t.meta_key = '_transcoded'
		";

		return (bool) $wpdb->get_var( $query );
	}

	/**
	 * WPCOM specific - when system glitch happens, such as file server is super busy or down,
	 * try the same video job again after deferred amount of time
	 */
	protected function _video_try_again_later( $job )
	{
		$pass_number = $job->data->pass_number; 
		
		$new_job = clone $job; 
		$new_job->data->pass_number = $pass_number + 1; 
		
		if ( $pass_number == 0 )
			$delay = 2*60; 
		else if ( $pass_number == 1 )
			$delay = 2*60; 
		else if ( $pass_number == 2 )
			$delay = 2*60; 
			
		$when = time() + $delay; 
		deferred_video_job( $new_job->data, array(&$this, 'transcode_video'), $when ); 
	}
	
	/**
	 * checks to see whether video processing is completed, and all formats are produced
	 * @param int $blog_id The ID of the blog in question.
	 * @param int $video_id The video object ID
	 * @return true if yes, false otherwise or error
	 */
	protected function _is_video_finished( $blog_id, $video_id )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		$info = $this->get_video_formats( $blog_id, $video_id ); 
		
		if ( empty($info) )
			return false; 
		
		$height  = $this->get_video_height( $blog_id, $video_id ); 
		$width  = $this->get_video_width( $blog_id, $video_id ); 
		
		if ( $width >= 1280 && $height >= 720 ) {
			
			if ( 'done' == $this->video_format_status( $info, 'fmt_std' ) && 'done' == $this->video_format_status( $info, 'fmt_dvd' ) && 'done' == $this->video_format_status( $info, 'fmt_hd' ) )
				return true; 
				
		} else if ( $width >= 640 && $height >= 360 ) {
			
			if ( 'done' == $this->video_format_status( $info, 'fmt_std' ) && 'done' == $this->video_format_status( $info, 'fmt_dvd' ) )
				return true; 
				
		} else {
			if ( 'done' == $this->video_format_status( $info, 'fmt_std' ) )
				return true; 
		} 

		//if the video is determined to be un-trancodeable, it's also considered job finished
		$permanent_errors = array( 'error_cannot_transcode', 
				       'error_cannot_obtain_width_height',
				       'error_cannot_get_thumbnail',
				       'error_cannot_obtain_duration' ); 
				       
		$status = $this->video_format_status( $info, 'fmt_std' ); 	
		if ( in_array( $status, $permanent_errors ) )
			return true; 		

		return false; 
	}

	protected function _update_video_info( $blog_id = 0, $video_id = 0, $key = '', $value = null )
	{
		$blog_id     = (int) $blog_id; 
		$video_id     = (int) $video_id; 

		switch_to_blog( $blog_id );
		
		if ( $key == 'fmt1_ogg' ){
			
			$existing_val = get_post_meta( $video_id, 'fmts_ogg', true ); 
			$this_val = 'fmt1_ogg:' . $value . ';'; 
			
			if ( empty( $existing_val ) )
				$new_val = $this_val; 
			else 
				$new_val = preg_replace( '/fmt1_ogg:[\w-]+;/', $this_val, $existing_val ); 
			
			update_post_meta( $video_id, 'fmts_ogg', $new_val ); 
			
		} else { 
			update_post_meta( $video_id, $key, $value ); 
		} 
		
		//remove relevant cache  
		$guid = get_post_meta( $video_id, 'guid', true );
		$key1 = 'video-info-by-' . $blog_id . '-' . $video_id; 
		wp_cache_delete( $key1, 'video-info' ); 
		
		$key2 = 'video-info-by-' . $guid; 
		wp_cache_delete( $key2, 'video-info' ); 
		
		$key3 = 'video-xml-by-' . $guid; 
		wp_cache_delete( $key3, 'video-info' ); 
		
		restore_current_blog();

		return true; 
	}
}

class Video_Transcoding_Status
{
	public static function get_percentage_completed( $log_file = '' )
	{
		if ( file_exists( $log_file ) ) {
			return self::_get_completed_percentage( $log_file );
		}
		return 0;
	}

	protected function _get_duration_seconds_from_log( $log_file = '' )
	{
		$content = file_get_contents( $log_file );
		if ( preg_match( '/Duration:\s*([\d:.]+),/', $content, $matches ) ) {
			if ( preg_match( '/(\d+):(\d+):(\d+)./', $matches[1], $duration_match ) ) {
				$secs = ( 360 * intval( $duration_match[1] ) ) +
					( 60 * intval( $duration_match[2] ) ) +
					( intval( floor( $duration_match[3] ) ) );

				return $secs;
			}
		}

		return false;
	}

	protected function _get_completed_seconds_from_log( $log_file = '' )
	{
		$output = shell_exec( "tail -n 1 {$log_file}" );
		if ( preg_match( '/time=([0-9.]+)\s/', $output, $matches ) ) {
			return (int) floor( $matches[1] );
		} else {
			// when completed, there are a bunch of extra lines at the end to ignore
			$output = shell_exec( "tail -n 30 {$log_file}" );
			if ( preg_match_all( '/time=([0-9.]+)\s/', $output, $all_matches ) ) {
				$last = array_pop( $all_matches[1] );
				return (int) floor( $last );
			}
		}
		return false;
	}

	protected function _get_completed_percentage( $log_file  = '' )
	{
		$total = self::_get_duration_seconds_from_log( $log_file );
		$completed = self::_get_completed_seconds_from_log( $log_file );
		$perc = 0;
		if ( 0 < $total ) {
			$perc = (int) floor( 100 * ( $completed / $total ) );
		}
		return $perc;
	}

}
