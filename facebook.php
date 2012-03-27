<?php
/*
Plugin Name: Facebook integration
Description: Facebook integration that doesn't make John want to gouge his eyeballs out
Author: John Du Hart
*/

class WpFacebook {

	private static $footerInits = array();

	/**
	 * Registers filters
	 */
	public static function init() {
		add_filter( 'language_attributes', array( __CLASS__, 'langAtrributes' ) );
		add_filter( 'init', array( __CLASS__, 'channelFile' ) );
		add_filter( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_filter( 'admin_print_footer_scripts', array( __CLASS__, 'footer' ), 20 );
		add_filter( 'wp_head', array( __CLASS__, 'pageMeta' ), 20 );
		add_filter( 'personal_options', array( __CLASS__, 'profilePageConnect' ) );
		add_filter( 'wp_ajax_facebook_login', array( __CLASS__, 'profilePageLoginAjax' ) );
		add_filter( 'wp_ajax_facebook_disconnect', array( __CLASS__, 'profilePageDisconnectAjax' ) );
	}

	/**
	 * Adds the facebook tag namespace to <html>
	 *
	 * @param $lang
	 * @return string
	 */
	public static function langAtrributes( $lang ) {
		return ' xmlns:fb="http://ogp.me/ns/fb#" xmlns:og="http://ogp.me/ns#" '.$lang;
	}

	/**
	 * Outputs the weird channel file for cross-domain support
	 */
	public static function channelFile() {
		if ( isset( $_GET['fb-channel-file'] ) ) {
			$cache_expire = 60*60*24*365;
			header( "Pragma: public" );
			header( "Cache-Control: max-age=" . $cache_expire);
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $cache_expire ) . ' GMT');
			echo '<script src="//connect.facebook.net/en_US/all.js"></script>';
			exit;
		}
	}

	/**
	 *  Adds the javascript SDK to the footer
	 *
	 * @param array $args
	 */
	public static function footer( $args = array() ) {
		$defaults = array(
			'appId' => FB_APP_ID,
			'channelUrl' => home_url( '?fb-channel-file' ),
			'status' => true,
			'cookie' => true,
			'xfbml' => true,
			'oauth' => true,
		);

		$args = wp_parse_args( $args, $defaults );
		?>
	<div id="fb-root"></div>
	<script type="text/javascript">
		window.fbAsyncInit = function() {
			FB.init( <?php echo json_encode( $args ) ?> );
			<?php self::footerInit() ?>
		};

		(function(d){
			var js, id = 'facebook-jssdk', ref = d.getElementsByTagName('script')[0];
			if (d.getElementById(id)) {return;}
			js = d.createElement('script'); js.id = id; js.async = true;
			js.src = "//connect.facebook.net/en_US/all.js";
			ref.parentNode.insertBefore(js, ref);
		}(document));
	</script>
		<?php
	}

	/**
	 * Calls all the queued footer inits
	 */
	private static function footerInit() {
		foreach ( self::$footerInits as $func ) {
			call_user_func( array( __CLASS__, $func ) );
		}
	}

	/**
	 * Adds a function to the footer queue
	 *
	 * @param $function
	 */
	private static function footerInitRegister( $function ) {
		self::$footerInits[] = $function;
	}

	/**
	 * Base64 encoding that doesn't need to be urlencode()ed.
	 * Exactly the same as base64_encode except it uses
	 *   - instead of +
	 *   _ instead of /
	 *
	 * @param string $input base64UrlEncoded string
	 * @return string
	 */
	private static function base64UrlDecode( $input ) {
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	/**
	 * Parses a signed_request and validates the signature.
	 *
	 * @param string $signed_request A signed token
	 * @return array The payload inside it or null if the sig is wrong
	 */
	protected static function parseSignedRequest( $signed_request ) {
		list( $encoded_sig, $payload ) = explode( '.', $signed_request, 2 );

		// decode the data
		$sig = self::base64UrlDecode( $encoded_sig );
		$data = json_decode( self::base64UrlDecode( $payload ), true );

		if ( strtoupper( $data['algorithm'] ) !== 'HMAC-SHA256' ) {
			return null;
		}

		// check sig
		$expected_sig = hash_hmac( 'sha256', $payload,
			FB_APP_SECRET, $raw = true );
		if ( $sig !== $expected_sig ) {
			return null;
		}

		return $data;
	}

	protected static function firstImage() {
		global $post;

		$output = preg_match_all( '/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post->post_content, $matches );

		if ( $output === 0 || $output === false ) {
			return false;
		}

		$image = $matches[1][0];

		// if no base url in image path lets make one
		if ( !preg_match( '/^https?:\/\//', $image ) ) {
			if ( $image[0] != '/' ) {
				$image = '/' . $image;
			}
			$image = home_url() . $image;
		}

		return $image;
	}

	/*
	 * Page header and <meta> magic
	 */

	public static function pageMeta() {

		$meta = array();

		if ( is_singular() ) {
			// Single post (article)
			global $wp_query;
			$post = $wp_query->get_queried_object();

			if ( has_excerpt( $post->ID ) ) {
				$description = esc_attr( strip_tags( get_the_excerpt( $post->ID ) ) );
			} else {
				$description = esc_attr( str_replace( "\r\n", ' ', substr(
					strip_tags( strip_shortcodes( $post->post_content ) ), 0, 160 ) ) );
			}

			if ( has_post_thumbnail( $post->ID ) ) {
				list( $image ) = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'thumbnail' );
			} elseif ( self::firstImage() !== false ) {
				$image = self::firstImage();
			}

			$link = get_permalink( $post->ID );
			$title = get_the_title( $post->ID );
			$type = 'article';
			$meta['og:site_name'] = get_bloginfo( 'name' );
		} else {
			if ( is_home() || is_front_page() ) {
				$link = get_bloginfo( 'url' );
			} else {
				// TODO: HTTP hardcode
				$link = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			}

			$title = get_bloginfo( 'name' );
			$description = get_bloginfo( 'description' );
			$type = 'website';
		}

		if ( !isset( $image ) ) {
			// TODO: Default image
			$image = '';
		}

		$meta = array_merge( array(
			'fb:app_id' => FB_APP_ID,
			'og:locale' => 'en_US',
			'og:title' => $title,
			'og:type' => $type,
			'og:url' => $link,
			'og:description' => $description,
			'og:image' => $image,
		), $meta );

		foreach ( $meta as $prop => $content ) {
			?><meta property="<?php echo $prop ?>" content="<?php echo $content ?>">
<?php
		}
	}

	/*
	 * Login stuff
	 */

	/**
	 * Add the connect link to the profile page
	 *
	 * @todo Add a link on other profile pages
	 * @param $user WP_User
	 */
	public static function profilePageConnect( $user ) {
		$data = get_user_meta( $user->ID, 'facebook', true );

		// Check to see if we already have tokens
		if ( $data instanceof WpFacebookData ) {
			self::footerInitRegister( 'profilePageFooter' );
		} else {
			self::footerInitRegister( 'profilePageLoginFooter' );
		}


		?>
		<tr>
			<th><label>Facebook Connect</label></th>

			<td><p>
				<?php if ( $data instanceof WpFacebookData ):
					$id = $data->userId;
				?>
					<img id="fb-profile-pic" src="https://graph.facebook.com/<?php echo $id ?>/picture?type=square" />
					Connected as
					<a id="fb-profile-link" href="#" fb-id="<?php echo $id ?>"></a>
					( <a href="#" id="fb-disconnect-link">Disconnect</a> )
				<?php else: ?>
				<fb:login-button scope="email,publish_stream" show-faces="false">Connect this WordPress account to Facebook</fb:login-button>
				<?php endif; ?>
			</p></td>
		</tr>
		<?php
	}

	/**
	 * AJAX for connecting to a facebook account
	 */
	public static function profilePageLoginAjax() {
		// Make sure the token is there
		if ( !isset( $_POST['token'] ) ) {
			exit;
		}

		// Get the extended token
		$response = wp_remote_get( 'https://graph.facebook.com/oauth/access_token?client_id=' . FB_APP_ID
			. '&client_secret=' . FB_APP_SECRET . '&grant_type=fb_exchange_token'
			. '&fb_exchange_token=' . $_POST['token'] );
		parse_str( $response['body'], $params );

		$cookie = self::parseSignedRequest( $_COOKIE['fbsr_' . FB_APP_ID] );

		$data = new WpFacebookData;
		$data->userId = $cookie['user_id'];
		$data->token = $params['access_token'];
		$data->expires = time() + $params['expires'];
		$data->update();

		// Save the meta and exit
		update_user_meta( wp_get_current_user()->ID, 'facebook', $data );
		exit;
	}

	/**
	 * AJAX for disconnecting the facebook account
	 */
	public static function profilePageDisconnectAjax() {
		$data = get_user_meta( wp_get_current_user()->ID, 'facebook', true );

		// Make sure it's a data instance
		if ( !( $data instanceof WpFacebookData ) ) {
			exit;
		}

		// Make a request to delete the authorization
		$response = wp_remote_request( 'https://graph.facebook.com/'
			. $data->userId . '/permissions?access_token=' . $data->token,
			array( 'method' => 'DELETE' ) );
		$response = json_decode( $response['body'] );
		// TODO: Care about the output

		delete_user_meta( wp_get_current_user()->ID, 'facebook' );
		exit;
	}

	/**
	 * JavaScript to handle logins
	 */
	private static function profilePageLoginFooter() {
		echo <<<JAVASCRIPT
function fbLogin( response ) {
	if ( response.status !== 'connected' ) {
		return;
	}

	jQuery.post( ajaxurl, {
		action: 'facebook_login',
		token: response.authResponse.accessToken
	}, function () {
		location.reload();
	} );
}
// Login and reload the page
FB.Event.subscribe( 'auth.statusChange', fbLogin );
JAVASCRIPT;
	}

	private static function profilePageFooter() {
		echo <<<JAVASCRIPT
var id = jQuery( '#fb-profile-link' ).attr( 'fb-id' );
FB.api( '/' + id, function ( response ) {
	jQuery( '#fb-profile-link' )
		.attr( 'href', 'https://www.facebook.com/' + id )
		.text( response.name );
} );

jQuery( '#fb-disconnect-link' ).click( function () {
	jQuery.post( ajaxurl, {
		action: 'facebook_disconnect'
	}, function () {
		location.reload();
	} );

	return false;
} );
JAVASCRIPT;

	}
}

/**
 * Serialized class
 */
class WpFacebookData {

	/**
	 * Facebook user id
	 *
	 * @var int
	 */
	public $userId;

	/**
	 * Access token
	 *
	 * @var string
	 */
	public $token;

	/**
	 * Expiration timestamp
	 *
	 * @var int
	 */
	public $expires;

	/**
	 * Day number of last update
	 *
	 * @var string
	 */
	public $lastUpdate;

	/**
	 * Helper function to update the date
	 */
	public function update() {
		$this->lastUpdate = date( 'd' );
	}
}

WpFacebook::init();