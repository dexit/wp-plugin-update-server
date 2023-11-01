<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPPUS_Package_API {
	protected $http_response_code = 200;

	protected static $doing_update_api_request = null;

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {

			if ( ! self::is_doing_api_request() ) {
				add_action( 'init', array( $this, 'add_endpoints' ), 10, 0 );
			}

			add_action( 'parse_request', array( $this, 'parse_request' ), -99, 0 );

			add_filter( 'query_vars', array( $this, 'addquery_variables' ), -99, 1 );
		}
	}

	public static function is_doing_api_request() {

		if ( null === self::$doing_update_api_request ) {
			self::$doing_update_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], 'wppus-package-api' ) );
		}

		return self::$doing_update_api_request;
	}

	public static function get_config() {
		$config = array(
			'use_remote_repository' => get_option( 'wppus_use_remote_repository' ),
			'private_api_auth_key'  => get_option( 'wppus_package_private_api_auth_key' ),
		);

		return apply_filters( 'wppus_package_api_config', $config );
	}

	public function add_endpoints() {
		add_rewrite_rule(
			'^wppus-package-api/(plugin|theme)/(.+)/*?$',
			'index.php?type=$matches[1]&package_id=$matches[2]&$matches[3]&__wppus_package_api=1&',
			'top'
		);

		add_rewrite_rule(
			'^wppus-package-api/*?$',
			'index.php?$matches[1]&__wppus_package_api=1&',
			'top'
		);
	}

	public function parse_request() {
		global $wp;

		if ( isset( $wp->query_vars['__wppus_package_api'] ) ) {
			$this->handle_api_request();

			die();
		}
	}

	public function addquery_variables( $query_variables ) {
		$query_variables = array_merge(
			$query_variables,
			array(
				'__wppus_package_api',
				'package_id',
				'type',
				'action',
				'browse_query',
			)
		);

		return $query_variables;
	}

	public function browse( $query ) {
		$result          = false;
		$query           = empty( $query ) || ! is_string( $query ) ? array() : json_decode( wp_unslash( $query ), true );
		$query['search'] = isset( $query['search'] ) ? trim( esc_html( $query['search'] ) ) : false;
		$result          = wppus_get_batch_package_info( $query['search'], false );
		$result['count'] = is_array( $result ) ? count( $result ) : 0;

		$result = apply_filters( 'wppus_package_browse', $result, $query );

		do_action( 'wppus_did_browse_package', $result );

		if ( empty( $result ) ) {
			$result = array( 'count' => 0 );
		}

		if ( isset( $result['count'] ) && 0 === $result['count'] ) {
			$this->http_response_code = 404;
		}

		return $result;
	}

	public function read( $package_id, $type ) {
		$result = wppus_get_package_info( $package_id, false );

		if (
			! is_array( $result ) ||
			! isset( $result['type'] ) ||
			$type !== $result['type']
		) {
			$result = false;
		} else {
			unset( $result['file_path'] );
		}

		$result = apply_filters( 'wppus_package_read', $result, $package_id, $type );

		do_action( 'wppus_did_read_package', $result );

		if ( ! $result ) {
			$this->http_response_code = 404;
		}

		return $result;
	}

	public function edit( $package_id, $type ) {
		$result = false;
		$config = self::get_config();

		if ( $config['use_remote_repository'] ) {
			$result = wppus_download_remote_package( $package_id, $type );
			$result = $result ? wppus_get_package_info( $package_id, false ) : $result;
			$result = apply_filters( 'wppus_package_update', $result, $package_id, $type );

			if ( $result ) {
				do_action( 'wppus_did_update_package', $result );
			}

			if ( ! $result ) {
				$this->http_response_code = 400;
			}
		}

		return $result;
	}

	public function add( $package_id, $type ) {
		$result = false;
		$config = self::get_config();

		if ( $config['use_remote_repository'] ) {
			$result = wppus_get_package_info( $package_id, false );

			if ( ! empty( $result ) ) {
				$result = false;
			} else {
				$result = wppus_download_remote_package( $package_id, $type );
				$result = $result ? wppus_get_package_info( $package_id, false ) : $result;
			}

			$result = apply_filters( 'wppus_package_create', $result, $package_id, $type );

			if ( $result ) {
				do_action( 'wppus_did_create_package', $result );
			}
		}

		if ( ! $result ) {
			$this->http_response_code = 409;
		}

		return $result;
	}

	public function delete( $package_id, $type ) {
		wppus_delete_package( $package_id );

		$result = ! (bool) $this->read( $package_id, $type );
		$result = apply_filters( 'wppus_package_delete', $result, $package_id, $type );

		if ( $result ) {
			do_action( 'wppus_did_delete_package', $result );
		}

		if ( ! $result ) {
			$this->http_response_code = 400;
		}

		return $result;
	}

	public function download( $package_id, $type ) {
		$path = wppus_get_local_package_path( $package_id );

		if ( ! $path ) {
			$this->http_response_code = 404;

			return array(
				'message' => __( 'Package not found.', 'wppus' ),
			);
		}

		wppus_download_local_package( $package_id, $path, false );
		do_action( 'wppus_did_download_package', $package_id );

		php_log( $package_id );

		exit;
	}

	protected function is_api_public( $method ) {
		// @TODO doc
		$public_api    = apply_filters(
			'wppus_package_public_api_methods',
			array(
				'browse',
				'read',
				'download',
			)
		);
		$is_api_public = in_array( $method, $public_api, true );

		return $is_api_public;
	}

	protected function handle_api_request() {
		global $wp;

		if ( isset( $wp->query_vars['action'] ) ) {
			$method = $wp->query_vars['action'];

			if (
				filter_input( INPUT_GET, 'action' ) &&
				! $this->is_api_public( $method )
			) {
				$this->http_response_code = 405;
				$response                 = array(
					'message' => __( 'Unauthorized GET method.', 'wppus' ),
				);
			} else {

				if (
					'browse' === $wp->query_vars['action'] &&
					isset( $wp->query_vars['browse_query'] )
				) {
					$payload = $wp->query_vars['browse_query'];
				} else {
					$payload = $wp->query_vars;
				}

				if ( method_exists( $this, $method ) ) {
					$authorized = apply_filters(
						'wppus_package_api_request_authorized',
						(
							$this->is_api_public( $method ) && $this->authorize_public() ||
							$this->authorize_private()
						),
						$method,
						$payload
					);

					if ( $authorized ) {
						$type       = isset( $payload['type'] ) ? $payload['type'] : null;
						$package_id = isset( $payload['package_id'] ) ? $payload['package_id'] : null;

						if ( $type && $package_id ) {
							$response = $this->$method( $package_id, $type );
						} else {
							$response = $this->$method( $payload );
						}
					} else {
						$this->http_response_code = 403;
						$response                 = array(
							'message' => __( 'Unauthorized access - check the provided API key', 'wppus' ),
						);
					}
				} else {
					$this->http_response_code = 400;
					$response                 = array(
						'message' => __( 'Package API action not found.', 'wppus' ),
					);
				}
			}

			wp_send_json( $response, $this->http_response_code );

			exit();
		}
	}

	protected function authorize_public() {
		$nonce = filter_input( INPUT_GET, 'token', FILTER_UNSAFE_RAW );

		if ( ! $nonce ) {
			$nonce = filter_input( INPUT_GET, 'nonce', FILTER_UNSAFE_RAW );
		}

		return wppus_validate_nonce( $nonce );
	}

	protected function authorize_private() {
		$key = false;

		if (
			isset( $_SERVER['HTTP_X_WPPUS_PRIVATE_PACKAGE_API_KEY'] ) &&
			! empty( $_SERVER['HTTP_X_WPPUS_PRIVATE_PACKAGE_API_KEY'] )
		) {
			$key = $_SERVER['HTTP_X_WPPUS_PRIVATE_PACKAGE_API_KEY'];
		} else {
			global $wp;

			if (
				isset( $wp->query_vars['api_auth_key'] ) &&
				is_string( $wp->query_vars['api_auth_key'] ) &&
				! empty( $wp->query_vars['api_auth_key'] )
			) {
				$key = $wp->query_vars['api_auth_key'];
			}
		}

		$config  = self::get_config();
		$is_auth = $config['private_api_auth_key'] === $key;

		return $is_auth;
	}
}