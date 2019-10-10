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

	
	function doesClaimExist ( $claim ) {
		$q = 'Q' . str_replace('Q','',$claim['q'].'') ;
		$p = 'P' . str_replace('P','',$claim['prop'].'') ;
		$url = 'https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=claims&ids=' . $q ;
		$j = json_decode ( file_get_contents ( $url ) ) ;

		if ( !isset ( $j->entities ) ) return false ;
		if ( !isset ( $j->entities->$q ) ) return false ;
		if ( !isset ( $j->entities->$q->claims ) ) return false ;
		if ( !isset ( $j->entities->$q->claims->$p ) ) return false ;

		$nid = 'numeric-id' ;
		$does_exist = false ;
		$cp = $j->entities->$q->claims->$p ; // Claims for this property
		foreach ( $cp AS $k => $v ) {
			if ( $claim['type'] == 'item' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( $v->mainsnak->datavalue->value->$nid != str_replace('Q','',$claim['target'].'') ) continue ;
				$does_exist = true ;
			} elseif ( $claim['type'] == 'string' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( $v->mainsnak->datavalue->value != $claim['text'] ) continue ;
				$does_exist = true ;
			} elseif ( $claim['type'] == 'date' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->time) ) continue ;
				if ( $v->mainsnak->datavalue->value->time != $claim['date'] ) continue ;
				if ( $v->mainsnak->datavalue->value->precision != $claim['prec'] ) continue ;
				$does_exist = true ;
			} else if ( $claim['type'] == 'monolingualtext' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->text) ) continue ;
				if ( $v->mainsnak->datavalue->value->text != $claim['text'] ) continue ;
				if ( $v->mainsnak->datavalue->value->language != $claim['language'] ) continue ;
				$does_exist = true ;
			} else if ( $claim['type'] == 'quantity' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->amount) ) continue ;
				if ( $v->mainsnak->datavalue->value->amount != $claim['amount'] ) continue ;
				if ( $v->mainsnak->datavalue->value->unit != $claim['unit'] ) continue ;
				$does_exist = true ;
			}
		}
	
		return $does_exist ;
	}


	function getConsumerRights () {
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'blockinfo|groups|rights'
		], $ch );
		
		return $res ;
	}

	
	function createItemFromPage ( $site , $page ) {
		$page = str_replace ( ' ' , '_' , $page ) ;
	
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createItemFromPage]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;


		$data = [ 'sitelinks' => [ $site => [ "site" => $site ,"title" => $page ] ] ] ;
		$m = [] ;
		if ( preg_match ( '/^(.+)wiki(|quote)$/' , $site , $m ) ) {
			$nice_title = preg_replace ( '/\s+\(.+\)$/' , '' , str_replace ( '_' , ' ' , $page ) ) ;
			$lang = $m[1] ;
			$lang_map = [
				'als' => 'gsw',
				'bat_smg' => 'sgs',
				'be_x_old' => 'be-tarask',
				'bh' => 'bho',
				'commons' => 'en',
				'fiu_vro' => 'vro',
				'mediawiki' => 'en',
				'meta' => 'en',
				'no' => 'nb',
				'roa_rup' => 'rup',
				'simple' => 'en',
				'species' => 'en',
				'wikidata' => 'en',
				'zh_classical' => 'lzh',
				'zh_min_nan' => 'nan',
				'zh_yue' => 'yue',
			] ;
			if ( isset( $lang_map[ $lang ] ) ) $lang = $lang_map[ $lang ] ;
			$data['labels'] = [ $lang => [ 'language' => $lang , 'value' => $nice_title ] ] ;
		}
