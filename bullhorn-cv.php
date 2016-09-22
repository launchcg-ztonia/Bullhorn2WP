<?php


// require_once dirname( dirname( __FILE__ ) ) . '/bullhorn-2-wp.php';

/**
 * This class is an extension of Bullhorn_Connection.  Its purpose
 * is to allow for resume and candidate posting
 *
 * Class Bullhorn_Extended_Connection
 */
class Bullhorn_Extended_Connection extends Bullhorn_Connection {

	/**
	 * Class Constructor
	 *
	 * @return \Bullhorn_Extended_Connection
	 */
	public function __construct () {
		// Call parent __construct()
		parent::__construct();

		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'parse_request', array( __CLASS__, 'sniff_requests' ) );
	}

	/**
	 * Update vars
	 *
	 * @param $vars
	 *
	 * @return array
	 */
	public static function add_query_vars ( $vars ) {
		$vars[] = '__api';
		$vars[] = 'endpoint';

		return $vars;
	}

	/**
	 * Initialize the reqrite rule
	 *
	 * @return void
	 */
	public static function add_endpoint () {
		add_rewrite_rule( '^api/bullhorn/([^/]+)/?', 'index.php?__api=1&endpoint=$matches[1]', 'top' );
	}

	/**
	 * Check to see if the request is a bullhorn API request
	 *
	 * @return void
	 */
	public static function sniff_requests () {
		global $wp;
		if ( isset( $wp->query_vars['__api'] ) && isset( $wp->query_vars['endpoint'] ) ) {
			switch ( $wp->query_vars['endpoint'] ) {
				case 'resume':

					$thanks_page_url = esc_url( ( isset( $_POST['_wp_http_referer'] ) ) ? $_POST['_wp_http_referer'] : '' );

					$settings = (array) get_option( 'bullhorn_settings' );
					if ( 0 < $settings['thanks_page'] ) {
						$thanks_page_url = get_permalink( $settings['thanks_page'] );
					}

					if (
						! isset( $_POST['bullhorn_cv_form'] )
						|| ! wp_verify_nonce( $_POST['bullhorn_cv_form'], 'bullhorn_cv_form' )
					) {
						esc_attr_e( 'Sorry, your nonce did not verify.', 'bh-staffing-job-listing-and-cv-upload-for-wp' );
						die();

					}

					// Get Resume
					$resume = self::parseResume();

					if ( false === $resume ) {
						// Redirect
						$permalink = add_query_arg( array(
							'bh_applied' => false,
						), $thanks_page_url );

						header( "location: $permalink" );
						exit;
					}

					if ( is_array( $resume ) ) {
						$orig_url = $_POST['_wp_http_referer'];
						$url      = add_query_arg(
							array(
								'bh-message' => rawurlencode( apply_filters( 'parse_resume_failed_text', $resume['errorMessage'] ) ),

							), $orig_url
						);

						wp_safe_redirect( $url );
						die();
					}

					// Create candidate
					$candidate = self::create_candidate( $resume );

					if ( false === $candidate || ! isset( $candidate->changedEntityId ) ) {
						error_log( 'Candidate ID not set: ' . serialize( $candidate ) );

						$permalink = add_query_arg( array(
							'bh_applied' => false,
						), $thanks_page_url );

						header( "location: $permalink" );
						exit;
					} else {
						// Attach education to candidate
						self::attachEducation( $resume, $candidate );

						// Attach work history to candidate
						self::attach_work_history( $resume, $candidate );
						//var_dump($resume->candidateWorkHistory);

						// Attach work history to candidate
						self::attach_skills( $resume, $candidate );

						// link to job
						self::link_candidate_to_job( $candidate );

						// Attach resume file to candidate
						error_log( 'wp_upload_file_request: ' . self::wp_upload_file_request( $candidate ) );

						do_action( 'wp-bullhorn-cv-upload-complete', $candidate, $resume );

						// Redirect
						$permalink = add_query_arg( array(
							'bh_applied' => true,
						), $thanks_page_url );
						header( "location: $permalink" );
						exit;
					}


					break;
				default:
					$response = array(
						'status' => 404,
						'error'  => __( 'The endpoint you are trying to reach does not exist.', 'bh-staffing-job-listing-and-cv-upload-for-wp' ),
					);
					echo wp_json_encode( $response );
			}
			exit;
		}
	}

	public static function add_bullhorn_candidate ( $profile_data, $file_data ) {

		// Get Resume
		if ( is_array( $file_data ) ) {
			$resume = self::parseResume( $file_data );

		if ( false === $resume ) {

			return false;
		}
		} else {
			// create data oject to create Candidate

			$resume                     = new stdClass();
			$resume->candidate          = new stdClass();
			$resume->candidate->address = array();
			$resume->skillList          = array();
		}


		// Create candidate
		$candidate = self::create_candidate( $resume, $profile_data );

		// Attach education to candidate
		self::attachEducation( $resume, $candidate );

		// Attach work history to candidate
		self::attach_work_history( $resume, $candidate );
		//var_dump($resume->candidateWorkHistory);

		// Attach work history to candidate
		self::attach_skills( $resume, $candidate );

		// Attach resume file to candidate
		if ( is_array( $file_data ) ) {
		$file_name = $file_data['resume']['name'];
			$local_file = $file_data['resume']['tmp_name'];
		error_log( 'wp_upload_file_request: ' . self::wp_upload_file_request( $candidate, $local_file, $file_name ) );
		}

		do_action( 'add_bullhorn_candidate_complete', $candidate, $resume, $profile_data, $file_data );

		return $candidate->changedEntityId;
	}

