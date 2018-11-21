<?PHP

# declare(strict_types=1); # PHP7

/*
error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');

ini_set('memory_limit','500M');
set_time_limit ( 60 * 10 ) ; // Seconds
*/

//define('CLI', PHP_SAPI === 'cli');
//if ( !isset($noheaderwhatsoever) ) header("Connection: close");

/*
require_once ( '/data/project/magnustools/public_html/php/ToolforgeCommon.php' ) ;
$tfc = new ToolforgeCommon('mytoolname') ;
*/

final class ToolforgeCommon {

	public /*string*/ $toolname ;
	public $prefilled_requests = [] ;
	public /*string*/ $tool_user_name ; # force different DB user name
	public $use_db_cache = true ;

	private $browser_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:57.0) Gecko/20100101 Firefox/57.0" ;
	private	$db_servers = [
			'fast' => '.web.db.svc.eqiad.wmflabs' ,
			'slow' => '.analytics.db.svc.eqiad.wmflabs' ,
			'old' => '.labsdb'
		] ;
	
	private $cookiejar ; # For doPostRequest
	private/*string*/  $mysql_user , $mysql_password ;
	private $db_cache = [] ;
	
	public function __construct ( /*string*/ $toolname = '' ) {
		if ( $toolname != '' ) $this->toolname = $toolname ;
		else $this->toolname = $this->determineToolname() ;
		assert ( $this->toolname != '' , 'Toolname is empty' ) ;
		ini_set('user_agent','Toolforge - '.$this->toolname); # Fake user agent
	}
	
	private function determineToolname () /*:string*/ {
		$toolname = basename($_SERVER["SCRIPT_FILENAME"], '.php') ;
		if ( $toolname == '' or $toolname == 'index' or preg_match ( '/^api/' , $toolname ) ) {
			$toolname = preg_replace ( '/^.+\.org\//' , '' , $_SERVER["SCRIPT_FILENAME"] ) ;
			$toolname = preg_replace ( '/\/.*$/' , '' , $toolname ) ;
		}
		return $toolname ;
	}

// CONVENIENCE

	function getRequest ( $key , $default = "" ) /*:string*/ {
		if ( isset ( $this->prefilled_requests[$key] ) ) return $this->prefilled_requests[$key] ;
		if ( isset ( $_REQUEST[$key] ) ) return str_replace ( "\'" , "'" , $_REQUEST[$key] ) ;
		return $default ;
	}
	
	function urlEncode ( $t ) /*:string*/ {
		$t = str_replace ( " " , "_" , $t ) ;
		$t = urlencode ( $t ) ;
		return $t ;
	}
	
	function escapeAttribute ( $s ) /*:string*/ {
		$ret = preg_replace ( "/\"/" , '&quot;' , $s ) ;
		$ret = preg_replace ( "/'/" , '&apos;' , $ret ) ;
		return $ret ;
	}

	function flush () /*:void*/ {
		@ob_flush();
		flush();
	}

	function getCurrentTimestamp () /*:string*/ {
		return date ( 'YmdHis' ) ;
	}


// Toolforge/Wikimedia name conversions

	function getWebserverForWiki ( $wiki ) /*:string*/ {
		$wiki = preg_replace ( '/_p$/' , '' , $wiki ) ; // Paranoia
		if ( $wiki == 'commonswiki' ) return "commons.wikimedia.org" ;
		if ( $wiki == 'wikidatawiki' ) return "www.wikidata.org" ;
		if ( $wiki == 'specieswiki' ) return "species.wikimedia.org" ;
		if ( $wiki == 'metawiki' ) return "meta.wikimedia.org" ;
		$wiki = preg_replace ( '/_/' , '-' , $wiki ) ;
		if ( preg_match ( '/^(.+)wiki$/' , $wiki , $m ) ) return $m[1].".wikipedia.org" ;
		if ( preg_match ( '/^(.+)(wik.+)$/' , $wiki , $m ) ) return $m[1].".".$m[2].".org" ;
		return '' ;
	}

	function trimWikitextMarkup ( $wikitext ) /*:string*/ {
		$wikitext = preg_replace ( '/\[\[(.+?)[\#\|].*?\]\]/' , '$1' , $wikitext ) ;
		$wikitext = preg_replace ( '/\[\[(.+?)\]\]/' , '$1' , $wikitext ) ;
		$wikitext = preg_replace ( '/\{\{(.+?)\}\}/' , '' , $wikitext ) ;
		$wikitext = preg_replace ( '/<.*?>/' , '' , $wikitext ) ;
		return $wikitext ;
	}

	function getWikiPageText ( $wiki , $page ) /*:string*/ {
		$server = $this->getWebserverForWiki ( $wiki ) ;
		$url = "https://{$server}/w/api.php?action=parse&prop=wikitext&format=json&page=" . $this->urlEncode ( $page ) ;
		$json = json_decode ( @file_get_contents ( $url ) ) ;
		if ( !isset($json) or $json === null ) return '' ;
		if ( !isset($json->parse) ) return '' ;
		if ( !isset($json->parse->wikitext) ) return '' ;
		$star = '*' ;
		if ( !isset($json->parse->wikitext->$star) ) return '' ;

		$ret = $json->parse->wikitext->$star ;
		$ret = @iconv('UTF-8', 'UTF-8//IGNORE', $ret) ;
//		print "$ret\n" ; exit(0) ;
		return $ret ;
	}

	function getWikiForLanguageProject ( $language , $project ) /*:string*/ {
		$language = trim ( strtolower ( $language ) ) ;
		if ( $language == 'commons' ) return 'commonswiki' ;
		if ( $language == 'wikidata' ) return 'wikidatawiki' ;
		if ( $language == 'meta' ) return 'metawiki' ;
		if ( $project == 'wikipedia' ) return $language . 'wiki' ;
		return $language . $project . 'wiki' ; // I'm trying, OK?
	}

