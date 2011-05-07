<?php
/*
Plugin Name:Video Access
Plugin URI:
Description: Restricted use of videos.
Version: 1.0
Author: Austin Matzko
Author URI: http://austinmatzko.com
*/

class Video_Access_Control
{
	public $model;
	public $view;

	protected $_video_edit = 0;

	public function __construct()
	{
		global $wp_flexible_uploader, $video_transcoding_control;

		if ( ! defined( 'VIDEO_ACCESS_SECRET_SUBDIR' ) ) {
			// the parent directory of the video directories, meant to be unknown to the public to prevent unauthorized video access
			// hence you should define your own value in wp-config.php
			define( 'VIDEO_ACCESS_SECRET_DIR', '93b0f8b640c357ba3432b5d679d5aedd' );
		}

		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'custom_image_sizes' . DIRECTORY_SEPARATOR . 'filosofo-custom-image-sizes.php';
		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'flexible_uploader' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR  . 'core.php';
		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'wp-json-rpc-api' . DIRECTORY_SEPARATOR . 'wp-json-rpc-api.php';
		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'wp-filosofo-js-library' . DIRECTORY_SEPARATOR . 'wp-filosofo-js-library.php';
		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'video-transcoder.php';
		include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'local_video_player' . DIRECTORY_SEPARATOR . 'local-video-player.php';
		
		global $filosofo_custom_image_sizes;

		if ( empty( $filosofo_custom_image_sizes ) && function_exists( 'initialize_custom_image_sizes' ) ) {
			initialize_custom_image_sizes();
		}

		if ( function_exists( 'load_wp_json_rpc_api' ) ) {
			load_wp_json_rpc_api();
			include dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'json-server-class.php';
		}
		
		$control = apply_filters( 'wp_flexible_uploader_control', 'WP_Flexible_Uploader_Control' ); 
		$wp_flexible_uploader = new $control;

		$video_transcoding_control = new Video_Transcoding_Control;
		load_local_video_player();

		$this->model = new Video_Access_Model;
		$this->view = new Video_Access_View;

		add_action( 'add_attachment', array(&$video_transcoding_control, 'remote_transcode_one_video' ) );
		add_action( 'video_access_markup', array(&$this, 'event_video_access_markup' ) );
		add_action( 'init', array(&$this, 'event_init' ), 1 );
		add_action( 'template_redirect', array(&$this, 'event_template_redirect' ) );
		add_action( 'video_transcode_complete', array( $this, 'event_video_transcode_complete' ) ); 
		add_action( 'wp_footer', array(&$this, 'event_wp_footer' ) );
		add_action( 'wp_flexible_uploader_ending_form', array(&$this, 'event_wp_flexible_uploader_ending_form' ) );
		add_action( 'wp_flexible_uploader_starting_form', array(&$this, 'event_wp_flexible_uploader_starting_form' ) );
		add_action( 'wp_head', array(&$this, 'event_wp_head' ) );

		add_filter( 'flexible_uploader_uploads_dir', array(&$this, 'filter_flexible_uploader_uploads_dir' ) ); 
		add_filter( 'flex_uploader_attachment_properties', array($this->model, 'filter_attach_props_to_set_parent' ), 10, 2 );
		add_filter( 'the_content', array(&$this, 'filter_late_the_content' ), 99 );
		add_filter( 'upload_mimes', array(&$this, 'filter_upload_mimes_add'), 99 );
		add_filter( 'video_transcoding_create_thumbnail_path', array($this, 'filter_video_transcoding_create_thumbnail_path'), 10, 5 ); 
		add_filter( 'wp_flexible_uploader_allowed_file_types', array(&$this, 'filter_wp_flexible_uploader_allowed_file_types' ) ); 
		add_filter( 'wp_flexible_uploader_browsefiles_filter', array($this, 'filter_wp_flexible_uploader_browsefiles_filter' ) );
		add_filter( 'wp_flexible_uploader_form_browse', array($this, 'filter_wp_flexible_uploader_form_browse' ), 10, 2 );
		add_filter( 'wp_flexible_uploader_form_extra_fields', array(&$this, 'filter_wp_flexible_uploader_form_extra_fields' ) ); 
		add_filter( 'wp_flexible_uploader_form_instructions', '__return_false' );
		add_filter( 'wp_flexible_uploader_form_submit', '__return_false' );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_wp_get_attachment_url' ), 10, 2 );
		add_filter( 'wp_handle_upload', array( $this, 'filter_wp_handle_upload' ), 10, 2 );

		add_image_size( 'video-thumbnail', 95, 64, true );
	}

	public function filter_late_the_content( $content = '' )
	{
		global $wp_flexible_uploader;
		$post_obj = get_queried_object();
		$edit_request = (int) get_query_var( 'edit-video' );

		if ( 
			! empty( $edit_request ) &&
			! empty( $post_obj ) && 
			'site-video' == $post_obj->post_type &&
			$edit_request == $post_obj->ID &&
			current_user_can(  Video_Access_Model::UPLOAD_CAP, $post_obj->ID )
		) {
			ob_start();
			$wp_flexible_uploader->view->print_form();
			$content = ob_get_clean();
		}

		return $content;
	}