//TODO: finish
	public static function update_bullhorn_candidate ( $candidate_id, $profile_data, $file_data ) {

		// Get Resume
		if ( is_array( $file_data ) ) {
			$resume = self::parseResume( $file_data );

			if ( false === $resume ) {

				return false;
			}
		} else {
			// create an empty data object to create Candidate

			$resume                     = new stdClass();
			$resume->candidate          = new stdClass();
			$resume->candidate->address = array();
			$resume->skillList          = array();
		}

		$resume = self::add_data_to_canditate_data( $resume, $profile_data );

		// Update candidate
		$candidate = self::update_candidate( $candidate_id, $resume->candidate );

		do_action( 'update_bullhorn_candidate_complete', $candidate_id, $resume, $profile_data, $file_data, $candidate );

		return true;
	}

	/**
	 * Takes the posted 'resume' file and returns a parsed version from bullhorn
	 *
	 * @return mixed
	 */
	public static function parseResume( $local_files = null ) {

		$ext = $format = '';
		if ( null === $local_files ) {
			// check to make sure file was posted
			if ( ! isset( $_FILES['resume'] ) ) {

				self::throwJsonError( 500, 'No resume file found.' );
			}
			list( $ext, $format ) = self::get_filetype();

			// http://gerhardpotgieter.com/2014/07/30/uploading-files-with-wp_remote_post-or-wp_remote_request/
			$local_file = $_FILES['resume']['tmp_name'];
		} else {
			if ( ! isset( $local_files['resume'] ) ) {

				self::throwJsonError( 500, 'No resume file found.' );
		}
			list( $ext, $format ) = self::get_filetype( $local_files );

			// http://gerhardpotgieter.com/2014/07/30/uploading-files-with-wp_remote_post-or-wp_remote_request/
			$local_file = $local_files['resume']['tmp_name'];
		}


		if ( ! file_exists( $local_file ) ) {

			return false;
		}
		// wp_remote_request way

		//https://github.com/jeckman/wpgplus/blob/master/gplus.php#L554
		$boundary = md5( time() );
		$payload  = '';
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="photo_upload_file_name"; filename="' . $_FILES['resume']['name'] . '"' . "\r\n";
		$payload .= 'Content-Type: ' . $ext . '\r\n'; // If you	know the mime-type
		$payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents( $local_file );
		$payload .= "\r\n";
		$payload .= '--' . $boundary . '--';
		$payload .= "\r\n\r\n";

		$args = array(
			'method'  => 'POST',
			'timeout' => 120, // default is 45 set to 2 minets for this one call
			'headers' => array(
				'accept'       => 'application/json', // The API returns JSON
				'content-type' => 'multipart/form-data;boundary=' . $boundary, // Set content type to multipart/form-data
			),
			'body'    => $payload,
		);
		// API authentication
		self::apiAuth();

		$url = add_query_arg(
			array(
				'BhRestToken'         => self::$session,
				'format'              => $format,
				'populateDescription' => 'html',
			), self::$url . 'resume/parseToCandidate'
		);
		$safety_count = 0;
		// make call to the parse the CV
		$response = wp_remote_request( $url, $args );
		while ( 10 > $safety_count ) {

			// sometimes we will get an REX error this is due to a comms failing between bullhorn servers aand the 3rd party servers

			// if are good exit while loop
			if ( ! is_wp_error( $response ) && isset( $response['body'] ) && false === strpos( strtolower( $response['body'] ), 'convert failed' ) ) {
				break;
			}
			if ( is_wp_error( $response ) ) {
				error_log( 'CV parse looped with : ' . serialize( $response ) . ': ' . $safety_count );
			} elseif ( isset( $response['errorMessage'] ) ) {
				error_log( 'CV parse looped with : ' . $response['errorMessage'] . ': ' . $safety_count );
			}

			// make a attempt call to the parse the CV
			$response = wp_remote_request( $url, $args );
			$safety_count ++;
		}

		if ( is_wp_error( $response ) ) {

			return false;
		}

		if ( 200 === $response['response']['code'] ) {

			return json_decode( $response['body'] );
		}

		return json_decode( $response['body'], true );
	}

	/**
	 * Send a json error to the screen
	 *
	 * @param $status
	 * @param $error
	 */
	public static function throwJsonError ( $status, $error ) {
		$response = array( 'status' => $status, 'error' => $error );
		echo wp_json_encode( $response );
		exit;
	}

	/**
	 * @return array
	 */
	private static function get_filetype( $local_files = null ) {
		// Get file extension
		if ( null === $local_files ) {
		$file_type = wp_check_filetype_and_ext( $_FILES['resume']['tmp_name'], $_FILES['resume']['name'] );
		} else {
			$file_type = wp_check_filetype_and_ext( $local_files['resume']['tmp_name'], $local_files['resume']['name'] );
		}

		$ext       = $file_type['type'];

		switch ( strtolower( $ext ) ) {
			case 'text/plain':
				$format = 'TEXT';
				break;
			case 'application/msword':
				$format = 'DOC';
				break;
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$format = 'DOCX';
				break;
			case 'application/pdf':
				$format = 'PDF';
				break;
			case 'text/rtf':
				$format = 'RTF';
				break;
			case 'text/html':
				$format = 'HTML';
				break;
			default:
				$format   = '';
				$orig_url = $_POST['_wp_http_referer'];
				$url      = add_query_arg(
					array(
						'bh-message' => rawurlencode( apply_filters( 'file_type_failed_text', __( "Oops. This document isn't the correct format. Please upload it as one of the following formats: .txt, .html, .pdf, .doc, .docx, .rft.", 'bh-staffing-job-listing-and-cv-upload-for-wp' ) ) ),

					), $orig_url
				);

				wp_safe_redirect( $url );
				die();
		}

		return array( $ext, $format );
	}

	/**
	 * Run this before any api call.
	 *
	 * @return void
	 */
	private static function apiAuth () {

		// login to bullhorn api
		$logged_in = self::login();
		if ( ! $logged_in ) {
			self::throwJsonError( 500, __( 'There was a problem logging into the Bullhorn API.', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) );
		}
	}

	/**
	 * Create a candidate int he system
	 *
	 * @param $resume
	 * @param array $profile_data
	 *
	 * @return mixed
	 */
	public static function create_candidate( $resume, $profile_data = array() ) {

		if ( ! isset( $resume->candidate ) ) {

			return false;
		}
		$resume = self::add_data_to_canditate_data( $resume, $profile_data );

		$resume->candidate->source = 'New Website';

		// API authentication
		self::apiAuth();

		$url = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/Candidate'
		);

		$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $resume->candidate ), 'method' => 'PUT' ) );

		$safety_count = 0;
		while ( 500 === $response['response']['code'] && 5 > $safety_count ) {
			error_log( 'Create Canditate failed( ' . $safety_count . '): ' . serialize( $response ) );
			$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $resume->candidate ), 'method' => 'PUT' ) );
			$safety_count ++;
		}

		if ( isset( $profile_data['phone'] ) ) {
			$cv_phone = $resume->candidate->phone;

			$resume->candidate->phone  = esc_attr( $profile_data['phone'] );
			$resume->candidate->phone2 = esc_attr( $cv_phone );
		} elseif ( isset( $_POST['phone'] ) ) {
			$cv_phone = $resume->candidate->phone;

			$resume->candidate->phone  = esc_attr( $_POST['phone'] );
			$resume->candidate->phone2 = esc_attr( $cv_phone );
		}

		if ( isset( $profile_data['name'] ) ) {

			$resume->candidate->name = esc_attr( $profile_data['name'] );
		} elseif ( isset( $_POST['name'] ) ) {

			$resume->candidate->name = esc_attr( $_POST['name'] );
		}

		$address_fields = array( 'address1', 'address2', 'city', 'state', 'zip' );
		if ( isset( $profile_data['address'] ) ) {
			$cv_address = $resume->candidate->address;

			$address_data = array();

			foreach ( $address_fields as $key ) {
				$address_data[ $key ] = ( isset( $profile_data[ $key ] ) ) ? $profile_data[ $key ] : '';
			}
			$resume->candidate->address          = $address_data;
			$resume->candidate->secondaryAddress = $cv_address;
		} elseif ( isset( $_POST['address1'] ) ) {
			$cv_address = $resume->candidate->address;

			$address_data = array();

			foreach ( $address_fields as $key ) {
				$address_data[ $key ] = ( isset( $_POST[ $key ] ) ) ? $_POST[ $key ] : '';
			}

			$resume->candidate->address          = $address_data;
			$resume->candidate->secondaryAddress = $cv_address;

		}

		$resume->candidate->source = 'New Website';

		// API authentication
		self::apiAuth();

		$url = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/Candidate'
		);

		$response = wp_remote_get( $url, array( 'body' => json_encode( $resume->candidate ), 'method' => 'PUT' ) );

		$safety_count = 0;
		while ( 500 === $response['response']['code'] && 5 > $safety_count ) {
			error_log( 'Create Canditate failed( ' . $safety_count . '): ' . serialize( $response ) );
			$response = wp_remote_get( $url, array( 'body' => json_encode( $resume->candidate ), 'method' => 'PUT' ) );
			$safety_count ++;
		}

		if ( ! is_wp_error( $response ) && 200 === $response['response']['code'] ) {

			return json_decode( $response['body'] );
		}

		return false;
	}

	/**
	 * Create a candidate int he system
	 *
	 * @param $resume
	 * @param array $profile_data
	 *
	 * @return mixed
	 */
	public static function update_candidate( $candidate_id, $candidate ) {


		// API authentication
		self::apiAuth();

		$url = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/Candidate/' . $candidate_id
		);

		$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $candidate ), 'method' => 'POST' ) );

		$safety_count = 0;
		while ( 500 === $response['response']['code'] && 5 > $safety_count ) {
			error_log( 'Create Canditate failed( ' . $safety_count . '): ' . serialize( $response ) );
			$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $candidate ), 'method' => 'PUT' ) );
			$safety_count ++;
			}

		if ( ! is_wp_error( $response ) && 200 === $response['response']['code'] ) {

			return json_decode( $response['body'] );
			}

		return false;
		}


	private static function get_candidate( $candidate_id ) {

		// API authentication
		self::apiAuth();

		$url = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/Candidate/' . $candidate_id . '?fields=*'
		);

		$response = wp_remote_get( $url );

		$safety_count = 0;
		while ( 500 === $response['response']['code'] && 5 > $safety_count ) {
			error_log( 'Get Canditate failed( ' . $safety_count . '): ' . serialize( $response ) );
			$response = wp_remote_get( $url );
			$safety_count ++;
		}

		if ( ! is_wp_error( $response ) && 200 === $response['response']['code'] ) {

			return json_decode( $response['body'] );
		}

		return false;
	}

	/**
	 * Attach education to cantitates
	 *
	 * @param $resume
	 * @param $candidate
	 *
	 * @return mixed
	 */
	public static function attachEducation ( $resume, $candidate ) {

		if ( empty( $resume->candidateEducation ) ) {

			return false;
		}

		// API authentication
		self::apiAuth();

		$responses = array();
		$url       = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/CandidateEducation'
		);

		foreach ( $resume->candidateEducation as $edu ) {
			$edu->candidate     = new stdClass;
			$edu->candidate->id = $candidate->changedEntityId;
			if ( ! is_int( $edu->gpa ) || ! is_float( $edu->gpa ) ) {
				unset( $edu->gpa );
			}

			//$edu_data = json_encode( $edu );

			$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $edu ), 'method' => 'PUT' ) );

			if ( 200 === $response['response']['code'] ) {
				$responses[] = wp_remote_retrieve_body( $response );
			}
		}

		return json_decode( '[' . implode( ',', $responses ) . ']' );
	}

	/**
	 * Attach Work History to a candidate
	 *
	 * @param $resume
	 * @param $candidate
	 *
	 * @return mixed
	 */
	public static function attach_work_history( $resume, $candidate ) {

		if ( empty( $resume->candidateWorkHistory ) ) {

			return false;
		}
		// API authentication
		self::apiAuth();

		// Create the url && variables array
		$responses = array();
		$url       = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/CandidateWorkHistory'
		);

		foreach ( $resume->candidateWorkHistory as $job ) {

			$job->candidate     = new stdClass;
			$job->candidate->id = $candidate->changedEntityId;

			$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $job ), 'method' => 'PUT' ) );

			if ( 200 === $response['response']['code'] ) {
				$responses[] = wp_remote_retrieve_body( $response );
			}
		}

		return json_decode( '[' . implode( ',', $responses ) . ']' );
	}

	/**
	 * Attach Work History to a candidate
	 *
	 * @param $resume
	 * @param $candidate
	 *
	 * @return mixed
	 */
	public static function attach_skills( $resume, $candidate ) {

		if ( empty( $resume->skillList ) ) {
			return false;
		}
		// API authentication
		self::apiAuth();

		$skillList = self::get_skill_list();

		$skill_ids = array();
		if ( ! empty( $skill_ids ) ) {
			foreach ( $resume->skillList as $skill ) {
				if ( false !== $key = array_search( strtolower( $skill ), $skillList ) ) {
					$skill_ids[] = $key;
				}
			}
			$skill_ids = array_unique( $skill_ids );
		}

		// Create the url && variables array
		$url      = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . '/entity/Candidate/' . $candidate->changedEntityId . '/primarySkills/' . implode( ',', $skill_ids )
		);
		$response = wp_remote_get( $url, array( 'method' => 'PUT' ) );

		if ( 200 === $response['response']['code'] ) {

			return wp_remote_retrieve_body( $response );
		}

		return false;
	}

	/**
	 * @return array $skill_list
	 */
	public static function get_skill_list () {
		if ( null === self::$session ) {
			self::login();
		}

		$skill_list_id = 'bullhorn_skill_list';

		$skill_list = get_transient( $skill_list_id );
		if ( false === $skill_list ) {
			$skill_list = array();
			$url        = add_query_arg(
				array(
					'BhRestToken' => self::$session,
				), self::$url . 'options/Skill'
			);

			$response = self::request( $url, false );
			if ( is_wp_error( $response ) ) {

				return $response;
			}

			$body     = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['data'] ) ) {

				return $skill_list;
			}

			foreach ( $data['data'] as $skill ) {
				$skill_list[ $skill['value'] ] = self::clean_skill_label( $skill['label'] );
			}
			$skill_list = array_unique( $skill_list );
			set_transient( $skill_list_id, $skill_list, HOUR_IN_SECONDS * 6 );
		}

		return $skill_list;
	}
	/**
	 * @return array $skill_list
	 */
	public static function get_userType() {
		if ( null === self::$session ) {
			self::login();
		}

		$user_type_list_id = 'bullhorn_user_type';

		$user_type_list = false;// get_transient( $user_type_list_id );
		if ( false === $user_type_list ) {
			$user_type_list = array();
			$url        = add_query_arg(
				array(
					'BhRestToken' => self::$session,
				), self::$url . 'options/userType'
			);

			$response = self::request( $url, false );
			if ( is_wp_error( $response ) ) {

				return $response;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['data'] ) ) {

				return $user_type_list;
			}

			foreach ( $data['data'] as $skill ) {
				$skill_list[ $skill['value'] ] = self::clean_skill_label( $skill['label'] );
			}
			$skill_list = array_unique( $user_type_list );
			set_transient( $user_type_list_id, $skill_list, HOUR_IN_SECONDS * 6 );
		}

		return $user_type_list;
	}
	private static function clean_skill_label ( $label ) {
		$label = strtolower( trim( $label ) );

		return $label;
	}

	/**
	 * @param $candidate
	 *
	 * @param null $local_file
	 * @param null $file_name
	 *
	 * @return array|bool|mixed|object
	 */
	public static function wp_upload_file_request ( $candidate, $local_file = null, $file_name = null ) {

		list( $ext, $format ) = self::get_filetype();

		$local_file = ( null === $local_file ) ? $_FILES['resume']['tmp_name'] : $local_file;
		$file_name  = ( null === $file_name ) ? $_FILES['resume']['name'] : $file_name;
		$file_title = ( isset( $candidate->firstName ) ) ? $candidate->firstName . ' ' : '';
		$file_title .= ( isset( $candidate->lastName ) ) ? $candidate->lastName : '';
		$file_title = ( ! empty( $file_title ) ) ? ' for ' . $file_title : $file_title;
		// wp_remote_request way

		//https://github.com/jeckman/wpgplus/blob/master/gplus.php#L554
		$boundary = md5( time() . $ext );
		$payload  = '';
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="Resume' . $file_title . '"; filename="' . esc_url_raw( $file_name ) . '"' . "\r\n";
		$payload .= 'Content-Type: ' . $format . '\r\n'; // If you	know the mime-type
		$payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents( $local_file );
		$payload .= "\r\n";
		$payload .= '--' . $boundary . '--';
		$payload .= "\r\n\r\n";

		$args = array(
			'method'  => 'PUT',
			'headers' => array(
				'accept'       => 'application/json', // The API returns JSON
				'content-type' => 'multipart/form-data;boundary=' . $boundary, // Set content type to multipart/form-data
			),
			'body'    => $payload,
		);

		$url      = add_query_arg(
			array(
				'BhRestToken' => self::$session,
				'externalID'  => 'Portfolio',
				'fileType'    => 'SAMPLE',
			), self::$url . '/file/Candidate/' . esc_url_raw( $candidate->changedEntityId ) . '/raw'
		);
		$response = wp_remote_request( $url, $args );

		// try once more if we get an error
		if ( is_wp_error( $response ) || 201 !== $response['response']['code'] ) {
			$response = wp_remote_request( $url, $args );
		}

		if ( 200 === $response['response']['code'] ) {
			return json_decode( $response );
		}

		return false;
	}

	/**
	 * @param $candidate
	 *
	 * @return array|bool|mixed|object
	 */
	public static function wp_upload_html_request ( $candidate ) {

		list( $ext, $format ) = self::get_filetype();

		$local_file = $_FILES['resume']['tmp_name'];
		// wp_remote_request way

		//https://github.com/jeckman/wpgplus/blob/master/gplus.php#L554
		$boundary = md5( time() . $ext );
		$payload  = '';
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="photo_upload_file_name"; filename="' . $_FILES['resume']['name'] . '"' . "\r\n";
		$payload .= 'Content-Type: ' . $format . '\r\n'; // If you	know the mime-type
		$payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents( $local_file );
		$payload .= "\r\n";
		$payload .= '--' . $boundary . '--';
		$payload .= "\r\n\r\n";

		$args = array(
			'method'  => 'PUT',
			'headers' => array(
				'accept'       => 'application/json', // The API returns JSON
				'content-type' => 'multipart/form-data;boundary=' . $boundary, // Set content type to multipart/form-data
			),
			'body'    => $payload,
		);

		$url      = add_query_arg(
			array(
				'BhRestToken' => self::$session,
				'format'      => $ext,
			), self::$url . '}/resume/convertToHTML'
		);
		$response = wp_remote_request( $url, $args );

		// try once more if we get an error
		if ( is_wp_error( $response ) || 201 !== $response['response']['code'] ) {
			$response = wp_remote_request( $url, $args );
		}


		//https://github.com/jeckman/wpgplus/blob/master/gplus.php#L554
		$boundary = md5( time() . $ext );
		$payload  = '';
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="photo_upload_file_name"; filename="' . $_FILES['resume']['name'] . '"' . "\r\n";
		$payload .= 'Content-Type: ' . $format . '\r\n'; // If you	know the mime-type
		$payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
		$payload .= "\r\n";
		$payload .= $response;
		$payload .= "\r\n";
		$payload .= '--' . $boundary . '--';
		$payload .= "\r\n\r\n";

		$args = array(
			'method'  => 'PUT',
			'headers' => array(
				'accept'       => 'application/json', // The API returns JSON
				'content-type' => 'multipart/form-data;boundary=' . $boundary, // Set content type to multipart/form-data
			),
			'body'    => $payload,
		);

		$url      = add_query_arg(
			array(
				'BhRestToken' => self::$session,
				'externalID'  => 'Portfolio',
				'fileType'    => 'SAMPLE',
			), self::$url . '/file/Candidate/' . $candidate->changedEntityId . '/raw'
		);
		$response = wp_remote_request( $url, $args );

		// try once more if we get an error
		if ( is_wp_error( $response ) || 201 !== $response['response']['code'] ) {
			$response = wp_remote_request( $url, $args );
		}

		if ( 200 === $response['response']['code'] ) {
			return json_decode( $response['body'] );
		}

		return false;

	}

	/**
	 * Link a candidate to job.
	 *
	 * @param $candidate
	 *
	 * @return mixed
	 */
	public static function link_candidate_to_job ( $candidate ) {
		// API authentication
		self::apiAuth();

		if ( ! isset( $_POST['position'] ) ) {
			return false;
		}
		$jobOrder = absint( $_POST['position'] );

		$url = add_query_arg(
			array(
				'BhRestToken' => self::$session,
			), self::$url . 'entity/JobSubmission'
		);

		$settings       = (array) get_option( 'bullhorn_settings' );
		$mark_submitted = 'true';
		if ( isset( $settings['mark_submitted'] ) ) {
			$mark_submitted = $settings['mark_submitted'];
		}

		$body = array(
			'candidate'       => array( 'id' => absint( $candidate->changedEntityId ) ),
			'jobOrder'        => array( 'id' => absint( $jobOrder ) ),
			'status'          => ( $mark_submitted ) ? 'Submitted' : 'New Lead',
			'dateWebResponse' => self::microtime_float(), //date( 'u', $date ),// time(),
		);

		$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $body ), 'method' => 'PUT' ) );

		$safety_count = 0;
		while ( 500 === $response['response']['code'] && 5 > $safety_count ) {
			error_log( 'Link to job failed( ' . $safety_count . '): ' . serialize( $response ) );
			$response = wp_remote_get( $url, array( 'body' => wp_json_encode( $body ), 'method' => 'PUT' ) );
			$safety_count ++;
		}

		if ( 200 === $response['response']['code'] ) {
			return json_decode( $response['body'] );
		}

		return false;
	}

	/**
	 * get time in microseconds
	 * @return float
	 */
	private static function microtime_float () {
		list( $usec, $sec ) = explode( ' ', microtime() );

		return ( (float) $usec + (float) $sec ) * 100;
	}

	/**
	 *
	 *
	 * @static
	 *
	 * @param $resume
	 * @param $profile_data
	 */
	private static function add_data_to_canditate_data( $resume, $profile_data ) {
// Make sure country ID is correct
		if ( isset( $resume->candidate->address->countryID ) && is_null( $resume->candidate->address->countryID ) ) {
			$resume->candidate->address->countryID = 1;
}

		if ( isset( $profile_data['email'] ) && ! empty( $profile_data['email'] ) ) {
			if ( isset( $resume->candidate->email ) ) {
				$cv_email                  = $resume->candidate->email;
				$resume->candidate->email2 = esc_attr( $cv_email );
			}

			$resume->candidate->email = esc_attr( $profile_data['email'] );
		} elseif ( isset( $_POST['email'] ) && ! empty( $_POST['email'] ) ) {
			if ( isset( $resume->candidate->email ) ) {
				$cv_email                  = $resume->candidate->email;
				$resume->candidate->email2 = esc_attr( $cv_email );
			}

			$resume->candidate->email = sanitize_text_field( $_POST['email'] );
		}

		if ( isset( $profile_data['phone'] ) && ! empty( $profile_data['phone'] ) ) {
			if ( isset( $resume->candidate->phone ) ) {
				$cv_phone                  = $resume->candidate->phone;
				$resume->candidate->phone2 = esc_attr( $cv_phone );
			}

			$resume->candidate->phone = esc_attr( $profile_data['phone'] );

		} elseif ( isset( $_POST['phone'] ) && ! empty( $_POST['phone'] ) ) {
			if ( isset( $resume->candidate->phone ) ) {
				$cv_phone                  = $resume->candidate->phone;
				$resume->candidate->phone2 = esc_attr( $cv_phone );
			}

			$resume->candidate->phone = sanitize_text_field( $_POST['phone'] );
		}

		if ( isset( $profile_data['work_phone'] ) ) {

			$resume->candidate->workPhone = esc_attr( $profile_data['work_phone'] );
		} elseif ( isset( $_POST['workPhone'] ) && ! empty( $_POST['workPhone'] ) ) {

			$resume->candidate->workPhone = sanitize_text_field( $_POST['workPhone'] );
		}

		if ( isset( $profile_data['mobile_phone'] ) ) {

			$resume->candidate->mobile = esc_attr( $profile_data['mobile_phone'] );
		} elseif ( isset( $_POST['mobile'] ) && ! empty( $_POST['mobile'] ) ) {

			$resume->candidate->mobile = sanitize_text_field( $_POST['workPhone'] );
		}
		if ( isset( $profile_data['name'] ) ) {

			$resume->candidate->name = esc_attr( $profile_data['name'] );
		} elseif ( isset( $_POST['name'] ) && ! empty( $_POST['name'] ) ) {

			$resume->candidate->name = sanitize_text_field( $_POST['name'] );
		}

		if ( isset( $profile_data['first_name'] ) ) {

			$resume->candidate->firstName = esc_attr( $profile_data['first_name'] );
		} elseif ( isset( $_POST['firstName'] ) && ! empty( $_POST['firstName'] ) ) {

			$resume->candidate->firstName = sanitize_text_field( $_POST['firstName'] );
		}

		if ( isset( $profile_data['last_name'] ) ) {

			$resume->candidate->lastName = esc_attr( $profile_data['last_name'] );
		} elseif ( isset( $_POST['lastName'] ) && ! empty( $_POST['lastName'] ) ) {

			$resume->candidate->lastName = sanitize_text_field( $_POST['lastName'] );
		}

		$address_fields = array( 'address1', 'address2', 'city', 'state', 'zip', 'countryName' );
		if ( isset( $profile_data['address'] ) && ! empty( $profile_data['address'] ) ) {
			if ( isset( $resume->candidate->address ) ) {
				$cv_address = $resume->candidate->address;
				if ( is_array( $cv_address ) && ! empty( $cv_address ) ) {

					$resume->candidate->secondaryAddress = $cv_address;
				}
			}

			$address_data = array();

			foreach ( $address_fields as $key ) {
				$address_data[ $key ] = ( isset( $profile_data[ $key ] ) ) ? $profile_data[ $key ] : '';
			}

			$resume->candidate->address = $address_data;
		} elseif ( isset( $_POST['address1'] ) && ! empty( $_POST['address1'] ) ) {

			if ( isset( $resume->candidate->address ) ) {
				$cv_address = $resume->candidate->address;
				if ( is_array( $cv_address ) && ! empty( $cv_address ) ) {

					$resume->candidate->secondaryAddress = $cv_address;
				}
			}

			$address_data = array();

			foreach ( $address_fields as $key ) {
				$address_data[ $key ] = ( isset( $_POST[ $key ] ) ) ? sanitize_text_field( $_POST[ $key ] ) : '';
			}

			$resume->candidate->address = $address_data;
		}

		if ( isset( $profile_data['skillList'] ) && ! empty( $profile_data['skillList'] ) ) {
			if ( ! isset( $resume->skillList ) ) {
				$resume->skillList = $profile_data['skillList'];
			} else {
				$resume->skillList = array_merge( $resume->skillList, $profile_data['skillList'] );
			}
		}

		return apply_filters( 'bullhorn_add_data_to_canditate_data', $resume, $profile_data );
	}
}

new Bullhorn_Extended_Connection();
