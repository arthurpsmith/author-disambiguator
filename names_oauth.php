<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);
$oauth->interactive = true;

$action = get_request ( 'action' , '' ) ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'names_oauth.php');
	exit(0);
}

$name = trim ( str_replace ( '_' , ' ' , get_request ( 'name' , '' ) ) ) ;

if ( $action == 'add' ) {
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	if ( $author_match == 'new' ) {
		print disambig_header( True );
		print "<form method='post' class='form form-inline' action='https://tools.wmflabs.org/quickstatements/api.php'>" ;
		print "<input type='hidden' name='action' value='import' />" ;
		print "<input type='hidden' name='temporary' value='1' />" ;
		print "<input type='hidden' name='openpage' value='1' />" ;
		$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
		$viaf_author = trim ( get_request ( 'viaf_author' , '' ) ) ;
		$researchgate_author = trim ( get_request ( 'researchgate_author' , '' ) ) ;
		print "</div></div><div>Quickstatements V1 commands for creating new author item:" ;
		$commands = new_author_qs_commands ( $name, $orcid_author, $viaf_author, $researchgate_author ) ;
		print "<textarea name='data' rows=5>" . implode("\n",$commands) . "</textarea>" ;
		print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' /><br/>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		print "</form></div><div>" ;
		print_footer() ;
		exit ( 0 ) ;
	}
	$author_q = $author_match ;
        if ( $author_match == 'manual' ) {
           $author_q = trim ( get_request ( 'q_author' , '' ) );
        }
	if ( $author_q == '' ) {
		print disambig_header( True );
		print "Sorry, can't find author" ;
		print_footer() ;
		exit ( 0 ) ;
	}
	if (! $oauth->isAuthOK()) {
		print disambig_header( True );
		print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
		print_footer() ;
		exit ( 0 ) ;
	}
	$batch_id = Batch::generate_batch_id() ;

	$papers = get_request ( 'papers' , array() ) ;

	$dbtools = new DatabaseTools($db_passwd_file);
	$db_conn = $dbtools->openToolDB('authors');
	$dbquery = "INSERT INTO batches VALUES('$batch_id', '" . $db_conn->real_escape_string($oauth->userinfo->name) . "',  NULL, NULL, 1)";
	$db_conn->query($dbquery);
// Should probably check for errors!
	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'replace_name', ?, 'READY', NULL, NULL)");
	$seq = 0;
	foreach ( $papers AS $author_match ) {
		$seq += 1;
		$data = "$author_q:$author_match";
		$add_command->bind_param('is', $seq, $data);
		$add_command->execute();
	}
	$add_command->close();

	$batch = new Batch($batch_id);
	$batch->load($db_conn);

	$db_conn->close();

	if ($seq > 0) { // Don't bother to start if no commands to run
		$batch->start($oauth);
		sleep(1);
	}
	header("Location: batches_oauth.php?id=$batch_id");
	exit ( 0 ) ;
}

$fuzzy = get_request ( 'fuzzy' , 0 ) * 1 ;
$fuzzy_checked = $fuzzy ? 'checked' : '' ;
$wbsearch = get_request ( 'wbsearch' , 0 ) * 1 ;
$wbsearch_checked = $wbsearch ? 'checked' : '' ;
$filter = get_request ( 'filter', '' ) ;
$filter_authors = get_request ( 'filter_authors', '') ;
$filter_authors_checked = $filter_authors ? 'checked' : '' ;
$article_limit = get_request ( 'limit', '' ) ;
$precise = get_request ( 'precise' , 0 ) * 1 ;
if ($article_limit == '' ) $article_limit = 500 ;
$limit_options = [10, 50, 200, 500] ;

$use_name_strings = get_request ( 'use_name_strings' , 0 ) * 1 ;
$use_name_strings_checked = $use_name_strings ? 'checked' : '' ;
$name_strings = get_request ( 'name_strings' , '') ;
$input_names = preg_split('/[\r\n]+/', $name_strings);

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
	print_footer() ;
	exit ( 0 ) ;
}
print "<hr>";

// Publications
$nm = new NameModel($name);
$names = $nm->default_search_strings();

