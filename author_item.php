<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
if (limit_requests( $db_conn, 10 ) ) {
	$db_conn->close();

	$oauth_url = str_replace('/author_item.php', '/author_item_oauth.php', $_SERVER['REQUEST_URI']);

	print disambig_header( False );
	print "<h1>Too many requests</h1>";
	print "Please wait before making another request of this service; note that use of <a href='$oauth_url'>the OAuth option</a> is not rate-limited.";
	print_footer() ;
	exit ( 0 ) ;
}
$db_conn->close();

$action = get_request ( 'action' , '' ) ;
$author_qid = get_request( 'id', '' ) ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 5000 ;
$filter = get_request ( 'filter', '' ) ;

print disambig_header( False );
print "<div style='font-size:9pt'>(<a href='author_item_oauth.php?id=$author_qid&limit=$article_limit'> Log in to your Wikimedia account to use OAuth instead of Quickstatements for updates.</a>) </div> " ;
print "<hr>";

print "<form method='get' class='form form-inline'>
Author Wikidata ID: 
<input name='id' value='" . escape_attribute($author_qid) . "' type='text' placeholder='Qxxxxx' />
<input type='submit' class='btn btn-primary' name='doit' value='Get author data' />
<div style='font-size:9pt'>Additional SPARQL filters separated by semicolons (eg. for papers on Zika virus, enter wdt:P921 wd:Q202864):
<input style='font-size:9pt' size='40' name='filter' value='" . escape_attribute($filter) . "' type='text' placeholder='wdt:PXXX wd:QYYYYY; wdt:PXX2 wd:QYY2 '/></div>
</form>" ;

if ( $author_qid == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;

$delete_statements = array() ;
if ( $action == 'remove' ) {
	print "<form method='post' class='form' action='$quickstatements_api_url'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;

	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$new_author_q = trim ( get_request ( 'new_author_q' , '' ) ) ;

	$papers = get_request ( 'papers' , array() ) ;

	$to_load = array() ;
	$to_load[] = $author_qid ;
	foreach ( $papers AS $q ) $to_load[] = $q ;
	$wil->loadItems ( $to_load ) ;

	if ( $author_match == 'none' ) {
		$commands = revert_authors_qs_commands ( $wil, $papers, $author_qid ) ;
	} else {
		$commands = move_authors_qs_commands ( $wil, $papers, $author_qid, $new_author_q) ;
	}

	print "Quickstatements V1 commands for replacing author items on these papers:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}


$filter_in_context = ((! isset($filter)) || ($filter == '')) ? '.' : "; $filter . ";
$sparql = "SELECT ?q { ?q wdt:P50 wd:$author_qid $filter_in_context } LIMIT $article_limit" ;
$items_papers = getSPARQLitems ( $sparql ) ;
$limit_reached = (count($items_papers) == $article_limit) ;


// Load items
$to_load = array() ;
$to_load[] = $author_qid ;
$wil->loadItems ( $to_load ) ;

$article_items = generate_article_entries( $items_papers );

# Just need labels for the following:
$qids_to_label = array();
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth) $qids_to_label[$auth] = 1 ;
	foreach ( $article->published_in AS $pub ) $qids_to_label[$pub] = 1 ;
	foreach ( $article->topics AS $topic ) $qids_to_label[$topic] = 1 ;
}
$qid_labels = AuthorData::labelsForItems(array_keys($qids_to_label));

usort( $article_items, 'WikidataArticleEntry::dateCompare' ) ;

