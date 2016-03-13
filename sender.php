<?php

class WP_Webmention_Again_Sender extends WP_Webmention_Again {

	// cron handle for processing outgoing
	const cron = 'webmention_send';
	const pung = '_webmention_pung';

	/**
	 * regular cron interval for processing incoming
	 *
	 * use 'wp-webmention-again_interval_received' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function interval () {
		return apply_filters( 'wp-webmention-again-sender_interval', 90 );
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
		return apply_filters( 'wp-webmention-again-sender_per_batch', 42 );
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
		return apply_filters( 'wp-webmention-again-sender_retry', 5 );
	}

	public function __construct() {
		parent::__construct();

		// extend current cron schedules with our entry
		//add_filter( 'cron_schedules', array(&$this, 'add_cron_schedule' ) );

		// this is mostly for debugging reasons
		// register_activation_hook( __FILE__ , array( __CLASS__ , 'plugin_activate' ) );

		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( __CLASS__ , 'plugin_deactivate' ) );

		// register the action for processing received
		add_action( static::cron, array( &$this, 'process' ) );

		// register new posts
		add_action( 'transition_post_status', array( &$this, 'queue_post' ), 98, 5 );

	}

	public function init () {

		// get_pung is not restrictive enough
		//add_filter ( 'get_pung', array( &$this, 'get_pung' ) );

		if ( ! wp_get_schedule( static::cron ) )
			wp_schedule_event( time(), static::cron, static::cron );

	}

	/**
	 * plugin deactivation hook
	 *
	 * makes sure there are no scheduled cron hooks left
	 *
	 */
	public static function plugin_deactivate () {

		wp_unschedule_event( time(), static::cron );
		wp_clear_scheduled_hook( static::cron );

	}

	/**
	 * make pung stricter
	 *
	 * @param array $pung array of pinged urls
	 *
	 * @return array a better array of pinged urls
	 *
	 *
	public static function get_pung ( $post ) {

		foreach ($pung as $k => $e )
			$pung[ $k ] = strtolower( $e );

		$pung = array_unique($pung);

		return $pung;
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
	public static function queue_post( $new_status, $old_status, $post ) {

		if ( ! static::is_post( $post ) ) {
			static::debug( "Whoops, this is not a post.", 6);
			return false;
		}

		if ( 'publish' != $new_status ) {
			static::debug( "Not adding {$post->ID} to mention queue yet; not published" );
			return false;
		}

		static::debug("Trying to get urls for #{$post->ID}", 6);

		// try to avoid redirects, so no shortlink is sent for now as source
		$source = get_permalink( $post->ID );

		// process the content as if it was the_content()
		$content = static::get_the_content( $post );

		// get all urls in content
		$urls = static::extract_urls( $content );

		// for special ocasions when someone wants to add to this list
		$urls = apply_filters( 'webmention_links', $urls, $post->ID );

		// lowercase url is good for your mental health
		foreach ( $urls as $k => $url )
			$urls[ $k ] = strtolower( $url );

		// remove all already pinged urls
		$pung = get_post_meta( $post->ID, static::pung, false );

		/*
		// retrofill pung from pingback field, temporary
		if ( empty ($pung) ) {
			$_pung = get_pung ( $post->ID );
			if ( ! empty ($_pung) ) {
				$pung = $_pung;
				foreach ( $_pung as $url ) {
					add_post_meta( $post->ID, static::pung, $url, false );
				}
			}
		}
		*/

		$urls = array_diff ( $urls, $pung );