	public function event_video_access_appcontainer_start( $args = array() )
	{
		$template = $args['template'];	
		$current_user = get_user_by( 'id', $args['current_user'] );
		switch ( $template ) :
			case 'archive' :
			case 'single' :
				?>
				<div class="section-meta">
					<div class="meta-selection-wrap">
						<div class="meta-selection-description">do something with the dropdown</div>
						<select>
							<option>one</option>
							<option>two</option>
							<option>three</option>
						</select>
					</div>
				</div>
				<?php
			break;
		endswitch;
	}

	public function event_video_access_markup( $args = array() )
	{
		global $blog_id, $local_video_player;

		$video_id = isset( $args['video_id'] ) ? (int) $args['video_id'] : 0;
		$edit_request = (int) get_query_var( 'edit-video' );
		if ( ! empty( $video_id ) ) {
			// edit request: let's show the form for editing the video, instead of the video markup
			if ( empty( $edit_request ) || $edit_request != $video_id ) {
				$attach_id = get_post_meta( $video_id, '_original-attachment', true );

				if ( ! empty( $attach_id ) ) {
					$video_format = get_post_meta( $attach_id, 'video_format', true );
					$orig_height = get_post_meta( $attach_id, $video_format . '_height', true );
					$orig_width = get_post_meta( $attach_id, $video_format . '_width', true );
					if ( 0 == $orig_width )
						$orig_width = 1;

					// want the videos to be 500 px wide
					$width = 500;
					$height = $width * ( $orig_height / $orig_width );

					
					$video_url = $this->model->get_video_url( $blog_id, $attach_id );

					echo $local_video_player->print_video_player( $video_url, 'single-video-object-' . $attach_id, $height, $width );

					if ( current_user_can(  Video_Access_Model::UPLOAD_CAP, $video_id ) ) {
						?>
						<div class="edit-wrap">
							<?php // edit_post_link( __( 'Edit', 'video-access' ), '<span class="edit-link">', '</span>' ); ?>
							<span class="delete-link">
								<a href="<?php
									echo add_query_arg( array(
										'delete-video' => $video_id,
										'video-access-delete-video' => wp_create_nonce( 'video-delete-nonce' ),
									) );
								?>" class="delete-video-link"><?php _e( 'Delete', 'video-access' ); ?></a>
							</span>
						</div>
						<?php
					}
				}
			}
		}
	}

