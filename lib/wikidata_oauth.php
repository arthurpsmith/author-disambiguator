<?PHP

class WD_OAuth {

	var $use_cookies = true ;
	var $testing = false ;
	var $tool ;
	var $debugging = false ;
	var $ini_file , $params ;
	var $mwOAuthUrl = 'https://www.mediawiki.org/w/index.php?title=Special:OAuth';
	var $publicMwOAuthUrl; //if the mediawiki url given to the user is different from how this
							//script may see it (e.g. if behind a proxy) set the user url here.
	var $mwOAuthIW = 'mw'; // Set this to the interwiki prefix for the OAuth central wiki.
	var $userinfo ;

	var $auto_detect_lag = false ;
	var $delay_after_create_s = 2 ;
	var $delay_after_edit_s = 1 ;
	var $delay_after_upload_s = 1 ;
	
	function __construct ( $tool, $ini_file ) {
		$this->tool = $tool ;
		$this->ini_file = $ini_file ;

		$this->apiUrl = 'https://www.wikidata.org/w/api.php' ;

		if ( !isset( $this->publicMwOAuthUrl )) {
			$this->publicMwOAuthUrl = $this->mwOAuthUrl;
		}

		$this->loadIniFile() ;
		$this->setupSession() ;
		$this->loadToken() ;

		if ( isset( $_GET['oauth_verifier'] ) && $_GET['oauth_verifier'] ) {
			$this->fetchAccessToken();
		}
	}

	function sleepAfterEdit ( $type ) {
		if ( $this->auto_detect_lag ) { // Try to auto-detect lag
			$url = $this->apiUrl . '?action=query&meta=siteinfo&format=json&maxlag=-1' ;
			$t = @file_get_contents ( $url ) ;
			if ( $t !== false ) {
				$j = @json_decode ( $t ) ;
				if ( isset($j->error->lag) ) {
					$lag = $j->error->lag ;
					if ( $lag > 1 ) sleep ( $lag * 3 ) ;
					return ;
				}
			}
		}

		if ( $type == 'create' ) sleep ( $this->delay_after_create_s ) ;
		if ( $type == 'edit' ) sleep ( $this->delay_after_edit_s ) ;
		if ( $type == 'upload' ) sleep ( $this->delay_after_upload_s ) ;
	}
	
	function logout () {
		$this->setupSession() ;
		session_start();
		setcookie ( 'tokenKey' , '' , 1 , '/'.$this->tool.'/' ) ;
		setcookie ( 'tokenSecret' , '' , 1 , '/'.$this->tool.'/' ) ;
		$_SESSION['tokenKey'] = '' ;
		$_SESSION['tokenSecret'] = '' ;
		session_write_close();
	}
	
	function setupSession() {
		// Setup the session cookie
		session_name( $this->tool );
		$params = session_get_cookie_params();
		session_set_cookie_params(
			$params['lifetime'],
			dirname( $_SERVER['SCRIPT_NAME'] )
		);
	}
	
	function loadIniFile () {
		$this->params = parse_ini_file ( $this->ini_file ) ;
		$this->gUserAgent = $this->params['agent'];
		$this->gConsumerKey = $this->params['consumerKey'];
		$this->gConsumerSecret = $this->params['consumerSecret'];
	}
	
	// Load the user token (request or access) from the session
	function loadToken() {
		$this->gTokenKey = '';
		$this->gTokenSecret = '';
		session_start();
		if ( isset( $_SESSION['tokenKey'] ) ) {
			$this->gTokenKey = $_SESSION['tokenKey'];
			$this->gTokenSecret = $_SESSION['tokenSecret'];
		} elseif ( $this->use_cookies and isset( $_COOKIE['tokenKey'] ) ) {
			$this->gTokenKey = $_COOKIE['tokenKey'];
			$this->gTokenSecret = $_COOKIE['tokenSecret'];
		}
		session_write_close();
	}


