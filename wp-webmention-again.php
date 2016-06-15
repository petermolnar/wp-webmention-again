<?php
/*
Plugin Name: wp-webmention-again
Plugin URI: https://github.com/petermolnar/wp-webmention-again
Description:
Version: 0.5.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
*/

if ( ! class_exists( 'WP_Webmention_Again' ) ):

// something else might have loaded this already
if ( ! class_exists( 'Mf2\Parser' ) ) {
	require ( __DIR__ . '/vendor/autoload.php' );
}

// if something has loaded Mf2 already, the autoload won't kick in,
// and we need this
if ( ! class_exists( 'EmojiRecognizer' ) ) {
	require ( __DIR__ . '/vendor/dissolve/single-emoji-recognizer/src/emoji.php' );
}

class WP_Webmention_Again {

	// WP cache expiration seconds
	const expire = 10;
	// queue & history table name
	const tablename = 'webmentions';

	/**
	 * regular cron interval for processing incoming
	 *
	 * use 'wp-webmention-again_interval_received' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function remote_timeout () {
		return apply_filters( 'wp-webmention-again_remote_timeout', 100 );
	}


	/**
	 * mapping for comment types -> mf2 names
	 *
	 * use 'wp-webmention-again_mftypes' to filter this array
	 *
	 * @return array array of comment_type => mf2_name entries
	 *
	 */
	protected static function mftypes () {
		$map = array (
			 // http://indiewebcamp.com/reply
			'reply' => 'in-reply-to',
			// http://indiewebcamp.com/repost
			'repost' => 'repost-of',
			// http://indiewebcamp.com/like
			'like' => 'like-of',
			// http://indiewebcamp.com/favorite
			'favorite' => 'favorite-of',
			// http://indiewebcamp.com/bookmark
			'bookmark' => 'bookmark-of',
			//  http://indiewebcamp.com/rsvp
			'rsvp' => 'rsvp',
			// http://indiewebcamp.com/tag
			'tag' => 'tag-of',
		);

		return apply_filters( 'wp-webmention-again_mftypes', $map );
	}