		foreach ( $urls as $target ) {

			$s_domain = parse_url( $source, PHP_URL_HOST);
			$t_domain = parse_url( $target, PHP_URL_HOST);

			// skip self-pings
			if ( $s_domain == $t_domain )
				continue;

			$r = static::queue_add ( 'out', $source, $target, $post->post_type, $post->ID );

			if ( !$r )
				static::debug( "  tried adding post #{$post->ID}, url: {$target} to mention queue, but it didn't go well", 4);
		}

	}

	/**
	 * worker method for doing received webmentions
	 * triggered by cron
	 *
	 */
	public function process () {

		$outgoing = static::queue_get ( 'out', static::per_batch() );

		if ( empty( $outgoing ) )
			return true;

		foreach ( (array)$outgoing as $send ) {

			// this really should not happen, but if it does, get rid of this entry immediately
			if (! isset( $send->target ) ||
					  empty( $send->target ) ||
					! isset( $send->source ) ||
					  empty( $send->source )
			) {
					static::debug( "  target or souce empty, aborting", 6);
					static::queue_del ( $send->id );
					continue;
				}

			static::debug( "processing webmention:  target -> {$send->target}, source -> {$send->source}", 5 );

			// too many retries, drop this mention and walk away
			if ( $send->tries >= static::retry() ) {
				static::debug( "  this mention was tried earlier and failed too many times, drop it", 5);
				static::queue_done ( $send->id );
				continue;
			}

			// increment retries
			static::queue_inc ( $send->id );

			// try sending
			$s = static::send( $send->source, $send->target );

			if ( false == $s ) {
					static::debug( "    sending failed: ", 5);
			}
			else {
				static::debug( "  sending succeeded!", 5);

				$post_types = get_post_types( '', 'names' );
				if ( in_array( $send->object_type, $post_types ) && 0 != $send->object_id ) {
					add_post_meta ( $send->object_id, static::pung, $send->target, false );
					//add_ping( $send->object_id, $send->target );
				}

				static::queue_done ( $send->id, $s );
			}
		}
	}

	/**
	 * send a single webmention
	 * based on [webmention](https://github.com/pfefferle/wordpress-webmention)
	 *
	 */
	public static function send ( $source, $target, $post_id = false ) {
		$options = static::get_options();

		// stop selfpings on the same URL
		if ( isset( $options['disable_selfpings_same_url'] ) &&
				 $options['disable_selfpings_same_url'] == '1' &&
				 $source === $target
			 )
			return false;

		// stop selfpings on the same domain
		if ( isset( $options['disable_selfpings_same_domain'] ) &&
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
				'timeout' => static::remote_timeout(),
			);

			static::debug( "Sending webmention to: " .$webmention_server_url . " as: " . $args['body'], 5);
			$response = wp_remote_post( $webmention_server_url, $args );

			// use the response to do something usefull
			// do_action( 'webmention_post_send', $response, $source, $target, $post_ID );

			if ( is_wp_error( $response ) ) {
				static::debug( "sending failed: " . $response->get_error_message(), 4);
				return false;
			}

			if ( ! is_array( $response ) || ! isset( $response['response'] ) || ! isset( $response['response']['code'] ) || empty( $response['response']['code'] ) ) {
				static::debug( "sending failed: the response is empty", 4);
				return false;
			}

			if ( 200 <= $response['response']['code'] && 300 > $response['response']['code'] ) {
				static::debug( "sending succeeded: ${$response['response']['code']}, message: {$response['response']['message']}", 5);
				return true;
			}
			else {
				static::debug( "wrong response code, this sending probably failed: {$response['response']['code']}, message: {$response['response']['message']}", 4);
				return false;
			}

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

		// Not an URL. This should never happen.
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) )
			return false;

		// do not search for a WebMention server on our own uploads
		$uploads_dir = wp_upload_dir();
		if ( 0 === strpos( $url, $uploads_dir['baseurl'] ) )
			return false;

		$response = wp_remote_head( $url, array( 'timeout' => static::remote_timeout(), 'httpversion' => '1.0' ) );

		if ( is_wp_error( $response ) ) {
			static::debug( "Something went wrong: " . $response->get_error_message(), 5);
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
		$response = wp_remote_get( $url, array( 'timeout' => static::remote_timeout(), 'httpversion' => '1.0' ) );

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

}