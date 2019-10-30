<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);

$action = get_request ( 'action' , '' ) ;
$author_qid = get_request( 'id', '' ) ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 5000 ;
$merge = get_request ( 'merge' , 0 ) ;
$merge_checked = $merge ? 'checked' : '' ;
$filter = get_request ( 'filter', '' ) ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'author_item_oauth.php');
	exit(0);
}

print get_common_header ( '' , 'Author Disambiguator' ) ;

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

print "<form method='get' class='form form-inline'>
Author Wikidata ID: 
<input name='id' value='" . escape_attribute($author_qid) . "' type='text' placeholder='Qxxxxx' />
<label><input type='checkbox' name='merge' value='1' $merge_checked />Find duplicates to merge</label>
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
	$edit_claims = new EditClaims($oauth);

	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$new_author_q = trim ( get_request ( 'new_author_q' , '' ) ) ;
	$papers = get_request ( 'papers' , array() ) ;

	$to_load = array() ;
	$to_load[] = $author_qid ;
	foreach ( $papers AS $q ) $to_load[] = $q ;
	$wil->loadItems ( $to_load ) ;

	$author_item = $wil->getItem($author_qid);

	print "Processing requests....\n";
	print "<ul>";
	foreach ($papers AS $work_qid) {
		print "<li>" . wikidata_link($work_qid, $work_qid, '') . ": ";
		if ( $author_match == 'none' ) {
			$result = $edit_claims->revert_author( $work_qid, $author_item, "Author Disambiguator revert author for $work_qid" . edit_groups_string() ) ;
		} else {
			$result = $edit_claims->move_author( $work_qid, $author_qid, $new_author_q, "Author Disambiguator change author for $work_qid" . edit_groups_string() ) ;
		}
		if ($result) {
			print "revert completed";
		} else {
			print "update failed!?";
			print_r($edit_claims->error);
		}
		print "</li>\n";
	}
	print "</ul>";
	print_footer() ;
	exit ( 0 ) ;
}

if ($action == 'merge') {
	$edit_claims = new EditClaims($oauth);
	$papers = get_request ( 'papers' , array() ) ;
	$article_items = generate_article_entries2( $papers );
	$to_load = array() ;
	foreach ( $article_items AS $article ) {
		foreach ( $article->authors AS $auth_list ) {
			foreach ( $auth_list AS $auth ) {
				$to_load[] = $auth ;
			}
		}
		foreach ( $article->published_in AS $pub ) $to_load[] = $pub ;
		foreach ( $article->topics AS $topic ) $to_load[] = $topic ;
	}
	$to_load = array_unique( $to_load );
	$wil->loadItems ( $to_load ) ;
# Fetch 'stated as' values for all identified authors on all articles:
	$author_qid_map = array();
	foreach ($article_items AS $article) {
		foreach ( $article->authors as $author_qid_list ) {
			foreach ($author_qid_list as $qid) {
				$author_qid_map[$qid] = 1;
			}
		}
	}
	$author_qids = array_keys($author_qid_map);
	$stated_as_names = fetch_stated_as_for_authors($author_qids);
	print "Processing requests....\n";
	print "<ul>";
	foreach ( $article_items AS $article ) {
		$merge_candidates = $article->merge_candidates($wil, $stated_as_names);
		$work_qid = $article->q;
# Act as if all valid entries were selected for merge ...
		$author_numbers = array() ;
		foreach ( $merge_candidates AS $num => $do_merge ) {
			if ($do_merge) {
				$author_numbers[] = $num ;
			}
		}
		$result = $edit_claims->merge_authors( $work_qid, $author_numbers, array(), "Author Disambiguator merge authors for $work_qid" . edit_groups_string() ) ;
		print "<li>" . wikidata_link($work_qid, $work_qid, '') . ": ";
		if ($result) {
			print "merges done";
		} else {
			print "update failed!?";
			print_r($edit_claims->error);
		}
		print "</li>\n";
	}
	print "</ul>";
	print_footer() ;
	exit(0);
}


