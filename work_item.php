<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
if (limit_requests( $db_conn, 10 ) ) {
	$db_conn->close();

	$oauth_url = str_replace('/work_item.php', '/work_item_oauth.php', $_SERVER['REQUEST_URI']);

	print disambig_header( False );
	print "<h1>Too many requests</h1>";
	print "Please wait before making another request of this service; note that use of <a href='$oauth_url'>the OAuth option</a> is not rate-limited.";
	print_footer() ;
	exit ( 0 ) ;
}
$db_conn->close();

$action = get_request ( 'action' , '' ) ;
$work_qid = get_request( 'id', '' ) ;

print disambig_header( False );
print "<div style='font-size:9pt'>(<a href='work_item_oauth.php?id=$work_qid'> Log in to your Wikimedia account to use OAuth instead of Quickstatements for updates.</a>) </div> " ;
print "<hr>";

print "<form method='get' class='form form-inline'>
Work Wikidata ID: 
<input name='id' value='" . escape_attribute($work_qid) . "' type='text' placeholder='Qxxxxx' />
<input type='submit' class='btn btn-primary' name='doit' value='Get author links for work' />
</form>" ;

if ( $work_qid == '' ) {
	print_work_example();
	print_footer() ;
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;

if ( $action == 'add' ) {
	print "<form method='post' class='form' action='$quickstatements_api_url'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;

	$author_numbers = get_request ( 'merges' , array() ) ;

	$commands = merge_authors_qs_commands ( $wil, $work_qid, $author_numbers ) ;

	print "<div>Quickstatements V1 commands for merging author name strings with author items on this work:<div>" ;

	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea></div>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;

	print "</form></div><div>" ;

	print_footer() ;
	exit ( 0 ) ;
}


$article_entry = generate_article_entries2( [$work_qid] ) [ $work_qid ];

// Load items
$to_load = array() ;
$to_load[] = $work_qid ;

foreach ( $article_entry->authors AS $auth_list ) {
	foreach ( $auth_list AS $auth ) {
		$to_load[] = $auth ;
	}
}
foreach ( $article_entry->published_in AS $pub ) $to_load[] = $pub ;
foreach ( $article_entry->topics AS $topic ) $to_load[] = $topic ;

$to_load = array_unique( $to_load );
$wil->loadItems ( $to_load ) ;

$work_item = $wil->getItem ( $work_qid ) ;

# Regenerate article entry directly from item:
$article_entry = new WikidataArticleEntry2( $work_item );

if ( !isset($work_item) )  {
	print "<h2>Warning: $work_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}

print "<h2>" . $work_item->getLabel() . "</h2>" ;
print "<div>" ;
print wikidata_link($work_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://scholia.toolforge.org/work/$work_qid'>Scholia Work Page</a>" ;
print ' | ' ;
print "<a target='_blank' href='$reasonator_prefix$work_qid'>Reasonator</a>" ;
print ' | ' ;
print "<a target='_blank' href='$sqid_prefix$work_qid'>SQID</a>" ;
print '</div><div>' ;
print "Published: " .  $article_entry->formattedPublicationDate () . "; " ;
if ( $article_entry->doi != '' ) {
	print "DOI: <a target='_blank' href='https://doi.org/$article_entry->doi'>$article_entry->doi</a>; " ;
}
if ($article_entry->pmid != '' ) {
	print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article_entry->pmid'>$article_entry->pmid</a>" ;
}
print '</div><div>' ;

$published_in = array() ;
foreach ( $article_entry->published_in AS $qt ) {
	$i2 = $wil->getItem ( $qt ) ;
	if ( isset($i2) ) $published_in[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . "&nbsp;[<a href='https://scholia.toolforge.org/venue/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
}
$published_in_list = implode ( ', ', $published_in ) ;
print "Journal(s): $published_in_list" ;
if ( count($article_entry->topics) > 0 ) {
	print '</div><div>' ;
	print "Main subject(s): ";
	$topics = [] ;
	foreach ( $article_entry->topics AS $qt ) {
		$i2 = $wil->getItem($qt) ;
		if ( !isset($i2) ) continue ;
		$topics[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . "&nbsp;[<a href='https://scholia.toolforge.org/topic/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
	}
	print implode ( '; ' , $topics ) ;
}
print "</div>" ;

# Fetch 'stated as' values for all identified authors:
$author_qid_map = array();
foreach ( $article_entry->authors as $author_qid_list ) {
	foreach ($author_qid_list as $qid) {
		$author_qid_map[$qid] = 1;
	}
}
$author_qids = array_keys($author_qid_map);
$stated_as_names = fetch_stated_as_for_authors($author_qids);
$merge_candidates = $article_entry->merge_candidates($wil, $stated_as_names);

// Author list
print "<h2>Authors</h2>" ;
print "<form method='post' class='form' target='_blank'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='id' value='$work_qid' />" ;

print('<ul>');
$formatted_authors = array();
foreach ( $article_entry->author_names AS $num => $a_list ) {
	$formatted_authors[$num] = [];
	foreach ( $a_list AS $a ) {
		$formatted_authors[$num][] = "<a href='index.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
	}
}
foreach ( $article_entry->authors AS $num => $qt_list ) {
	if (! isset($formatted_authors[$num])) {
		$formatted_authors[$num] = [];
	}
	foreach ( $qt_list AS $qt ) {
		$i2 = $wil->getItem ( $qt ) ;
		$label = $i2->getLabel() ;
		$formatted_authors[$num][] = "<a href='author_item.php?limit=50&id=" . $i2->getQ() . "' style='color:green'>$label</a>" ;
	}
}

ksort($formatted_authors);

$merge_count = 0;
foreach ( $formatted_authors AS $num => $display_list ) {
	print "<li>[$num] ";
	if ( $merge_candidates[$num] ) {
		$merge_count += 1;
		print "<input type='checkbox' name='merges[$num]' value='$num' checked/>" ;
	} else if (count($display_list) > 1) {
		print "<span style='color:red'>Name mismatch:</span>";
	}
	print implode ( '|', $display_list) . "</li>";
}
print "</ul>" ;

if ($merge_count > 0) {
	print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to merge these author records' class='btn btn-primary' /></div>" ;
}
print "</form>" ;

print_footer() ;

?>
