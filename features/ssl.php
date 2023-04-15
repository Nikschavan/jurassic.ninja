<?php
/**
 * Let's SSL it!
 *
 * @package jurassic-ninja
 */

namespace jn;

add_action(
	'jurassic_ninja_init',
	function () {
		$defaults = array(
			'auto_ssl' => true,
			'ssl' => false,
		);

		add_action(
			'jurassic_ninja_add_features_after_create_app',
			function ( &$app, $features ) use ( $defaults ) {
				$features = array_merge( $defaults, $features );

				// Schedule a single cron event to trigger after 5 minutes.
				// This is to give the site time to be provisioned and ready to be accessed.
				wp_schedule_single_event(
					time() + 1 * MINUTE_IN_SECONDS,
					'jurassic_ninja_enable_ssl',
					array(
						$app->id,
						$features,
					)
				);
			},
			10,
			3
		);

		add_action(
			'jurassic_ninja_enable_ssl',
			function ( $app_id, $features ) use ( $defaults ) {
				$features = array_merge( $defaults, $features );

				if ( $features['auto_ssl'] ) {
					// Currently not a feature of Jurassic Ninja but the code works.
					$response = provisioner()->enable_auto_ssl( $app_id );

					if ( is_wp_error( $response ) ) {
						throw new \Exception( 'Error enabling auto SSL: ' . $response->get_error_message() );
					}

					// Force ssl on the site.
					$response = provisioner()->force_ssl_redirection( $app_id );

					if ( is_wp_error( $response ) ) {
						throw new \Exception( 'Error forcing SSL redirection: ' . $response->get_error_message() );
					}
				}
			},
			10,
			2
		);

		add_action(
			'jurassic_ninja_add_features_before_auto_login',
			function ( &$app = null, $features, $domain ) use ( $defaults ) {
				// We can't easily enable SSL for subodmains because
				// wildcard certificates don't support multiple levels of subdomains
				// and this can result in awful experience.
				// Need to explore a little bit better.

				if ( $features['ssl'] && ! ( isset( $features['subdomain_multisite'] ) && $features['subdomain_multisite'] ) ) {
					$features = array_merge( $defaults, $features );
					if ( $features['auto_ssl'] ) {
						debug( 'Both ssl and auto_ssl features were requested. Ignoring ssl and launching with custom SSL' );
					}
					debug( '%s: Enabling custom SSL', $domain );

					$response = provisioner()->force_ssl_redirection( $app->id );

					if ( is_wp_error( $response ) ) {
						throw new \Exception( 'Error enabling SSL: ' . $response->get_error_message() );
					}

					debug( '%s: Setting home and siteurl options to account for SSL', $domain );
					set_home_and_site_url( $domain );
				}
			},
			10,
			3
		);

		add_filter(
			'jurassic_ninja_rest_feature_defaults',
			function ( $rest_default_features ) use ( $defaults ) {
				return array_merge(
					$defaults,
					$rest_default_features,
					array(
						'ssl' => (bool) settings( 'ssl_use_custom_certificate', false ),
					)
				);
			}
		);

		add_filter(
			'jurassic_ninja_rest_create_request_features',
			function ( $features, $json_params ) {
				return array_merge(
					$features,
					array(
						'ssl' => $features['ssl'] && ( isset( $json_params['ssl'] ) ? $json_params['ssl'] : true ),
					)
				);
			},
			10,
			2
		);

		add_filter(
			'jurassic_ninja_created_site_url',
			function ( $domain, $features ) {
				// See note in launch_wordpress() about why we can't launch subdomain_multisite with ssl.
				$schema = ( $features['ssl'] && ! $features['subdomain_multisite'] ) ? 'https' : 'http';
				$url = "$schema://" . $domain;
				return $url;
			},
			10,
			2
		);
	}
);

add_action(
	'jurassic_ninja_admin_init',
	function () {
		add_filter(
			'jurassic_ninja_settings_options_page',
			function ( $options_page ) {
				$settings = array(
					'title' => __( 'SSL Configuration', 'jurassic-ninja' ),
					'text' => '<p>' . __( 'Paste a wildcard SSL certificate and the private key used to generate it.', 'jurassic-ninja' ) . '</p>',
					'fields' => array(
						'ssl_use_custom_certificate' => array(
							'id' => 'ssl_use_custom_certificate',
							'title' => __( 'Use custom SSL certificate', 'jurassic-ninja' ),
							'type' => 'checkbox',
							'checked' => false,
						),
						'ssl_certificate' => array(
							'id' => 'ssl_certificate',
							'title' => __( 'SSL certificate', 'jurassic-ninja' ),
							'text' => __( 'Paste the text here.', 'jurassic-ninja' ),
							'type' => 'textarea',
						),
						'ssl_private_key' => array(
							'id' => 'ssl_private_key',
							'title' => __( 'The private key used to create the certificate', 'jurassic-ninja' ),
							'text' => __( 'Paste the text here.', 'jurassic-ninja' ),
							'type' => 'textarea',
						),
						'ssl_ca_certificates' => array(
							'id' => 'ssl_ca_certificates',
							'title' => __( 'CA certificates', 'jurassic-ninja' ),
							'text' => __( 'Paste the text here.', 'jurassic-ninja' ),
							'type' => 'textarea',
						),
					),
				);
				$options_page[ SETTINGS_KEY ]['sections']['ssl'] = $settings;
				return $options_page;
			}
		);
	}
);

/**
 * WP CLI commands to set URLs.
 *
 * @param string $domain Site domain.
 */
function set_home_and_site_url( $domain ) {
	$cmd = "wp option set siteurl https://$domain"
		. " && wp option set home https://$domain";

	add_filter(
		'jurassic_ninja_feature_command',
		function ( $s ) use ( $cmd ) {
			return "$s && $cmd";
		}
	);
}