// DATABASE


	function getDBname ( $language , $project ) /*:string*/ {
		$ret = $language ;
		if ( $language == 'commons' ) $ret = 'commonswiki_p' ;
		elseif ( $language == 'wikidata' || $project == 'wikidata' ) $ret = 'wikidatawiki_p' ;
		elseif ( $language == 'mediawiki' || $project == 'mediawiki' ) $ret = 'mediawikiwiki_p' ;
		elseif ( $language == 'species' || $project == 'wikimedia' ) $ret = 'specieswiki_p' ;
		elseif ( $language == 'meta' && $project == 'wikimedia' ) $ret = 'metawiki_p' ;
		elseif ( $project == 'wikipedia' ) $ret .= 'wiki_p' ;
		elseif ( $project == 'wikisource' ) $ret .= 'wikisource_p' ;
		elseif ( $project == 'wiktionary' ) $ret .= 'wiktionary_p' ;
		elseif ( $project == 'wikibooks' ) $ret .= 'wikibooks_p' ;
		elseif ( $project == 'wikinews' ) $ret .= 'wikinews_p' ;
		elseif ( $project == 'wikiversity' ) $ret .= 'wikiversity_p' ;
		elseif ( $project == 'wikivoyage' ) $ret .= 'wikivoyage_p' ;
		elseif ( $project == 'wikiquote' ) $ret .= 'wikiquote_p' ;
		elseif ( $project == 'wikispecies' ) $ret = 'specieswiki_p' ;
		elseif ( $language == 'meta' ) $ret .= 'metawiki_p' ;
		else if ( $project == 'wikimedia' ) $ret .= $language.$project."_p" ;
		else die ( "Cannot construct database name for $language.$project - aborting." ) ;
		return $ret ;
	}

	private function getDBpassword () /*:string*/ {
		if ( isset ( $this->tool_user_name ) and $this->tool_user_name != '' ) $user = $this->tool_user_name ;
		else $user = str_replace ( 'tools.' , '' , get_current_user() ) ;
		$passwordfile = '/data/project/' . $user . '/replica.my.cnf' ;
		if ( $user == 'magnus' ) $passwordfile = '/home/' . $user . '/replica.my.cnf' ; // Command-line usage
		$config = parse_ini_file( $passwordfile );
		if ( isset( $config['user'] ) ) {
			$this->mysql_user = $config['user'];
		}
		if ( isset( $config['password'] ) ) {
			$this->mysql_password = $config['password'];
		}
	}

	public function openDBtool ( $dbname = '' , $server = '' , $force_user = '' , $persistent = false ) {
		$this->getDBpassword() ;
		if ( $dbname == '' ) $dbname = '_main' ;
		else $dbname = "__$dbname" ;
		if ( $force_user == '' ) $dbname = $this->mysql_user.$dbname;
		else $dbname = $force_user.$dbname;
		if ( $server == '' ) $server = "tools.labsdb" ; //"tools-db" ;
		if ( $persistent ) $server = "p:$server" ;
		$db = @new mysqli($server, $this->mysql_user, $this->mysql_password , $dbname);
		assert ( $db->connect_errno == 0 , 'Unable to connect to database [' . $db->connect_error . ']' ) ;
		return $db ;
	}

	public function openDBwiki ( $wiki , $slow_queries = false , $persistent = false ) {
		preg_match ( '/^(.+)(wik.+)$/' , $wiki , $m ) ;
		assert ( $m !== null , "Cannot parse $wiki" ) ;
		if ( $m[2] == 'wiki' ) $m[2] = 'wikipedia' ;
		return $this->openDB ( $m[1] , $m[2] , $slow_queries , $persistent ) ;
	}

	public function openDB ( $language , $project , $slow_queries = false , $persistent = false ) {
		$db_key = "$language.$project" ;
		if ( !$persistent and isset ( $this->db_cache[$db_key] ) ) return $this->db_cache[$db_key] ;
	
		$this->getDBpassword() ;
		$dbname = $this->getDBname ( $language , $project ) ;

		# Try optimal server
		$server = substr( $dbname, 0, -2 ) . ( $slow_queries ? $this->db_servers['slow'] : $this->db_servers['fast'] ) ;
		if ( $persistent ) $server = "p:$server" ;
		$db = @new mysqli($server, $this->mysql_user, $this->mysql_password , $dbname);

		if ( $db->connect_errno > 0 and preg_match ( '/max_user_connections/' , $db->connect_error ) ) { // Bloody Toolforge DB connection limit
			$seconds = rand ( 10 , 60*10 ) ; // Random delay
			sleep ( $seconds ) ;
			return $this->openDB ( $language , $project , $slow_queries , $persistent ) ;
		}
	
		# Try the other server
		if($db->connect_errno > 0 ) {
			$server = substr( $dbname, 0, -2 ) . ( $slow_queries ? $this->db_servers['fast'] : $this->db_servers['slow'] ) ;
			if ( $persistent ) $server = "p:$server" ;
			$db = @new mysqli($server, $this->mysql_user, $this->mysql_password , $dbname);
		}

		# Try the old server as fallback
		if($db->connect_errno > 0) {
			$server = substr( $dbname, 0, -2 ) . $this->db_servers['old'];
			if ( $persistent ) $server = "p:$server" ;
			$db = @new mysqli($server, $this->mysql_user, $this->mysql_password , $dbname);
		}
	
		assert ( $db->connect_errno == 0 , 'Unable to connect to database [' . $db->connect_error . ']' ) ;
		if ( !$persistent and $this->use_db_cache ) $this->db_cache[$db_key] = $db ;
		return $db ;
	}

	public function getSQL ( &$db , &$sql , $max_tries = 3 , $message = '' ) {
		while ( $max_tries > 0 ) {
			$pinging = 10 ;
			while ( !@$db->ping() ) {
				if ( $pinging < 0 ) break ;
	//			print "RECONNECTING..." ;
				sleep ( 1 ) ;
				@$db->connect() ;
				$pinging-- ;
			}
			if($ret = @$db->query($sql)) return $ret ;
			$max_tries-- ;
		}
		$e = new \Exception;
		var_dump($e->getTraceAsString());
		die ( 'There was an error running the query [' . $db->error . '/' . $db->errno . ']'."\n$sql\n$message\n" ) ;
	}

	public function findSubcats ( &$db , $root , &$subcats , $depth = -1 ) {
		$check = [] ;
		$c = [] ;
		foreach ( $root AS $r ) {
			if ( isset ( $subcats[$r] ) ) continue ;
			$subcats[$r] = $db->real_escape_string ( $r ) ;
			$c[] = $db->real_escape_string ( $r ) ;
		}
		if ( count ( $c ) == 0 ) return ;
		if ( $depth == 0 ) return ;
		$sql = "SELECT DISTINCT page_title FROM page,categorylinks WHERE page_id=cl_from AND cl_to IN ('" . implode ( "','" , $c ) . "') AND cl_type='subcat'" ;
		$result = $this->getSQL ( $db , $sql , 2 ) ;
		while($row = $result->fetch_assoc()){
			if ( isset ( $subcats[$row['page_title']] ) ) continue ;
			$check[] = $row['page_title'] ;
		}
		if ( count ( $check ) == 0 ) return ;
		$this->findSubcats ( $db , $check , $subcats , $depth - 1 ) ;
	}

	public function getPagesInCategory ( &$db , $category , $depth = 0 , $namespace = 0 , $no_redirects = false ) {
		$depth *= 1 ;
		$namespace *= 1 ;
		$ret = [] ;
		$cats = [] ;
		$category = str_replace ( ' ' , '_' , $category ) ;
		$this->findSubcats ( $db , [$category] , $cats , $depth ) ;

		if ( $namespace == 14 ) return $cats ; // Faster, and includes root category

		$namespace *= 1 ;
		$sql = "SELECT DISTINCT page_title FROM page,categorylinks WHERE cl_from=page_id AND page_namespace=$namespace AND cl_to IN ('" . implode("','",$cats) . "')" ;
		if ( $no_redirects ) $sql .= " AND page_is_redirect=0" ;

		$result = $this->getSQL ( $db , $sql , 2 ) ;
		while($o = $result->fetch_object()){
			$ret[$o->page_title] = $o->page_title ;
		}
		return $ret ;
	}



