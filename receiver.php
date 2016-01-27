<?php

class WP_Webmention_Again_Receiver extends WP_Webmention_Again {

	// cron handle for processing outgoing
	const cron = 'webmention_received';

	/**
	 * regular cron interval for processing incoming
	 *
	 * use 'wp-webmention-again_interval_received' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function interval () {
		return apply_filters( 'wp-webmention-again-receiver_interval', 90 );
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
		return apply_filters( 'wp-webmention-again-receiver_per_batch', 42 );
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
		return apply_filters( 'wp-webmention-again-receiver_retry', 5 );
	}

	/**
	 * regular cron interval for processing incoming
	 *
	 * use 'wp-webmention-again_interval_received' to filter this integer
	 *
	 * @return int cron interval in seconds
	 *
	 */
	protected static function known_reacji () {
		return apply_filters( 'wp-webmention-again-receiver_known_reacji', 'reacji' );
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
		return apply_filters( 'wp-webmention-again-receiver_endpoint', 'webmention' );
	}

	public function __construct() {
		parent::__construct();

		// this is mostly for debugging reasons
		// register_activation_hook( __FILE__ , array( __CLASS__ , 'plugin_activate' ) );

		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( __CLASS__ , 'plugin_deactivate' ) );

		add_action( 'parse_query', array( &$this, 'receive' ) );

		add_action( 'wp_head', array( &$this, 'html_header' ), 99 );
		add_action( 'send_headers', array( &$this, 'http_header' ) );

		// register the action for processing received
		add_action( static::cron, array( &$this, 'process' ) );