if ( $use_name_strings &&  ( count($input_names) > 0 && strlen($input_names[0]) > 0 ) ) {
	$names = $input_names ;
} else {
	if ( $fuzzy ) {
		$names = $nm->fuzzy_search_strings();
	}
	if ( $wbsearch ) {
		$names = $nm->names_from_wbsearch( $names );
	}
}

print "<form method='get' class='form form-inline'>
<input type='hidden' name='precise' value='$precise' />
Author name: 
<input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' />
<label><input type='checkbox' name='fuzzy' value='1' $fuzzy_checked /> Fuzzy match</label>
<label style='margin:10px'><input type='checkbox' name='wbsearch' value='1' $wbsearch_checked /> Wikibase search </label>
<label style='margin:10px'><input type='checkbox' name='use_name_strings' value='1' $use_name_strings_checked /> Specify name strings </label>
<div style='margin:10px'><input type='submit' class='btn btn-primary' name='doit' value='Look for author' /></div>
Limit: <select name='limit'>" ;
foreach ($limit_options AS $limit_option) {
	print "<option value='$limit_option'" ;
	if ($article_limit == $limit_option) print ' selected' ;
	print ">$limit_option</option>" ;
}
print "</select><br />
<div style='font-size:9pt'>Additional SPARQL filters separated by semicolons (eg. for papers on Zika virus, enter wdt:P921 wd:Q202864):
<input style='font-size:9pt' size='40' name='filter' value='" . escape_attribute($filter) . "' type='text' placeholder='wdt:PXXX wd:QYYYYY; wdt:PXX2 wd:QYY2 '/></div>
<div style='font-size:9pt'><input type='checkbox' name='filter_authors' value='1' $filter_authors_checked /> Filter potential authors as well?</div><br/>";

if ( $use_name_strings ) {
	print "<div><textarea name='name_strings' rows=5>" . implode("\n",$names) . "</textarea></div>" ;
}
print "</form>" ;

if ( $name == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}


$author_names_strings = '"' . implode ( '" "' , $names ) . '"' ;

$languages_to_search = ['en', 'de', 'fr', 'es', 'nl'] ;
$names_with_langs = array();
foreach($languages_to_search AS $lang) {
	foreach($names AS $name_entry) {
		$names_with_langs[] = '"' . $name_entry . '"@' . $lang ;
	}
}
$names_strings = implode ( ' ' , $names_with_langs ) ;
$filter_in_context = "; $filter . ";
$sparql = "SELECT ?q WHERE { VALUES ?name { $author_names_strings } . ?q wdt:P2093 ?name $filter_in_context } LIMIT $article_limit" ;
#print $sparql ;
$items_papers = getSPARQLitems ( $sparql ) ;
$limit_reached = (count($items_papers) == $article_limit) ;
$items_papers = array_unique( $items_papers );

#print "<pre>" ; print_r ( $items_papers) ; print "</pre>" ;

// Potential authors
$author_filter = $filter_authors ? "?article wdt:P50 ?q $filter_in_context" : '' ;
$items_authors = array() ;
$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31 wd:Q5 . $author_filter }" ;
#print $sparql ;
$items_individual_authors = getSPARQLitems ( $sparql ) ;

if (strlen($nm->last_name) < 4) {
	$items_collective_authors = []; # Otherwise may time out
} else {
	$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31/wdt:P279* wd:Q16334295 . $author_filter }" ;
#print $sparql ;
	$items_collective_authors = getSPARQLitems ( $sparql ) ;
}
$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $author_names_strings } . ?paper p:P50 ?statement . ?statement ps:P50 ?q ; pq:P1932 ?name . $author_filter FILTER NOT EXISTS {?q owl:sameAs ?redirect} }" ;
#print $sparql ;
$items_stated_as_authors = getSPARQLitems ( $sparql ) ;

$items_authors = array_unique( array_merge( $items_individual_authors, $items_collective_authors, $items_stated_as_authors ) ) ;

// Load items
$wil = new WikidataItemList ;
$to_load = array() ;
foreach ( $items_authors AS $q ) $to_load[] = $q ;
$wil->loadItems ( $to_load ) ;