// INTERFACE

	private function loadCommonHeader () /*:string*/ {
		$dir = __DIR__ . '/../resources/html' ;
		$f1 = file_get_contents ( "$dir/index_bs4.html" ) ;
		$f2 = file_get_contents ( "$dir/menubar_bs4.html" ) ;
		
		assert ( isset($f1) and $f1 != '' ) ;
		assert ( isset($f2) and $f2 != '' ) ;

		$f1 = preg_replace ( '/<body>.*/ms' , "<body>\n" , $f1 ) ;
		$f1 = preg_replace ( '/<script src=".\/main.js"><\/script>\s*/' , '' , $f1 ) ;
		$f3 = '<div id="main_content" class="container"><div class="row"><div class="col-sm-12" style="margin-bottom:20px;margin-top:10px;">' ;

		return "$f1$f2$f3\n" ;
	}

	public function getCommonHeader ( $title = '' , $p = [] ) /*:string*/ {
		if ( $title == '' ) $title = ucfirst ( strtolower ( $this->toolname ) ) ;
		
		if ( !headers_sent() ) {
			header('Content-type: text/html; charset=UTF-8'); // UTF8 test
			header("Cache-Control: no-cache, must-revalidate");
		}
		$s = $this->loadCommonHeader() ;
		if ( isset ( $p['style'] ) ) $s = str_replace ( '</style>' , $p['style'].'</style>' , $s ) ;
		if ( isset ( $p['script'] ) ) $s = str_replace ( '</script>' , $p['script'].'</script>' , $s ) ;
		if ( isset ( $p['title'] ) ) $s = str_replace ( '</head>' , "<title>{$p['title']}</title></head>" , $s ) ;
	
		$misc = '' ;
		if ( isset ( $p['link'] ) ) $misc .= $p['link'] ;
		$s = str_replace ( '<!--header_misc-->' , $misc , $s ) ;
	
		$s = str_replace ( '$$TITLE$$' , $title , $s ) ;
		return $s ;
	}

	public function getCommonFooter () /*:string*/ {
		return "</div></div></body></html>" ;
	}

