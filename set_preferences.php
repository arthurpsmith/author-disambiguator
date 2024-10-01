<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $db_conn);
$oauth->interactive = true;

$action = get_request ( 'action' , '' ) ;
if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'work_item_oauth.php');
	$db_conn->close();
	exit(0);
}

print disambig_header( True );

$username = NULL;
if ($oauth->isAuthOK()) {
	$username = $oauth->userinfo->name;
	print "Wikimedia user account: $username" ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	$db_conn->close();
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
	print_footer() ;
	exit ( 0 ) ;
}

$preferences = new Preferences();

if ($action == 'set') {
	$use_main_subgraph = get_request('use_main_subgraph', 0);
	$preferences->set_subgraph_selection(! $use_main_subgraph);
	$subgraph_checked = $use_main_subgraph ? 'checked' : '' ;
}

$db_conn->close();

print "<hr>";
print "<h3> Preferences </h3>";

print "<form method='post' class='form'>" ;
print "<ol><li>";
print 'In late 2024 the <a href="https://www.wikidata.org/wiki/Wikidata:SPARQL_query_service/WDQS_graph_split">Wikidata graph was split</a> into "scholarly" and "main" collections. This application uses extensive queries of the Wikidata graph for information about authorship. By default it will limit queries regarding authored items to the scholarly subgraph. If you check this option it will query the main subgraph instead, showing only authorship of non-scholarly items. At this time querying both graphs simultaneously is not supported.<br>';

$subgraph_checked = $preferences->use_scholarly_subgraph ? '' : 'checked' ;
print "<input type='hidden' name='action' value='set' />";
print "<label title='set subgraph preference' style='margin:10px'><input type='checkbox' name='use_main_subgraph' value='1' $subgraph_checked />Use main subgraph?</label>";
print "</li></ol>";
print "<input type='submit' class='btn btn-primary' name='doit' value='Set preferences' /></form>" ;

print_footer() ;

?>
