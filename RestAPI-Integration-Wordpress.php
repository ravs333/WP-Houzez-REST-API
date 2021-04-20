<?php
/**
 * PIAB API V2 Class
 *
 * Fetch Property Listings
 *
 * @since 2.0
 */

require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

if ( ! class_exists( 'PIAB_API_V2' ) ) {
	

	ini_set('memory_limit', '-1');
	ini_set('max_execution_time', 5000);

	class PIAB_API_V2 {
		private $devpc = false;
		private $api_host;
		private $cdnurl;
		private $siteurl;

		private $endpoints = array(
			'check-subscription' => 'subscription',
			'property-list' => 'properties',
			'property-remove' => 'properties-remove',
			'contact'      => 'contact'
		);

		private $db_settings = array(
			'host' => 'localhost',
			'user' => 'root',
			'pass' => 'root',
			'dbname' => ''
		);

		private $is_active_contact_from7_plugin = false;
		private $link_shown = 0;
		private $lastError = 0;
		private $username = 0;
		private $password = 0;

		private $property_types = array(
			1=>'House and Land',
			2=>'Apartment',//'Apartment/Unit',
			3=>'Town House/Unit',//'Town House',
			4=>'Duplex',
			5=>'Dual Occupancy',
			6=>'Commercial',
			7=>'Dual Key',
			8=>'Display',
			9=>'Terrace/Villas'
		);

		private $terms;
		private $taxonomies = array(
			'state' => 'property_state',
			'city'  => 'property_city',
			'suburb'  => 'property_area',
			'status' => 'property_status',
			'property_type' => 'property_type',
			'label' => 'property_label'
		);

		private $default_agent_id = null;

		public function __construct(){
	
			$this->siteurl = get_site_url();
			if(!empty($this->siteurl) && strpos($this->siteurl, '.local')){
			    // Always ON when running locally.
			    $this->setDevpc(TRUE);
			}
			DEFINE('DEVPC', $this->devpc);

			if (DEVPC)
				define('CDNHOST', 'cdn.mypropertyenquiry.local');
			else
				define('CDNHOST', 'cdn.mypropertyenquiry.com.au');

			$this->api_host = $this->api_host();
			$this->cdnurl = $this->cdn_url();

			// debug_pre($this->api_host);
	
			$this->username             = $this->username();
			$this->password             = $this->password();
		}

		function setDevpc($devpc){
			$this->devpc = $devpc;
		}

		private function api_host(){
			// return DEVPC ? 'http://api.fusioncrm.local/v2/' : 'https://api.fusioncrm.com.au/v2/';

			return 'https://api.fusioncrm.com.au/v2/';
		}

		private function cdn_url(){
			return sprintf(
				"%s://%s%s",
				isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
				CDNHOST,
				'/'
			);
		}

		private function username(){
			return 'SampleUser';
		}
	
		private function password(){
			return 'SamplePassword';
		}

		private function _eval($str){
       
			if(strlen(trim($str) > 1) && strstr($str, "+")){
				$stArr = explode('+', $str);
				$result = 0;
				foreach($stArr as $key => $value){
					if(strlen(trim($value)) == 1){
						$result += $this->_eval(trim($value));
					}else{
						$result ++;
					}
				}
				return $result;
			}
			if((int) $str != $str){
				return floatval($str);
			}
			return intval($str);
		}

		private function _slug($str, $isFile = false){
			$str = strtolower($str);
			$str = str_replace('%', ' percent', $str);
			$str = str_replace("'", '', $str);
			$str = str_replace('"', '', $str);
			$str = str_replace('-', '', $str);
			$str = str_replace('  ', '-', $str);
			$str = str_replace(' ', '-', $str);
			$str = str_replace('--', '-', $str);
			$str = str_replace('(', '', $str);
			$str = str_replace(')', '', $str);
			$str = str_replace('/', '-', $str);
			$str = str_replace('\\', '-', $str);
			$str = trim($str);
			$str = trim($str, '-');
			if(!$isFile){
				$str = sanitize_title($str);
			}
	
			return $str;
		}

		/**
		 * Updates post meta for a post. It also automatically deletes or adds the value to field_name if specified
		*
		* @access     protected
		* @param      integer     The post ID for the post we're updating
		* @param      string      The field we're updating/adding/deleting
		* @param      string      [Optional] The value to update/add for field_name. If left blank, data will be deleted.
		* @return     void
		*/
		private function __save_post_meta( $post_id, $field_name, $value = '' ){
			if ( empty( $value ) OR ! $value )
			{
				delete_post_meta( $post_id, $field_name );
			}
			elseif ( ! get_post_meta( $post_id, $field_name ) )
			{
				add_post_meta( $post_id, $field_name, $value );
			}
			else
			{
				update_post_meta( $post_id, $field_name, $value );
			}
		}

		private function __save_meta_key_value($post_id, $field_name, $value = ''){
			$args = array (
				'p'	=> $post_id,
				'post_type' => 'any',
				'meta_key' => $field_name,
				'meta_value' => $value
			);
			
			// The Query
			$query = new WP_Query( $args );
			if( $query->found_posts > 0){
				return update_post_meta( $post_id, $field_name, $value, $value);
			}

			return add_post_meta( $post_id, $field_name, $value );
		}

		private function __save_terms( $termData, $post_id = null ){
			$term_id = 0;
			$term = term_exists($termData['name'], $termData['taxonomy']);

			$args = array(
				'description' => $termData['description'],
				'slug'        => $termData['slug'],
				'parent'      => $termData['parent_term_id'],
			);
			if(isset($termData['args'])){
				foreach($termData['args'] as $arg_key => $arg_val){
					$args[$arg_key] = $arg_val;
				}
			}

			if ( !$term )
			{
				
				$insert = wp_insert_term(
					$termData['name'],   // the term 
					$termData['taxonomy'], // the taxonomy
					$args
				);
				$term_id = $insert['term_id'];
				// debug_pre($insert);
			}else{
				$term_id = $term['term_id'];
				$args = array_merge(array(
					'name' => $termData['name'],
					'slug' => $termData['slug'],
					'description' => $termData['description'],
					'parent' => $termData['parent_term_id']
				), $args);
				$update = wp_update_term( $term_id, $termData['taxonomy'], $args );
			}
			if($post_id){
				wp_set_post_terms( $post_id , array($term_id), $termData['taxonomy']);
			}
			return $term_id;
		}

		private function __save_images($file, $parent_post_id, $propertyID){
			$path_parts = pathinfo($file);
			$extension = $path_parts['extension'];
			$filename = $this->_slug(basename($path_parts['filename']));
			$filename = $filename . '.' . $extension;
			$wp_filetype = wp_check_filetype($filename, null );
			$args = array(
				'post_type' => 'attachment',
				'post_mime_type' => $wp_filetype['type'],
				'post_parent' => $parent_post_id,
				'title' => preg_replace('/\.[^.]+$/', '', $filename),
				'post_status' => 'any'
			);

			$query = new WP_Query($args);
			// debug_pre($query->last_query);
			// Print last SQL query string
			// global $wpdb;
			// echo $wpdb->last_query;
			// die;
			if( $query->found_posts == 0){

				$folder = 'images/'.$propertyID;

				add_filter( 'upload_dir', function( $arr ) use ( $folder ) {
					return piab_property_images_upload_dir( $arr, $folder );
				});

				$local_path = WP_CONTENT_DIR . '/uploads/'. $folder . '/' .$filename;
				$local_url = WP_CONTENT_URL . '/uploads/'. $folder . '/' .$filename;
				$attachment_id = 0;

				if(file_exists($local_path)){
					unlink($local_path);
					// $attachment_id = attachment_url_to_postid($local_url);
				}
				// $attachment_id = media_sideload_image( $file, $parent_post_id, preg_replace('/\.[^.]+$/', '', $filename), 'id' );
				if($remote_file_content = file_get_contents(str_replace(' ', '%20', $file))){
					$upload_file = wp_upload_bits($filename, null, $remote_file_content);
					if (!$upload_file['error']) {
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_parent' => $parent_post_id,
							'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
							'post_content' => '',
							'post_status' => 'inherit'
						);
						$attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $parent_post_id );
						if (!is_wp_error($attachment_id)) {
							require_once(ABSPATH . "wp-admin" . '/includes/image.php');
							$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
							wp_update_attachment_metadata( $attachment_id,  $attachment_data );
						}
					}

					error_log(print_r(array(
						"url" => $local_url,
						"attachment_id" => $attachment_id
					),true));

					remove_filter( 'upload_dir', 'piab_property_images_upload_dir' );
					if(!is_object($attachment_id)){
						return $attachment_id;
					}
				}else{
					error_log(print_r(array(
						"url" => $local_url,
						"attachment_id" => $attachment_id
					),true));
				}
				

				return false;
			}else{
				return $query->posts[0]->ID;
			}
		}

		public function agent_info(){
			$agent = get_posts( array(
				'posts_per_page' => 1, // we only want to check if any exists, so don't need to get all of them
				'post_type' => 'houzez_agent',
				'post_status' => 'publish',
				'fields' => 'ids', // we don't need it's content, etc.
			) );

			$agentID = 0;

			

			if(count($agent)){
				$agentID = $agent[0];
			}else{
				$agent_post = array(
					'post_title'    => get_option('blogname'),
					'post_content'  => get_option('blogdescription'),
					'post_excerpt'  => get_option('blogdescription'),
					'post_status'   => 'publish',
					'post_name'   => $this->_slug(get_option('blogname')),
					'post_author' => 1,
					'comment_status' => 'closed',
					'ping_status' => 'closed',
					'post_type' => 'houzez_agent',
					'post_parent' => 0
				);

				$agentID = wp_insert_post( $agent_post );

				$website_settings = get_option('piab_website_settings');
				$website_settings = $website_settings['data']['settings'];

				add_post_meta( $agentID, 'fave_agent_email', $website_settings['email'] );
				add_post_meta( $agentID, 'fave_agent_office_num', $website_settings['phone'] );
				add_post_meta( $agentID, 'fave_agent_visible', 0 );
			}

			$this->default_agent_id = $agentID;
			return true;
		}
	
		function retRes($status= '200', $message='',$data = []){
			switch ($status) {
				case '400': 
					header("HTTP/1.1 400 Bad Request");
					break;
				case '401': 
					header("HTTP/1.1 401 Unauthorized");
					break;
				case '403': 
					header("HTTP/1.1 403 Forbidden");
					break;
				case '404': 
					header("HTTP/1.1 404 No Found");
					break;
				case '500': 
					header("HTTP/1.1 500 Internal Server Error");
					break;
				case '502': 
					header("HTTP/1.1 502 Bad Request");
					break;
				case '503': 
					header("HTTP/1.1 503 Service Unavailable");
					break;
				case '202': 
					header("HTTP/1.1 202 Accepted");
					break;
				case '201': 
					header("HTTP/1.1 201 Created");
					break;
				case '200':
					header("HTTP/1.1 200 OK");
					break;
				default:
					header("HTTP/1.1 404 Not Found");
					exit;
					break;
			}
			header('Content-Type: application/json');
			echo json_encode(array(
				'status' => $status,
				'message' => $message,
				'data' => (object) $data
			));
			exit;
		}

		function property_list(){
			$data = array(
				'available' => 1,
				'lots_data' => true,
				'page' =>  0,
			);
	
			$property_data = sendCurlRequest($this->api_host.$this->endpoints['property-list'],$this->username, $this->password,$data);
			// debug_pre($property_data);
			return $property_data;
		}

		function property_unlist(){
			$data = array(
				'available' => 0,
				'available_since' => date('Y-m-d H:i:s', strtotime('-1 week')),
				// 'lots_data' => true,
				'page' =>  0,
			);
			$property_data = sendCurlRequest($this->api_host.$this->endpoints['property-remove'],$this->username, $this->password,$data);
			
			$properties = json_decode($property_data, true);
			$properties = $properties['properties'];

			$meta_query = array(
				'relation' => 'OR'
			);
			foreach($properties as $propertyID){
				$meta_query[] = array(
					'key'     => 'piab_property_id',
            		'value'   => $propertyID,
				);
			}
			$args = array(
				'post_type'  => 'property',
				'post_status' => 'any',
				'posts_per_page' => -1,
				'meta_query' => $meta_query
			);
			
			$query = new WP_Query( $args );
			$posts = $query->posts;

			if ( $query->have_posts() ) {

				while ( $query->have_posts() ) {
	
					$query->the_post();
	
					$postData = array(
						'ID'           => get_the_ID(),
						'post_status'   => 'expired'
					);
					wp_update_post( $postData );
				}
	
			}
			
			return $posts;
		}

		function save_property($property){

			$posts_with_meta = get_posts( array(
				'posts_per_page' => 1, // we only want to check if any exists, so don't need to get all of them
				'post_type' => 'property',
				'post_status' => 'any',
				'meta_key' => 'piab_property_id',
				'meta_value' => $property['ID'],
				'fields' => 'ids', // we don't need it's content, etc.
			) );
			
			$property_post = array(
				'post_title'    => $property['text_title'],
				'post_content'  => $property['long_description'],
				'post_excerpt'  => $property['short_description'],
				'post_status'   => 'publish',
				'post_name'   => $this->_slug($property['text_title']) . '-' . $property['ID'],
				'post_author' => 1,
				'comment_status' => 'closed',
				'ping_status' => 'closed',
				'post_type' => 'property',
				'post_parent' => 0
			);

			// Print last SQL query string
			// global $wpdb;
			// echo $wpdb->last_query;
			$postID = 0;
			if ( !count( $posts_with_meta ) ){
				$postID = wp_insert_post( $property_post );
			}else{
				$postID = $posts_with_meta[0];
				$property_post['ID'] = $postID;
				wp_update_post( $property_post );

			}

			/** Set Post Meta */

			$multi_units = array();
			$first_lot = reset($property['lots']);
			$size_low = $size_high = $this->_eval($first_lot['living_area']);
			$land_low = $land_high = $this->_eval($first_lot['land_size']);
			$bed_low = $bed_high = $this->_eval($first_lot['bedrooms']);
			$bath_low = $bath_high = $this->_eval($first_lot['bathrooms']);
			$garage = $garage_low = $garage_high = $this->_eval($first_lot['garage']);
			
			foreach($property['lots'] as $lot){
				$multi_units[] = array(
					'fave_mu_title' => $lot['lot_name'],
					'fave_mu_price' => $lot['price'],
					'fave_mu_beds' => $lot['bedrooms'],
					'fave_mu_baths' => $lot['bathrooms'],
					'fave_mu_garages' => empty($lot['car']) ? $lot['garage'] : $lot['car'],
					'fave_mu_level' =>  $lot['level'],
					'fave_mu_aspect' =>  $lot['aspect'],
					'fave_mu_stage' =>  $lot['stage'],
					'fave_mu_storeys' =>  $lot['storeys'],
					'fave_mu_internal' => $lot['internal'],
					'fave_mu_external' => $lot['external'],
					'fave_mu_living_area' => $lot['living_area'],
					'fave_mu_size' => empty($lot['land_size']) ? $lot['total'] : $lot['land_size'],
					'fave_mu_size_postfix' => 'm<sup>2</sup>',
					'fave_mu_type' => $property['property_type']
				);
				if($property['property_type_id'] == 1){
					if($size_low > $this->_eval($lot['living_area'])){
						$size_low = $lot['living_area'];
					}
					if($size_high < $this->_eval($lot['living_area'])){
						$size_high = $lot['living_area'];
					}

					if($land_low > $this->_eval($lot['land_size'])){
						$land_low = $lot['land_size'];
					}
					if($land_high < $this->_eval($lot['land_size'])){
						$land_high = $lot['land_size'];
					}
				}

				$bed_low = $bed_low > $this->_eval($lot['bedrooms']) ? $this->_eval($lot['bedrooms']) : $bed_low;
				$bed_high = $bed_high < $this->_eval($lot['bedrooms']) ? $this->_eval($lot['bedrooms']) : $bed_high;
				$bath_low = $bath_low > $this->_eval($lot['bathrooms']) ? $this->_eval($lot['bathrooms']) : $bath_low;
				$bath_high = $bath_high > $this->_eval($lot['bathrooms']) ? $this->_eval($lot['bathrooms']) : $bath_high;
				if(isset($lot['garage']) && !empty($lot['garage'])){
					$garage_low = $garage_low > $this->_eval($lot['garage'])  && $this->_eval($lot['garage']) > 0? $this->_eval($lot['garage']) : $garage_low;
					$garage_high = $garage_high < $this->_eval($lot['garage']) ? $this->_eval($lot['garage']) : $garage_high;
				}

				if(isset($lot['car']) && !empty($lot['car'])){
					$garage_low = $garage_low > $this->_eval($lot['car']) && $this->_eval($lot['car']) > 0 ? $this->_eval($lot['car']) : $garage_low;
					$garage_high = $garage_high < $this->_eval($lot['car']) ? $this->_eval($lot['car']) : $garage_high;
				}
				$garage = ($garage_low != $garage_high) && $garage_low > 0 ? $garage_low . '-' . $garage_high : $garage_high;
			}

			$meta_map = array(
				'_edit_lock' => time() . ':1',
				'_edit_last' => 2,
				'slide_template' => 'default',
				'_yoast_wpseo_title' => $property['text_title'],
				'_yoast_wpseo_content_score' => 60,
				'fave_property_size' => '',
				'fave_property_size_low' => $size_low,
				'fave_property_size_high' => $size_high,
				'fave_property_size_prefix' => 'm<sup>2</sup>',
				'fave_property_land' => '',
				'fave_property_land_low' => $land_low,
				'fave_property_land_high' => $land_high,
				'fave_property_land_postfix' => 'm<sup>2</sup>',
				'fave_property_bedrooms' => $property['bedrooms_low'] . '-' . $property['bedrooms_high'],
				'fave_property_bedrooms_low' => $bed_low,
				'fave_property_bedrooms_high' => $bed_high,
				'fave_property_bathrooms' => $property['bathrooms_low'] . '-' . $property['bathrooms_high'],
				'fave_property_bathrooms_low' => $bath_low,
				'fave_property_bathrooms_high' => $bath_high,
				'fave_property_garage' => $garage,
				'fave_property_garage_low' => $garage_low,
				'fave_property_garage_high' => $garage_high,
				'fave_property_garage_size' => '',
				'fave_property_id' => $property['ID'],
				'piab_property_id' => $property['ID'],
				'fave_property_map' => 1,
				'fave_property_map_address' => $property['suburb'] . ', ' . $property['city'] . ', ' . $property['state'] . ', ' .$property['pincode'] . ', Australia',
				'fave_property_location' => $property['latitude'].','.$property['longitude'],
				'fave_property_map_street_view' => 'show',
				'fave_property_address' => $property['suburb'] . ', ' . $property['city'] . ', ' . $property['state'] . ', ' .$property['pincode'] . ', Australia',
				'fave_property_zip' => $property['pincode'],
				'fave_property_country' => 'AU',
				'fave_loggedintoview' => 0,
				'fave_agent_display_option' => 'agent_info',
				'fave_agents' => $this->default_agent_id,
				'fave_prop_homeslider' => 'no',
				'fave_multiunit_plans_enable' => 'enable',
				'fave_floor_plans_enable' => 'disable',
				'fave_attachments' => '',
				'fave_single_top_area' => 'global',
				'fave_single_content_area' => 'global',
				'fave_additional_features_enable' => 'disable',
				'fave_currency_info' => '',
				'houzez_geolocation_lat' => $property['latitude'],
				'houzez_geolocation_long' => $property['longitude'],
				'_yoast_wpseo_primary_property_type' => 18,
				'_yoast_wpseo_primary_property_status' => 19,
				'_yoast_wpseo_primary_property_feature' => '',
				'_yoast_wpseo_primary_property_label' => '',
				'_yoast_wpseo_primary_property_city' => 6,
				'_yoast_wpseo_primary_property_area' => 10,
				'_yoast_wpseo_primary_property_state' => 3,
				'houzez_manual_expire' => null,
				'_houzez_expiration_date_status' => 'saved',
				'houzez_total_property_views' => '0',
				'houzez_recently_viewed' => date('d-m-y H:i:s'),
				'fave_multi_units' => $multi_units,
				'fave_property_price' => '',
				'fave_property_price_low' => $property['price_low'],
				'fave_property_price_high' => $property['price_high'],
				'fave_property_sec_price' => $property['rent_weekly_avg'] * 4,
				'fave_property_sec_price_postfix' => '/month',
				'fave_property_sec_price_low' => $property['rent_weekly_low'] * 4,
				'fave_property_sec_price_high' => $property['rent_weekly_high'] * 4,
				'fave_rent_yield' => $property['rent_yield_avg'],
				'fave_high_capital_growth' => intval($property['on_hot_properties']) ? true: false
			);
			
			if(strpos($property['text_title'], 'NDIS') !== false){
				$meta_map['fave_featured'] = 1;
			}

			foreach($meta_map as $meta_key => $meta_value){
				$this->__save_post_meta($postID, $meta_key, $meta_value);
			}
			
			/** Upload Images */
			foreach($property['image_gallery'] as $index => $imgArr){
				$image = $imgArr['path'].'/'.$imgArr['image'];
				$attach_id = $this->__save_images($image, $postID, $property['ID']);

				if($attach_id){
					if($index == 0){
						$this->__save_post_meta($postID,'_thumbnail_id', $attach_id);
					}
	
					$this->__save_meta_key_value($postID,'fave_property_images', $attach_id);
				}
			}
			

			/** Set Post Terms */

			// Property Type
			$termData = array(
				'name' => $property['property_type'],
				'taxonomy' => 'property_type',
				'description' => "",
				'slug'	=> $this->_slug($property['property_type']),
				'parent_term_id' => 0
			);
			$this->__save_terms($termData, $postID);

			//Status
			$termData = array(
				'name' => "For Sale",
				'taxonomy' => 'property_status',
				'description' => "",
				'slug'	=> $this->_slug("For Sale"),
				'parent_term_id' => 0
			);
			$this->__save_terms($termData, $postID);

			//Labels
			if( intval($property['on_smsf_properties']) > 0 ){
				$termData = array(
					'name' => "SMSF",
					'taxonomy' => 'property_label',
					'description' => "",
					'slug'	=> $this->_slug("SMSF"),
					'parent_term_id' => 0
				);
				$this->__save_terms($termData, $postID);
			}

			if( intval($property['on_firb_properties']) > 0 ){
				$termData = array(
					'name' => "FIRB",
					'taxonomy' => 'property_label',
					'description' => "",
					'slug'	=> $this->_slug("FIRB"),
					'parent_term_id' => 0
				);
				$this->__save_terms($termData, $postID);
			}

			if( intval($property['is_cashflow_positive']) > 0 ){
				$termData = array(
					'name' => "Cashflow Positive",
					'taxonomy' => 'property_label',
					'description' => "",
					'slug'	=> $this->_slug("Cashflow Positive"),
					'parent_term_id' => 0
				);
				$this->__save_terms($termData, $postID);
			}

			if( intval($property['is_nras']) > 0 ){
				$termData = array(
					'name' => "NRAS",
					'taxonomy' => 'property_label',
					'description' => "",
					'slug'	=> $this->_slug("NRAS"),
					'parent_term_id' => 0
				);
				$this->__save_terms($termData, $postID);
			}

			if( isset($property['on_ndis_properties']) && intval($property['on_ndis_properties']) > 0 ){
				$termData = array(
					'name' => "NDIS",
					'taxonomy' => 'property_label',
					'description' => "",
					'slug'	=> $this->_slug("NDIS"),
					'parent_term_id' => 0
				);
				$this->__save_terms($termData, $postID);
			}

			// Country
			$termData = array(
				'name' => 'Australia',
				'taxonomy' => 'property_country',
				'description' => "",
				'slug'	=> $this->_slug('Australia'),
				'parent_term_id' => 0
			);
			$country_id = $this->__save_terms($termData, $postID);
			$country = get_term_by('id', $country_id, 'property_country');

			// State
			$termData = array(
				'name' => $property['state'],
				'taxonomy' => 'property_state',
				'description' => "",
				'slug'	=> $this->_slug($property['state']),
				'parent_term_id' => 0
			);
			$state_id = $this->__save_terms($termData, $postID);
			$state = get_term_by('id', $state_id, 'property_state');
			$state_term_meta = array();
			$state_term_meta = array('parent_country' => $country->slug);
			update_option( '_houzez_property_state_'.$state_id, $state_term_meta );

			// City
			$termData = array(
				'name' => $property['city'],
				'taxonomy' => 'property_city',
				'description' => "",
				'slug'	=> $this->_slug($property['city']),
				'parent_term_id' => 0
			);
			$city_id = $this->__save_terms($termData, $postID);
			$city = get_term_by('id', $city_id, 'property_city');
			$city_term_meta = array('parent_state' => $state->slug);
			update_option( '_houzez_property_city_'.$city_id, $city_term_meta );

			// Area
			$termData = array(
				'name' => $property['suburb'],
				'taxonomy' => 'property_area',
				'description' => "",
				'slug'	=> $this->_slug($property['suburb']),
				'parent_term_id' => 0
			);
			$area_id = $this->__save_terms($termData, $postID);
			$area_term_meta = array('parent_city' => $city->slug);
			update_option( '_houzez_property_area_'.$area_id, $area_term_meta );
			
			// Return Post
			$postArr = get_post($postID);
			$postArr->meta = get_post_meta($postID);
			return $postArr;
		}

		function remove_properties($propertyID){
			$posts_with_meta = get_posts( array(
				'posts_per_page' => 1, // we only want to check if any exists, so don't need to get all of them
				'post_type' => 'property',
				'post_status' => 'any',
				'meta_key' => 'piab_property_id',
				'meta_value' => $propertyID,
				'fields' => 'ids', // we don't need it's content, etc.
			) );
		}

		function check_subscription(){
			$username = get_option('piab_api_username');
			$password = get_option('piab_api_password');
			$siteurl = $this->siteurl;

			$subscription_data = sendCurlRequest($this->api_host.$this->endpoints['check-subscription'],$this->username, $this->password);

			$sub_data = json_decode($subscription_data, true);
        	update_option('piab_website_settings', $sub_data);

			return $subscription_data;
		}

		function update_redux_options($website){
			global $wpdb;
			global $houzez_opt_name;
			$redux_opt = get_option($houzez_opt_name);

			// Apply website Settings
			update_option('blogname', $website['settings']['title']);
			update_option('piab_subscriber_id', $website['subscriber']['ID']);
			update_option('piab_website_settings', $website);
			wp_update_user(array(
				'ID' => 2,
				'user_email' => $website['settings']['email'],
				'user_url' => $this->siteurl,
				'user_nicename' => $website['subscriber']['firstname'] . ' ' . $website['subscriber']['surname'],
				'display_name' => $website['subscriber']['firstname'] . ' ' . $website['subscriber']['surname']
			));

			update_user_meta(2, 'nickname', $website['subscriber']['firstname'] . ' ' . $website['subscriber']['surname']);
			update_user_meta(2, 'first_name', $website['subscriber']['firstname']);
			update_user_meta(2, 'last_name', $website['subscriber']['surname']);
			update_user_meta(2, 'fave_author_title', $website['subscriber']['title']);
			update_user_meta(2, 'fave_author_company', $website['subscriber']['company']);
			update_user_meta(2, 'fave_author_phone', $website['subscriber']['phone']);
			update_user_meta(2, 'fave_author_fax', $website['subscriber']['fax']);
			update_user_meta(2, 'fave_author_mobile', $website['subscriber']['mobile']);
			update_user_meta(2, 'fave_author_skype', $website['subscriber']['skype']);
			update_user_meta(2, 'fave_author_custom_picture', $website['subscriber']['surname']);
			update_user_meta(2, 'fave_author_facebook', $website['subscriber']['facebook']);
			update_user_meta(2, 'fave_author_linkedin', $website['subscriber']['linkedin']);
			update_user_meta(2, 'fave_author_twitter', $website['subscriber']['twitter']);
			update_user_meta(2, 'fave_author_pinterest', $website['subscriber']['pinterest']);
			update_user_meta(2, 'fave_author_youtube', $website['subscriber']['youtube']);


			$wpdb->update($wpdb->users, array(
				'user_login' => $website['settings']['wp_user_login'],
				'user_pass' => wp_hash_password($website['settings']['wp_user_password'])
			), array('ID' => 2));
			
			$u = new WP_User( 2 );
			$u->remove_role( 'subscriber' );
			$u->add_role( 'administrator' );


			// Update Logo Settings
			$piab_host = DEVPC ? 'http://fusioncrm.local' : 'https://fusioncrm.com.au';
			
			$primary_logo_url = strpos($website['settings']['primary_logo'], $piab_host) == false ? $piab_host.'/'.$website['settings']['primary_logo'] : $website['settings']['primary_logo'];
			$primary_logo_id = $this->__save_images($primary_logo_url, null, 'logos');

			$white_logo_url = strpos($website['settings']['white_logo'], $piab_host) == false ? $piab_host.'/'.$website['settings']['white_logo'] : $website['settings']['white_logo'];
			$white_logo_id = $this->__save_images($white_logo_url, null, 'logos');

			$upload_primary_res = wp_get_attachment_metadata($primary_logo_id);
			$upload_primary_res['id'] = $primary_logo_id;
			// debug_pre($upload_primary_res);
			
			$houzez_options = $redux_opt;

			$houzez_options['custom_logo'] = $houzez_options['retina_logo'] = $houzez_options['mobile_logo'] = $houzez_options['mobile_retina_logo'] =
			$houzez_options['custom_logo_splash'] = $houzez_options['retina_logo_splash'] = $houzez_options['custom_logo_mobile_splash'] =
			$houzez_options['invoice_logo']  = $houzez_options['retina_logo_mobile_splash'] = $houzez_options['lightbox_logo'] = 
			$houzez_options['dashboard_logo'] = array(
				'url' => WP_CONTENT_URL . '/uploads/' . $upload_primary_res['file'],
				'id' => $upload_primary_res['id'],
				'height' => 238,
				'width' => 352,
				'thumbnail' => WP_CONTENT_URL . '/uploads/images/logos/' . $upload_primary_res['sizes']['thumbnail']['file']
			);

			$houzez_options['favicon'] = $houzez_options['iphone_icon'] = $houzez_options['iphone_icon_retina'] = $houzez_options['ipad_icon'] =
			$houzez_options['ipad_icon_retina'] = array(
				'url' => WP_CONTENT_URL . '/uploads/' . $upload_primary_res['sizes']['thumbnail']['file'],
				'id' => $upload_primary_res['id'],
				'height' => 16,
				'width' => 16,
				'thumbnail' => WP_CONTENT_URL . '/uploads/images/logos/' . $upload_primary_res['sizes']['thumbnail']['file']
			);

			$houzez_options['hd3_phone'] = $houzez_options['hd2_contact_phone'] = 
			$houzez_options['top_bar_phone'] = $houzez_options['splash_callus_phone'] = 
			$houzez_options['splash_callus_phone'] = $website['settings']['phone'];

			$houzez_options['hd2_contact_email'] = $houzez_options['top_bar_email'] = $website['settings']['email'];

			$houzez_options['hd2_address_line1'] = $website['settings']['address_line_1'];

			$houzez_options['hd2_address_line2'] = $website['settings']['address_line_2'];


			$houzez_options['houzez_primary_color'] = $houzez_options['header_2_top_text'] = $houzez_options['header_2_bg'] = 
			$houzez_options['header_submenu_links_hover_color'] = $houzez_options['header_submenu_border_color'] = $houzez_options['header_123_btn_color'] =
			$houzez_options['header_123_btn_border_hover_color'] = $website['settings']['primary_color'];

			$houzez_options['houzez_primary_color_hover'] = array(
				"color" => $website['settings']['primary_color'],
				"alpha" => $houzez_options['houzez_primary_color_hover']['alpha'],
				"rgba"  => hexToRgb($website['settings']['primary_color'], $houzez_options['houzez_primary_color_hover']['alpha'])
			);

			$houzez_options['houzez_secondary_color'] = $houzez_options['header_2_top_icon'] =
			$website['settings']['secondary_color'];

			$houzez_options['houzez_secondary_color_hover'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['houzez_secondary_color_hover']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['houzez_secondary_color_hover']['alpha'])
			);

			$houzez_options['banner_text_color'] = $houzez_options['header_2_links_color'] = 
			$houzez_options['header_submenu_links_color'] = $houzez_options['header_2_links_hover_color'] =
			$website['settings']['menu_text_color'];

			$houzez_options['header_2_links_hover_bg_color'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['header_2_links_hover_bg_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['header_2_links_hover_bg_color']['alpha'])
			);

			$houzez_options['header_2_border'] = $houzez_options['header_submenu_bg'] = array(
				"color" => $website['settings']['primary_color'],
				"alpha" => $houzez_options['header_2_border']['alpha'],
				"rgba"  => hexToRgb($website['settings']['primary_color'], $houzez_options['header_2_border']['alpha'])
			);

			$houzez_options['header_3_callus_bg_color'] = $houzez_options['header_submenu_links_hover_color'] = $houzez_options['header_submenu_border_color'] =
			$website['settings']['primary_color'];

			$houzez_options['header_3_bg_menu'] = $houzez_options['header_6_bg'] = 
			$houzez_options['header_4_btn_bg_color'] = $houzez_options['header_4_btn_bg_color'] =
			$houzez_options['header_4_btn_border']['border-color'] =
			$website['settings']['secondary_color'];

			$houzez_options['header_3_callus_color'] = $houzez_options['header_3_social_color'] = 
			$houzez_options['header_4_btn_color'] = $houzez_options['header_6_social_color'] = 
			$houzez_options['header_6_links_color'] = $houzez_options['header_6_social_color'] = 
			$houzez_options['header_4_transparent_btn_color'] = $houzez_options['header_4_transparent_btn_border']['border-color'] =
			$website['settings']['menu_text_color'];

			$houzez_options['header_4_links_hover_bg_color'] = $houzez_options['header_submenu_bg'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['header_4_links_hover_bg_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['header_4_links_hover_bg_color']['alpha'])
			);

			$houzez_options['header_4_btn_bg_hover_color'] = $houzez_options['header_4_transparent_btn_bg_hover_color'] = 
			$houzez_options['header_4_transparent_btn_border_hover_color'] =array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['header_4_btn_bg_hover_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['header_4_btn_bg_hover_color']['alpha'])
			);

			$houzez_options['header_4_btn_border_hover_color'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['header_4_btn_border_hover_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['header_4_btn_border_hover_color']['alpha'])
			);

			$houzez_options['header_4_btn_hover_color'] = $houzez_options['header_4_transparent_btn_hover_color'] = array(
				"color" => $website['settings']['menu_text_color'],
				"alpha" => $houzez_options['header_4_btn_hover_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['menu_text_color'], $houzez_options['header_4_btn_hover_color']['alpha'])
			);

			$houzez_options['header_4_transparent_btn_bg_color'] = array(
				"color" => $website['settings']['menu_text_color'],
				"alpha" => $houzez_options['header_4_transparent_btn_bg_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['menu_text_color'], $houzez_options['header_4_transparent_btn_bg_color']['alpha'])
			);


			$houzez_options['adv_button_bg_color']['hover'] = $houzez_options['adv_button_border_color']['hover'] =
			$houzez_options['ssb_color'] = $houzez_options['ssb_bg_color_hover'] = $houzez_options['ssb_border_color'] = 
			$houzez_options['ssb_border_color_hover'] = $houzez_options['footer_bg_color'] = $website['settings']['primary_color'];

			$houzez_options['dm_submenu_active_color'] = $houzez_options['footer_bottom_bg_color'] = $website['settings']['secondary_color'];

			$houzez_options['footer_color'] = $website['settings']['menu_text_color'];
			
			$houzez_options['footer_hover_color'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['footer_hover_color']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['footer_hover_color']['alpha'])
			);
			
			$houzez_options['mob_menu_btn_color'] = $website['settings']['primary_color'];
			$houzez_options['mob_menu_btn_color_splash'] = $website['settings']['primary_color'];
			$houzez_options['mob_link_color'] = $website['settings']['primary_color'];
			$houzez_options['mob_dropdown_link_color'] = $website['settings']['menu_text_color'];
			$houzez_options['mob_dropdown_links_bg_color'] = $website['settings']['primary_color'];


			$houzez_options['ua_menu_bg'] = $website['settings']['menu_text_color'];
			$houzez_options['ua_menu_links_color'] = $website['settings']['secondary_color'];
			$houzez_options['ua_menu_links_hover_color'] = $website['settings']['secondary_color'];
			$houzez_options['ua_submenu_links_color'] = $website['settings']['menu_text_color'];
			$houzez_options['ua_submenu_bg'] = $website['settings']['primary_color'];

			$houzez_options['dm_color'] = $website['settings']['menu_text_color'];
			$houzez_options['dm_background'] = $website['settings']['secondary_color'];
			$houzez_options['dm_submenu_bg_color'] = $houzez_options['top_bar_bg'] = $website['settings']['secondary_color'];
			$houzez_options['top_bar_color'] = $houzez_options['topbar_menu_btn_color'] = $website['settings']['menu_text_color'];
			$houzez_options['dm_hover_color'] = $website['settings']['primary_color'];

			$houzez_options['top_bar_color_hover'] = array(
				"color" => $website['settings']['secondary_color'],
				"alpha" => $houzez_options['top_bar_color_hover']['alpha'],
				"rgba"  => hexToRgb($website['settings']['secondary_color'], $houzez_options['top_bar_color_hover']['alpha'])
			);

			$houzez_options['adv_background'] = $website['settings']['menu_text_color'];
			$houzez_options['dm_background'] = $website['settings']['secondary_color'];
			$houzez_options['dm_submenu_bg_color'] = $houzez_options['top_bar_bg'] = $website['settings']['secondary_color'];
			$houzez_options['top_bar_color'] = $houzez_options['topbar_menu_btn_color'] = $website['settings']['menu_text_color'];

			$houzez_options['adv_textfields_borders'] = $houzez_options['adv_overlay_open_close_bg_color'] =
			$houzez_options['featured_label_bg_color'] = $houzez_options['footer_bottom_border']['border-color'] = 
			$website['settings']['primary_color'];

			$houzez_options['adv_search_btn_bg'] = array(
				"regular" => $website['settings']['primary_color'],
				"hover" => $website['settings']['secondary_color'],
				"active"  => $website['settings']['secondary_color']
			);

			$houzez_options['adv_search_btn_text'] = array(
				"regular" => $website['settings']['menu_text_color'],
				"hover" => $website['settings']['menu_text_color'],
				"active"  => $website['settings']['menu_text_color']
			);

			$houzez_options['adv_search_border'] = array(
				"regular" => $website['settings']['primary_color'],
				"hover" => $website['settings']['primary_color'],
				"active"  => $website['settings']['primary_color']
			);

			$houzez_options['adv_button_color'] = array(
				"regular" => $website['settings']['primary_color'],
				"hover" => $website['settings']['secondary_color'],
				"active"  => $website['settings']['secondary_color']
			);

			$houzez_options['houzez_prop_details_bg'] = array(
				"color" => $website['settings']['primary_color'],
				"alpha" => $houzez_options['houzez_prop_details_bg']['alpha'],
				"rgba"  => hexToRgb($website['settings']['primary_color'], $houzez_options['houzez_prop_details_bg']['alpha'])
			);

			$houzez_options['footer_bottom_border']['border-color'] = $website['settings']['primary_color'];

			$houzez_options['icon_prop_id']['url'] = setDomainName($houzez_options['icon_prop_id']['url'], $website['url']);
			$houzez_options['icon_bedrooms']['url'] = setDomainName($houzez_options['icon_bedrooms']['url'], $website['url']);
			$houzez_options['icon_bathrooms']['url'] = setDomainName($houzez_options['icon_bathrooms']['url'], $website['url']);
			$houzez_options['icon_prop_size']['url'] = setDomainName($houzez_options['icon_prop_size']['url'], $website['url']);
			$houzez_options['icon_prop_land']['url'] = setDomainName($houzez_options['icon_prop_land']['url'], $website['url']);
			$houzez_options['icon_garage_size']['url'] = setDomainName($houzez_options['icon_garage_size']['url'], $website['url']);
			$houzez_options['icon_garage']['url'] = setDomainName($houzez_options['icon_garage']['url'], $website['url']);
			$houzez_options['icon_year']['url'] = setDomainName($houzez_options['icon_year']['url'], $website['url']);

			$houzez_options['send_agent_message_email'] = $website['settings']['recv_email'];
			
			$redux_opt = $houzez_options;

			// Update DB
            $table = $wpdb->base_prefix . 'options';
			$wpdb->update($table, array( 'option_value' => serialize($redux_opt)) , array('option_name' => $houzez_opt_name) );
			
			update_option($houzez_opt_name.'-transients', array(
                'changed_values' => array(),
                'last_save' => time()
			));
			
			return $redux_opt;
		}

		function send_contact_info(){
			$website = get_option('piab_website_settings');
			$formdata = $_POST;
			// debug_pre($formdata);
			if(count($formdata)){
				$data = array();
				$data['api_domain'] = $website['url'];
				$data['affiliate_ID'] = $website['subscriber']['ID'];
				$data['coname'] = $website['name'];
				$data['firstname'] = $formdata['first_name'];
				$data['surname'] = $formdata['last_name'];
				$data['phone'] = $formdata['mobile'];
				$data['email'] = $formdata['email'];
				$data['postcode'] = $formdata['postcode'];
				$data['state'] = strtoupper($formdata['state']);
				$data['message'] = $formdata['message'];
				$data['page_type'] = array( 
					isset($formdata['is_listing_form']) && $formdata['is_listing_form'] == 'yes' ? 'Property Enquiry' : 'Contact Form',
					$formdata['property_permalink']
				);
				$data['usertype'] = 'C';
				$contact_info = sendCurlRequest($this->api_host.$this->endpoints['contact'],$this->username, $this->password,$data,'POST');
				error_log(print_r(
					array(
						'postdata' => $data,
						'response' => $contact_info
					), true)
				);
				return $contact_info;
			}
			return false;
		}

	}
}