$potential_author_data = AuthorData::authorDataFromItems( $items_authors, $wil, true ) ;
$to_load = array() ;
foreach ($potential_author_data AS $author_data) {
	foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
}
$to_load = array_unique($to_load);
$wil->loadItems ( $to_load ) ;

print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='precise' value='$precise' />
<input type='hidden' name='wbsearch' value='$wbsearch' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

$article_items = generate_article_entries( $items_papers );

# Just need labels for the following:
$qids_to_label = array();
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth) $qids_to_label[$auth] = 1 ;
	foreach ( $article->published_in AS $pub ) $qids_to_label[$pub] = 1 ;
	foreach ( $article->topics AS $topic ) $qids_to_label[$topic] = 1 ;
}
$qid_labels = AuthorData::labelsForItems(array_keys($qids_to_label));

#print "<pre>" ; print_r ( $article_items) ; print "</pre>" ;

$clusters = cluster_articles ( $article_items, $names, $precise) ;

$potential_authors_by_cluster_label = array();
foreach ($clusters AS $label => $cluster ) {
	$potential_authors_by_cluster_label[$label]  = array();
	foreach ( $potential_author_data AS $author_data ) {
		if (author_matches_cluster( $cluster, $author_data, $names )) {
			$potential_authors_by_cluster_label[$label][$author_data->qid] = 1 ;
		}
	}
}

#print "<pre>" ; print_r ( $clusters ) ; print "</pre>" ;
// Publications
$name_counter = array() ;
$author_qid_counter = array() ;
$venue_counter = array() ;
$topic_counter = array() ;
print "<h2>Potential publications</h2>" ;
print "<p>" . count($article_items) . " publications found</p>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; process these papers and then reload to see if there are more for this author name string</div>" ;
}
if (! $precise ) {
	print "<div style='font-size:9pt'><a href='?name=" . urlencode($name) . "&precise=1&fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&use_name_strings=$use_name_strings&name_strings=" . urlencode($name_strings) . "'> Click here to create clusters based on exact author strings rather than rougher matches.</a> </div> " ;
} else {
	print "<div style='font-size:9pt'><a href='?name=" . urlencode($name) . "&precise=0&fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&use_name_strings=$use_name_strings&name_strings=" . urlencode($name_strings) . "'> Click here for rougher clustering.</a> </div> " ;
}