//		print "<pre>" ; print_r ( json_encode ( $data ) ) ; print " </pre>" ; return true ;

		$params = [
			'format' => 'json',
			'action' => 'wbeditentity',
			'new' => 'item' ,
			'data' => json_encode ( $data ) ,
			'token' => $token,
			'bot' => 1
		] ;
		
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $params ) ; print "</pre>" ;
		}
		
		$res = $this->doApiQuery( $params , $ch );

		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) return false ;

		$this->sleepAfterEdit ( 'create' ) ;

		return true ;
	}

	function removeClaim ( $id , $baserev ) {
		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [removeClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
	
	
	
		// Now do that!
		$params = [
			'format' => 'json',
			'action' => 'wbremoveclaims',
			'claim' => $id ,
			'token' => $token,
			'bot' => 1
		] ;
		if ( isset ( $baserev ) and $baserev != '' ) $params['baserevid'] = $baserev ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;


		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->sleepAfterEdit ( 'edit' ) ;
		
		return true ;
	}
	
	
	function genericAction ( $j , $summary = '' ) {
		if ( is_array($j) ) $j = (object) $j ;
		if ( !isset($j->action) ) { // Paranoia
			$this->error = "No action in " . json_encode ( $j ) ;
			return false ;
		}
		
		
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [genericAction]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}

		$j->token = $res->query->tokens->csrftoken;
		$j->format = 'json' ;
		$j->bot = 1 ;
		
		$params = [] ;
		foreach ( $j AS $k => $v ) $params[$k] = $v ;


		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = ($summary!='') ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( $summary != '' ) $params['summary'] = $summary ;
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "!!!!!<pre>" ; print_r ( $params ) ; print "</pre>" ;
		}

		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		if ( $j->action == 'wbeditentity' and isset($j->{'new'}) ) $this->sleepAfterEdit ( 'create' ) ;
		else $this->sleepAfterEdit ( 'edit' ) ;

		return true ;
	}


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


# Function to merge author claims where duplicates have been entered on a single work
    function merge_authors ($work_qid, $author_numbers, $remove_claims, $edit_summary) {
	// Fetch edit token
	$ch = null;
	$res = $this->doApiQuery( [
		'format' => 'json',
		'action' => 'query' ,
		'meta' => 'tokens'
	], $ch );
	if ( !isset( $res->query->tokens->csrftoken ) ) {
		$this->error = 'Bad API response [createItemFromPage]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
		return false ;
	}
	$token = $res->query->tokens->csrftoken;

	// Fetch work
	$work_item = $this->doApiQuery( [
		'format' => 'json',
		'action' => 'wbgetentities' ,
		'ids' => $work_qid,
		'redirects' => 'no'
	], $ch )->entities->$work_qid;

	$baserev = $work_item->lastrevid;

	$commands = array();

	$author_claims = isset($work_item->claims->P50) ? $work_item->claims->P50 : [] ;
	$ordered_author_claims = array();

	foreach ( $author_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$ordinals = $c->qualifiers->P1545 ;
			foreach ($ordinals AS $tmp) {
				$num = $tmp->datavalue->value ;
				if ( ! isset($ordered_author_claims[$num]) ) {
					$ordered_author_claims[$num] = [] ;
				}
				$ordered_author_claims[$num][] = $c ;
			}
		}
	}

	$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
	$ordered_author_name_claims = array();
	foreach ( $author_name_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$ordinals = $c->qualifiers->P1545 ;
			foreach ($ordinals AS $tmp) {
				$num = $tmp->datavalue->value ;
				if ( ! isset($ordered_author_name_claims[$num]) ) {
					$ordered_author_name_claims[$num] = [] ;
				}
				$ordered_author_name_claims[$num][] = $c ;
			}
		}
	}

	foreach ( $author_numbers AS $num ) {
		$author_claims = [];
		if (isset($ordered_author_claims[$num])) {
			$author_claims = $ordered_author_claims[$num];
		}
		$author_name_claims = [];
		if (isset($ordered_author_name_claims[$num])) {
			$author_name_claims = $ordered_author_name_claims[$num];
		}
		$commands = array_merge($commands,
			$this->single_index_merge($work_item, $author_claims,
				$author_name_claims));
	}