		// additional comment types
		add_action( 'admin_comment_types_dropdown', array( &$this, 'comment_types_dropdown' ) );

	}

	public function init () {

		// add webmention endpoint to query vars
		add_filter( 'query_vars', array( &$this, 'add_query_var' ) );

		// because this needs one more filter
		add_filter( 'get_avatar_comment_types', array( &$this, 'add_comment_types' ) );

		// additional avatar filter
		add_filter( 'get_avatar' , array( &$this, 'get_avatar' ), 1, 5 );

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
	* Extend the "filter by comment type" of in the comments section
	* of the admin interface with all of our methods
	*
	* @param array $types the different comment types
	*
	* @return array the filtered comment types
	*/
	public function comment_types_dropdown( $types ) {
		$options = static::get_options();

		foreach ( $options['comment_types'] as $type => $fancy )
			if ( ! isset( $types[ $type ] ) )
				$types[ $type ] = ucfirst( $type );

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

		foreach ( $options['comment_types'] as $type => $fancy )
			if ( ! in_array( $type, $types ) )
				array_push( $types, $type );

		return $types;
	}

	/**
	 * add webmention to accepted query vars
	 *
	 * @param array $vars current query vars
	 *
	 * @return array extended vars
	 */
	public function add_query_var( $vars ) {
		array_push( $vars, static::endpoint() );
		return $vars;
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
	 * parse & queue incoming webmention endpoint requests
	 *
	 * @param mixed $wp WordPress Query
	 *
	 */
	public function receive ( $wp ) {

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

		$target = filter_var( $_POST['target'], FILTER_SANITIZE_URL );
		$source = filter_var( $_POST['source'], FILTER_SANITIZE_URL );

		if ( false === filter_var( $target, FILTER_VALIDATE_URL ) ) {
			status_header( 400 );
			echo '"target" is an invalid URL';
			exit;
		}

		if ( false === filter_var( $source, FILTER_VALIDATE_URL ) ) {
			status_header( 400 );
			echo '"source" is an invalid URL';
			exit;
		}

		$post_id = static::validate_local( $target );

		if (! $post_id || 0 == $post_id ) {
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
		//$r = static::queue_receive( $source, $target, $post_id );
		$r = static::queue_add( 'in', $source, $target, 'post', $post_id );

		if ( true == $r ) {
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
	 * worker method for doing received webmentions
	 * triggered by cron
	 *
	 */
	public function process () {

		$incoming = static::queue_get ( 'in', static::per_batch() );

		if ( empty( $incoming ) )
			return true;

		foreach ( (array)$incoming as $received ) {

			// this really should not happen, but if it does, get rid of this entry immediately
			if (! isset( $received->target ) ||
					  empty( $received->target ) ||
					! isset( $received->source ) ||
					  empty( $received->source )
			) {
					static::debug( "  target or souce empty, aborting" );
					static::queue_del ( $received->id );
					continue;
				}

			static::debug( "processing webmention:  target -> {$received->target}, source -> {$received->source}" );

			if ( empty( $received->object_id ) || 0 == $received->object_id )
				$post_id = url_to_postid ( $received->target );
			else
				$post_id = $received->object_id;

			$post = get_post ( $post_id );

			if ( ! static::is_post( $post ) ) {
				static::debug( "  no post found for this mention, try again later, who knows?" );
				//static::queue_del ( $received->id );
				continue;
			}

			// too many retries, drop this mention and walk away
			if ( $received->tries >= static::retry() ) {
				static::debug( "  this mention was tried earlier and failed too many times, drop it" );
				static::queue_del ( $received->id );
				continue;
			}

			// increment retries
			static::queue_inc ( $received->id );

			// validate target
			$remote = static::try_receive_remote( $post_id, $received->source, $received->target );

			if ( false === $remote || empty( $remote ) ) {
				static::debug( "  parsing this mention failed, retrying next time" );
				continue;
			}

			// we have remote data !
			$c = static::try_parse_remote ( $post_id, $received->source, $received->target, $remote );
			$ins = static::insert_comment ( $post_id, $received->source, $received->target, $remote, $c );

			if ( true === $ins ) {
				static::debug( "  duplicate (or something similar): this queue element has to be ignored; deleting queue entry" );
					static::queue_del ( $received->id );
			}
			elseif ( is_numeric( $ins ) ) {
				static::debug( "  all went well, we have a comment id: {$ins}, deleting queue entry" );
				static::queue_done ( $received->id );
			}
			else {
				static::debug( "This is unexpected. Try again." );
			}
		}
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
		$q = static::_wp_remote_get( $source );

		if ( false === $q )
			return false;

		$targets = array (
			$target,
			wp_get_shortlink( $post_id ),
			get_permalink( $post_id )
		);

		$found = false;

		foreach ( $targets as $k => $t ) {
			$t = preg_replace( '/https?:\/\/(?:www.)?/', '', $t );
			$t = preg_replace( '/#.*/', '', $t );
			$t = untrailingslashit( $t );
			//$targets[ $k ] = $t;

			if ( ! stristr( $q['body'], $t ) )
				$found = true;

		}

		// check if source really links to target
		// this could be a temporary error, so we'll retry later this one as well
		if ( false == $found ) {
			static::debug( "    missing link to {$t} in remote body" );
			return false;
		}

		$ctype = isset( $q['headers']['content-type'] ) ? $q['headers']['content-type'] : 'text/html';

		if ( "text/plain" == $ctype ) {
			static::debug( "  interesting, plain text webmention. I'm not prepared for this yet" );
			return false;
		}
		elseif ( $ctype == "application/json" ) {
			static::debug( "  content is JSON" );
			$content = json_decode( $q['body'], true );
		}
		else {
			static::debug( "  content is (probably) html, trying to parse it with MF2" );
			try {
				$content = Mf2\parse( $q['body'], $source );
			}
			catch ( Exception $e ) {
				static::debug( "  parsing MF2 failed: " . $e->getMessage() );
				return false;
			}

			$content = static::flatten_mf2_array( $content );
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

		if ( isset( $content['items']['properties'] ) && isset( $content['items']['type'] ) ) {
			$item = $content['items'];
		}
		elseif ( is_array($content['items'] ) && ! empty( $content['items']['type'] ) ) {
			foreach ( $content['items'] as $i ) {
				if ( 'h-entry' == $i['type'] ) {
					$items[] = $i;
				}
				elseif ( 'h-card' == $i['type'] ) {
					$p_authors[] = $i;
				}
				elseif ( 'u-comment' == $i['type'] ) {
					$comments[] = $i;
				}
			}
		}

		if ( ! empty ( $items ) )
			$item = array_pop( $items );
		elseif ( empty( $items ) && ! empty( $comments ) )
			$item = array_pop( $comments );

		if (! $item || empty( $item )) {
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
				'comment_content'				=> sprintf( __( 'This entry was webmentioned on <a href="%s">%s</a>.' ), $source, $source ),
			);
			return $c;
		}

		$author_url = $source;
		// process author
		$author_name = $avatar = $a = false;

		if ( isset( $item['properties']['author'] ) ) {
			$a = $item['properties']['author'];
		}
		elseif ( ! empty( $p_authors ) ) {
			$a = array_pop( $p_authors );
		}

		if ( $a && isset( $a['properties'] ) ) {
			$a = $a['properties'];

			if ( isset($a['name']) && ! empty( $a['name'] ) )
				$author_name = $a['name'];

			$try_photos = array ('photo', 'avatar');
			$p = false;
			foreach ( $try_photos as $photo ) {
				if (isset( $a[ $photo ]) && ! empty( $a[ $photo ] ) ) {
					$p = $a[ $photo ];
					if ( !empty( $p ) ) {
						$avatar = $p;
						break;
					}
				}
			}

			if ( isset($a['url']) && ! empty( $a['url'] ) )
				$author_url = $a['url'];
		}

		// process type
		$type = 'webmention';

		foreach ( static::mftypes() as $k => $mapped ) {
			if ( is_array( $item['properties'] ) && isset( $item['properties'][ $mapped ]) )
				$type = $k;
		}

		//process content
		$c = '';
		if ( isset( $item['properties']['content'] ) && isset( $item['properties']['content']['html'] ) )
			$c = $item['properties']['content']['html'];
		if ( isset( $item['properties']['content'] ) && isset( $item['properties']['content']['value'] ) )
			$c = $item['properties']['content']['value'];

		$c = wp_filter_kses ( $c );

		// REACJI
		$emoji = EmojiRecognizer::isSingleEmoji( $c );

		if ( $emoji ) {
			static::debug( "wheeeee, reacji!" );
			$type = 'reacji';
			//static::register_reacji( $type );
		}

		// process date
		if ( isset( $item['properties']['modified'] ) )
			$date = strtotime( $item['properties']['modified'] );
		elseif ( isset( $item['properties']['published'] ) )
			$date = strtotime( $item['properties']['published'] );
		else
			$date = time();

		$name = empty( $author_name ) ? $source : $author_name;

		$c = array (
			'comment_author'				=> $name,
			'comment_author_url'		=> $author_url,
			'comment_author_email'	=> static::try_get_author_email ( $author_url ),
			'comment_post_ID'				=> $post_id,
			'comment_type'					=> $type,
			'comment_date'					=> date( "Y-m-d H:i:s", $date ),
			'comment_date_gmt'			=> date( "Y-m-d H:i:s", $date ),
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
		if( isset( $comment['comment_avatar'] ) ) {
			$avatar = $comment['comment_avatar'];
			unset( $comment['comment_avatar'] );
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
		if ( 'comment' != $comment['comment_type'] )
			$testargs['type'] = $comment['comment_type'];

		// in case it's a fav or a like, the date field is not always present
		// but there should be only one of those, so the lack of a date field indicates
		// that we should not look for a date when checking the existence of the
		// comment
		if ( isset( $comment['comment_date'] ) && ! empty( $comment['comment_date'] ) ) {
			// in case you're aware of a nicer way of doing this, please tell me
			// or commit a change...

			$tmp = explode ( " ", $comment['comment_date'] );
			$d = explode( "-", $tmp[0] );
			$t = explode ( ':', $tmp[1] );

			$testargs['date_query'] = array(
				'year'     => $d[0],
				'monthnum' => $d[1],
				'day'      => $d[2],
				'hour'     => $t[0],
				'minute'   => $t[1],
				'second'   => $t[2],
			);

			//test if we already have this imported
			static::debug( "checking comment existence (with date) for post #{$post_id}" );
		}
		else {
			// we do need a date
			$comment['comment_date'] = date( "Y-m-d H:i:s" );
			$comment['comment_date_gmt'] = date( "Y-m-d H:i:s" );

			static::debug( "checking comment existence (no date) for post #{$post_id}" );
		}

		$existing = get_comments( $testargs );

		// no matching comment yet, insert it
		if ( ! empty( $existing ) ) {
			static::debug ( "comment already exists" );
			return true;
		}

		// disable flood control, just in case
		remove_filter( 'check_comment_flood', 'check_comment_flood_db', 10, 3 );
		$comment = apply_filters( 'preprocess_comment', $comment );

		if ( $comment_id = wp_new_comment( $comment ) ) {

			// add avatar for later use if present
			if ( ! empty( $avatar ) )
				update_comment_meta( $comment_id, 'avatar', $avatar );

			// full raw response for the vote, just in case
			update_comment_meta( $comment_id, 'webmention_source_mf2', $raw );

			update_comment_meta( $comment_id, 'webmention_source', $source );
			update_comment_meta( $comment_id, 'webmention_target', $target );

			// info
			$r = "new comment inserted for {$post_id} as #{$comment_id}";

			// notify author
			// wp_notify_postauthor( $comment_id );
		}
		else {
			$r = "something went wrong when trying to insert comment for post #{$post_id}";
		}

		// re-add flood control
		add_filter( 'check_comment_flood', 'check_comment_flood_db', 10, 3 );

		static::debug( $r );

		return $comment_id;
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
	public function get_avatar( $avatar, $id_or_email, $size, $default = '', $alt = '' ) {
		if ( ! is_object( $id_or_email ) || ! isset( $id_or_email->comment_type ) )
			return $avatar;

		// check if comment has an avatar
		$c_avatar = get_comment_meta( $id_or_email->comment_ID, 'avatar', true );

		if ( ! $c_avatar )
			return $avatar;

		if ( false === $alt )
			$safe_alt = '';
		else
			$safe_alt = esc_attr( $alt );

		return sprintf( '<img alt="%s" src="%s" class="avatar photo u-photo" style="width: %dpx; height: %dpx;" />', $safe_alt, $c_avatar, $size, $size );
	}

	/**
	 *
	 *
	 */
	public static function try_get_author_email ( $author_url ) {
		$mail = '';

		if ( stristr ( $author_url, 'facebook' ) )
			$mail = parse_url ( rtrim ( $author_url, '/'), PHP_URL_PATH ) . '@facebook.com';

		return $mail;
	}

}