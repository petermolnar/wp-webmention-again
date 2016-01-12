<?php
/*
Plugin Name: wp-webmention-again
Plugin URI: https://github.com/petermolnar/wp-webmention-again
Description:
Version: 0.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
*/

if (!class_exists('WP_WEBMENTION_AGAIN')):

// global send_webmention function
if (!function_exists('send_webmention')) {
	function send_webmention( $source, $target ) {
		return WP_WEBMENTION_AGAIN::send( $source, $target );
	}
}

// something else might have loaded this already
if ( !class_exists('Mf2\Parser') ) {
	require (__DIR__ . '/vendor/autoload.php');
}

// if something has loaded Mf2 already, the autoload won't kick in,
// and we need this
if (!class_exists('EmojiRecognizer')) {
	require (__DIR__ . '/vendor/dissolve/single-emoji-recognizer/src/emoji.php');
}

class WP_WEBMENTION_AGAIN {

	// post meta key for queued incoming mentions
	const meta_received = '_webmention_received';
	// cron handle for processing incoming
	const cron_received = 'webmention_received';
	// post meta key for posts marked as outgoing and in need of processing
	const meta_send = '_webmention_send';
	// cron handle for processing outgoing
	const cron_send = 'webmention_send';
	// WP cache expiration seconds
	const expire = 10;

	/**
	 * cron interval for processing incoming
	 *
	 * use 'wp-webmention-again_interval_received' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function interval_received () {
		return apply_filters('wp-webmention-again_interval_received', 90);
	}

	/**
	 * cron interval for processing outgoing
	 *
	 * use 'wp-webmention-again_interval_send' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function interval_send () {
		return apply_filters('wp-webmention-again_interval_send', 90);
	}

	/**
	 * max number of retries ( both for outgoing and incoming )
	 *
	 * use 'wp-webmention-again_retry' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function retry () {
		return apply_filters('wp-webmention-again_retry', 5);
	}

	/**
	 * endpoint for receiving webmentions
	 *
	 * use 'wp-webmention-again_endpoint' to filter this string
	 *
	 * @return string name of endpoint
	 *
	 */
	protected static function endpoint() {
		return apply_filters('wp-webmention-again_endpoint', 'webmention');
	}

	/**
	 * maximum amount of posts per batch to be processed
	 *
	 * use 'wp-webmention-again_per_batch' to filter this int
	 *
	 * @return int posts per batch
	 *
	 */
	protected static function per_batch () {
		return apply_filters('wp-webmention-again_per_batch', 10);
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

		return apply_filters('wp-webmention-again_mftypes', $map );
	}

	/**
	 * runs on plugin load
	 */
	public function __construct() {

		add_action( 'init', array( &$this, 'init'));

		add_action( 'parse_query', array( &$this, 'receive' ) );

		add_action( 'wp_head', array( &$this, 'html_header' ), 99 );
		add_action( 'send_headers', array( &$this, 'http_header' ) );

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );

		// extend current cron schedules with our entry
		add_filter( 'cron_schedules', array(&$this, 'add_cron_schedule' ));

		// register the action for processing received
		add_action( static::cron_received, array( &$this, 'process_received' ) );

		// register the action for processing received
		add_action( static::cron_send, array( &$this, 'process_send' ) );

		// additional comment types
		add_action('admin_comment_types_dropdown', array(&$this, 'comment_types_dropdown'));