	public function event_init()
	{
		global $wp, $wp_rewrite;
		load_plugin_textdomain('video-access', false, dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'l10n' );

		register_post_type( 'site-video', array(
			'labels' => array(
				'label' => __('Video', 'video-access'),
				'name' => __('Videos', 'video-access'),
				'add_new' => __('Add New Video', 'video-access'),
				'singular_name' => __('Video', 'video-access'),
				'add_new_item' => __('Add New Video', 'video-access'),
				'edit_item' => __('Edit Video', 'video-access'),
				'new_item' => __('New Video', 'video-access'),
				'view_item' => __('View Video', 'video-access'),
				'search_items' => __('Search Videos', 'video-access'),
				'not_found' => __('No Videos found', 'video-access'),
				'not_found_in_trash' => __('No Videos found in Trash', 'video-access'),
			),
			'public' => true,
			'show_ui' => true,
			'capability_type' => 'post',
			'has_archive' => true,
			'hierarchical' => false,
			'rewrite' => array(
				'slug' => 'videos',
			),
			'query_var' => true,
			'supports' => array( 
				'comments',
				'title', 
				'editor', 
				'thumbnail',
			),
		) );

		add_rewrite_endpoint( 'edit-video', EP_ALL );
		add_rewrite_endpoint( 'upload-video', EP_ALL );
		add_rewrite_rule( "videos/video-type/([^/]+)/{$wp_rewrite->pagination_base}/([0-9]{1,})/?$", 'index.php?post_type=site-video&video-query-type=video-type&video-query-term=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( 'videos/video-type/([^/]+)/?$', 'index.php?post_type=site-video&video-query-type=video-type&video-query-term=$matches[1]', 'top' );
		
		add_rewrite_rule( "videos/user/([^/]+)/{$wp_rewrite->pagination_base}/([0-9]{1,})/?$", 'index.php?post_type=site-video&author_name=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( 'videos/user/([^/]+)/upload-video/([^/]+)/?$', 'index.php?post_type=site-video&author_name=$matches[1]&upload-video=$matches[2]', 'top' );
		add_rewrite_rule( 'videos/user/([^/]+)/?$', 'index.php?post_type=site-video&author_name=$matches[1]', 'top' );

		$wp->add_query_var( 'video-file' );
		$wp->add_query_var( 'video-file-blog' );
		$wp->add_query_var( 'video-file-type' );
		$wp->add_query_var( 'video-query-term' );
		$wp->add_query_var( 'video-query-type' );
		add_rewrite_rule( 'video-file/([0-9]+)/([^/]+)/([^/]+)(/ext\.[a-zA-Z0-9]{3})?/?$', 'index.php?video-file-blog=$matches[1]&video-file=$matches[2]&video-file-type=$matches[3]', 'top' );

		if ( ! is_admin() ) {
			wp_enqueue_script(
				'video-access',
				plugins_url(
					'client-files/js/video-access.js',
					__FILE__
				),
				array( 'filosofo-common-js', 'flexible-uploader', 'json-rpc-api-helper' )
			);

		}

		$this->_listen_for_submissions();
	}

	public function event_template_redirect()
	{
		$this->_listen_for_requests();
	}

	/**
	 * Publish a video once its transcoding is complete.
	 *
	 * @param int $video_attachment_id The ID of the video attachment.
	 */
	public function event_video_transcode_complete( $video_attachment_id = 0 )
	{
		$video_attachment_id = (int) $video_attachment_id;
		$video_id = (int) get_post_meta( $video_attachment_id, '_parent_site_video', true );
		if ( ! empty( $video_id ) && 'draft' == get_post_status( $video_id ) ) {
			$data = wp_get_single_post( $video_id, ARRAY_A );
			$data['post_status'] = 'publish';
			if ( empty( $data['post_name'] ) ) {
				$data['post_name'] = sanitize_title( $data['post_title'] );
			}
			wp_update_post( $data );
		}
	}

	public function event_wp_footer()
	{
		global $wp_flexible_uploader;
		if ( ! is_admin() && empty( $this->_video_edit ) ) {
			ob_start();
			$wp_flexible_uploader->view->print_form();

			$form = ob_get_clean();
			$this->view->print_footer( array( 'form_markup' => $form ) );
		}
	}

	public function event_wp_flexible_uploader_ending_form()
	{
		// something is unsetting $current_user->id, which is used by create_nonce
		global $current_user;
		if ( ! empty( $current_user->ID ) && empty( $current_user->id ) ) {
			$current_user->id = (int) $current_user->ID;
		}

		wp_nonce_field( 'video-access-upload', 'video-upload-nonce' );
		echo $this->view->get_video_form_save_button();
	}

	public function event_wp_flexible_uploader_starting_form()
	{
		$this->view->print_lightbox_close();
	}

	public function event_wp_head()
	{
		?>
		<style type="text/css">
		.lightbox-parent {
			display:none;
		}
		</style>
		<?php
	}

	/**
	 * Make sure that the mimes we want to allow are allowed.
	 *
	 * @param array $mimes The associative array of mime types.
	 * @return array The mimes, filtered.
	 */
	public function filter_upload_mimes_add( $mimes = array() )
	{
		$allowed = $this->model->get_allowed_mime_types();
		foreach( $allowed as $key => $value ) {
			if ( ! isset( $mimes[$key] ) ) {
				$mimes[$key] = $value;
			}
		}
		return $mimes;
	}

	/**
	 * Create an attachment from the thumbnail image generated by the video transcoder.
	 *
	 * @param string $thumb_path The path to the thumbnail image.
	 * @param int $width The width of the generated thumbnail.
	 * @param int $height The height of the generated thumbnail.
	 * @param int $user_id The ID of the user associated with this thumbnail.
	 * @param int $video_id The ID of the video attachment.
	 * @return string The path to the thumbnail.
	 */
	public function filter_video_transcoding_create_thumbnail_path( $thumb_path = '', $width = 0, $height = 0, $user_id = 0, $video_id = 0 )
	{
		global $wp_flexible_uploader;
		$user_id = (int) $user_id;
		$video_id = (int) $video_id;
	
	error_log( 'filtering video stuff:');
	error_log( print_r( func_get_args(), true ) );
		if ( ! empty( $user_id ) ) {
			$attach_id = $this->model->save_thumbnail_as_attachment( $thumb_path, $user_id, $video_id );
			if ( ! is_wp_error( $attach_id ) ) {
				$file_path = get_attached_file( $attach_id );
				if ( file_exists( $file_path ) ) {
					$thumb_path = $file_path;
				}
			}
		}
		return $thumb_path;
	}

	public function filter_wp_flexible_uploader_allowed_file_types( $types = array() )
	{
		if ( isset( $types['image-files'] ) ) {
			unset( $types['image-files'] ); 
		}
		return $types;
	}

	public function filter_wp_flexible_uploader_browsefiles_filter( $filters = array() )
	{
		array_unshift( $filters,
			array(
				'desc' => __( 'Video Files', 'video-access' ),
				'exts' => $this->model->get_allowed_extensions(),
			)
		);
		return $filters;
	}

	public function filter_wp_flexible_uploader_form_browse( $browse_text = '', $browse_id = '' )
	{
		?>	
		<div class="row">
			<label>
				<button type="submit" class="buttonCSS" id="<?php echo esc_attr( $browse_id ); ?>"><?php _e( 'Browse', 'video-access' ); ?></button>
			</label>
			<input type="text" class="uploadfield" id="file-upload-field" value="" />
			<button type="submit" class="buttonCSS" id="file-upload-button"><?php _e( 'Upload', 'video-access' ); ?></button>
		</div>
		<?php
		return false;
	}

	public function filter_wp_flexible_uploader_form_extra_fields( $fields = array() )
	{
		$values = array();
		if ( ! empty( $this->_video_edit ) ) {
			$video_obj = get_post( $this->_video_edit );
			if ( ! empty( $video_obj->post_type ) && 'site-video' == $video_obj->post_type ) { 
				$values['video-upload-title'] = $video_obj->post_title;
				$values['video-upload-desc'] = $video_obj->post_content;
				$values['video-upload-tags'] = implode( ', ', wp_get_post_tags( $video_obj->ID ) );
				$values['video-upload-privacy'] = get_post_meta( $video_obj->ID, '_video-privacy', true );
			}
		}
		$fields[] = $this->view->get_video_form_fields( $values );
		return $fields;
	}

	public function filter_flexible_uploader_uploads_dir( $upload_path = '' )
	{
		$user_dir = $this->model->get_user_directory_filepath( get_current_user_id() );
		if ( ! file_exists( $user_dir ) ) {
			wp_mkdir_p( $user_dir );
		}

		return $user_dir;
	}

	public function filter_wp_get_attachment_url( $url = '', $attach_id = 0 )
	{
		$mime_types = explode( '/', get_post_mime_type( $attach_id ) );
		if ( in_array( 'video', $mime_types ) ) {
			$hash = $this->model->get_video_hash( $attach_id );
			// if there isn't a hash yet, we should create it
			if ( empty( $hash ) ) {
				$file_path = get_attached_file( $attach_id );
				$hash = md5( md5( $file_path . get_current_user_id() . uniqid() ) );
				update_post_meta( $attach_id, 'video_file_hash', $hash );
			}
			$url = $this->model->get_video_url( null, $attach_id );
		}
		return $url;
	}

	/**
	 * Attempt to move uploaded videos to the private directory.
	 *
	 * @param array $file_data The uploaded file data from WP's uploader: 
	 *	It has 3 indexes:
	 *		- 'file' : The path to the uploaded file.
	 *		- 'type' : The mime type of the the uploaded file.
	 *		- 'url' : The URL to the uploaded file.
	 * @param string $upload_location WP's identifier for where the upload is occurring ('sideload' or 'upload').
	 * @return array $file_data, filtered.
	 */
	public function filter_wp_handle_upload( $file_data = array(), $upload_location = 'upload' )
	{
		if (
			isset( $file_data['file'] ) &&
			file_exists( $file_data['file'] ) &&
			isset( $file_data['type'] )
		) {
			$types = explode( '/', $file_data['type'] );
			if ( in_array( 'video', $types ) ) {
				$new_dir = $this->filter_flexible_uploader_uploads_dir();
				$full_file_name = $new_dir . DIRECTORY_SEPARATOR . basename( $file_data['file'] );
				if ( rename( $file_data['file'], $full_file_name ) ) {
					$file_data['file'] = $full_file_name;
				}
			}
		}
		return $file_data;
	}
	
	protected function _listen_for_requests()
	{
		$video_file = get_query_var( 'video-file' );
		$blog_id = (int) get_query_var( 'video-file-blog' );
		$video_type = get_query_var( 'video-file-type' );
		$edit_request = (int) get_query_var( 'edit-video' );

		if ( ! empty( $edit_request ) ) {
			$this->_video_edit = (int) $edit_request;
		}

		if ( 
			! empty( $_REQUEST['delete-video'] ) &&
			! empty( $_REQUEST['video-access-delete-video'] ) &&
			wp_verify_nonce( $_REQUEST['video-access-delete-video'], 'video-delete-nonce' ) &&
			current_user_can(  Video_Access_Model::UPLOAD_CAP, intval( $_REQUEST['delete-video'] ) )
		) {
			$video_id = (int) $_REQUEST['delete-video'];
			// delete children
			$video_children = get_posts( array(
				'post_parent' => $video_id,
				'post_type' => 'attachment',
				'showposts' => -1,
			) );

			foreach( (array) $video_children as $child ) {
				if ( ! empty( $child->ID ) ) {
					wp_delete_post( $child->ID, true );
				}
			}

			wp_delete_post( $video_id, true );
			wp_redirect( remove_query_arg( array( 'delete-video', 'video-access-delete-video' ) ) );
			exit;
		}

		if ( ! empty( $video_file ) ) {
			switch( $video_type ) {
				case 'video' :
					$video_id = $this->model->get_video_by_hash( $blog_id, $video_file );
					$name = $this->model->get_video_filename( $blog_id, $video_id );
					$file_path = $this->model->get_video_actual_path( $blog_id, $video_id );
					
					if ( file_exists( $file_path ) ) {
						$size = filesize( $file_path );
						@ header('Content-Type: application/octet-stream');
						@ header('Content-Disposition: attachment; filename=' . $name);
						@ header('Content-Length: ' . $size);
						nocache_headers();
						readfile($file_path);
						exit;
					}
				break;

				case 'thumb' :
					$video_id = $this->model->get_video_by_hash( $blog_id, $video_file );
					$file_path = $this->model->get_video_image_actual_path( $blog_id, $video_id, 'thumbnail' );
					
					if ( file_exists( $file_path ) ) {
						$size = filesize( $file_path );
						@ header('Content-Type: image/jpeg');
						@ header('Content-Length: ' . $size);
						// nocache_headers();
						readfile($file_path);
						exit;
					}

				break;

				default :
					$image_sizes = get_intermediate_image_sizes();
					if ( in_array( $video_type, $image_sizes ) ) {
						$video_id = $this->model->get_video_by_hash( $blog_id, $video_file );
						$file_path = $this->model->get_video_image_actual_path( $blog_id, $video_id, $video_type );
						
						if ( file_exists( $file_path ) ) {
							$size = filesize( $file_path );
							@ header('Content-Type: image/jpeg');
							@ header('Content-Length: ' . $size);
							// nocache_headers();
							readfile($file_path);
							exit;
						}
					}
				break;
			}
		}
	}

	/**
	 * Listen for submitted data
	 */
	protected function _listen_for_submissions()
	{
		if ( ! empty( $_POST['video-access-form'] ) ) {
			switch( $_POST['video-access-form'] ) {
				case 'video-upload-form' :
					$this->_process_video_upload_form( $_POST );
				break;
			}
		}
	}

	/**
	 * Submit video object form data.
	 *
	 * @param string $form_id The ID of the form.
	 * @param array $data The form data.
	 * @return int|WP_Error The ID of the video object or error object upon error.
	 */
	public function submit_form_data( $form_id = '', $data = array() )
	{
		if ( 'video-upload-form' == $form_id ) {
			return $this->_process_video_upload_form( $data, true );
		}
	}

	protected function _process_video_upload_form( $posted = array(), $is_ajax = false )
	{
		if ( 
			current_user_can( Video_Access_Model::UPLOAD_CAP ) &&
			wp_verify_nonce( $posted['video-upload-nonce'], 'video-access-upload' )
		) {
			$video_title = isset( $posted['video-upload-title'] ) ? $posted['video-upload-title'] : ''; 
			$video_privacy = isset( $posted['video-upload-privacy'] ) && in_array(  $posted['video-upload-privacy'], array( 'private', 'public' ) )  ? $posted['video-upload-privacy'] : 'public'; 
			$video_desc = isset( $posted['video-upload-desc'] ) ? $posted['video-upload-desc'] : ''; 
			$video_tags = isset( $posted['video-upload-tags'] ) ? filter_var( $posted['video-upload-tags'] ) : ''; 
			$video_attachment_id = isset( $posted['flexible-uploader-attachment-id'] ) ? (int) $posted['flexible-uploader-attachment-id'] : 0; 

			$video_title = strip_tags( $video_title );
			$video_title = substr( $video_title, 0, 17 );
			

			$video_file = get_post_meta( $video_attachment_id, 'video_file', true );

			$video_data = array(
				'post_type' => 'site-video',
				'post_status' => ( empty( $video_file ) ? 'draft' : 'publish' ),
				'post_author' => get_current_user_id(),
				'post_title' => $video_title,
				'post_content' => $video_desc,
			);

			if ( ! empty( $posted['video-access-video-id'] ) ) {
				$video_data['ID'] = (int) $posted['video-access-video-id'];
			}

			$result = wp_insert_post( $video_data );

			if ( is_wp_error( $result ) ) {
				$this->model->log_wp_error( $result );
				if ( $is_ajax ) {
					return $result;
				} else {
					wp_redirect( add_query_arg( 'error', 1 ) );
					exit;
				}
			} else {
				$video_id = (int) $result;
				if ( ! empty( $video_id ) ) {
					wp_set_post_tags( $video_id, $video_tags );
					update_post_meta( $video_id, '_video-privacy', $video_privacy );

					if ( ! empty( $video_attachment_id ) ) {
						update_post_meta( $video_id, '_original-attachment', $video_attachment_id );
						update_post_meta( $video_attachment_id, '_transcoded', false );
						update_post_meta( $video_attachment_id, '_parent_site_video', $video_id );

						$video_attachment_data = get_post( $video_attachment_id, ARRAY_A );
						$video_attachment_data['post_parent'] = $video_id;
						if ( empty( $video_attachment_data['post_title'] ) ) {
							$video_attachment_data['post_title'] = $video_title;
						}

						wp_insert_attachment( $video_attachment_data );
					}

					if ( $is_ajax ) {
						return $video_id;
					} else {
						wp_redirect( get_permalink( $video_id ) );
						exit;
					}
				}
			}
		}
	}

	public static function restore_current_blog()
	{
		if ( function_exists( 'restore_current_blog' ) ) {
			return restore_current_blog();
		} else {
			return false;
		}
	}

	public static function switch_to_blog( $blog_id = 0 )
	{
		if ( function_exists( 'switch_to_blog' ) ) {
			return switch_to_blog( $blog_id );
		} else {
			return false;
		}
	}
}

class Video_Access_Model
{
	const UPLOAD_CAP = 'read';
	const PUBLIC_ERROR = 46000;

	private $_current_parent_id = null;
	private $_current_user_id = null;

	public function get_allowed_extensions()
	{
		$exts = array();
		$ext_patterns = array_keys( $this->get_allowed_mime_types() );
		foreach( $ext_patterns as $pattern ) {
			$exts = array_merge( $exts, explode( '|', $pattern ) );
		}

		$exts = array_merge(
			$exts,
			array_map( 'strtoupper', $exts )
		);

		return array_filter( $exts );
	}

	/**
	 * Allowed video mime types.  Formatting and usage from WP's get_allowed_mime_types()
	 */
	public function get_allowed_mime_types()
	{
		return array(
			'asf|asx|wax|wmv|wmx' => 'video/asf',
			'avi' => 'video/avi',
			'flv' => 'video/x-flv',
			'mov|qt' => 'video/quicktime',
			'mp4|m4v' => 'video/mp4',
			'm2v' => 'video/mpeg',
		);
	}

	public function get_uploads_dir()
	{
		return apply_filters( 'video_access_secret_directory', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . VIDEO_ACCESS_SECRET_DIR );
	}

	/**
	 * Get the directory that a user has for thumbnail images.
	 * NOTE: This is what the directory *should* be; it doesn't
	 * mean that the directory actually exists.
	 *
	 * @param int $user_id The ID of the user in question.
	 * @return string  The full path to the user's directory.
	 */
	public function get_user_directory_filepath( $user_id = 0 )
	{
		$user_id = (int) $user_id;
		if ( empty( $user_id ) ) {
			return '';
		} else {
			$uploads_dir = $this->get_uploads_dir() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $user_id;
			return apply_filters( 'video_access_user_directory_filepath', $uploads_dir, $user_id );
		}
	}

	public function get_video_actual_path( $blog_id = 0, $video_id = 0 )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;
		Video_Access_Control::switch_to_blog( $blog_id );
		$file = get_post_meta( $video_id, 'video_file_full', true );
		Video_Access_Control::restore_current_blog();

		return $file;
	}

	public function get_video_by_hash( $blog_id = 0, $hash = '' )
	{
		global $wpdb;
		$blog_id = (int) $blog_id;
		$hash = mysql_real_escape_string( $hash, $wpdb->dbh );
		$table = 1 < $blog_id ? $wpdb->prefix . $blog_id . '_' . $wpdb->postmeta : $wpdb->postmeta;
		$query = sprintf( 
			'SELECT post_id 
				FROM %1$s
				WHERE 
					meta_key = "video_file_hash" AND 
					meta_value = "%2$s"
				LIMIT 1
			',
			$table,
			$hash
		);

		$video_id = $wpdb->get_var( $query ); 

		return (int) $video_id;
	}

	/**
	 * The public file name of the video, which is displayed to the public,
	 * (not the actual filename on the server)
	 *
	 * @param int $blog_id The blog ID of the video.
	 * @param int $video_id The ID of the video.
	 */
	public function get_video_filename( $blog_id = null, $video_id = 0 )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;
		Video_Access_Control::switch_to_blog( $blog_id );

		$actual_file = get_post_meta( $video_id, 'video_file', true );
		$ext = array_pop( explode( '.', $actual_file ) );
		$ext = 3 == strlen( $ext ) ? $ext : 'mp4';

		$parent_video_title = preg_replace( '#[^a-zA-Z0-9_]#', '_', get_the_title( get_post_meta( $video_id, '_parent_site_video', true ) ) );

		$filename = empty( $parent_video_title ) ? get_post_meta( $video_id, 'video_file_hash', true ) : $parent_video_title;

		$filename .= '.' . $ext;
		Video_Access_Control::restore_current_blog();

		return $filename;
	}

