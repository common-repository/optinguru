<?php defined( 'ABSPATH' ) OR die( 'This script cannot be accessed directly.' );

if ( ! is_admin() OR ! current_user_can( 'install_plugins' ) ) {
	return;
}
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

global $ogr_file, $ogrm_file, $ogrm_source, $ogrm_installed;
$ogrm_file = 'convertful/convertful.php';
$ogrm_source = 'https://downloads.wordpress.org/plugin/convertful.1.1.zip';

// The plugin is already installed and activated, doing nothing
if ( is_plugin_active( $ogrm_file ) ) {
	deactivate_plugins( $ogr_file );
	return;
}

$ogrm_installed = array_key_exists( $ogrm_file, get_plugins() );
$ogrm_dismiss_notice = ( get_option( 'ogrm_dismiss_notice' ) !== FALSE AND get_option( 'ogrm_dismiss_notice' ) > time() );

if ( ! $ogrm_dismiss_notice ) {
	add_action( 'admin_notices', 'ogrm_admin_notice' );
}
function ogrm_admin_notice() {
	global $ogrm_installed;
	?>
	<style>
		@-webkit-keyframes ogrm-spin {
			0% { -webkit-transform: rotate(0deg); transform: rotate(0deg); }
			100% { -webkit-transform: rotate(359deg); transform: rotate(359deg); }
		}
		@keyframes ogrm-spin {
			0% { -webkit-transform: rotate(0deg); transform: rotate(0deg); }
			100% { -webkit-transform: rotate(359deg); transform: rotate(359deg); }
		}
		.ogr-migrate-notice .button.loading {
			position: relative;
			padding-left: 28px;
		}
		.ogr-migrate-notice .button.loading:before {
			content: "\f463";
			display: block;
			position: absolute;
			font-size: 20px;
			left: 5px;
			font-family: dashicons;
			-webkit-animation: ogrm-spin 2s infinite linear;
			animation: ogrm-spin 2s infinite linear;
		}
	</style>
	<div class="notice notice-warning ogr-migrate-notice is-dismissible">
		<p>To properly maintain the subscription forms,
			<strong>MailChimp Forms by Optin.Guru</strong> should be replaced with
			<strong>Convertful MailChimp Forms</strong> plugin.
			<a href="javascript:void(0)" class="install-now button"
			   data-action="<?php echo esc_attr( $ogrm_installed ? 'activate' : 'install' ) ?>">Replace Now</a>
		</p>
	</div>
	<?php
}

add_action( 'admin_print_scripts', 'ogrm_admin_print_scripts', 99 );
function ogrm_admin_print_scripts() {
	?>
	<script>
		jQuery(function($){
			var $document = $(document);
			$document.on('click', '.ogr-migrate-notice .notice-dismiss', function(){
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					data: {
						action: 'ogrm_dismiss_notice'
					}
				});
			});
			$document.on('click', '.ogr-migrate-notice .button.install-now', function(e){
				var $button = $(e.target);
				if ($button.hasClass('loading')) return;
				var doAction = function(action){
					$button.addClass('loading').addClass('disabled')
						.html((action === 'install') ? 'Installing ...' : 'Activating ...');
					$.ajax({
						type: 'post',
						url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
						data: {
							action: 'ogrm_' + action
						},
						success: function(r){
							if (!r || !r.success) {
								$button.closest('.notice')
									.html('<p>An error has occured. Please reload the page and try again.</p>');
								return;
							}
							if (action === 'install') {
								doAction('activate');
							} else if (action === 'activate') {
								$button.closest('.notice')
									.html('<p>The plugin was successfully replaced. Thank you!</p>');
								if (r.data && r.data.location) location.assign(r.data.location);
							}
						}
					});
				};
				doAction($button.data('action'));
			});
		});
	</script>
	<?php
}

add_action( 'wp_ajax_ogrm_dismiss_notice', 'ogrm_dismiss_notice' );
function ogrm_dismiss_notice() {
	// Dismissing for two weeks
	$dismiss_duration = 60 * 60 * 24 * 14;
	update_option( 'ogrm_dismiss_notice', time() + $dismiss_duration, FALSE );
}

add_action( 'wp_ajax_ogrm_install', 'ogrm_install' );
function ogrm_install() {
	global $ogrm_source, $ogrm_installed;
	set_time_limit( 300 );
	try {
		if ( $ogrm_installed ) {
			throw new Exception( 'Plugin is already installed' );
		}
		if ( ! ogrm_check_filesystem_permission() ) {
			throw new Exception( 'Please adjust file permissions to allow plugins installation' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin );
		$install_result = $upgrader->install( $ogrm_source );
		if ( is_wp_error( $install_result ) ) {
			throw new Exception( $install_result->get_error_message() );
		} elseif ( ! $install_result ) {
			throw new Exception( 'An error has occurred. Please reload the page and try again.' );
		}
		wp_send_json_success();
	}
	catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_ogrm_activate', 'ogrm_activate' );
function ogrm_activate() {
	global $ogr_file, $ogrm_installed, $ogrm_file;

	// Deactivating the old plugin
	deactivate_plugins( $ogr_file, TRUE );

	try {
		if ( ! $ogrm_installed ) {
			throw new Exception( 'Plugin is not installed yet' );
		}

		ob_start();
		$result = activate_plugin( $ogrm_file ); // Activate the plugin.
		ob_get_clean();

		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}

		// Removing the old plugin
		if ( ogrm_check_filesystem_permission() ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin );
			$upgrader->delete_old_plugin( TRUE, NULL, NULL, array( 'plugin' => $ogr_file ) );
		}

		wp_send_json_success();
	}
	catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}
}

function ogrm_check_filesystem_permission() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	ob_start();
	$creds = request_filesystem_credentials( '', '', FALSE, FALSE, NULL );
	ob_get_clean();

	// Abort if permissions were not available.
	if ( ! WP_Filesystem( $creds ) ) {
		return FALSE;
	}

	return TRUE;
}