$filter_in_context = ((! isset($filter)) || ($filter == '')) ? '.' : "; $filter . ";
$sparql = "SELECT ?q { ?q wdt:P50 wd:$author_qid $filter_in_context } LIMIT $article_limit" ;
$items_papers = getSPARQLitems ( $sparql ) ;
$limit_reached = (count($items_papers) == $article_limit) ;


// Load items
$to_load = array() ;
$to_load[] = $author_qid ;
$wil->loadItems ( $to_load ) ;

$article_items = generate_article_entries2( $items_papers );
$to_load = array() ;
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth_list ) {
		foreach ( $auth_list AS $auth ) {
			$to_load[] = $auth ;
		}
	}
	foreach ( $article->published_in AS $pub ) $to_load[] = $pub ;
	foreach ( $article->topics AS $topic ) $to_load[] = $topic ;
}
$to_load = array_unique( $to_load );
$wil->loadItems ( $to_load ) ;

usort( $article_items, 'WikidataArticleEntry2::dateCompare' ) ;

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
print "<a target='_blank' href='https://tools.wmflabs.org/scholia/author/$author_qid'>Scholia Profile</a>" ;
print " [<a target='_blank' href='https://tools.wmflabs.org/scholia/author/$author_qid/missing'>missing</a>]" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/reasonator/?q=$author_qid'>Reasonator</a>" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/sqid/#/view?id=$author_qid'>SQID</a>" ;
print '</div>' ;

print "<form method='post' class='form' target='_blank' action='?'>" ;
if ($merge) {
	print "<input type='hidden' name='action' value='merge' />" ;
} else {
	print "<input type='hidden' name='action' value='remove' />" ;
}
print "<input type='hidden' name='id' value='$author_qid' />" ;

// Publications
$name_counter = array() ;
$author_qid_counter = array() ;
$venue_counter = array() ;
$topic_counter = array() ;
print "<h2>Listed Publications</h2>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; query again or adjust the limit parameter if you need to see more papers from this author.</div>" ;
}
print "<p>" . count($article_items) . " publications found</p>" ;