	/**
	 * runs on plugin load
	 */
	public function __construct() {

		add_action( 'init', array( &$this, 'init' ) );

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( __CLASS__ , 'plugin_activate' ) );

		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( __CLASS__ , 'plugin_deactivate' ) );

		// extend current cron schedules with our entry
		add_filter( 'cron_schedules', array(&$this, 'add_cron_schedule' ) );

		static::lookfordeleted();

	}

	public static function lookfordeleted() {
		$url = substr( rtrim( ltrim( $_SERVER['REQUEST_URI'], '/' ), '/' ), 0, 191 ) . '__trashed';
		$query = new WP_Query( array (
			'name' => $url,
			'post_status' => 'trash',
			'post_type' => 'any'
		));

		if ( ! empty( $query->posts ) && $query->is_singular ) {
			status_header(410);
			nocache_headers();
			die('This post was removed.');
		}

	}

	/**
	 * plugin activation hook
	 *
	 * dies if PHP version is too low
	 *
	 */
	public static function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		//Use the character set and collation that's configured for WP tables
		$charset_collate = '';

		if ( !empty($wpdb->charset) ){
			$charset = str_replace('-', '', $wpdb->charset);
			$charset_collate = "DEFAULT CHARACTER SET {$charset}";
		}

		if ( !empty($wpdb->collate) ){
			$charset_collate .= " COLLATE {$wpdb->collate}";
		}

		$db_command = "CREATE TABLE IF NOT EXISTS `{$dbname}` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`date` datetime NOT NULL,
			`direction` varchar(12) NOT NULL DEFAULT 'in',
			`tries` int(4) NOT NULL DEFAULT '0',
			`source` text NOT NULL,
			`target` text NOT NULL,
			`object_type` varchar(255) NOT NULL DEFAULT 'post',
			`object_id` bigint(20) NOT NULL,
			`status` tinyint(4) NOT NULL DEFAULT '0',
			`note` text NOT NULL,
			PRIMARY KEY (`id`),
			KEY `time` (`date`),
			KEY `key` (`direction`)
		) {$charset_collate};";

		static::debug("Initiating DB {$dbname}", 4);
		try {
			$wpdb->query( $db_command );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

	}

	/**
	 * plugin deactivation hook
	 *
	 * makes sure there are no scheduled cron hooks left
	 *
	 */
	public static function plugin_deactivate () {

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$db_command = "DROP TABLE IF EXISTS `{$dbname}`;";

		static::debug("Deleting DB {$dbname}", 4);
		try {
			$wpdb->query( $db_command );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}
	}


	/**
	 * add our own schedule
	 *
	 * @param array $schedules - current schedules list
	 *
	 * @return array $schedules - extended schedules list
	 */
	public function add_cron_schedule ( $schedules ) {

		$schedules[ static::cron ] = array (
			'interval' => static::interval(),
			'display' => sprintf(__( 'every %d seconds' ), static::interval() )
		);

		return $schedules;
	}

	/**
	 * get plugin options
	 *
	 * use 'wp-webmention-again_comment_types' to filter registered comment types
	 * before being applied
	 *
	 * @return array plugin options
	 *
	 */
	public static function get_options () {
		$options = get_option( __CLASS__ );

		if ( isset( $options['comment_types'] ) && is_array( $options['comment_types'] ) )
			$options['comment_types'] = array_merge($options['comment_types'], static::mftypes());
		else
			$options['comment_types'] = static::mftypes();

		$options['comment_types'] = apply_filters( 'wp-webmention-again_comment_types', $options['comment_types'] );

		return $options;
	}

	/**
	 * insert a webmention to the queue
	 *
	 * @param string $direction - 'in' or 'out'
	 * @param string $source - source URL
	 * @param string $target - target URL
	 * @param string $object - object type: post, comment, etc.
	 * @param int $object_id - ID of object
	 *
	 * @return false|string - false on failure, inserted ID on success
	 *
	 */
	public static function queue_add ( $direction, $source, $target, $object = '', $object_id = 0, $epoch = false ) {
		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$direction = strtolower($direction);
		$valid_directions = array ( 'in', 'out' );
		if ( ! in_array ( $direction, $valid_directions ) )
			return false;

		//$id = sha1($source . $target . );
		//$id = uniqid();

		if ( static::queue_exists ( $direction, $source, $target, $epoch ) )
			return true;

		$q = $wpdb->prepare( "INSERT INTO `{$dbname}`
			(`date`,`direction`, `tries`,`source`, `target`, `object_type`, `object_id`, `status`, `note` ) VALUES
			( NOW(), '%s', 0, '%s', '%s', '%s', %d, 0, '' );",
			$direction, $source, $target, $object, $object_id );

		try {
			$req = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		return $wpdb->insert_id;
	}

	/**
	 * increment tries counter for a queue element
	 *
	 * @param string $id - ID of queue element
	 *
	 * @return bool - query success/failure
	 *
	 */
	public static function queue_inc ( $id ) {

		if ( empty( $id ) )
			return false;

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$q = $wpdb->prepare( "UPDATE `{$dbname}` SET `tries` = `tries` + 1 WHERE `id` = '%s'; ", $id );

		try {
			$req = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		return $req;
	}

	/**
	 * delete an entry from the webmentions queue
	 *
	 * @param string $id - ID of webmention queue element
	 *
	 * @return bool - query success/failure
	 *
	 */
	public static function queue_del ( $id ) {

		if ( empty( $id ) )
			return false;

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$q = $wpdb->prepare( "DELETE FROM `{$dbname}` WHERE `id` = '%s' LIMIT 1;", $id );

		try {
			$req = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		return $req;
	}

	/**
	 * delete an entry from the webmentions queue
	 *
	 * @param string $id - ID of webmention queue element
	 *
	 * @return bool - query success/failure
	 *
	 */
	public static function queue_done ( $id, $note = '' ) {

		if ( empty( $id ) )
			return false;

		if ( ! empty ( $note ) && ! is_string ( $note ) )
			$note = json_encode ( $note );

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		if ( empty( $note ) )
			$q = $wpdb->prepare( "UPDATE `{$dbname}` SET `status` = 1 WHERE `id` = '%s'; ", $id );
		else
			$q = $wpdb->prepare( "UPDATE `{$dbname}` SET `status` = 1, `note`='%s' WHERE `id` = '%s'; ", $note, $id );

		try {
			$req = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		//do_action ( 'wp_webmention_again_done', $id );

		return $req;
	}

	/**
	 * get a batch of elements according to direction
	 *
	 * @param string $direction - 'in' or 'out'
	 * @param int $limit - max number of items to get
	 *
	 * @return array of queue objects
	 *
	 */
	public static function queue_get ( $direction, $limit = 1 ) {

		$direction = strtolower($direction);
		$valid_directions = array ( 'in', 'out' );
		if ( ! in_array ( $direction, $valid_directions ) )
			return false;

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$q = $wpdb->prepare( "SELECT * FROM `{$dbname}` WHERE `direction` = '%s' and `status` = 0 and `tries` < ". static::retry() ." LIMIT %d;", $direction, $limit );

		try {
			$req = $wpdb->get_results( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		if ( ! empty ( $req ) )
			return $req;

		return false;
	}

	/**
	 * checks existence of a queue element
	 *
	 * @param string $direction - 'in' or 'out'
	 * @param string $source - source URL
	 * @param string $target - target URL
	 * @param int $epoch - epoch to compare date with; used for updates
	 *
	 * @return bool true on existing element, false on not found
	 */
	public static function queue_exists ( $direction, $source, $target, $epoch = false ) {

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$direction = strtolower($direction);
		$valid_directions = array ( 'in', 'out' );
		if ( ! in_array ( $direction, $valid_directions ) )
			return false;

		$q = $wpdb->prepare( "SELECT date, status FROM `{$dbname}` WHERE `direction` = '%s' and `source` = '%s' and `target`='%s' ORDER BY date DESC LIMIT 1;", $direction, $source, $target );

		try {
			$req = $wpdb->get_row( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		if ( ! empty ( $req ) ) {

			if ( false != $epoch && 0 != $req->status ) {
				$dbtime = strtotime( $req->date );

				if ( $epoch > $dbtime ) { // the post was recently updated
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * get a single webmention based on id
	 *
	 * @param int $id webmention id
	 *
	 * @return array of webmention
	 *
	 *
	public static function webmention_get ( $id ) {

		global $wpdb;
		$dbname = $wpdb->prefix . static::tablename;

		$q = $wpdb->prepare( "SELECT * FROM `{$dbname}` WHERE `id` = '%d'", $id );

		try {
			$req = $wpdb->get_results( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage(), 4);
		}

		if ( ! empty ( $req ) )
			return $req;

		return false;
	}
	*/

	/**
	 * extended wp_remote_get with debugging
	 *
	 * @param string $source URL to pull
	 *
	 * @return array wp_remote_get array
	 */
	protected static function _wp_remote_get ( &$source ) {
		$content = false;
		static::debug( "    fetching {$source}", 6);
		$url = htmlspecialchars_decode( $source );
		$q = wp_remote_get( $source );

		if ( is_wp_error( $q ) ) {
			static::debug( "    something went wrong: " . $q->get_error_message() );
			return false;
		}

		if ( !is_array( $q ) ) {
			static::debug( "    $q is not an array. It should be one.", 6);
			return false;
		}

		if ( ! isset( $q['headers'] ) || ! is_array( $q['headers'] ) ) {
			static::debug( "    missing response headers.", 6);
			return false;
		}

		if ( ! isset( $q['body'] ) || empty( $q['body'] ) ) {
			static::debug( "    missing body", 6);
			return false;
		}

		static::debug('Headers: ' . json_encode($q['headers']), 7);

		return $q;
	}

	/**
	 * validated a URL that is supposed to be on our site
	 *
	 * @param string $url URL to check against
	 *
	 * @return bool|int false on not found, int post_id on found
	 */
	protected static function validate_local ( $url ) {
		// normalize url scheme, url_to_postid will take care of it anyway
		$url = preg_replace( '/^https?:\/\//i', 'http://', strtolower($url) );
		$postid = url_to_postid( $url );
		static::debug( "Found postid for {$url}: {$postid}", 6);
		return apply_filters ('wp_webmention_again_validate_local', $postid , $url );
	}

	/**
	 * recursive walker for an mf2 array: if it find an element which has a value
	 * of a single element array, flatten the array to the element
	 *
	 * @param array $mf2 Mf2 array to flatten
	 *
	 * @return array flattened Mf2 array
	 *
	 */
	protected static function flatten_mf2_array ( $mf2 ) {

		if (is_array($mf2) && count($mf2) == 1) {
			$mf2 = array_pop($mf2);
		}

		if (is_array($mf2)) {
			foreach($mf2 as $key => $value) {
				$mf2[$key] = static::flatten_mf2_array($value);
			}
		}

		return $mf2;
	}

	/**
	 * find all urls in a text
	 *
	 * @param string $text - text to parse
	 *
	 * @return array array of URLS found in the test
	 */
	protected static function extract_urls( &$text ) {
		$matches = array();
		preg_match_all("/\b(?:http|https)\:\/\/?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\.\/\?\:@\-_=#]*/i", $text, $matches);

		$matches = $matches[0];

		$matches = array_unique($matches);

		return $matches;
	}

	/**
	 * validates a post object if it really is a post
	 *
	 * @param $post object Wordpress Post Object to check
	 *
	 * @return bool true if it's a post, false if not
	 */
	protected static function is_post ( &$post ) {
		if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
			return true;

		return false;
	}



	/**
	 * Converts relative to absolute urls
	 *
	 * code from [webmention](https://github.com/pfefferle/wordpress-webmention)
	 * which is based on the code of 99webtools.com
	 *
	 * @link http://99webtools.com/relative-path-into-absolute-url.php
	 *
	 * @param string $base the base url
	 * @param string $rel the relative url
	 *
	 * @return string the absolute url
	 */
	protected static function make_url_absolute( $base, $rel ) {
		if ( 0 === strpos( $rel, '//' ) ) {
			return parse_url( $base, PHP_URL_SCHEME ) . ':' . $rel;
		}
		// return if already absolute URL
		if ( parse_url( $rel, PHP_URL_SCHEME ) != '' ) {
			return $rel;
		}
		// queries and	anchors
		if ( '#' == $rel[0]  || '?' == $rel[0] ) {
			return $base . $rel;
		}
		// parse base URL and convert to local variables:
		// $scheme, $host, $path
		extract( parse_url( $base ) );
		// remove	non-directory element from path
		$path = preg_replace( '#/[^/]*$#', '', $path );
		// destroy path if relative url points to root
		if ( '/' == $rel[0] ) {
			$path = '';
		}
		// dirty absolute URL
		$abs = "$host";
		// check port
		if ( isset( $port ) && ! empty( $port ) ) {
			$abs .= ":$port";
		}
		// add path + rel
		$abs .= "$path/$rel";
		// replace '//' or '/./' or '/foo/../' with '/'
		$re = array( '#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#' );
		for ( $n = 1; $n > 0; $abs = preg_replace( $re, '/', $abs, -1, $n ) ) { }
		// absolute URL is ready!
		return $scheme . '://' . $abs;
	}

	/**
	 * get content like the_content
	 *
	 * @param object $post - WP Post object
	 *
	 * @return string the_content
	 */
	protected static function get_the_content( &$post ){

		if ( ! static::is_post( $post ) )
			return false;

		if ( $cached = wp_cache_get ( $post->ID, __CLASS__ . __FUNCTION__ ) )
			return $cached;

		$r = apply_filters( 'the_content', $post->post_content );

		wp_cache_set ( $post->ID, $r, __CLASS__ . __FUNCTION__, static::expire );

		return $r;
	}


	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 *
	 * @output log to syslog | wp_die on high level
	 * @return false on not taking action, true on log sent
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( empty( $message ) )
			return false;

		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		$levels = array (
			LOG_EMERG => 0, // system is unusable
			LOG_ALERT => 1, // Alert 	action must be taken immediately
			LOG_CRIT => 2, // Critical 	critical conditions
			LOG_ERR => 3, // Error 	error conditions
			LOG_WARNING => 4, // Warning 	warning conditions
			LOG_NOTICE => 5, // Notice 	normal but significant condition
			LOG_INFO => 6, // Informational 	informational messages
			LOG_DEBUG => 7, // Debug 	debug-level messages
		);

		// number for number based comparison
		// should work with the defines only, this is just a make-it-sure step
		$level_ = $levels [ $level ];

		// in case WordPress debug log has a minimum level
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ > $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
			exit;
		}

		$trace = debug_backtrace();
		$caller = $trace[1];
		$parent = $caller['function'];

		if (isset($caller['class']))
			$parent = $caller['class'] . '::' . $parent;

		return error_log( "{$parent}: {$message}" );
	}


}

require ( __DIR__ . '/sender.php' );
$WP_Webmention_Again_Sender = new WP_Webmention_Again_Sender();

require ( __DIR__ . '/receiver.php' );
$WP_Webmention_Again_Receiver = new WP_Webmention_Again_Receiver();


// global send_webmention function
if ( ! function_exists( 'send_webmention' ) ) {
	function send_webmention( $source, $target, $object = '', $object_id = 0 ) {
		return WP_Webmention_Again_Sender::queue_add ( 'out', $source, $target, $object, $object_id );
	}
}


endif;
