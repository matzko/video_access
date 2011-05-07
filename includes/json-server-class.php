<?php

class Video_Access_JSON_RPC_Server extends WP_JSON_RPC_Server {
	
	public function __construct()
	{
		$this->methods['videoAccess.checkTranscodeStatus'] = 'this:checkTranscodeStatus';
		$this->methods['videoAccess.getVideoComments'] = 'this:getVideoComments';
		$this->methods['videoAccess.getVideoURL'] = 'this:getVideoURL';
		$this->methods['videoAccess.saveVideoPost'] = 'this:saveVideoPost';
	}

	public function checkTranscodeStatus( $args = null )
	{
		global $video_controlled_access, $blog_id;
		if ( isset( $args->{'attachment-id'} ) ) {
			$attach_id = (int) $args->{'attachment-id'}; 
			if ( current_user_can(  Video_Access_Model::UPLOAD_CAP, $attach_id ) ) {
				$msg = array( 'attach-id' => $attach_id );

				$thumbnail_img = get_post_meta( $attach_id, 'thumbnail_img', true );
				$video_file = get_post_meta( $attach_id, 'video_file', true );
				$log_file = get_post_meta( $attach_id, 'log_file', true );
				$perc_complete = empty( $log_file ) ? false : Video_Transcoding_Status::get_percentage_completed( $log_file );

				if ( false !== $perc_complete && empty( $thumbnail_img ) ) {
					$msg['status'] = 0;
					$msg['message'] = '<p>' .  __( 'We\'ll take care of the processing from here on, so you can close your browser or navigate to another page if it takes a while.', 'video-access' ) . '</p>';
					$msg['message'] .= '<p>' . sprintf( __( 'Video processing is %s%% complete.', 'video-access' ), $perc_complete ) . '</p>';
					$msg['thumb'] = false;
					$msg['transcode_complete'] = $perc_complete;
				} elseif ( empty( $video_file ) ) {
					$msg['status'] = 0;
					$msg['message'] = __( 'Your video is being processed...', 'video-access' );
					$msg['thumb'] = false;
				} elseif ( ! empty( $thumbnail_img ) ) {
					$msg['status'] = 1;
					$msg['message'] = __( 'Your video has been processed successfully.', 'video-access' );
					$msg['thumb'] = $video_controlled_access->model->get_video_image_url( $blog_id, $attach_id, 'thumbnail' );
					$video_id = get_post_meta( $attach_id, '_parent_site_video', true );
					if ( ! empty( $video_id ) ) {
						$msg['video-url'] = get_permalink( $video_id );
						$msg['message'] = sprintf( 
							__( 'Your video is ready. Click <a href="%1$s">here</a> to view it.', 'video-access' ),
							$msg['video-url']
						);
					}
				}

				return $msg;
			} else {
				return new WP_Error(
					-34000,
					sprintf(
						__( 'You do not have permission to access video ID %d', 'video-access' ), 
						$attach_id
					)
				);
			}
		}
	}

	public function getVideoComments( $args = null )
	{
		global $video_controlled_access, $blog_id;
		if ( ! empty( $args->{'video-id'} ) ) {
			$video_id = (int) $args->{'video-id'};  
			$comments_query = new WP_Query( array(
				'post_type' => 'site-video',
				'post__in' => array( $video_id ),
				'showposts' => 1,
			) );

			if ( $comments_query->have_posts() ) {
				$comments_query->the_post();
				ob_start();

				locate_template( array( 'video-comments.php' ), true );

				$markup = ob_get_clean();

				return array(
					'video-id' => $video_id,
					'video-comments-markup' => $markup,
				);
			}
		}
	}

	public function getVideoURL( $args = null )
	{
		global $video_controlled_access, $blog_id;
		if ( ! empty( $args->{'video-id'} ) ) {
			$video_id = (int) $args->{'video-id'};  
			$attach_id = get_post_meta( $video_id, '_original-attachment', true );
			if ( ! empty( $attach_id ) ) {
				$video_url = $video_controlled_access->model->get_video_url( $blog_id, $attach_id );
				$video_format = get_post_meta( $attach_id, 'video_format', true );
				$orig_height = get_post_meta( $attach_id, $video_format . '_height', true );
				$orig_width = get_post_meta( $attach_id, $video_format . '_width', true );
				if ( 0 == $orig_width )
					$orig_width = 1;

				// want the videos to be 500 px wide
				$width = 500;
				$height = $width * ( $orig_height / $orig_width );
				if ( $video_url ) {
					return array(
						'video-url' => $video_url,
						'video-id' => $video_id,
						'video-height' => $height,
						'video-width' => $width,
						'video-title' => get_the_title( $video_id ),
					);
				}
			}
		}
	}

	public function saveVideoPost( $args = null )
	{
		global $video_controlled_access;
		if ( ! empty( $args->{'form-data'} ) ) {
			$form_data = get_object_vars( $args->{'form-data'} );
			if ( ! empty( $form_data['flexible-uploader-attachment-id'] ) ) {
				if ( ! empty( $form_data['video-access-video-id'] ) ) {
					$old_url = get_permalink( $form_data['video-access-video-id'] );
				}
				$video_obj_id = $video_controlled_access->submit_form_data( 'video-upload-form', $form_data ); 
				if ( is_wp_error( $video_obj_id ) ) {
					return $video_obj_id;
				} else {
					return array(
						'video-object-id' => (int) $video_obj_id,
						'old-video-url' => $old_url,
						'new-video-url' => get_permalink( $video_obj_id ),
					);
				}
			}
		}
	}
}

function video_access_filter_json_server_classname( $server_class = '', $method = '' )
{
	switch( $method ) :
		case 'videoAccess.checkTranscodeStatus' :
		case 'videoAccess.getVideoComments' :
		case 'videoAccess.getVideoURL' :
		case 'videoAccess.saveVideoPost' :
			$server_class = 'Video_Access_JSON_RPC_Server';
		break;
	endswitch;
	return $server_class;
}

add_filter('json_server_classname', 'video_access_filter_json_server_classname', 10, 2);