	/**
	 * Handle a callback to fetch the access token
	 * @return void
	 */
	function fetchAccessToken() {
		$url = $this->mwOAuthUrl . '/token';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$url .= http_build_query( [
			'format' => 'json',
			'oauth_verifier' => $_GET['oauth_verifier'],

			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		] );
		$this->signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $this->signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<h1>LOGIN</h1><pre>" ; print_r ( $data ) ; print "</pre></hr>" ;
		}

		if ( !$data ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( is_object( $token ) && isset( $token->error ) ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Save the access token
		session_start();
		$_SESSION['tokenKey'] = $this->gTokenKey = $token->key;
		$_SESSION['tokenSecret'] = $this->gTokenSecret = $token->secret;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'.$this->tool.'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'.$this->tool.'/' ) ;
		}
		session_write_close();
	}


	/**
	 * Utility function to sign a request
	 *
	 * Note this doesn't properly handle the case where a parameter is set both in 
	 * the query string in $url and in $params, or non-scalar values in $params.
	 *
	 * @param string $method Generally "GET" or "POST"
	 * @param string $url URL string
	 * @param array $params Extra parameters for the Authorization header or post 
	 * 	data (if application/x-www-form-urlencoded).
	 * @return string Signature
	 */
	function sign_request( $method, $url, $params = [] ) {
//		global $gConsumerSecret, $gTokenSecret;

		$parts = parse_url( $url );

		// We need to normalize the endpoint URL
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host = isset( $parts['host'] ) ? $parts['host'] : '';
		$port = isset( $parts['port'] ) ? $parts['port'] : ( $scheme == 'https' ? '443' : '80' );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ( $scheme == 'https' && $port != '443' ) ||
			( $scheme == 'http' && $port != '80' ) 
		) {
			// Only include the port if it's not the default
			$host = "$host:$port";
		}

		// Also the parameters
		$pairs = [];
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$query += $params;
		unset( $query['oauth_signature'] );
		if ( $query ) {
			$query = array_combine(
				// rawurlencode follows RFC 3986 since PHP 5.3
				array_map( 'rawurlencode', array_keys( $query ) ),
				array_map( 'rawurlencode', array_values( $query ) )
			);
			ksort( $query, SORT_STRING );
			foreach ( $query as $k => $v ) {
				$pairs[] = "$k=$v";
			}
		}

