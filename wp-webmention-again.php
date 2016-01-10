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

class WP_WEBMENTION_AGAIN {

	const meta_key = '_webmention_received';
	const cron_key = 'webmention_async';
	const endp_key = 'webmentions_again';

	public function __construct() {

		add_action( 'parse_query', array( &$this, 'parse_query' ) );

		add_action( 'wp_head', array( &$this, 'html_header' ), 99 );
		add_action( 'send_headers', array( &$this, 'http_header' ) );

		add_action( 'init', array( &$this, 'init'));

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );

		// register the action for the cron hook
		add_action( static::cron_key, array( &$this, 'process' ) );

	}

	/**
	 * init hook
	 */
	public function init() {
		add_filter( 'query_vars', array( &$this, 'query_var' ) );


		if (!wp_get_schedule( static::cron_key )) {
			wp_schedule_event( time(), 'daily', static::cron_key );
		}
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
	 *
	 */
	public function html_header () {
		$endpoint = site_url( '?'. static::endp_key .'=endpoint' );

		// backwards compatibility with v0.1
		echo '<link rel="http://webmention.org/" href="' . $endpoint . '" />' . "\n";
		echo '<link rel="webmention" href="' . $endpoint . '" />' . "\n";
	}

	/**
	 * T
	 */
	public function http_header() {
		$endpoint = site_url( '?'. static::endp_key .'=endpoint' );

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
		$vars[] = static::endp_key;
		return $vars;
	}


	/**
	 * parse incoming webmention endpoint requests
	 * main 'receiver' worker
	 *
	 * @param mixed $wp WordPress Query
	 */
	public function parse_query( $wp ) {

		// check if it is a webmention request or not
		if ( ! array_key_exists( static::endp_key, $wp->query_vars ) )
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

		if ( empty($target) ) {
			status_header( 400 );
			echo '"target" is an invalid URL';
			exit;
		}

		if ( empty($source) ) {
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
	 * worker method for doing received webmentions
	 * works in 10 post per cron run, reschedules itself after that
	 *
	 */
	public function process () {

		$args = array(
			'posts_per_page' => 10,
			'orderby' => 'date',
			'order' => 'ASC',
			'meta_key' => static::meta_key,
			'post_type' => 'any',
			'post_status' => 'any',
			'suppress_filters' => true,
		);

		$posts = get_posts( $args );

		if (empty($posts)) {
			// re-schedule ourselves, but delay
			return true;
		}

		foreach ($posts as $post ) {
			$received_mentions = get_post_meta ($post->ID, static::meta_key, false);
			foreach ($received_mentions as $m) {
				static::debug("working on webmention for post #{$post->ID}");
				// delete the current one, because even if it fails, it will change
				// to store the pull retries and the status
				delete_post_meta($post->ID, static::meta_key, $m);

				// this really should not happen, but if it does, get rid of this entry immediately
				if (!isset($m['target']) || empty($m['target']) || !isset($m['source']) || empty($m['source'])) {
					static::debug("  target or souce empty, aborting");
					continue;
				}
				else {
					static::debug("  target: {$m['target']}, source: {$m['source']}");
				}

				// if we'be been here before, we have retried counter already
				$retries = isset($m['retries']) ? intval($m['retries']) : 0;

				// too many retries, drop this mention and walk away
				if ($retries >= 5) {
					static::debug("  this mention was tried earlier at least 5 times, drop it");
					continue;
				}
				else {
					$m['retries'] = $retries + 1;
				}

				// validate target
				$parsed = static::try_get_remote( $m['source'], $m['target'] );

				// parsing didn't go well, try again later
				if ($parsed === false) {
					static::debug("  parsing this mention failed, retrying next time");
					add_post_meta($post->ID, static::meta_key, $m, false);
					continue;
				}

				static::debug("  it looks like we're done, and this webmention was added as a comment");
			}
		}

		// re-schedule ourselves immediately, we may not be finished with the mentions
		return true;
	}

	/**
	 * remote
	 */
	public static function try_get_remote ( $source, $target ) {

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
			$content = Mf2\parse($q['body'], $url);
		}

		return $this->try_save_comment( $source, $target, $content );
	}

	public function try_save_comment ( $source, $target, $content ) {
		return true;
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

		static::debug ("queueing {static::meta_key} meta for #{$post_id}; source: {$source}, target: {$target}");
		$r = add_post_meta($post_id, static::meta_key, $val, false);

		if ($r == false )
			static::debug ("adding {static::meta_key} meta for #{$post_id} failed");

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
	//public static function extract_urls( &$text ) {
		//$matches = array();
		//preg_match_all("/\b(?:http|https)\:\/\/?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\.\/\?\:@\-_=#]*/i", $text, $matches);

		//$matches = $matches[0];
		//return $matches;
	//}

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
}
$WP_WEBMENTION_AGAIN = new WP_WEBMENTION_AGAIN();

endif;