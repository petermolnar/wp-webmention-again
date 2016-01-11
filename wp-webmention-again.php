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
//if (!function_exists('send_webmention')) {
	//function send_webmention( $source, $target ) {
		//return WP_WEBMENTION_AGAIN::send( $source, $target );
	//}
//}

if ( !class_exists('Mf2\Parser') ) {
	require (__DIR__ . '/vendor/autoload.php');
}

if (!class_exists('EmojiRecognizer')) {
	require (__DIR__ . '/vendor/dissolve/single-emoji-recognizer/src/emoji.php');
}

class WP_WEBMENTION_AGAIN {

	const meta_received = '_webmention_received';
	const cron_received = 'webmention_received';
	const interval_received = 90;

	const meta_send = '_webmention_send';
	const cron_send = 'webmention_send';
	const interval_send = 90;

	const endp = 'webmentions_again';

	public function __construct() {

		//add_action( 'parse_query', array( &$this, 'receive' ) );

		//add_action( 'wp_head', array( &$this, 'html_header' ), 99 );
		//add_action( 'send_headers', array( &$this, 'http_header' ) );

		add_action( 'init', array( &$this, 'init'));

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		//register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );

		// register the action for processing received
		add_action( static::cron_received, array( &$this, 'process_received' ) );

		// register action for sending
		//add_action( static::cron_send, array( &$this, 'process_send' ) );

	}

	/**
	 * init hook
	 */
	public function init() {
		// add webmention endpoint to query vars
		//add_filter( 'query_vars', array( &$this, 'query_var' ) );

		// extend current cron schedules with our entry
		add_filter( 'cron_schedules', array(&$this, 'add_cron_schedule' ));

		if (!wp_get_schedule( static::cron_received )) {
			wp_schedule_event( time(), 'daily', static::cron_received );
		}

		// additional comment types
		add_action('admin_comment_types_dropdown', array(&$this, 'comment_types_dropdown'));

		// because this needs one more filter
		add_filter('get_avatar_comment_types', array( &$this, 'add_comment_types'));

		// additional avatar filter
		add_filter( 'get_avatar' , array(&$this, 'get_avatar'), 1, 5 );

	}