$is_first_group = true ;
foreach ( $clusters AS $cluster_name => $cluster ) {
	print "<div class='group'>" ;
	print "<h3>$cluster_name</h3>" ;
	$potential_authors = array_keys($potential_authors_by_cluster_label[$cluster_name]);
	foreach ( $potential_authors AS $potential_qid ) {
		$author_data = $potential_author_data[$potential_qid] ;
		$potential_item = $wil->getItem ( $potential_qid ) ;
		print "Matched potential author: <a href='author_item_oauth.php?id=" . $potential_item->getQ() . "' target='_blank' style='color:green'>" . $potential_item->getLabel() . "</a>" ;
		print " - author of $author_data->article_count items<br/>" ;
	}
	if (count($potential_authors) > 1) {
		print "<div><b>Warning:</b> Multiple potential authors match this cluster!</div>" ;
	}
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
	print "<th>Topic</th><th>Published Date</th><th>Match?</th></tr>" ;

	$selected_nums = array();
	if ($precise) {
		foreach ($cluster->article_authnums AS $article_num) {
			$matches = array();
			if (preg_match('/^(Q\d+):(\d+)/', $article_num, $matches)) {
				$qid = $matches[1];
				$num = $matches[2];
				$selected_nums[$qid] = $num;
			}
		}
	}
	foreach ( $cluster->article_list AS $article ) {
		$q = $article->q ;
		$selected_num = -1;
		if ($precise) {
			$selected_num = $selected_nums[$q];
		}

		$formatted_authors = array();
		$highlighted_authors = array();
		foreach ( $article->author_names AS $num => $a ) {
			if ( ($precise && ($num == $selected_num) ) ||
			  ( (! $precise ) && in_array ( $a , $names ) ) ) {
				$formatted_authors[$num] = "[$num]" .
			"<input type='checkbox' name='papers[$q:$num]' value='$q:$num' " .
			($is_first_group?'checked':'') . " /><b>$a</b>" ;
				$highlighted_authors[] = $num ;
			} else {
				$formatted_authors[$num] = "[$num]<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		
		foreach ( $article->authors AS $num => $qt ) {
//			$stated_as = $article->authors_stated_as[$qt] ;
			$display_num = $num ;
			if (isset($formatted_authors[$num])) {
				$display_num = "$num-$qt";
			}
			$label = $qid_labels[$qt];
			$author_qid_counter[$qt] = isset($author_qid_counter[$qt]) ? $author_qid_counter[$qt]+1 : 1 ;
			$formatted_authors[$display_num] = "[$display_num]<a href='author_item_oauth.php?id=$qt' target='_blank' style='color:green'>$label</a>" ;
		}
		ksort($formatted_authors);
		$authors_list = implode ( ', ' , compress_display_list($formatted_authors, $highlighted_authors, 20, 10, 2)) ;

		$published_in = array() ;
		foreach ( $article->published_in AS $qt ) {
			$label = $qid_labels[$qt];
			$venue_counter[$qt] = isset($venue_counter[$qt]) ? $venue_counter[$qt]+1 : 1 ;
			$published_in[] = wikidata_link($qt, $label, 'black') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/venue/$qt/missing' target='_blank'>missing</a>]" ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		print "<tr>" ;
		print "<td style='width:20%;font-size:10pt'><a href='work_item_oauth.php?id=$q'>$article->title</a></td>" ;
		print "<td style='width:50%;font-size:9pt'>$authors_list</td>" ;
		print "<td style='font-size:9pt'>$published_in_list</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $article->doi != '' ) {
			print "DOI: <a target='_blank' href='https://doi.org/$article->doi'>$article->doi</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->doi ) . "'>ORCID</a>]<br/>" ;
		}
		if ( $article->pmid != '' ) {
			print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article->pmid'>$article->pmid</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->pmid ) . "'>ORCID</a>]<br/>" ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( count($article->topics) > 0 ) {
			$topics = [] ;
			foreach ( $article->topics AS $qt ) {
				$label = $qid_labels[$qt];
				$topic_counter[$qt] = isset($topic_counter[$qt]) ? $topic_counter[$qt]+1 : 1 ;
				$topics[] = wikidata_link($qt, $label, 'brown') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/topic/$qt/missing' target='_blank'>missing</a>]" ;
			}
			print implode ( '; ' , $topics ) ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		print $article->formattedPublicationDate () ;
		print "</td><td style='font-size:10pt'>" ;

		foreach ( $potential_author_data AS $author_data ) {
			if (author_matches_article( $article, $author_data, $names )) {
				$potential_item = $wil->getItem ( $author_data->qid ) ;
				print "<a href='author_item_oauth.php?id=" . $potential_item->getQ() . "' target='_blank' style='color:green'>" . $potential_item->getLabel() . "</a>" ;
				print " ($author_data->qid; $author_data->article_count items)<br/>" ;
			}
		}
		print "</td>" ;
		print "</tr>" ;
	}
	print "</tbody></table></div>" ;
	$is_first_group = false ;
}

// Potential authors
#print "<pre>" ; print_r ( $items_authors ) ; print "</pre>" ;
print "<h2>Potential author items</h2>" ;
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
print "<tr><th></th><th>Name</th><th>Description</th><th>Authored items</th>" ;
print "<th>Identifiers</th><th>Employer(s)</th></tr>" ;
foreach ( $potential_author_data AS $q => $author_data ) {
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
	print "<tr>" ;
	print "<td><input type='radio' name='author_match' value='$q' /></td>" ;
	print "<td><a href='author_item_oauth.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a></td>" ;
	print "<td>" . $i->getDesc() . "</td>" ;
	print "<td>$author_data->article_count</td>" ;
	print "<td>" ;
	if ( $author_data->orcid != '' ) {
		print "ORCID: <a target='_blank' href='https://orcid.org/$author_data->orcid'>$author_data->orcid</a><br/>" ;
	}
	if ( $author_data->isni != '' ) {
		$isni = preg_replace('/\s+/', '', $author_data->isni) ;
		print "ISNI: <a target='_blank' href='http://isni.org/$isni'>$author_data->isni</a><br/>" ;
	}
	if ( $author_data->rsrchrid != '' ) {
		print "Researcher ID: <a target='_blank' href='https://www.researcherid.com/rid/$author_data->rsrchrid'>$author_data->rsrchrid</a><br/>" ;
	}
	if ( $author_data->viaf != '' ) {
		print "VIAF ID: <a target='_blank' href='https://viaf.org/viaf/$author_data->viaf'>$author_data->viaf</a><br/>" ;
	}
	if ( $author_data->rgprofile != '' ) {
		print "ResearchGate Profile: <a target='_blank' href='https://www.researchgate.net/profile/$author_data->rgprofile'>$author_data->rgprofile</a><br/>" ;
	}
	print "</td><td style='font-size:9pt'>" ;
	foreach ( $author_data->employer_qids AS $emp_qid ) {
		$emp_item = $wil->getItem ( $emp_qid ) ;
		if ( !isset($emp_item) ) continue ;
		print wikidata_link($emp_qid, $emp_item->getLabel(), '') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/organization/$emp_qid/missing' target='_blank'>missing</a>]<br/>" ;
	}
	print "</td></tr>" ;
}
print "<tr><td><input type='radio' name='author_match' value='manual' checked /></td><td><input type='text' name='q_author' placeholder='Qxxx' /></td><td colspan='4'>Other Q number of this author</td></tr>" ;
print "</tbody></table>" ;

print "<div style='margin:20px'><input type='submit' name='doit' value='Link selected works to author' class='btn btn-primary' /></div>" ;
print "</form>" ;

print "<h2>New Author Item</h2>" ;
print '(if the author you are looking for is not listed above and otherwise not yet in Wikidata)';

print "<form method='post' class='form form-inline' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='author_match' value='new'/>
<div>Author name: <input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' /></div>";
print "<div><a href='" . getORCIDurl($name) . "' target='_blank'>Check ORCID for $name</a> | Author has ORCID ID: <input type='text' name='orcid_author' placeholder='xxxx-xxxx-xxxx-xxxx' /></div>" ;
print "<div><a href='https://viaf.org/viaf/search?query=local.personalNames%20all%20%22$name' target='_blank'>Check VIAF for $name</a> | Author has VIAF ID: <input type='text' name='viaf_author' placeholder='xxxxxxxxxxxxxxxxxxxx' /></div>" ;
print "<div><a href='https://www.researchgate.net/search/authors?q=$name' target='_blank'>Check ResearchGate for $name</a> | Author has ResearchGate Profile ID: <input type='text' name='researchgate_author' placeholder='Xxxxxxx_Xxxxxx' /></div>" ;
print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to create author item' class='btn btn-primary' /></div>" ;
print "</form>" ;
print '<div>After creating the new author item, enter the Wikidata ID in the "Other Q number of this author" field above to link to their works.</div>' ;

arsort ( $author_qid_counter, SORT_NUMERIC ) ;
print "<h2>Author items in these papers</h2>" ;
print "<ul>" ;
foreach ( $author_qid_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li><a href='author_item_oauth.php?limit=50&id=$qt' style='color:green'>$label</a> (<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($name) . "&filter=wdt%3AP50+wd%3A$qt'>$cnt&times;</a>)</li>";
}
print "</ul>" ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common author name strings in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($a) . "'>$a</a> (<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($a) . "&filter=wdt%3AP2093+%22" . urlencode($name) . "%22'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $venue_counter , SORT_NUMERIC ) ;
print "<h2>Publishing venues for these papers</h2>" ;
print "<ul>" ;
foreach ( $venue_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li>" . wikidata_link($qt, $label, 'black') . " (<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($name) . "&filter=wdt%3AP1433+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $topic_counter , SORT_NUMERIC ) ;
print "<h2>Topics for these papers</h2>" ;
print "<ul>" ;
foreach ( $topic_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li>" . wikidata_link($qt, $label, 'brown') . " (<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($name) . "&filter=wdt%3AP921+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

print_footer() ;

?>