// CURL

	// Takes a URL and an array with POST parameters
	function doPostRequest ( $url , $params = [] , $optional_headers = null ) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiejar);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiejar);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->browser_agent);
		if ($optional_headers !== null) curl_setopt($ch, CURLOPT_HTTPHEADER, $optional_headers);
		$output = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		return $output ;
	}


	// Takes an array KEY=>URL, returns an array KEY=>PAGE_CONTENT
	public function getMultipleURLsInParallel ( $urls , $batch_size = 50 ) {
		$ret = [] ;
	
		$batches = [ [] ] ;
		foreach ( $urls AS $k => $v ) {
			if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
			$batches[count($batches)-1][$k] = $v ;
		}
	
		foreach ( $batches AS $batch_urls ) {
	
			$mh = curl_multi_init();
			curl_multi_setopt  ( $mh , CURLMOPT_PIPELINING , 1 ) ;
		//	curl_multi_setopt  ( $mh , CURLMOPT_MAX_TOTAL_CONNECTIONS , 5 ) ;
			$ch = [] ;
			foreach ( $batch_urls AS $key => $value ) {
				$ch[$key] = curl_init($value);
		//		curl_setopt($ch[$key], CURLOPT_NOBODY, true);
		//		curl_setopt($ch[$key], CURLOPT_HEADER, true);
				curl_setopt($ch[$key], CURLOPT_USERAGENT, $this->browser_agent);
				curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$key], CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch[$key], CURLOPT_SSL_VERIFYHOST, false);
				curl_multi_add_handle($mh,$ch[$key]);
			}
	
			do {
				curl_multi_exec($mh, $running);
				curl_multi_select($mh);
			} while ($running > 0);
	
			foreach(array_keys($ch) as $key){
				$ret[$key] = curl_multi_getcontent($ch[$key]) ;
				curl_multi_remove_handle($mh, $ch[$key]);
			}
	
			curl_multi_close($mh);
		}
	
		return $ret ;
	}