	/**
	 * activation hook
	 */
	public function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}
	}

	/**
	 * deactivation hook
	 */
	public function plugin_deactivate () {
		//static::debug('deactivating');
		//wp_unschedule_event( time(), __CLASS__ );
		//wp_clear_scheduled_hook( __CLASS__ );
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
			'interval' => static::interval_received,
			'display' => sprintf(__( 'every %d seconds' ), static::interval_received )
		);

		$schedules[ static::cron_send ] = array(
			'interval' => static::interval_send,
			'display' => sprintf(__( 'every %d seconds' ), static::interval_send )
		);

		return $schedules;
	}

	/**
	 *
	 */
	public function html_header () {
		$endpoint = site_url( '?'. static::endp .'=endpoint' );

		// backwards compatibility with v0.1
		echo '<link rel="http://webmention.org/" href="' . $endpoint . '" />' . "\n";
		echo '<link rel="webmention" href="' . $endpoint . '" />' . "\n";
	}

	/**
	 *
	 */
	public function http_header() {
		$endpoint = site_url( '?'. static::endp .'=endpoint' );

		// backwards compatibility with v0.1
		header( 'Link: <' . $endpoint . '>; rel="http://webmention.org/"', false );
		header( 'Link: <' . $endpoint . '>; rel="webmention"', false );
	}

	/**
	 * add webmention to accepted query vars
	 *
	 * @param array $vars
	 *
	 * @return array extended vars
	 */
	public function query_var($vars) {
		$vars[] = static::endp;
		return $vars;
	}

	/**
	 *
	 */
	public static function get_options () {
		$options = get_option(__CLASS__);

		if (isset($options['comment_types']) && is_array($options['comment_types']))
			$options['comment_types'] = array_merge($options['comment_types'], static::mftypes());
		else
			$options['comment_types'] = static::mftypes();

		return $options;
	}

	/**
	 *
	 */
	public static function set_options ( &$options ) {
		return update_option( __CLASS__ , $options );
	}


	/**
	 *
	 */
	public static function register_reacji ($reacji) {
		$options = static::get_options();

		if (!in_array($reacji, $options['comment_types'])) {
			$options['comment_types'][$reacji] = $reacji;
			static::set_options($options);
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
	 *
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
	 * parse incoming webmention endpoint requests
	 * main 'receiver' worker
	 *
	 * @param mixed $wp WordPress Query
	 */
	public function receive( $wp ) {

		// check if it is a webmention request or not
		if ( ! array_key_exists( static::endp, $wp->query_vars ) )
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

		// queue here, the remote check will be async
		$r = static::queue($source, $target, $post_id);

		if ($r) {
			status_header( 202 );
			echo 'Webmention accepted in the queue.';
		}
		else {
			status_header( 503 );
			echo 'Something went wrong; please try again later!';
		}

		exit;
	}

	/**
	 *
	 */
	public function get_received () {
		global $wpdb;
		$r = array();

		$dbname = "{$wpdb->prefix}postmeta";
		$key = static::meta_received;
		$db_command = "SELECT DISTINCT `post_id` FROM `{$dbname}` WHERE `meta_key` = '{$key}' LIMIT 10";

		static::debug($db_command);
		static::debug('fetching current mentions');
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
	}

	/**
	 * worker method for doing received webmentions
	 * works in 10 post per cron run, reschedules itself after that
	 *
	 */
	public function process_received () {

		$posts = static::get_received();

		if (empty($posts)) {

			// re-schedule ourselves, but delay
			return true;
		}

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
				else {
					static::debug("  target: {$_m['target']}, source: {$_m['source']}");
				}

				// if we'be been here before, we have retried counter already
				$retries = isset($_m['retries']) ? intval($_m['retries']) : 0;

				// too many retries, drop this mention and walk away
				if ($retries >= 500) {
					static::debug("  this mention was tried earlier at least 5 times, drop it");
					continue;
				}
				else {
					$_m['retries'] = $retries + 1;
				}

				// validate target
				$parsed = static::try_get_remote( $post_id, $_m['source'], $_m['target'] );

				// parsing didn't go well, try again later
				if ($parsed === false) {
					static::debug("  parsing this mention failed, retrying next time");
					update_post_meta($post_id, static::meta_received, $_m, $m);
					//add_post_meta($post->ID, static::meta_received, $m, false);
					continue;
				}
				elseif ($parsed === true) {
					static::debug("  duplicate or something else, but this queue element has to be ignored; deleting queue entry");
					delete_post_meta($post_id, static::meta_received, $m);
				}
				elseif (is_numeric($parsed)) {
					static::debug("  all went well, we have a comment id: {$parsed}, deleting queue entry");
					delete_post_meta($post_id, static::meta_received, $m);
				}
				else {
					die("This is unexpected.");
				}

				static::debug("  it looks like we're done, and this webmention was added as a comment");
			}
		}

		// re-schedule ourselves immediately, we may not be finished with the mentions
		// delete_post_meta($post->ID, static::meta_received, $m);
		return true;
	}

	/**
	 * remote
	 */
	public static function try_get_remote ( &$post_id, &$source, &$target ) {

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
				$content = Mf2\parse($q['body'], $url);
			}
			catch (Exception $e) {
				static::debug('  parsing MF2 failed:' . $e->getMessage());
				return false;
			}
		}

		if (!empty($content)) {
			return static::try_parse_comment( $post_id, $source, $target, $content );
		}

		return false;
	}

	/**
	 *
	 */
	public static function try_parse_comment ( &$post_id, &$source, &$target, &$raw ) {
		$hentry = false;

		$content = static::mf2_unarray($raw);

		if (empty($content) || !is_array($content) || !isset($content['items']) || empty($content['items'])) {
			static::debug('    nothing to parse :(');
			return false;
		}

		$item = false;
		$p_authors = array();
		// un-arrayed
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
				'comment_content'				=> sprintf(__('This entry was webmentioned on <a href="%s">%s</a>.'), $source, $source)
			);
			return static::insert_comment ($post_id, $source, $target, $c, $raw );
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

			//if (isset($a['url']) && !empty($a['url'])) {
				//if (is_array($a['url']))
					//$author_url = array_pop($a['url']);
				//else
					//$author_url = $a['url'];
			//}
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
		//$url = empty($author_url) ? $source : $author_url;

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
		);

		return static::insert_comment ($post_id, $source, $target, $c, $raw, $avatar );
	}

	/**
	 * Comment inserter
	 *
	 * @param string &$post_id post ID
	 * @param array &$comment array formatted to match a WP Comment requirement
	 * @param mixed &$raw Raw format of the comment, like JSON response from the provider
	 * @param string &$avatar Avatar string to be stored as comment meta
	 *
	 */
	public static function insert_comment ( &$post_id, &$source, &$target, &$comment, &$raw, &$avatar = '' ) {

		$comment_id = false;

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

			// full raw response for the vote, just in case
			//update_comment_meta( $comment_id, 'webmention_source', $ );


			// info
			$r = "new comment inserted for {$post_id} as #{$comment_id}";

			// notify author
			wp_notify_postauthor( $comment_id );
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
	 *
	 */
	public static function queue ($source, $target, $post_id) {
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
	 * validated a URL that is supposed to be on our site
	 *
	 * @param string $url URL to check against
	 *
	 * @return bool|int false on not found, int post_id on found
	 */
	public static function validate_local ( $url ) {
		// normalize url scheme, url_to_postid will take care of it anyway
		$url = preg_replace( '/^https?:\/\//i', 'http://', $url );
		return url_to_postid( $url );
	}

	/**
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
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

	/**
	 *
	 */
	public static function mftypes () {
		$map = array (
			 // http://indiewebcamp.com/reply
			'reply' => 'in-reply-to',
			// http://indiewebcamp.com/repost
			'repost' => 'repost-of',
			// http://indiewebcamp.com/like
			'like' => 'like-of',
			// http://indiewebcamp.com/favorite
			//'' => array ('favorite','',),
			// http://indiewebcamp.com/bookmark
			'bookmark' => 'bookmark-of',
			//  http://indiewebcamp.com/rsvp
			'rsvp' => 'rsvp',
			// http://indiewebcamp.com/tag
			'tag' => 'tag-of',
		);

		return $map;
	}

	/**
	 *
	 */
	public static function mf2_unarray ( $mf2 ) {

		if (is_array($mf2) && count($mf2) == 1) {
			$mf2 = array_pop($mf2);
		}

		if (is_array($mf2)) {
			foreach($mf2 as $key => $value) {
				$mf2[$key] = static::mf2_unarray($value);
			}
		}

		return $mf2;
	}

	/**
	 * validates a post object if it really is a post
	 *
	 * @param object Wordpress Post Object to check
	 *
	 * @return bool true if it's a post, false if not
	 */
	public static function is_post ( &$post ) {
		if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
			return true;

		return false;
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
	public static function get_avatar($avatar, $id_or_email, $size, $default = '', $alt = '') {
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

}
$WP_WEBMENTION_AGAIN = new WP_WEBMENTION_AGAIN();

endif;