# Fetch 'stated as' values for all identified authors on all articles:
$author_qid_map = array();
foreach ($article_items AS $article) {
	foreach ( $article->authors as $author_qid_list ) {
		foreach ($author_qid_list as $qid) {
			$author_qid_map[$qid] = 1;
		}
	}
}
$author_qids = array_keys($author_qid_map);
$stated_as_names = fetch_stated_as_for_authors($author_qids);

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
	if ($merge) {
		$merge_candidates = $article->merge_candidates($wil, $stated_as_names);
	}
	$q = $article->q ;

	$formatted_authors = array();
	foreach ( $article->author_names AS $num => $a_list ) {
		$formatted_authors[$num] = [];
		foreach ( $a_list AS $id => $a ) {
			$formatted_authors[$num][$id] = "<a href='names_oauth.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
			$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
		}
	}

	$highlighted_authors = array();
	foreach ( $article->authors AS $num => $qt_list ) {
	    if (! isset($formatted_authors[$num])) {
		$formatted_authors[$num] = [];
	    }
	    foreach ( $qt_list AS $id => $qt ) {
		$i2 = $wil->getItem ( $qt ) ;
		$label = $i2->getLabel() ;
		if ( $qt == $author_qid ) {
			$formatted_authors[$num][$id] = "<b>$label</b>" ;
			$highlighted_authors[] = $num ;
		} else {
			$author_qid_counter[$qt] = isset($author_qid_counter[$qt]) ? $author_qid_counter[$qt]+1 : 1 ;
			$formatted_authors[$num][$id] = "<a href='?limit=50&id=" . $i2->getQ() . "' style='color:green'>$label</a>" ;
		}
	    }
	}
	ksort($formatted_authors);
	$display_authors = [];
	$merge_count = 0;
	foreach ( $formatted_authors AS $num => $display_list ) {
		$display_authors[$num] = "[$num] " . implode ( '|', $display_list) ;
		if ($merge) {
			if ( $merge_candidates[$num] ) {
				$merge_count += 1;
				$highlighted_authors[] = $num ;
				$display_authors[$num] .= " <span style='color:dark green'>(merge)</span>";
			} else if (count($display_list) > 1) {
				$highlighted_authors[] = $num ;
				$display_authors[$num] .= " <span style='color:red'>(mismatch!)</span>";
			}
		}
	}
	$authors_list = implode ( ', ' , compress_display_list($display_authors, $highlighted_authors, 20, 10, 2)) ;

	$published_in = array() ;
	foreach ( $article->published_in AS $qt ) {
		$i2 = $wil->getItem ( $qt ) ;
		if ( !isset($i2) ) continue ;
		$venue_counter[$qt] = isset($venue_counter[$qt]) ? $venue_counter[$qt]+1 : 1 ;
		$published_in[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/venue/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
	}
	$published_in_list = implode ( ', ', $published_in ) ;
	
	print "<tr>" ;
	if ($merge) {
		if ($merge_count > 0) {
			print "<td><input type='checkbox' name='papers[$q]' value='$q'/></td>" ;
		} else {
			print "<td></td>" ;
		}
	} else {
		print "<td><input type='checkbox' name='papers[$q]' value='$q'/></td>" ;
	}
	print "<td style='width:20%;font-size:10pt'><a href='work_item_oauth.php?id=$q'>$article->title</a></td>" ;
	print "<td style='width:50%;font-size:9pt'>$authors_list</td>";
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
			$i2 = $wil->getItem($qt) ;
			if ( !isset($i2) ) continue ;
			$topic_counter[$qt] = isset($topic_counter[$qt]) ? $topic_counter[$qt]+1 : 1 ;
			$topics[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/topic/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
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

if ($merge) {
	print "<div style='margin:20px'><input type='submit' name='doit' value='Merge redundant author statements on selected articles' class='btn btn-primary' /></div>" ;
} else {
	print "<div><input type='radio' name='author_match' value='manual' /><span style='display:inline-block; width:200px'><input type='text' name='new_author_q' placeholder='Qxxx' /></span>Correct Q number of author item for selected works.</div>" ;
	print "<div><input type='radio' name='author_match' value='none' checked /> NO replacement author: revert to author name strings</div>" ;
	print "<div style='margin:20px'><input type='submit' name='doit' value='REMOVE selected works from this author' class='btn btn-primary' /></div>" ;
}
print "</form>";

arsort ( $author_qid_counter, SORT_NUMERIC ) ;
print "<h2>Common author items in these papers</h2>" ;
print "<ul>" ;
foreach ( $author_qid_counter AS $qt => $cnt ) {
	$i2 = $wil->getItem($qt) ;
	$label = $i2->getLabel() ;
	print "<li><a href='?limit=50&id=$qt' style='color:green'>$label</a> (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3AP50+wd%3A$qt'>$cnt&times;</a>) - <a href='match_multi_authors.php?limit=50&id=$author_qid+$qt'>Unmatched with both names</a> - <a href='https://tools.wmflabs.org/scholia/authors/$author_qid,$qt'>Scholia comparison</a></li>" ;
}
print "</ul>" ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common author name strings in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='names_oauth.php?limit=50&name=" . urlencode($a) . "'>$a</a> (<a href='names_oauth.php?limit=50&name=" . urlencode($a) . "&filter=wdt%3AP50+wd%3A$author_qid'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $venue_counter , SORT_NUMERIC ) ;
print "<h2>Publishing venues for these papers</h2>" ;
print "<ul>" ;
foreach ( $venue_counter AS $qt => $cnt ) {
	$i2 = $wil->getItem($qt) ;
	$label = $i2->getLabel() ;
	print "<li>" . wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . " (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3AP1433+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $topic_counter , SORT_NUMERIC ) ;
print "<h2>Topics for these papers</h2>" ;
print "<ul>" ;
foreach ( $topic_counter AS $qt => $cnt ) {
	$i2 = $wil->getItem($qt) ;
	$label = $i2->getLabel() ;
	print "<li>" . wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . " (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3AP921+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;


print_footer() ;

?>