		// register new posts
		add_action('transition_post_status', array( &$this, 'queue_send') ,12,5);
	}

	/**
	 * runs at WordPress init hook
	 *
	 */
	public function init() {

		// add webmention endpoint to query vars
		add_filter( 'query_vars', array( &$this, 'add_query_var' ) );

		// because this needs one more filter
		add_filter('get_avatar_comment_types', array( &$this, 'add_comment_types'));

		// additional avatar filter
		add_filter( 'get_avatar' , array(&$this, 'get_avatar'), 1, 5 );

		if (!wp_get_schedule( static::cron_received )) {
			wp_schedule_event( time(), static::cron_received, static::cron_received );
		}

		if (!wp_get_schedule( static::cron_send )) {
			wp_schedule_event( time(), static::cron_send, static::cron_send );
		}

	}

	/**
	 * plugin activation hook
	 *
	 * dies if PHP version is too low
	 *
	 */
	public function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}

		flush_rewrite_rules( true );
	}

	/**
	 * plugin deactivation hook
	 *
	 * makes sure there are no scheduled cron hooks left
	 *
	 */
	public function plugin_deactivate () {
		wp_unschedule_event( time(), static::cron_received );
		wp_clear_scheduled_hook( static::cron_received );

		wp_unschedule_event( time(), static::cron_send );
		wp_clear_scheduled_hook( static::cron_send );

		flush_rewrite_rules( true );
	}


	/**
	 * add our own schedule
	 *
	 * @param array $schedules - current schedules list
	 *
	 * @return array $schedules - extended schedules list
	 */
	public function add_cron_schedule ( $schedules ) {

		$schedules[ static::cron_received ] = array(
			'interval' => static::interval_received(),
			'display' => sprintf(__( 'every %d seconds' ), static::interval_received() )
		);

		$schedules[ static::cron_send ] = array(
			'interval' => static::interval_send(),
			'display' => sprintf(__( 'every %d seconds' ), static::interval_send() )
		);

		return $schedules;
	}

	/**
	 * extends HTML header with webmention endpoint
	 *
	 * @output string two lines of HTML <link>
	 *
	 */
	public function html_header () {
		$endpoint = site_url( '?'. static::endpoint() .'=endpoint' );

		// backwards compatibility with v0.1
		echo '<link rel="http://webmention.org/" href="' . $endpoint . '" />' . "\n";
		echo '<link rel="webmention" href="' . $endpoint . '" />' . "\n";
	}

	/**
	 * extends HTTP response header with webmention endpoints
	 *
	 * @output two lines of Link: in HTTP header
	 *
	 */
	public function http_header() {
		$endpoint = site_url( '?'. static::endpoint() .'=endpoint' );

		// backwards compatibility with v0.1
		header( 'Link: <' . $endpoint . '>; rel="http://webmention.org/"', false );
		header( 'Link: <' . $endpoint . '>; rel="webmention"', false );
	}

	/**
	 * add webmention to accepted query vars
	 *
	 * @param array $vars current query vars
	 *
	 * @return array extended vars
	 */
	public function add_query_var($vars) {
		array_push($vars, static::endpoint());
		return $vars;
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
		$options = get_option(__CLASS__);

		if (isset($options['comment_types']) && is_array($options['comment_types']))
			$options['comment_types'] = array_merge($options['comment_types'], static::mftypes());
		else
			$options['comment_types'] = static::mftypes();

		$options['comment_types'] = apply_filters('wp-webmention-again_comment_types', $options['comment_types']);

		return $options;
	}

	/**
	 * extends the current registered comment types in options to have
	 * single char emoji as comment type
	 *
	 * @param char $reacji single emoticon character to add as comment type
	 *
	 */
	public static function register_reacji ($reacji) {
		$options = static::get_options();

		if (!in_array($reacji, $options['comment_types'])) {
			$options['comment_types'][$reacji] = $reacji;
			update_option( __CLASS__ , $options );
		}
	}

	/**
	* Extend the "filter by comment type" of in the comments section
	* of the admin interface with all of our methods
	*
	* @param array $types the different comment types
	*
	* @return array the filtered comment types
	*/
	public function comment_types_dropdown($types) {
		$options = static::get_options();

		foreach ($options['comment_types'] as $type => $fancy ) {
			if (!isset($types[ $type ]))
				$types[ $type ] = ucfirst( $type );
		}
		return $types;
	}

	/**
	 * extend the abailable comment types in WordPress with the ones recognized
	 * by the plugin, including reacji
	 *
	 * @param array $types current types
	 *
	 * @return array extended types
	 */
	public function add_comment_types ( $types ) {
		$options = static::get_options();

		foreach ($options['comment_types'] as $type => $fancy ) {
			if (!in_array( $type, $types ))
				array_push( $types, $type );
		}

		return $types;
	}


	/**
	 * parse & queue incoming webmention endpoint requests
	 *
	 * @param mixed $wp WordPress Query
	 *
	 */
	public function receive( $wp ) {

		// check if it is a webmention request or not
		if ( ! array_key_exists( static::endpoint(), $wp->query_vars ) )
			return false;

		// plain text header
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );

		// check if source url is transmitted
		if ( ! isset( $_POST['source'] ) ) {
			status_header( 400 );
			echo '"source" is missing';
			exit;
		}

		// check if target url is transmitted
		if ( ! isset( $_POST['target'] ) ) {
			status_header( 400 );
			echo '"target" is missing';
			exit;
		}

		$target = filter_var($_POST['target'], FILTER_SANITIZE_URL);
		$source = filter_var($_POST['source'], FILTER_SANITIZE_URL);

		if ( filter_var($target, FILTER_VALIDATE_URL) === false ) {
			status_header( 400 );
			echo '"target" is an invalid URL';
			exit;
		}

		if ( filter_var($source, FILTER_VALIDATE_URL) === false ) {
			status_header( 400 );
			echo '"source" is an invalid URL';
			exit;
		}

		$post_id = static::validate_local($target);
		if (!$post_id || $post_id == 0) {
			status_header( 404 );
			echo '"target" not found.';
			exit;
		}

		// check if pings are allowed
		if ( ! pings_open( $post_id ) ) {
			status_header( 403 );
			echo 'Pings are disabled for this post';
			exit;
		}

		// queue here, the remote check will be async
		$r = static::queue_receive($source, $target, $post_id);

		if ($r) {
			status_header( 202 );
			echo 'Webmention accepted in the queue.';
		}
		else {
			status_header( 500 );
			echo 'Something went wrong; please try again later!';
		}

		exit;
	}

	/**
	 * add post meta for post with about incoming webmention request to be
	 * processed later
	 *
	 * @param string $source source URL
	 * @param string $target target URL
	 * @param int $post_id Post ID
	 *
	 * @return bool|int result of add_post_meta
	 */
	protected static function queue_receive ($source, $target, $post_id) {
		if(empty($post_id) || empty($source) || empty($target))
			return false;

		$val = array (
			'source' => $source,
			'target' => $target
		);

		static::debug ("queueing {static::meta_received} meta for #{$post_id}; source: {$source}, target: {$target}");
		$r = add_post_meta($post_id, static::meta_received, $val, false);

		if ($r == false )
			static::debug ("adding {static::meta_received} meta for #{$post_id} failed");

		return $r;
	}


	/**
	 * worker method for doing received webmentions
	 * triggered by cron
	 *
	 */
	public function process_received () {

		$posts = static::get_received();

		if (empty($posts))
			return true;

		foreach ($posts as $post_id ) {
			$received_mentions = get_post_meta ($post_id, static::meta_received, false);

			static::debug('todo: ' . json_encode($received_mentions));
			foreach ($received_mentions as $m) {
				// $m should not be modified as this is how the current entry can be identified!
				$_m = $m;

				static::debug("working on webmention for post #{$post_id}");

				// this really should not happen, but if it does, get rid of this entry immediately
				if (!isset($_m['target']) || empty($_m['target']) || !isset($_m['source']) || empty($_m['source'])) {
					static::debug("  target or souce empty, aborting");
					continue;
				}

				static::debug("  target: {$_m['target']}, source: {$_m['source']}");

				// if we'be been here before, we have retried counter already
				$retries = isset($_m['retries']) ? intval($_m['retries']) : 0;

				// too many retries, drop this mention and walk away
				if ($retries >= static::retry() ) {
					static::debug("  this mention was tried earlier and failed too many times, drop it");
					delete_post_meta($post_id, static::meta_received, $m);
					continue;
				}

				$_m['retries'] = $retries + 1;

				// validate target
				$remote = static::try_receive_remote( $post_id, $_m['source'], $_m['target'] );

				if ($remote === false || empty($remote)) {
					static::debug("  parsing this mention failed, retrying next time");
					update_post_meta($post_id, static::meta_received, $_m, $m);
					continue;
				}

				// we have remote data !
				$c = static::try_parse_remote ($post_id, $_m['source'], $_m['target'], $remote);
				$ins = static::insert_comment ($post_id, $_m['source'], $_m['target'], $remote, $c );

				if ($ins === true) {
					static::debug("  duplicate (or something similar): this queue element has to be ignored; deleting queue entry");
					delete_post_meta($post_id, static::meta_received, $m);
				}
				elseif (is_numeric($ins)) {
					static::debug("  all went well, we have a comment id: {$ins}, deleting queue entry");
					delete_post_meta($post_id, static::meta_received, $m);
				}
				else {
					static::debug("This is unexpected. Try again.");
					update_post_meta($post_id, static::meta_received, $_m, $m);
					continue;
				}
			}
		}
	}

	/**
	 * get posts which have queued incoming requests
	 *
	 * @return array array of WP Post objects or empty array
	 */
	protected static function get_received () {

		global $wpdb;
		$r = array();

		$dbname = "{$wpdb->prefix}postmeta";
		$key = static::meta_received;
		$limit = static::per_batch();
		$db_command = "SELECT DISTINCT `post_id` FROM `{$dbname}` WHERE `meta_key` = '{$key}' LIMIT {$limit}";

		try {
			$q = $wpdb->get_results($db_command);
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

		if (!empty($q) && is_array($q)) {
			foreach ($q as $post) {
				$r[] = $post->post_id;
			}
		}

		return $r;

		/*
		 * this does not work reliably
		$args = array(
			'posts_per_page' => static::per_batch(),
			'meta_key' => static::meta_received,
			//'meta_value' => '*',
			'post_type' => 'any',
			'post_status' => 'publish',
		);
		return get_posts( $args );
		*/
	}


	/**
	 * extended wp_remote_get with debugging
	 *
	 * @param string $source URL to pull
	 *
	 * @return array wp_remote_get array
	 */
	protected static function _wp_remote_get (&$source) {
		$content = false;
		static::debug("    fetching {$source}");
		$url = htmlspecialchars_decode($source);
		$q = wp_remote_get($source);

		if (is_wp_error($q)) {
			static::debug('    something went wrong: ' . $q->get_error_message());
			return false;
		}

		if (!is_array($q)) {
			static::debug('    $q is not an array. It should be one.');
			return false;
		}

		if (!isset($q['headers']) || !is_array($q['headers'])) {
			static::debug('    missing response headers.');
			return false;
		}

		if (!isset($q['body']) || empty($q['body'])) {
			static::debug('    missing body');
			return false;
		}

		return $q;
	}

	/**
	 * try to get contents of webmention originator
	 *
	 * @param int $post_id ID of post
	 * @param string $source Originator URL
	 * @param string $target Target URL
	 *
	 * @return bool|array false on error; plain array or Mf2 parsed (and
	 *   flattened ) array of remote content on success
	 */
	protected static function try_receive_remote ( &$post_id, &$source, &$target ) {

		$content = false;
		$q = static::_wp_remote_get($source);

		if ($q === false)
			return false;

		$t = $target;
		$t = preg_replace('/https?:\/\/(?:www.)?/', '', $t);
		$t = preg_replace('/#.*/', '', $t);
		$t = untrailingslashit($t);

		// check if source really links to target
		// this could be a temporary error, so we'll retry later this one as well
		if (!stristr($q['body'],$t)) {
			static::debug("    missing link to {$t} in remote body");
			return false;
		}

		$ctype = isset($q['headers']['content-type']) ? $q['headers']['content-type'] : 'text/html';

		if ($ctype == "text/plain") {
			static::debug("  interesting, plain text webmention. I'm not prepared for this yet");
			return false;
		}
		elseif ($ctype == "application/json") {
			static::debug("  content is JSON");
			$content = json_decode($q['body'], true);
		}
		else {
			static::debug("  content is (probably) html, trying to parse it with MF2");
			try {
				$content = Mf2\parse($q['body'], $source);
			}
			catch (Exception $e) {
				static::debug('  parsing MF2 failed:' . $e->getMessage());
				return false;
			}

			$content = static::flatten_mf2_array($content);
		}

		return $content;
	}

	/**
	 * try to convert remote content to comment
	 *
	 * @param int $post_id ID of post
	 * @param string $source Originator URL
	 * @param string $target Target URL
	 * @param array $content (Mf2) array of remote content
	 *
	 * @return bool|int false on error; insterted comment ID on success
	 */
	protected static function try_parse_remote ( &$post_id, &$source, &$target, &$content ) {

		$item = false;
		$p_authors = array();

		if (isset($content['items']['properties']) && isset($content['items']['type'])) {
			$item = $content['items'];
		}
		elseif (is_array($content['items']) && !empty($content['items']['type'])) {
			foreach ($content['items'] as $i) {
				if ($i['type'] == 'h-entry') {
					$item = $i;
				}
				elseif ($i['type'] == 'h-card') {
					$p_authors[] = $i;
				}
			}
		}

		if (!$item || empty($item)) {
			static::debug('    no parseable h-entry found, saving as standard mention comment');
			$c = array (
				'comment_author'				=> $source,
				'comment_author_url'		=> $source,
				'comment_author_email'	=> '',
				'comment_post_ID'				=> $post_id,
				'comment_type'					=> 'webmention',
				'comment_date'					=> date("Y-m-d H:i:s"),
				'comment_date_gmt'			=> date("Y-m-d H:i:s"),
				'comment_agent'					=> __CLASS__,
				'comment_approved' 			=> 0,
				'comment_content'				=> sprintf(__('This entry was webmentioned on <a href="%s">%s</a>.'), $source, $source),
			);
			return $c;
		}

		// process author
		$author_name = $author_url = $avatar = $a = false;

		if (isset($item['properties']['author'])) {
			$a = $item['properties']['author'];
		}
		elseif (!empty($p_authors)) {
			$a = array_pop($p_authors);
		}

		if ($a && isset($a['properties'])) {
			$a = $a['properties'];

			if (isset($a['name']) && !empty($a['name']))
				$author_name = $a['name'];

			$try_photos = array ('photo', 'avatar');
			$p = false;
			foreach ($try_photos as $photo) {
				if (isset($a[$photo]) && !empty($a[$photo])) {
					$p = $a[$photo];
					if (!empty($p)) {
						$avatar = $p;
						break;
					}
				}
			}
		}

		// process type
		$type = 'webmention';

		foreach ( static::mftypes() as $k => $mapped) {
			if (is_array($item['properties']) && isset($item['properties'][$mapped]))
				$type = $k;
		}

		//process content
		$c = '';
		if (isset($item['properties']['content']) && isset($item['properties']['content']['html']))
			$c = $item['properties']['content']['html'];
		if (isset($item['properties']['content']) && isset($item['properties']['content']['value']))
			$c = wp_filter_kses($item['properties']['content']['value']);

		// REACJI
		$emoji = EmojiRecognizer::isSingleEmoji($c);

		if ($emoji) {
			static::debug('wheeeee, reacji!');
			$type = trim($c);
			static::register_reacji( $type );
		}

		// process date
		if (isset($item['properties']['modified']))
			$date = strtotime($item['properties']['modified']);
		elseif (isset($item['properties']['published']))
			$date = strtotime($item['properties']['published']);
		else
			$date = time();

		$name = empty($author_name) ? $source : $author_name;

		$c = array (
			'comment_author'				=> $name,
			'comment_author_url'		=> $source,
			'comment_author_email'	=> '',
			'comment_post_ID'				=> $post_id,
			'comment_type'					=> $type,
			'comment_date'					=> date("Y-m-d H:i:s", $date ),
			'comment_date_gmt'			=> date("Y-m-d H:i:s", $date ),
			'comment_agent'					=> __CLASS__,
			'comment_approved' 			=> 0,
			'comment_content'				=> $c,
			'comment_avatar'				=> $avatar,
		);

		return $c;
	}

	/**
	 * Comment inserter
	 *
	 * @param string &$post_id post ID
	 * @param string &$source Originator URL
	 * @param string &$target Target URL
	 * @param mixed &$raw Raw format of the comment, like JSON response from the provider
	 * @param array &$comment array formatted to match a WP Comment requirement
	 *
	 */
	protected static function insert_comment ( &$post_id, &$source, &$target, &$raw, &$comment ) {

		$comment_id = false;

		$avatar = false;
		if( isset( $comment['comment_avatar'])) {
			$avatar = $comment['comment_avatar'];
			unset($comment['comment_avatar']);
		}

		// safety first
		$comment['comment_author_email'] = filter_var ( $comment['comment_author_email'], FILTER_SANITIZE_EMAIL );
		$comment['comment_author_url'] = filter_var ( $comment['comment_author_url'], FILTER_SANITIZE_URL );
		$comment['comment_author'] = filter_var ( $comment['comment_author'], FILTER_SANITIZE_STRING);

		//test if we already have this imported
		$testargs = array(
			'author_url' => $comment['comment_author_url'],
			'post_id' => $post_id,
		);

		// so if the type is comment and you add type = 'comment', WP will not return the comments
		// such logical!
		if ( $comment['comment_type'] != 'comment')
			$testargs['type'] = $comment['comment_type'];

		// in case it's a fav or a like, the date field is not always present
		// but there should be only one of those, so the lack of a date field indicates
		// that we should not look for a date when checking the existence of the
		// comment
		if ( isset( $comment['comment_date']) && !empty($comment['comment_date']) ) {
			// in case you're aware of a nicer way of doing this, please tell me
			// or commit a change...

			$tmp = explode ( " ", $comment['comment_date'] );
			$d = explode( "-", $tmp[0]);
			$t = explode (':',$tmp[1]);

			$testargs['date_query'] = array(
				'year'     => $d[0],
				'monthnum' => $d[1],
				'day'      => $d[2],
				'hour'     => $t[0],
				'minute'   => $t[1],
				'second'   => $t[2],
			);

			//$testargs['date_query'] = $comment['comment_date'];

			//test if we already have this imported
			static::debug("checking comment existence (with date) for post #{$post_id}");
		}
		else {
			// we do need a date
			$comment['comment_date'] = date("Y-m-d H:i:s");
			$comment['comment_date_gmt'] = date("Y-m-d H:i:s");

			static::debug("checking comment existence (no date) for post #{$post_id}");
		}

		$existing = get_comments($testargs);

		// no matching comment yet, insert it
		if (!empty($existing)) {
			static::debug ('comment already exists');
			return true;
		}

		// disable flood control, just in case
		remove_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);
		$comment = apply_filters( 'preprocess_comment', $comment );

		if ( $comment_id = wp_new_comment($comment) ) {

			// add avatar for later use if present
			if (!empty($avatar)) {
				update_comment_meta( $comment_id, 'avatar', $avatar );
			}

			// full raw response for the vote, just in case
			update_comment_meta( $comment_id, 'webmention_source_mf2', $raw );

			// original request
			update_comment_meta( $comment_id, 'webmention_original', array( 'target' => $target, 'source' => $source) );

			// info
			$r = "new comment inserted for {$post_id} as #{$comment_id}";

			// notify author
			// wp_notify_postauthor( $comment_id );
		}
		else {
			$r = "something went wrong when trying to insert comment for post #{$post_id}";
		}

		// re-add flood control
		add_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);

		static::debug($r);
		return $comment_id;
	}





	/**
	 * triggered on post transition, applied when new status is publish, therefore
	 * applied on edit of published posts as well
	 * add a post meta to the post to be processed by the send processor
	 *
	 * @param string $new_status New post status
	 * @param string $old_status Previous post status
	 * @param object $post WP Post object
	 */
	public function queue_send($new_status, $old_status, $post) {
		if (!static::is_post($post)) {
			static::debug("Woops, this is not a post.");
			return false;
		}

		if ($new_status != 'publish') {
			static::debug("Not adding {$post->ID} to mention queue yet; not published");
			return false;
		}


		if (! $r = add_post_meta($post->ID, static::meta_send, 1, true) )
			static::debug("Tried adding post #{$post->ID} to mention queue, but it didn't go well");

		return $r;
	}

	/**
	 * Main send processor
	 *
	 *
	 */
	public function process_send () {
		$posts = static::get_send();

		if (empty($posts))
			return false;

		foreach ($posts as $post_id) {
			$post = get_post($post_id);

			if (!static::is_post($post)) {
				delete_post_meta($post_id, static::meta_send);
				continue;
			}

			static::debug("Trying to get urls for #{$post->ID}");

			// try to avoid redirects, so no shortlink is sent for now
			$source = get_permalink( $post->ID );

			// process the content as if it was the_content()
			$content = static::get_the_content($post);
			// get all urls in content
			$urls = static::extract_urls($content);
			// for special ocasions when someone wants to add to this list
			$urls = apply_filters( 'webmention_links', $urls, $post->ID );

			$urls = array_unique( $urls );
			$todo = $urls;
			$failed = array();
			$pung = static::get_pung( $post->ID );

			foreach ( $urls as $target ) {
				$target = strtolower($target);
				static::debug('  url to ping: ' . $target );

				// already pinged, skip
				if (in_array( $target, $pung )) {
					static::debug('    already pinged!' );
					$todo = array_diff($todo, array($target));
					continue;
				}

				// tried too many times
				$try_key = static::meta_send . '_' . $target;
				$tries = intval(get_post_meta( $post->ID, $try_key, true ));
				if ($tries && $tries >= static::retry() ) {
					static::debug("    failed too many times; skipping");
					$todo = array_diff($todo, array($target));
					array_push($failed, $try_key);
					continue;
				}

				// try sending
				$s = static::send($source, $target, $post->ID );

				if (!$s) {
					$tries = $tries + 1;
					static::debug("    sending failed; retrying later ({$tries} time)");
					update_post_meta($post->ID, $try_key, $tries );
					continue;
				}
				else {
					static::debug('  sending succeeded!');
					add_ping( $post->ID, $target );
					$todo = array_diff($todo, array($target));
				}

			}

			if (empty($todo)) {
				static::debug('  no more urls to ping or no more tries left, cleaning up');

				foreach ($failed as $try_key)
					delete_post_meta($post->ID, $try_key);

				delete_post_meta($post->ID, static::meta_send);

			}

		}
	}

	/**
	 *
	 */
	protected static function get_pung ($post_id) {
		$pung = get_pung( $post_id );
		foreach ($pung as $k => $e ) {
			$pung[$k] = strtolower($e);
		}
		$pung = array_unique($pung);
		return $pung;
	}

	/**
	 * get all posts that have meta entry for sending
	 *
	 * @return array of WP Post objects
	 */
	protected static function get_send () {
		global $wpdb;
		$r = array();

		$dbname = "{$wpdb->prefix}postmeta";
		$key = static::meta_send;
		$limit = static::per_batch();
		$db_command = "SELECT DISTINCT `post_id` FROM `{$dbname}` WHERE `meta_key` = '{$key}' LIMIT {$limit}";

		try {
			$q = $wpdb->get_results($db_command);
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

		if (!empty($q) && is_array($q)) {
			foreach ($q as $post) {
				$r[] = $post->post_id;
			}
		}

		return $r;

		/*
		 * this is not reliable
		$args = array(
			//'posts_per_page' => static::per_batch(),
			'posts_per_page' => -1,
			'meta_key' => static::meta_send,
			'meta_value' => 1,
			'post_type' => 'any',
			'post_status' => 'publish',
		);


		return get_posts( $args );
		*/

	}

	/**
	 * send a single webmention
	 * based on [webmention](https://github.com/pfefferle/wordpress-webmention)
	 *
	 */
	public static function send ( $source, $target, $post_id = false ) {
		$options = static::get_options();

		// stop selfpings on the same URL
		if ( isset($options['disable_selfpings_same_url']) &&
				 $options['disable_selfpings_same_url'] == '1' &&
				 $source === $target
			 )
			return false;

		// stop selfpings on the same domain
		if ( isset($options['disable_selfpings_same_domain']) &&
				 $options['disable_selfpings_same_domain'] == '1' &&
				 parse_url( $source, PHP_URL_HOST ) == parse_url( $target, PHP_URL_HOST )
			 )
			return false;

		// discover the webmention endpoint
		$webmention_server_url = static::discover_endpoint( $target );

		// if I can't find an endpoint, perhaps you can!
		$webmention_server_url = apply_filters( 'webmention_server_url', $webmention_server_url, $target );

		if ( $webmention_server_url ) {
			$args = array(
				'body' => 'source=' . urlencode( $source ) . '&target=' . urlencode( $target ),
				'timeout' => 100,
			);

			static::debug('Sending webmention to: ' .$webmention_server_url . ' as: ' . $args['body']);
			$response = wp_remote_post( $webmention_server_url, $args );

			// use the response to do something usefull
			// do_action( 'webmention_post_send', $response, $source, $target, $post_ID );

			return $response;
		}

		return false;
	}

	/**
	 * Finds a WebMention server URI based on the given URL
	 *
	 * code from [webmention](https://github.com/pfefferle/wordpress-webmention)
	 *
	 * Checks the HTML for the rel="http://webmention.org/" link and http://webmention.org/ headers. It does
	 * a check for the http://webmention.org/ headers first and returns that, if available. The
	 * check for the rel="http://webmention.org/" has more overhead than just the header.
	 *
	 * @param string $url URL to ping
	 *
	 * @return bool|string False on failure, string containing URI on success
	 */
	protected static function discover_endpoint( $url ) {
		/** @todo Should use Filter Extension or custom preg_match instead. */
		$parsed_url = parse_url( $url );

		if ( ! isset( $parsed_url['host'] ) ) { // Not an URL. This should never happen.
			return false;
		}

		// do not search for a WebMention server on our own uploads
		$uploads_dir = wp_upload_dir();
		if ( 0 === strpos( $url, $uploads_dir['baseurl'] ) ) {
			return false;
		}

		$response = wp_remote_head( $url, array( 'timeout' => 100, 'httpversion' => '1.0' ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// check link header
		if ( $links = wp_remote_retrieve_header( $response, 'link' ) ) {
			if ( is_array( $links ) ) {
				foreach ( $links as $link ) {
					if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(.org)?\/?[\"\']?/i', $link, $result ) ) {
						return self::make_url_absolute( $url, $result[1] );
					}
				}
			} else {
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(.org)?\/?[\"\']?/i', $links, $result ) ) {
					return self::make_url_absolute( $url, $result[1] );
				}
			}
		}

		// not an (x)html, sgml, or xml page, no use going further
		if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
			return false;
		}

		// now do a GET since we're going to look in the html headers (and we're sure its not a binary file)
		$response = wp_remote_get( $url, array( 'timeout' => 100, 'httpversion' => '1.0' ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$contents = wp_remote_retrieve_body( $response );

		// boost performance and use alreade the header
		$header = substr( $contents, 0, stripos( $contents, '</head>' ) );

		// unicode to HTML entities
		$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );

		libxml_use_internal_errors( true );

		$doc = new DOMDocument();
		$doc->loadHTML( $contents );

		$xpath = new DOMXPath( $doc );

		// check <link> elements
		// checks only head-links
		foreach ( $xpath->query( '//head/link[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
			return self::make_url_absolute( $url, $result->value );
		}

		// check <a> elements
		// checks only body>a-links
		foreach ( $xpath->query( '//body//a[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
			return self::make_url_absolute( $url, $result->value );
		}

		return false;
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
		$url = preg_replace( '/^https?:\/\//i', 'http://', $url );
		return url_to_postid( $url );
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
	 * of there is a comment meta 'avatar' field, use that as avatar for the commenter
	 *
	 * @param string $avatar the current avatar image string
	 * @param mixed $id_or_email this could be anything that triggered the avatar all
	 * @param string $size size for the image to display
	 * @param string $default optional fallback
	 * @param string $alt alt text for the avatar image
	 */
	public function get_avatar($avatar, $id_or_email, $size, $default = '', $alt = '') {
		if (!is_object($id_or_email) || !isset($id_or_email->comment_type))
			return $avatar;

		// check if comment has an avatar
		$c_avatar = get_comment_meta($id_or_email->comment_ID, 'avatar', true);

		if (!$c_avatar)
			return $avatar;

		if (false === $alt)
			$safe_alt = '';
		else
			$safe_alt = esc_attr($alt);


		return sprintf( '<img alt="%s" src="%s" class="avatar photo u-photo" style="width: %spx; height: %spx;" />', $safe_alt, $c_avatar, $size, $size );
	}

	/**
	 * get content like the_content
	 *
	 * @param object $post - WP Post object
	 *
	 * @return string the_content
	 */
	protected static function get_the_content( &$post ){

		if (!static::is_post($post))
			return false;

		if ( $cached = wp_cache_get ( $post->ID, __CLASS__ . __FUNCTION__ ) )
			return $cached;

		$r = apply_filters('the_content', $post->post_content);

		wp_cache_set ( $post->ID, $r, __CLASS__ . __FUNCTION__, static::expire );

		return $r;
	}

	/**
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 */
	protected static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . ": " . $message );
	}

}
$WP_WEBMENTION_AGAIN = new WP_WEBMENTION_AGAIN();

endif;