<?PHP

define('CLI', PHP_SAPI === 'cli');
ini_set('user_agent','Magnus labs tools'); # Fake user agent
if ( !isset($noheaderwhatsoever) ) header("Connection: close");
$tools_webproxy = 'tools-webproxy' ;
$tusc_url = "http://$tools_webproxy/tusc/tusc.php" ; // http://tools-webserver-01/ // tools.wmflabs.org
$use_db_cache = false ;
$common_db_cache = [] ;
$wdq_internal_url = 'http://wdq.wmflabs.org/api' ; // 'http://wikidata-wdq-mm.eqiad.wmflabs/api'
$pagepile_enabeled = true ; //isset($_REQUEST['pagepile_enabeled']) ;
$maxlag = 5 ; // https://www.mediawiki.org/wiki/Manual:Maxlag_parameter

$petscan_note = "<div style='margin:3px;padding:3px;text-align:center;background-color:#d9edf7;'>Please try <a href='https://petscan.wmflabs.org/'>PetScan</a>, the designated successor to this tool!</div>" ;
$petscan_note2 = "<div style='margin:3px;padding:3px;text-align:center;background-color:#FF4848;font-size:12pt;'><b>This tool will be replaced with <a href='https://petscan.wmflabs.org/'>PetScan</a> in the next few days!</b><br/>" ;
$petscan_note2 .= "All links to this tool with URL parameters will be forwarded to PetScan, and should remain functional.<br/>" ;
$petscan_note2 .= "Please add any blocking issues to the <a href='https://bitbucket.org/magnusmanske/petscan/issues?status=new&status=open'>PetScan bug tracker</a>.</div>" ;


// LEGACY WRAPPER FUNCTIONS AROUND ToolforgeCommon METHODS TO AVOID CODE DUPLICATION
require_once ( __DIR__ . '/ToolforgeCommon.php' ) ;
$wrapper_tfc = new ToolforgeCommon ( 'legacy code' );
$wrapper_tfc->use_db_cache = false ; # Some scripts close the DB

# Misc
function myurlencode ( $t ) { global $wrapper_tfc ; return $wrapper_tfc->urlEncode ( $t ) ; }
function getWebserverForWiki ( $wiki ) { global $wrapper_tfc ; return $wrapper_tfc->getWebserverForWiki ( $wiki ) ; }
function escape_attribute ( $s ) { global $wrapper_tfc ; return $wrapper_tfc->escapeAttribute ( $s ) ; }
function myflush () { global $wrapper_tfc ; return $wrapper_tfc->flush() ; }
function get_common_header ( $script , $title = '' , $p = [] ) { global $wrapper_tfc ; return $wrapper_tfc->getCommonHeader ( $title , $p ) ; }
function get_common_footer () { global $wrapper_tfc ; return $wrapper_tfc->getCommonFooter() ; }
function get_request ( $key , $default = "" ) {  global $wrapper_tfc , $prefilled_requests ; $wrapper_tfc->prefilled_requests = $prefilled_requests ; return $wrapper_tfc->getRequest ( $key , $default ) ; }

# Database access
function openToolDB ( $dbname = '' , $server = '' , $force_user = '' ) { global $wrapper_tfc ; return $wrapper_tfc->openDBtool($dbname,$server,$force_user) ; }
function openDBwiki ( $wiki , $slow_queries = false ) { global $wrapper_tfc ; return $wrapper_tfc->openDBwiki ( $wiki , $slow_queries ) ; }
function openDB ( $language , $project , $slow_queries = false ) { global $wrapper_tfc ; return $wrapper_tfc->openDB ( $language , $project , $slow_queries ) ; }
function getSQL ( &$db , &$sql , $max_tries = 2 , $message = '' ) { global $wrapper_tfc ; return $wrapper_tfc->getSQL ( $db , $sql , $max_tries , $message ) ; }
function getPagesInCategory ( $db , $category , $depth = 0 , $namespace = 0 , $no_redirects = false ) { global $wrapper_tfc ; return $wrapper_tfc->getPagesInCategory ( $db , $category , $depth , $namespace , $no_redirects ) ; }

# SPARQL
function getSPARQL ( $cmd ) { global $wrapper_tfc ; return $wrapper_tfc->getSPARQL ( $cmd ) ; }
function getSPARQLitems ( $cmd , $varname = 'q' ) {
	global $wrapper_tfc ;
	$items = $wrapper_tfc->getSPARQLitems ( $cmd , $varname ) ;
	foreach ( $items AS $k => $v ) $items[$k] = preg_replace ( '/\D/' , '' , $v ) ; # Legacy function returns numeric-only, ToolforgeCommon method returns Qxxx, need to rewrite
	return $items ;
}

# Misc
function get_server_for_lp ( $lang , $project ) { 	global $wrapper_tfc ; return $wrapper_tfc->getWebserverForWiki ( $wrapper_tfc->getWikiForLanguageProject ( $lang , $project ) ) ; }
function fix_language_code ( $s ) { return strtolower ( preg_replace ( '/[^a-z-]/' , '' , $s ) ) ; }
function check_project_name ( $s ) { return strtolower ( preg_replace ( '/[^a-z]/' , '' , $s ) ) ; }
function pre ( $d ) { print "<pre>" ; print_r ( $d ) ; 	print "</pre>" ; }
function strip_html_comments ( &$text ) { return preg_replace( '?<!--.*-->?msU', '', $text); }
function get_image_url ( $lang , $image , $project = "wikipedia" ) { return "//".get_server_for_lp($lang,$project)."/wiki/Special:Redirect/file/".myurlencode($image); }
function get_thumbnail_url ( $lang , $image , $width , $project = "wikipedia" ) { return "//".get_server_for_lp($lang,$project)."/wiki/Special:Redirect/file/".myurlencode($image)."?width={$width}"; }
function get_wikipedia_url ( $lang , $title , $action = "" , $project = "wikipedia" ) { global $wrapper_tfc; return "https://".get_server_for_lp($lang,$project)."/w/index.php?title=".$wrapper_tfc->urlEncode($title).($action==''?'':"&action={$action}") ; }
function make_db_safe ( &$s , $fixup = false ) { $s = get_db_safe ( $s , $fixup ) ; }
function get_db_safe ( $s , $fixup = false ) {
	global $db ;
	if ( $fixup ) $s = str_replace ( ' ' , '_' , trim ( ucfirst ( $s ) ) ) ;
	if ( !isset($db) ) return addslashes ( str_replace ( ' ' , '_' , $s ) ) ;
	return $db->real_escape_string ( str_replace ( ' ' , '_' , $s ) ) ;
}

/**
 * Returns the raw text of a wikipedia page, trimmed and with html comments removed
 * Returns empty string if something went wrong
 */
function get_wikipedia_article ( $lang , $title , $allow_redirect = true , $project = "wikipedia" , $remove_comments = true) {
	global $wrapper_tfc ;
	$wiki = $wrapper_tfc->getWikiForLanguageProject ( $lang , $project ) ;
	$text = $wrapper_tfc->getWikiPageText ( $wiki , $title ) ;
	# TODO check redirect?
	if ( $remove_comments ) $text = strip_html_comments ( $text ) ;
	return trim ( $text ) ;
}


?>