$author_item = $wil->getItem ( $author_qid ) ;
if ( !isset($author_item) )  {
	print "<h2>Warning: $author_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}
print "<h2>" . $author_item->getLabel() . "</h2>" ;
print "<div>" ;
print wikidata_link($author_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://scholia.toolforge.org/author/$author_qid'>Scholia Profile</a>" ;
print " [<a target='_blank' href='https://scholia.toolforge.org/author/$author_qid/missing'>missing</a>]" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/reasonator/?q=$author_qid'>Reasonator</a>" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/sqid/#/view?id=$author_qid'>SQID</a>" ;
print '</div>' ;

print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='remove' />
<input type='hidden' name='id' value='$author_qid' />" ;

// Publications
$name_counter = array() ;
$author_qid_counter = array() ;
print "<h2>Listed Publications</h2>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; query again or adjust the limit parameter if you need to see more papers from this author.</div>" ;
}
print "<p>" . count($article_items) . " publications found</p>" ;

print "<div class='group'>" ;
?>
<div>
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>
<?PHP
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
print "<tr><th></th><th>Title</th>" ;
print "<th>Authors (<span style='color:green'>identified</span>)</th>" ;
print "<th>Published In</th><th>Identifier(s)</th>" ;
print "<th>Topic</th><th>Published Date</th></tr>" ;
foreach ( $article_items AS $article ) {
	$q = $article->q ;

	$formatted_authors = array();
	foreach ( $article->author_names AS $num => $a ) {
		$formatted_authors[$num] = "[$num]<a href='index.php?name=" . urlencode($a) . "'>$a</a>" ;
		$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
	}

	$highlighted_authors = array();
	foreach ( $article->authors AS $num => $qt ) {
		$label = $qid_labels[$qt];
		$display_num = $num ;
		if (isset($formatted_authors[$num])) {
			$display_num = "$num-$qt";
		}
		if ( $qt == $author_qid ) {
			$formatted_authors[$display_num] = "[$display_num]<b>$label</b>" ;
			$highlighted_authors[] = $display_num ;
		} else {
			$author_qid_counter[$qt] = isset($author_qid_counter[$qt]) ? $author_qid_counter[$qt]+1 : 1 ;
			$formatted_authors[$display_num] = "[$display_num]<a href='?id=$qt' style='color:green'>$label</a>" ;
		}
	}
	ksort($formatted_authors);
	$authors_list = implode ( ', ' , compress_display_list($formatted_authors, $highlighted_authors, 20, 10, 2)) ;

	$published_in = array() ;
	foreach ( $article->published_in AS $qt ) {
		$label = $qid_labels[$qt];
		$published_in[] = wikidata_link($qt, $label, 'black') . "&nbsp;[<a href='https://scholia.toolforge.org/venue/$qt/missing' target='_blank'>missing</a>]" ;
	}
	$published_in_list = implode ( ', ', $published_in ) ;
	
	print "<tr>" ;
	print "<td><input type='checkbox' name='papers[$q]' value='$q'/></td>" ;
	print "<td style='width:20%;font-size:10pt'>" . wikidata_link($q, $article->title, '') . "</td>" ;
	print "<td style='width:50%;font-size:9pt'>$authors_list <a href='work_item.php?id=$q'>[Full author list]</a></td>" ;
	print "<td style='font-size:9pt'>$published_in_list</td>" ;
	print "<td style='font-size:9pt'>" ;
	if ( $article->doi != '' ) {
		print "DOI: <a target='_blank' href='https://doi.org/$article->doi'>$article->doi</a><br/>" ;
	}
	if ( $article->pmid != '' ) {
		print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article->pmid'>$article->pmid</a>" ;
	}
	print "</td>" ;
	print "<td style='font-size:9pt'>" ;
	if ( count($article->topics) > 0 ) {
		$topics = [] ;
		foreach ( $article->topics AS $qt ) {
			$label = $qid_labels[$qt];
			$topics[] = wikidata_link($qt, $label, 'brown') . "&nbsp;[<a href='https://scholia.toolforge.org/topic/$qt/missing' target='_blank'>missing</a>]" ;
		}
		print implode ( '; ' , $topics ) ;
	}
	print "</td>" ;
	print "<td style='font-size:9pt'>" ;
	print $article->formattedPublicationDate () ;
	print "</td>" ;
	print "</tr>" ;
}
print "</tbody></table></div>" ;

print "<div><input type='radio' name='author_match' value='manual' /><span style='display:inline-block; width:200px'><input type='text' name='new_author_q' placeholder='Qxxx' /></span>Correct Q number of author item for selected works.</div>" ;
print "<div><input type='radio' name='author_match' value='none' checked /> NO replacement author: revert to author name strings</div>" ;
print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to REMOVE selected works from this author' class='btn btn-primary' /></div>" ;
print "</form>";

arsort ( $author_qid_counter, SORT_NUMERIC ) ;
print "<h2>Common author items in these papers</h2>" ;
print "<ul>" ;
foreach ( $author_qid_counter AS $qt => $cnt ) {
	if ( $cnt == 1 ) break ;
	$label = $qid_labels[$qt];
	print "<li><a href='author_item.php?limit=50&id=$qt' style='color:green'>$label</a> ($cnt&times;) - <a href='match_multi_authors.php?limit=50&id=$author_qid+$qt'>Unmatched with both names</a> - <a href='https://scholia.toolforge.org/authors/$author_qid,$qt'>Scholia comparison</a></li>" ;
}
print "</ul>" ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='index.php?limit=50&name=" . urlencode($a) . "'>$a</a> (<a href='index.php?limit=50&name=" . urlencode($a) . "&filter=wdt%3AP50+wd%3A$author_qid'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

print_footer() ;

?>
