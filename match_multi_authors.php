<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
if (limit_requests( $db_conn, 10 ) ) {
	$db_conn->close();

	# This isn't (yet) supported in oauth, link just to names page:
	$oauth_url = str_replace('match_multi_authors.php', 'author_item_oauth.php', $_SERVER['REQUEST_URI']);

	print disambig_header( False );
	print "<h1>Too many requests</h1>";
	print "Please wait before making another request of this service; note that use of <a href='$oauth_url'>the OAuth option</a> is not rate-limited.";
	print_footer() ;
	exit ( 0 ) ;
}
$db_conn->close();

$action = get_request ( 'action' , '' ) ;
$author_qids_string = get_request( 'id', '' ) ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 500 ;
$limit_options = [10, 50, 200, 500] ;
$author_qids = preg_split("/[^[:alnum:]]+/",$author_qids_string);
$name_strings = get_request ( 'name_strings' , array() ) ;
$input_names = array();
foreach ($name_strings AS $q => $name_list) {
	$input_names[$q] = preg_split('/[\r\n]+/', $name_list);
}

print disambig_header( False );

print "<h3>Multi-author match</h3>";

if ( $action == 'match' ) {
	print "<form method='post' class='form' action='$quickstatements_api_url'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;
	$papers = get_request ( 'papers' , array() ) ;

	$commands = match_authors_qs_commands ( $papers ) ;

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}

print "<form method='get' class='form'>
<div style='display:table'>
<div style='display:table-row'>
<div style='display:table-cell'>Author Wikidata IDs (space-separated):</div>
<div style='display:table-cell'>
<input name='id' value='" . escape_attribute(implode(" ", $author_qids)) . "' type='text' placeholder='Qxxxxx Qyyyyy' size=65 /> </div></div></div>" ;

$wil = new WikidataItemList ;
$wil->loadItems ( $author_qids ) ;

$names = array();
foreach ($author_qids AS $author_qid) {
	if (isset($input_names[$author_qid]) && count($input_names[$author_qid]) > 0) continue;
	$author_item = $wil->getItem($author_qid);
	if ( !isset($author_item) )  {
		print "<h2>Warning: $author_qid not found!</h2>" ;
		print_footer() ;
		exit ( 0 ) ;
	}
	$auth_name = $author_item->getLabel() ;
	$nm = new NameModel($auth_name);
	$names[$author_qid] = $nm->default_search_strings();
}

$author_qids_for_sparql = 'wd:' . implode ( ' wd:' , $author_qids) ;
$sparql = "SELECT DISTINCT ?author_qid ?name { VALUES ?author_qid { $author_qids_for_sparql } .
	?q p:P50 ?auth_statement .
	?auth_statement ps:P50 ?author_qid ;
                        pq:P1932 ?name .
}" ;
$query_result = getSPARQL( $sparql ) ;
$bindings = $query_result->results->bindings ;
foreach ( $bindings AS $binding ) {
	$author_qid = item_id_from_uri($binding->author_qid->value) ;
	if (isset($input_names[$author_qid]) && count($input_names[$author_qid]) > 0) continue;
	$names[$author_qid][] = $binding->name->value ;
}

print "<div style='display:table'>Name strings for matching (one per line)</div>";
foreach ($author_qids as $author_qid) {
	if (isset($input_names[$author_qid]) && count($input_names[$author_qid]) > 0) {
		$names[$author_qid] = $input_names[$author_qid] ;
	}
	$names[$author_qid] = array_unique($names[$author_qid]) ;
	$author_item = $wil->getItem($author_qid);
	print "<div style='display:table-cell'>";
	print wikidata_link($author_qid, $author_item->getLabel(), 'blue');
	print ": <textarea name='name_strings[$author_qid]' rows=5>" . implode("\n",$names[$author_qid]) . "</textarea></div>" ;
}
print "</div><div>
Limit: <select name='limit'>" ;
foreach ($limit_options AS $limit_option) {
	print "<option value='$limit_option'" ;
	if ($article_limit == $limit_option) print ' selected' ;
	print ">$limit_option</option>" ;
}
print "</select>";
print "<input type='submit' class='btn btn-primary' name='doit' value='Find papers by these authors' /></div>" ;
print "</form>" ;