	/**
	 * Get the file path to the image associated with the video.
	 *
	 * @param int $blog_id. Optional. The ID of the blog associated with this video.
	 * @param int $video_id The ID of the video attachment for which we're getting the image.
	 * @param string $image_type The type of image to get, such as "thumbnail"
	 * @return string The path to the thumbnail in question.
	 */
	public function get_video_image_actual_path( $blog_id = null, $video_id = 0, $image_type = 'thumbnail' )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		Video_Access_Control::switch_to_blog( $blog_id );

		$attachment_id = (int) get_post_meta( $video_id, 'image_attachment_id', true );

		if ( empty( $attachment_id ) ) {

/*
			if ( 'thumbnail' == $image_type ) {
				$file_name = get_post_meta( $video_id, 'thumbnail_img', true );
			} else {
				$file_name = get_post_meta( $video_id, 'original_img', true );
			}

			if ( empty( $file_name ) ) {
				$file = '';
			} else {
				$file = $this->get_uploads_dir() . DIRECTORY_SEPARATOR . $file_name;
			}
			*/

		} else {
			$file = $this->_get_image_path_by_size( $attachment_id, $image_type );
		}

		Video_Access_Control::restore_current_blog();

		return $file;
	}	

	/**
	 * Get the URL to the relevant view all page.
	 *
	 * @param array $args The context of the view all link.
	 */
	public function get_view_all_link( $args = array() )
	{
		return 'http://google.com';
	}

	/**
	 * Get the path to the WP attachment image, by attachment ID and registered thumbnail size.
	 *
	 * @param int $attach_id The ID of the attachment.
	 * @param string $size The registered string of the size.
	 * @return string|bool The path to that image file or false upon failure.
	 */
	protected function _get_image_path_by_size( $attach_id = 0, $size = 'full' )
	{
		$image_sizes = get_intermediate_image_sizes();
		if ( 'full' == $size ) {
			return get_attached_file( $attach_id );
		} else {
			$data = wp_get_attachment_metadata( $attach_id );
			if ( 
				isset( $data['file'] ) &&
				isset( $data['sizes'] ) &&
				isset( $data['sizes'][ $size ] )
			) {
				$uploads_dir = $this->get_uploads_dir();
				return str_replace( basename( $uploads_dir ), str_replace( basename( $data['file'] ), $data['sizes'][ $size ]['file'], $data['file'] ), $uploads_dir );

			// if the size doesn't exist yet, but it's legit, let's create it
			} elseif ( in_array( $size, $image_sizes ) ) {
				image_downsize( $attach_id, $size );
				
				// try again to get the appropriate size
				$data = wp_get_attachment_metadata( $attach_id );
				if ( 
					isset( $data['file'] ) &&
					isset( $data['sizes'] ) &&
					isset( $data['sizes'][ $size ] )
				) {
					$uploads_dir = $this->get_uploads_dir();
					return str_replace( basename( $uploads_dir ), str_replace( basename( $data['file'] ), $data['sizes'][ $size ]['file'], $data['file'] ), $uploads_dir );
				}
			}
		}

		return false;
	}

	public function get_video_hash( $video_id = 0, $blog_id = null )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;

		Video_Access_Control::switch_to_blog( $blog_id );
		$hash = get_post_meta( $video_id, 'video_file_hash', true );
		Video_Access_Control::restore_current_blog();
		return $hash;
	}

	/**
	 * Get the public URL to the image associated with the video.
	 *
	 * @param int $blog_id. Optional. The ID of the blog associated with this video.
	 * @param int $video_id The ID of the video attachment for which we're getting the image.
	 * @param string $image_type The type of image to get, such as "thumbnail"
	 * @return string The URL to the thumbnail in question.
	 */
	public function get_video_image_url( $_blog_id = null, $video_id = 0, $image_type = 'thumbnail' )
	{
		$_blog_id = (int) $_blog_id;
		$video_id = (int) $video_id;

		$image_sizes = get_intermediate_image_sizes();

		$size = in_array( $image_type, $image_sizes ) ? $image_type : 'thumb';	

		global $blog_id;
		
		if ( empty( $_blog_id ) ) {
			$_blog_id = (int) $blog_id;
		}

		Video_Access_Control::switch_to_blog( $_blog_id );
		
		$hash = get_post_meta( $video_id, 'video_file_hash', true );
		
		$url = get_site_url( $blog_id, sprintf(
			'/video-file/%1$s/%2$s/%3$s/',
			$_blog_id,
			$hash,
			$size
		) );

		Video_Access_Control::restore_current_blog();

		return $url;
	}

	public function get_video_url( $blog_id = null, $video_id = 0 )
	{
		$blog_id = (int) $blog_id;
		$video_id = (int) $video_id;
		Video_Access_Control::switch_to_blog( $blog_id );
		$hash = get_post_meta( $video_id, 'video_file_hash', true );
		$url = get_site_url( $blog_id, sprintf(
			'/video-file/%1$s/%2$s/%3$s/ext.mp4',
			$blog_id,
			$hash,
			'video'
		) );
		restore_current_blog();

		return $url;
	}

	public function log_wp_error( WP_Error $error )
	{
		if ( function_exists( 'wp_create_user_notification' ) ) {
			wp_create_user_notification( $error->get_error_message(), 'error' );
		} elseif ( function_exists( 'error_log' ) ) {
			error_log( $error->get_error_message() );
		}
	}

	public function save_thumbnail_as_attachment( $tmp_path = '', $user_id = 0, $video_id = 0 )
	{
		global $wp_flexible_uploader;
		$user_id = (int) $user_id;
		$video_id = (int) $video_id;

		$thumbnail_url = $this->get_video_image_url( null, $video_id, 'thumbnail' );

		$user_id = (int) $user_id;

		$user_dir = $this->get_user_directory_filepath( $user_id );
		if ( ! file_exists( $user_dir ) ) {
			wp_mkdir_p( $user_dir );
		}

		if ( file_exists( $user_dir ) ) {
			if ( is_writeable( $user_dir ) ) {
				$file_name = basename( $tmp_path );	
				$full_file_name = trailingslashit( $user_dir ) . $file_name;
error_log( 'trying to write image as ' . $full_file_name . ' from ' . $tmp_path );
				if ( rename( $tmp_path, $full_file_name ) ) {
					// now make it an attachment	
				
					$this->_current_parent_id = $video_id;
					$this->_current_user_id = $user_id;
					$attachment_id = $wp_flexible_uploader->model->save_file_as_attachment( $full_file_name, $user_id, $thumbnail_url );
					if ( is_wp_error( $attachment_id ) ) {
						return $attachment_id;
					}
					$this->_current_parent_id = null;
					$this->_current_user_id = null;

					update_post_meta( $video_id, 'image_attachment_id', $attachment_id );

					return $attachment_id;
				} else {
					return new WP_Error(
						self::PUBLIC_ERROR,
						sprintf(
							__( 'Error: We are unable to copy the thumbnail file to the directory for user ID %d.', 'video-access' ), 
							$user_id 
						)
					);
				}
			} else {
				return new WP_Error(
					self::PUBLIC_ERROR,
					sprintf(
						__( 'Error: We are unable to save files to the directory for user ID %d.', 'video-access' ), 
						$user_id 
					)
				);
			}
		} else {
			return new WP_Error(
				self::PUBLIC_ERROR,
				sprintf(
					__( 'Error: We are unable to create file directory for user ID %d.', 'video-access' ), 
					$user_id 
				)
			);
		}
	}

	/**
	 * Set the attachment's parent value if relevant
	 *
	 * @param array $props The attachment properties.
	 * @param string $file_path The path to the attachment.
	 */
	public function filter_attach_props_to_set_parent( $props = array(), $file_path = '' )
	{
		if ( 
			! empty( $props['post_author'] ) &&
			! empty( $this->_current_parent_id ) && 
			! empty( $this->_current_user_id ) &&
			$this->_current_user_id == $props['post_author']
		) {
			$props['post_parent'] = $this->_current_parent_id;
		}

		return $props;
	}
}