# Remove additional claims supplied in args:
	foreach ( $remove_claims AS $claim_id ) {
		$commands[] = ['id' => $claim_id, 'remove' => ''] ;
	}

	$data['claims'] = $commands;
	$params = [
		'format' => 'json',
		'action' => 'wbeditentity',
		'id' => $work_qid ,
		'data' => json_encode ( $data ) ,
		'token' => $token
	] ;
	if ( isset ( $baserev ) and $baserev != '' ) $params['baserevid'] = $baserev ;
	if ( isset($edit_summary) and $edit_summary != '' ) $params['summary'] = $edit_summary ;

	$res = $this->doApiQuery( $params , $ch );
	if ( isset ( $res->error ) ) {
		$this->error = $res->error->code;
		return false ;
	}

	return true;
    }

    function single_index_merge($work_item, $author_claims, $author_name_claims) {
	$commands = array();
	$old_commands = array();
	$work_qid = $work_item->id;
	if (count($author_claims) > 0) {
		$save_claim = NULL;
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_claims AS $i => $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			if ($i == 0) {
				$save_claim = $c ;
				if (isset($c->qualifiers)) {
					$save_quals = (array) $c->qualifiers;
				}
				if (isset($c->references)) {
					$save_refs = $c->references;
				}
			} else {
				$commands[] = ['id' => $c->id, 'remove' => ''] ;
			}
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
		}
		foreach ( $author_name_claims AS $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
			$author_name = $c->mainsnak->datavalue->value ;
			$new_quals = $this->merge_qualifiers($new_quals, ['P1932' => [['snaktype' => 'value', 'property' => 'P1932', 'datavalue' => ['value'=> $author_name, 'type' => 'string'], 'datatype' => 'string']]]) ;
			$commands[] = ['id' => $c->id, 'remove' => ''] ;
		}
		
		$changed = false ;
		if (count($new_quals) > 0) {
			$changed = true;
			$save_claim->qualifiers = $new_quals;
		}
		if ( count($new_refs) > 0 ) {
			$changed = true;
			$save_claim->references = $new_refs;
		}
		if ( $changed ) {
			$commands[] = $save_claim;
		}
	} else {
		$max_len = 0;
		$save_claim = NULL;
		foreach ( $author_name_claims AS $claim ) {
			$author_name = $claim->mainsnak->datavalue->value ;
			$len = strlen($author_name);
			if ($len > $max_len) {
				$max_len = $len;
				$save_claim = $claim;
			}
		}
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_name_claims AS $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			if ($c->id != $save_claim->id) {
				$commands[] = ['id' => $c->id, 'remove' => ''] ;
			}
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
		}

		$changed = false ;
		if (count($new_quals) > 0) {
			$changed = true;
			$save_claim->qualifiers = $new_quals;
		}
		if ( count($new_refs) > 0 ) {
			$changed = true;
			$save_claim->references = $new_refs;
		}
		if ( $changed ) {
			$commands[] = $save_claim;
		}
	}
	return $commands;
    }

	function merge_qualifiers($cq, $qualifiers) {
		foreach( $qualifiers AS $qual_prop => $qual_list ) {
			if (isset($cq[$qual_prop]) ) {
				$current_list = $cq[$qual_prop];
				foreach ( $qual_list AS $new_qual ) {
					$new_values = $this->value_from_qualifier($new_qual);
					$match = false;
					foreach ( $current_list AS $old_qual ) {
						$old_values = $this->value_from_qualifier($old_qual);
						$values_diff = array_diff_assoc($old_values, $new_values);
						if (count($values_diff) == 0) {
							$match = true;
							break;
						}
					}
					if (! $match) {
						$current_list[] = $new_qual;
					}
				}
				$cq[$qual_prop] = $current_list;
			} else {
				$cq[$qual_prop] = $qual_list;
			}
		}
		return $cq;
	}


// The following assumes hashes have been pre-caculated for all references
	function merge_references($cr, $references) {
		$current_ref_hashes = [];
		foreach( $cr AS $reference ) {
			$current_ref_hashes[$reference->hash] = 1;
		}
		foreach( $references AS $reference ) {
			$hash = $reference->hash;
			if (isset($current_ref_hashes[$hash])) {
				continue;
			}
			$current_ref_hashes[$hash] = 1;
			$cr[] = $reference;
		}
		return $cr;
	}

	function value_from_qualifier($q) {
		$ret = [];
		if (is_array($q) ) {
			$ret = (array) $q['datavalue']['value'];
		} else {
			$ret = (array) $q->datavalue->value;
		}
		return $ret;
	}

# Function to renumber (set series ordinal) for author claims per user request
	function renumber_authors( $work_qid, $renumbering, $remove_claims, $edit_summary ) {
	// Fetch edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createItemFromPage]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

	// Fetch work
		$work_item = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'wbgetentities' ,
			'ids' => $work_qid,
			'redirects' => 'no'
		], $ch )->entities->$work_qid;

		$baserev = $work_item->lastrevid;

		$commands = array();
		$author_claims = isset($work_item->claims->P50) ? $work_item->claims->P50 : [] ;
		foreach ( $author_claims AS $c ) {
			$new_cmd = $this->renumber_claim($c, $renumbering);
			if ($new_cmd != NULL) {
				$commands[] = $new_cmd;
			}
		}
		$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
		foreach ( $author_name_claims AS $c ) {
			$new_cmd = $this->renumber_claim($c, $renumbering);
			if ($new_cmd != NULL) {
				$commands[] = $new_cmd;
			}
		}