// Wikidata

	public function hasWikidataItemEverBeenEditedWithString ( $item , $string ) {
		$db = $this->openDB ( 'wikidata' , 'wikidata' , true , true ) ;
		$ns = 0 ;
		if ( preg_match ( '/^[pP]/',$item) ) $ns = 120 ;
		$item = $db->real_escape_string ( $item ) ;
		$string = $db->real_escape_string ( $string ) ;
		$sql = "SELECT * FROM page,revision_compat WHERE rev_comment LIKE '%{$string}%' AND rev_page=page_id AND page_namespace={$ns} AND page_title='{$item}' LIMIT 1" ;
		$result = $this->getSQL ( $db , $sql ) ;
		while($row = $result->fetch_assoc()) return true ;
		return false ;
	}


// SPARQL

	// Takes a SPARQL query, adds the tool name (for reacking at query server), returns the decoded JSON result
	public function getSPARQL ( $cmd ) {
		$sparql = "$cmd\n#TOOL: {$this->toolname}" ;

		$ctx = stream_context_create(['http'=>
			['timeout' => 1200]  //1200 seconds is 20 minutes
		]);

		$url = "https://query.wikidata.org/sparql?format=json&query=" . urlencode($sparql) ;
		$fc = @file_get_contents ( $url , false , $ctx ) ;
	
		// Catch "wait" response, wait 5, retry
		if ( preg_match ( '/429/' , $http_response_header[0] ) ) {
			sleep ( 5 ) ;
			return $this->getSPARQL ( $cmd ) ;
		}
		
		assert ( $fc !== false , 'SPARQL query failed: '.$sparql ) ;

		if ( $fc === false ) return ; // Nope
		return json_decode ( $fc ) ;
	}

	public function parseItemFromURL ( $url ) /*:string*/ {
		return preg_replace ( '/^.+([A-Z]\d+)$/' , '$1' , $url ) ;
	}

	// Returns an array of strings, usually Q IDs
	public function getSPARQLitems ( $cmd , $varname = '' ) {
		$ret = [] ;
		$j = $this->getSPARQL ( $cmd ) ;
		if ( !isset($j) or !isset($j->head) ) return $ret ;
		if ( $varname == '' ) $varname = $j->head->vars[0] ;
		if ( !isset($j->results) or !isset($j->results->bindings) or count($j->results->bindings) == 0 ) return $ret ;
		foreach ( $j->results->bindings AS $v ) {
			$ret[] = $this->parseItemFromURL ( $v->$varname->value ) ;
		}
		$ret = array_unique ( $ret ) ;
		return $ret ;
	}

// QuickStatements
// require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;

	// Will use the first *.conf config file in tools directory, unless specified
	public function getQS ( $toolname , $config_file = '' , $useTemporaryBatchID = false ) {
		if ( $config_file == '' ) $config_file = $this->guessConfigFile() ;
		if ( $config_file == '' ) die ( "Can't determine QS config file location" ) ;
		$qs = new QuickStatements() ;
		$qs->use_oauth = false ;
		$qs->bot_config_file = $config_file ;
		$qs->toolname = $toolname ;
		$qs->sleep = 1 ;
		$qs->use_command_compression = true ;
		if ( $useTemporaryBatchID ) $qs->generateAndUseTemporaryBatchID() ;
		$this->qs = $qs ;
		return $qs ;
	}

	// $commands as array
	// Returns last (created) item, or undefined
	public function runCommandsQS ( $commands , $qs = '' ) {
		if ( count($commands) == 0 ) return ;
		if ( $qs == '' ) $qs = $this->qs ;
		$commands = implode ( "\n" , $commands ) ;
		$tmp_uncompressed = $qs->importData ( $commands , 'v1' ) ;
		$tmp['data']['commands'] = $qs->compressCommands ( $tmp_uncompressed['data']['commands'] ) ;
		$qs->runCommandArray ( $tmp['data']['commands'] ) ;
		if ( !isset($qs->last_item) ) return ;
		$last_item = 'Q' . preg_replace ( '/\D/' , '' , "{$qs->last_item}" ) ;
		return $last_item ;
	}

	private function guessConfigFile () /*:string*/ {
		if ( preg_match ( '/(\/mnt\/nfs\/labstore-secondary-tools-project\/[^\/]+)/' , getcwd() , $m ) ) {
			$dir = $m[1] ;
			$files = scandir ( $dir ) ;
			foreach ( $files AS $file ) {
				if ( preg_match ( '/^[^\.].*\.conf$/' , $file ) ) return "{$dir}/{$file}" ;
			}
		}
		return '' ; // No luck
	}


} ;


?>