class Video_Access_View
{
	protected function _get_template( $template = '' )
	{
		$template_file = get_query_template( $template );
		if ( empty( $template_file ) ) {
			$template_file = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template . '.php';
		}

		if ( ! empty( $template_file ) && file_exists( $template_file ) ) {
			return $template_file;
		} else {
			return false;
		}
	}

	public function get_video_detail()
	{
		ob_start();
		if ( $detail_template = $this->_get_template( 'single-site-video' ) ) {
			include $detail_template;
		}
		return ob_get_clean();
	}

	public function get_video_form_fields( $values = array() )
	{
		ob_start();
		?>
		<input type="hidden" name="video-access-form" id="video-access-form" value="video-upload-form" />
		<input type="hidden" name="video-access-id" id="video-access-id" value="<?php
			if ( ! empty( $values['video-upload-id'] ) ) {
				echo esc_attr( $values['video-upload-id'] ); 
			}
		?>" />
		<div class="row">
			<label for="video-upload-title-field"><?php _e( 'Video Title', 'video-access' ); ?></label>
			<input type="text" name="video-upload-title" id="video-upload-title-field" value="<?php
				if ( ! empty( $values['video-upload-title'] ) ) {
					echo esc_attr( $values['video-upload-title'] ); 
				}
			?>" maxlength="17" />
		</div>
		<div class="row">
		<label for="video-upload-desc-field"><?php _e( 'Video Description', 'video-access' ); ?></label>
			<textarea name="video-upload-desc" id="video-upload-desc-field"><?php
				if ( ! empty( $values['video-upload-desc'] ) ) {
					echo esc_textarea( $values['video-upload-desc'] ); 
				}
			?></textarea>
		</div>
		<div class="row">
		<label for="video-upload-tags-field"><?php _e( 'Video Tags', 'video-access' ); ?></label>
			<input type="text" name="video-upload-tags" id="video-upload-tags-field" value="<?php
				if ( ! empty( $values['video-upload-tags'] ) ) {
					echo esc_attr( $values['video-upload-tags'] ); 
				}
			?>" />

		</div>
		<div id="video-metadata-wrap">
		</div>

		
		<div class="row">
			<label class="radio-group-label"><?php _e( 'Privacy Settings', 'video-access' ); ?></label>

			<input type="radio" name="video-upload-privacy" id="video-upload-privacy-public" value="public" <?php
				if ( empty( $values['video-upload-privacy'] ) || 'public' == $values['video-upload-privacy'] ) {
					echo ' checked="checked"';
				}
			?> />
			<label for="video-upload-privacy-public" class="radio-label"><?php _e( 'Public (anyone can view your videos)', 'video-access' ); ?></label>
			<br />
			<input type="radio" name="video-upload-privacy" id="video-upload-privacy-private" value="private" <?php
				if ( 'private' == $values['video-upload-privacy'] ) {
					echo ' checked="checked"';
				}
			?>/>
			<label for="video-upload-privacy-private" class="radio-label"><?php _e( 'Private (only people in your network can view your videos)', 'video-access' ); ?></label>
		</div>
		<?php

		return ob_get_clean();
	}

	public function get_video_form_save_button()
	{
		ob_start();

		?>
		<div class="row">
			<button type="submit" class="buttonCSS widgetsubmit" id="video-data-save-button" value="<?php _e( 'Save Video Info', 'video-access' ); ?>"><?php _e( 'Save Video Info', 'video-access' ); ?></button>
		</div>
		<?php

		return ob_get_clean();
	}

	public function print_lightbox_close()
	{
		?>
		 <a class="jqmClose" href="#"><?php _e('Close', 'video-access'); ?></a>
		 <?php
	}

	public function print_footer( $args = array() )
	{
	}
}

function video_access_set_flexible_uploader_property()
{
	global $wp_flexible_uploader;
	$wp_flexible_uploader->model->user_upload_cap = 'read';
}

function load_video_access()
{
	global $video_controlled_access;
	$video_controlled_access = new Video_Access_Control;
}

add_action( 'plugins_loaded', 'load_video_access' );
add_action( 'plugins_loaded', 'video_access_set_flexible_uploader_property', 99 );
// eof