$name_hash = array();
foreach ($author_qids as $author_qid) {
	foreach ($names[$author_qid] as $name) {
		if ( isset($name_hash[$name]) ) {
			$match_q = $name_hash[$name];
			if ($match_q != $author_qid) {
				print "WARNING: $author_qid and $match_q both map to the name '$name'";
			}
		} else {
			$name_hash[$name] = $author_qid ;
		}
	}
}

// Want to find all papers with all of these author names, or with one
// of the authors as P50 (author) and the other(s) as names

$values_choices = array();
$name_selectors = array();
foreach ($author_qids as $num => $author_qid) {
	$label = "?name$num" ;
	$values_choices[$num] = "VALUES $label { " . '"' . implode('" "', $names[$author_qid]) . '" } .' ;
	$name_selectors[$num] = "wdt:P2093 $label" ;
}
$all_names_query = "{ " . implode(" ", $values_choices) . " ?q " . implode(" ; ", $name_selectors) . " . }";
$part_names_queries = [] ;
foreach ($author_qids as $num => $author_qid) {
	$name_selectors_copy = $name_selectors;
	unset($name_selectors_copy[$num]);
	$part_names_queries[] = "{ " . implode(" ", $values_choices) . " ?q wdt:P50 wd:$author_qid ; " . implode(" ; ", $name_selectors_copy) . " . }";
}

$sparql = "SELECT ?q WHERE { $all_names_query UNION " . implode(" UNION ", $part_names_queries) . " . } LIMIT $article_limit" ;
$items_papers = getSPARQLitems ( $sparql ) ;
$limit_reached = (count($items_papers) == $article_limit) ;
$items_papers = array_unique( $items_papers );

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

print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='match' />
<input type='hidden' name='id' value='" . escape_attribute(implode(" ", $author_qids)) . "' />" ;

// Publications
$name_counter = array() ;
$author_qid_counter = array() ;
print "<h2>Listed Publications</h2>" ;
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
print "<tr><th>Title</th>" ;
print "<th>Authors (<span style='color:green'>identified</span>)</th>" ;
print "<th>Published In</th><th>Identifier(s)</th>" ;
print "<th>Topic</th><th>Published Date</th></tr>" ;
foreach ( $article_items AS $article ) {
	$q = $article->q ;

	$formatted_authors = array();
	$highlighted_authors = array();
	foreach ( $article->author_names AS $num => $a ) {
		if ( isset($name_hash[$a]) ) {
			$match_q = $name_hash[$a] ;
			$highlighted_authors[] = $num ;
			$formatted_authors[$num] = "[$num]<b>$a</b>" .
			"<input type='checkbox' name='papers[$q:$num:$match_q]' value='$q:$num:$match_q' />=$match_q</b>" ;
		} else {
			$formatted_authors[$num] = "[$num]<a href='index.php?name=" . urlencode($a) . "'>$a</a>" ;
		}
		$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
	}

	foreach ( $article->authors AS $num => $qt ) {
		$label = $qid_labels[$qt];
		$display_num = $num ;
		if (isset($formatted_authors[$num])) {
			$display_num = "$num-$qt";
		}
		$formatted_authors[$display_num] = "[$display_num]<a href='author_item.php?id=$qt' style='color:green'>$label</a>" ;
		if ( in_array($qt, $author_qids) ) {
			$highlighted_authors[] = $display_num ;
		} else {
			$author_qid_counter[$qt] = isset($author_qid_counter[$qt]) ? $author_qid_counter[$qt]+1 : 1 ;
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

print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to add selected authorships for these authors' class='btn btn-primary' /></div>" ;
print "</form>";

arsort ( $author_qid_counter, SORT_NUMERIC ) ;
print "</div><h2>Common author items in these papers</h2>" ;
print "<ul>" ;
foreach ( $author_qid_counter AS $qt => $cnt ) {
	if ( $cnt == 1 ) break ;
	$label = $qid_labels[$qt];
	print "<li><a href='author_item.php?limit=50&id=$qt' style='color:green'>$label</a> ($cnt&times;) - <a href='match_multi_authors.php?limit=50&id=$author_qids_string+$qt'>Add to search</a></li>" ;
}
print "</ul>" ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='index.php?limit=50&name=" . urlencode($a) . "'>$a</a> ($cnt&times;)</li>" ;
}
print "</ul><div>" ;

print_footer() ;

?>