		$toSign = rawurlencode( strtoupper( $method ) ) . '&' .
			rawurlencode( "$scheme://$host$path" ) . '&' .
			rawurlencode( join( '&', $pairs ) );
		$key = rawurlencode( $this->gConsumerSecret ) . '&' . rawurlencode( $this->gTokenSecret );
		return base64_encode( hash_hmac( 'sha1', $toSign, $key, true ) );
	}

	/**
	 * Request authorization
	 * @return void
	 */
	function doAuthorizationRedirect($callback) {
		// First, we need to fetch a request token.
		// The request is signed with an empty token secret and no token key.
		$this->gTokenSecret = '';
		$url = $this->mwOAuthUrl . '/initiate';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$query = [
			'format' => 'json',
		
			// OAuth information
			'oauth_callback' => $callback,
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		] ;
		$query['callback'] = $callback ;
		$url .= http_build_query( $query );
		$signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( $token === NULL ) {
			print_r ( $data ) ; exit ( 0 ) ; // SHOW MEDIAWIKI ERROR
		}
		if ( is_object( $token ) && isset( $token->error ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Now we have the request token, we need to save it for later.
		session_start();
		$_SESSION['tokenKey'] = $token->key;
		$_SESSION['tokenSecret'] = $token->secret;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'.$this->tool.'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'.$this->tool.'/' ) ;
		}
		session_write_close();

		// Then we send the user off to authorize
		$url = $this->publicMwOAuthUrl . '/authorize';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$arr = [
			'oauth_token' => $token->key,
			'oauth_consumer_key' => $this->gConsumerKey,
		] ;
		if ( $callback != '' ) $arr['callback'] = $callback ;
		$url .= http_build_query( $arr );
		header( "Location: $url" );
		echo 'Please see <a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $url ) . '</a>';
	}


	function doIdentify() {

		$url = $this->mwOAuthUrl . '/identify';
		$headerArr = [
			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		];
		$signature = $this->sign_request( 'GET', $url, $headerArr );
		$headerArr['oauth_signature'] = $signature;

		$header = [];
		foreach ( $headerArr as $k => $v ) {
			$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		$header = 'Authorization: OAuth ' . join( ', ', $header );
		if ( $this->testing ) print "HEADER: {$header}\n" ;

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ $header ] );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		$err = json_decode( $data );
		if ( is_object( $err ) && isset( $err->error ) && $err->error === 'mwoauthdatastore-access-token-not-found' ) {
			// We're not authorized!
#			echo 'You haven\'t authorized this application yet! Go <a href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that.';
#			echo '<hr>';
			return (object) ['is_authorized'=>false] ;
		}
		
		// There are three fields in the response
		$fields = explode( '.', $data );
		if ( count( $fields ) !== 3 ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid identify response: ' . htmlspecialchars( $data );
			exit(0);
		}

		// Validate the header. MWOAuth always returns alg "HS256".
		$header = base64_decode( strtr( $fields[0], '-_', '+/' ), true );
		if ( $header !== false ) {
			$header = json_decode( $header );
		}
		if ( !is_object( $header ) || $header->typ !== 'JWT' || $header->alg !== 'HS256' ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid header in identify response: ' . htmlspecialchars( $data );
			exit(0);
		}

		// Verify the signature
		$sig = base64_decode( strtr( $fields[2], '-_', '+/' ), true );
		$check = hash_hmac( 'sha256', $fields[0] . '.' . $fields[1], $this->gConsumerSecret, true );
		if ( $sig !== $check ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'JWT signature validation failed: ' . htmlspecialchars( $data );
			echo '<pre>'; var_dump( base64_encode($sig), base64_encode($check) ); echo '</pre>';
			exit(0);
		}

		// Decode the payload
		$payload = base64_decode( strtr( $fields[1], '-_', '+/' ), true );
		if ( $payload !== false ) {
			$payload = json_decode( $payload );
		}
		if ( !is_object( $payload ) ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid payload in identify response: ' . htmlspecialchars( $data );
			exit(0);
		}
		
		$payload->is_authorized = true ;
		return $payload ;
	}



	/**
	 * Send an API query with OAuth authorization
	 *
	 * @param array $post Post data
	 * @param object $ch Curl handle
	 * @return array API results
	 */
	function doApiQuery( $post, &$ch = null , $mode = '' , $iterations_left = 5 , $last_maxlag = -1 ) {
		if ( $iterations_left <= 0 ) return ; // Avoid infinite recursion when Wikidata Is Too Damn Slow Again

		global $maxlag ;
		if ( !isset($maxlag) ) $maxlag = 5 ;
		$give_maxlag = $maxlag ;
		if ( $last_maxlag != -1 ) $give_maxlag = $last_maxlag ;

		// Not an edit, high maxlag allowed
		if ( isset($post['action']) and $post['action']=='query' and isset($post['meta']) and $post['meta']=='userinfo' ) {
			$give_maxlag = 99999 ;
		}

		$post['maxlag'] = $give_maxlag ;
		if ( isset ( $_REQUEST['test'] ) ) print "<pre>GIVEN MAXLAG:{$give_maxlag}</pre>" ;

		$headerArr = [
			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		];

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ;
			print "!!\n" ;
//			print_r ( $headerArr ) ;
			print "</pre>" ;
		}
		
		$to_sign = '' ;
		if ( $mode == 'upload' ) {
			$to_sign = $headerArr ;
		} else {
			$to_sign = $post + $headerArr ;
		}
		$url = $this->apiUrl ;
		if ( $mode == 'identify' ) $url .= '/identify' ;
		$signature = $this->sign_request( 'POST', $url, $to_sign );
		$headerArr['oauth_signature'] = $signature;

		$header = [];
		foreach ( $headerArr as $k => $v ) {
			$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		$header = 'Authorization: OAuth ' . join( ', ', $header );


		if ( !$ch ) {
			$ch = curl_init();
			
		}
		
		$post_fields = '' ;
		if ( $mode == 'upload' ) {
			$post_fields = $post ;
			$post_fields['file'] = new CurlFile($post['file'], 'application/octet-stream', $post['filename']);
		} else {
			$post_fields = http_build_query( $post ) ;
		}
		
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ $header ] );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

		$data = curl_exec( $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<hr/><h3>API query</h3>" ;
//			print "URL:<pre>$url</pre>" ;
//			print "Header:<pre>" ; print_r ( $header ) ; print "</pre>" ;
			print "Payload:<pre>" ; print_r ( $post ) ; print "</pre>" ;
			print "Result:<pre>" ; print_r ( $data ) ; print "</pre>" ;
			print "<hr/>" ;
		}

		if ( !$data ) return ;
		$ret = json_decode( $data );
		if ( $ret == null ) return ;
		
		# maxlag
		if ( isset($ret->error) and isset($ret->error->code) and $ret->error->code == 'maxlag' ) {
			$lag = $maxlag ;
			if ( isset($ret->error->lag) ) $last_maxlag = $ret->error->lag*1 + $maxlag ;
			sleep ( $lag ) ;
			$ch = null ;
			$ret = $this->doApiQuery( $post, $ch , '' , $iterations_left-1 , $last_maxlag ) ;
		}
		
		return $ret ;
	}


	// Wikidata-specific methods

	function isAuthOK () {

		$ch = null;

		// First fetch the username
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query',
			'uiprop' => 'groups|rights' ,
			'meta' => 'userinfo',
		], $ch , 'userinfo' );

		if ( isset( $res->error->code ) && $res->error->code === 'mwoauth-invalid-authorization' ) {
			// We're not authorized!
			$this->error = 'You haven\'t authorized this application yet! Go <a target="_blank" href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that, then reload this page.' ;
			return false ;
		}

		if ( !isset( $res->query->userinfo ) ) {
			$this->error = 'Not authorized (bad API response [isAuthOK]: ' . htmlspecialchars( json_encode( $res) ) . ')' ;
			return false ;
		}
		if ( isset( $res->query->userinfo->anon ) ) {
			$this->error = 'Not logged in. (How did that happen?)' ;
			return false ;
		}

		$this->userinfo = $res->query->userinfo ;
		

		return true ;
	}

	function prepare_edit_token($action_label) {
	// Fetch edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = "Bad API response [$action_label]: <pre>" . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return NULL;
		}
		$token = $res->query->tokens->csrftoken;
		return [$ch, $token];
	}

	function fetch_item($qid, $ch) {
		$item = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'wbgetentities' ,
			'ids' => $qid,
			'redirects' => 'no'
		], $ch )->entities->$qid;

		return $item;
	}

	function apply_commands_to_item($qid, $baserev, $edit_summary, $token, $ch, $commands) {
		$data = array();
		$data['claims'] = $commands;
		$params = [
			'format' => 'json',
			'action' => 'wbeditentity',
			'id' => $qid ,
			'data' => json_encode ( $data ) ,
			'token' => $token
		] ;
		if ( isset ( $baserev ) and $baserev != '' ) $params['baserevid'] = $baserev ;
		if ( isset($edit_summary) and $edit_summary != '' ) $params['summary'] = $edit_summary ;

		$res = $this->doApiQuery( $params , $ch );
		if ( isset ( $res->error ) ) {
			$this->error = "Error code: " . $res->error->code ;
			return false ;
		}
		return true;
	}

}

?>