# Remove additional claims supplied in args:

		foreach ( $remove_claims AS $claim_id ) {
			$commands[] = ['id' => $claim_id, 'remove' => ''] ;
		}

		$data['claims'] = $commands;
		$params = [
			'format' => 'json',
			'action' => 'wbeditentity',
			'id' => $work_qid ,
			'data' => json_encode ( $data ) ,
			'token' => $token
		] ;
		if ( isset ( $baserev ) and $baserev != '' ) $params['baserevid'] = $baserev ;
		if ( isset($edit_summary) and $edit_summary != '' ) $params['summary'] = $edit_summary ;

		$res = $this->doApiQuery( $params , $ch );
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->code;
			return false ;
		}

		return true;
	}

	function renumber_claim($c, $renumbering) {
		if ( ! isset($renumbering[$c->id] ) ) return NULL;
		$new_num = $renumbering[$c->id];
		if ($new_num == '') return NULL;
		$new_qualifier_entry = [['snaktype' => 'value', 'property' => 'P1545', 'datavalue' => ['value'=> $new_num, 'type' => 'string'], 'datatype' => 'string']] ;
		if ( isset($c->qualifiers) ) {
			if ( isset($c->qualifiers->P1545) ) {
				$ordinals = $c->qualifiers->P1545 ;
				foreach ($ordinals AS $tmp) {
					$old_num = $tmp->datavalue->value ;
					if ($old_num == $new_num) return NULL;
				}
			}
			$c->qualifiers->P1545 = $new_qualifier_entry;
		} else {
			$c->qualifiers = ['P1545' => $new_qualifier_entry];
		}
		return $c;
	}

	function match_authors( $work_qid, $matches, $edit_summary ) {
	// Fetch edit token
		$ch = null;
		$res = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		], $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createItemFromPage]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

	// Fetch work
		$work_item = $this->doApiQuery( [
			'format' => 'json',
			'action' => 'wbgetentities' ,
			'ids' => $work_qid,
			'redirects' => 'no'
		], $ch )->entities->$work_qid;

		$baserev = $work_item->lastrevid;

		$auth_qid_by_ordinal = array();
		$ordinal_used = array();
		foreach ($matches AS $match) {
			$parts = array();
			$author_qid = NULL;
			if (preg_match('/^(Q\d+):(\d+)/', $match, $parts)) {
				$author_qid = $parts[1];
				$num = $parts[2];
			} else {
				$this->error = "ERROR: bad input data '$match'" ;
				return false;
			}
			if (isset($ordinal_used[$num])) {
				$this->error = "ERROR: duplicate ordinal '$num'" ;
				return false;
			} else {
				$ordinal_used[$num] = 1;
				$auth_qid_by_ordinal[$num] = $author_qid;
			}
		}
		$commands = array();
		$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
		foreach ( $author_name_claims AS $c ) {
			if ( isset($c->qualifiers) ) {
				if ( isset($c->qualifiers->P1545) ) {
					$ordinals = $c->qualifiers->P1545 ;
					foreach ($ordinals AS $tmp) {
						$num = $tmp->datavalue->value ;
						if (isset($auth_qid_by_ordinal[$num])) {
							$new_cmds = $this->change_name_to_author_claim($c, $num, $auth_qid_by_ordinal[$num]);
							$commands = array_merge($commands, $new_cmds);
						}
					}
				}
			}
		}

		$data['claims'] = $commands;
		$params = [
			'format' => 'json',
			'action' => 'wbeditentity',
			'id' => $work_qid ,
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

	function change_name_to_author_claim($c, $num, $author_qid) {
		$commands = array();
		$numeric_id = 0;
		$parts = array();
		if (preg_match('/^Q(\d+)$/', $author_qid, $parts)) {
			$numeric_id = intval($parts[1]);
		}
		
		$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
		$refs = isset($c->references) ? $c->references : [] ;
		$author_name = $c->mainsnak->datavalue->value ;
		$new_quals = $this->merge_qualifiers($quals, ['P1932' => [['snaktype' => 'value', 'property' => 'P1932', 'datavalue' => ['value'=> $author_name, 'type' => 'string'], 'datatype' => 'string']]]) ;
		$new_claim = ['mainsnak' => ['snaktype' => 'value', 'property' => 'P50', 'datatype' => 'wikibase-item', 'datavalue' => ['value' => ['entity-type' => 'item', 'id' => $author_qid, 'numeric-id' => $numeric_id],  'type' => 'wikibase-entityid']], 'type' => 'statement', 'rank' => 'normal'];
		$new_claim['qualifiers'] = $new_quals;
		$new_claim['references'] = $refs;
		$commands[] = $new_claim ;
		$commands[] = ['id' => $c->id, 'remove' => ''] ;
		return $commands;
	}
}

?>
