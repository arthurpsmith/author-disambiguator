<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;

$oauth_url = str_replace('precise_cluster.php', 'names_oauth.php', $_SERVER['REQUEST_URI']);

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
if (limit_requests( $db_conn, 10 ) ) {
	$db_conn->close();

	if (strpos($oauth_url, '?') === false) {
		$oauth_url .= '?precise=1';
	} else {
		$oauth_url .= '&precise=1';
	}

	print disambig_header( False );
	print "<h1>Too many requests</h1>";
	print "Please wait before making another request of this service; note that use of <a href='$oauth_url'>the OAuth option</a> is not rate-limited.";
	print_footer() ;
	exit ( 0 ) ;
}
$prefs = new Preferences;
$use_scholarly_subgraph = $prefs->use_scholarly_subgraph;
$db_conn->close();

$action = get_request ( 'action' , '' ) ;
$name = trim ( str_replace ( '_' , ' ' , get_request ( 'name' , '' ) ) ) ;
$fuzzy = get_request ( 'fuzzy' , 0 ) * 1 ;
$fuzzy_checked = $fuzzy ? 'checked' : '' ;
$wbsearch = get_request ( 'wbsearch' , 0 ) * 1 ;
$wbsearch_checked = $wbsearch ? 'checked' : '' ;
$filter = get_request ( 'filter', '' ) ;
$filter_authors = get_request ( 'filter_authors', '') ;
$filter_authors_checked = $filter_authors ? 'checked' : '' ;
$filter_authors_checked = $filter_authors ? 'checked' : '' ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 500 ;
$limit_options = [10, 50, 200, 500] ;

$use_name_strings = get_request ( 'use_name_strings' , 0 ) * 1 ;
$use_name_strings_checked = $use_name_strings ? 'checked' : '' ;
$name_strings = get_request ( 'name_strings' , '') ;
$input_names = preg_split('/[\r\n]+/', $name_strings);

print disambig_header( False );

print "<div style='font-size:9pt'>(<a href='$oauth_url'> Log in to your Wikimedia account to use OAuth instead of Quickstatements for updates.</a>) </div> " ;
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
		$names = $nm->names_from_wbsearch( $names, $use_scholarly_subgraph );
	}
}

if ( $action == 'add' ) {
	print "<form method='post' class='form' action='$quickstatements_api_url'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;
	$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
	$viaf_author = trim ( get_request ( 'viaf_author' , '' ) ) ;
	$researchgate_author = trim ( get_request ( 'researchgate_author' , '' ) ) ;
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$author_q = $author_match ;
        if ( $author_match == 'manual' ) {
           $author_q = trim ( get_request ( 'q_author' , '' ) );
        }

	$papers = get_request ( 'papers' , array() ) ;

	if ( $author_match == 'new' ) {
		print "<br/>Quickstatements V1 commands for creating new author item:" ;
		$commands = new_author_qs_commands ( $name, $orcid_author, $viaf_author, $researchgate_author ) ;
		print "<textarea name='data' rows=5>" . implode("\n",$commands) . "</textarea>" ;
		print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' /><br/>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		print "</form>" ;
		exit ( 0 ) ;
	}
	if ( $author_q == '' ) {
		print "Sorry, can't find author" ;
		exit ( 0 ) ;
	}

	$commands = replace_authors_qs_commands ( $papers, $names, $author_q, $use_scholarly_subgraph ) ;

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}

print "<form method='get' class='form form-inline'>
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
<div style='font-size:9pt'>Additional SPARQL filters separated by semicolons (eg. for papers on Zika virus, enter wdt:$topic_prop_id wd:Q202864):
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
$items_papers = getSPARQLitems ( $sparql, $use_scholarly_subgraph ) ;
$limit_reached = (count($items_papers) == $article_limit) ;
$items_papers = array_unique( $items_papers );

// Potential authors
$author_filter = $filter_authors ? "?article wdt:P50 ?q $filter_in_context" : '' ;
$items_authors = array() ;
$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31 wd:$human_qid . $author_filter }" ;
#print $sparql ;
$items_individual_authors = getSPARQLitems ( $sparql, false ) ;

if (strlen($nm->last_name) < 4) {
	$items_collective_authors = []; # Otherwise may time out
} else {
	$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31/wdt:P279* wd:$human_group_qid . $author_filter }" ;
#print $sparql ;
	$items_collective_authors = getSPARQLitems ( $sparql, false ) ;
}
$sparql = "SELECT DISTINCT ?q WHERE { VALUES ?name { $author_names_strings } . ?paper p:P50 ?statement . ?statement ps:P50 ?q ; pq:P1932 ?name . $author_filter FILTER NOT EXISTS {?q owl:sameAs ?redirect} }" ;
#print $sparql ;
$items_stated_as_authors = getSPARQLitems ( $sparql, $use_scholarly_subgraph ) ;

$items_authors = array_unique( array_merge( $items_individual_authors, $items_collective_authors, $items_stated_as_authors ) ) ;

// Load items
$wil = new WikidataItemList ;
$to_load = array() ;
foreach ( $items_authors AS $q ) $to_load[] = $q ;
$wil->loadItems ( $to_load ) ;

$potential_author_data = AuthorData::authorDataFromItems( $items_authors, $wil, true, $use_scholarly_subgraph ) ;
$to_load = array() ;
foreach ($potential_author_data AS $author_data) {
	foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
}
$to_load = array_unique($to_load);
$wil->loadItems ( $to_load ) ;

print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='wbsearch' value='$wbsearch' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

$article_items = generate_article_entries( $items_papers, $use_scholarly_subgraph );

# Just need labels for the following:
$qids_to_label = array();
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth) $qids_to_label[$auth] = 1 ;
	foreach ( $article->published_in AS $pub ) $qids_to_label[$pub] = 1 ;
	foreach ( $article->topics AS $topic ) $qids_to_label[$topic] = 1 ;
}
$qid_labels = AuthorData::labelsForItems(array_keys($qids_to_label));

$clusters = array();
foreach ($article_items as $article) {
	foreach ( $article->author_names AS $num => $a ) {
		if ( in_array ( $a , $names ) ) {
			$neighbor_names = neighboring_author_strings($article, $num);
			$article_num = $article->q . ':' . $num ;
			if (! isset($clusters[$neighbor_names])) {
				$clusters[$neighbor_names] = array();
			}
			$clusters[$neighbor_names][] = $article_num;
		}
	}
}

uasort ( $clusters, function ($a, $b) {
		return count($b) - count($a) ;
	}
);

foreach ($clusters as $key => $cluster) {
	if (count($cluster) == 1) {
		if (! isset($clusters['Misc']) ) {
			$clusters['Misc'] = array();
		}
		$clusters['Misc'][] = $cluster[0];
		unset($clusters[$key]);
	}
}

foreach (array_keys($clusters) as $key) {
	usort( $clusters[$key], function ($a, $b ) use ($article_items) {
		$q_a = substr($a, 0, strpos($a, ':'));
		$q_b = substr($b, 0, strpos($a, ':'));
		$article_a = $article_items[$q_a] ;
		$article_b = $article_items[$q_b] ;
		return WikidataArticleEntry::dateCompare($article_a, $article_b);
	} ) ;
}

// Publications
$name_counter = array() ;
print "<h2>Potential publications</h2>" ;
print "<p>" . count($article_items) . " publications found</p>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; process these papers and then reload to see if there are more for this author name string</div>" ;
}
print "<div style='font-size:9pt'><a href='index.php?name=" . urlencode($name) . "&fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&use_name_strings=$use_name_strings&name_strings=" . urlencode($name_strings) . "'> Click here for rougher clustering.</a> </div> " ;

$is_first_group = true ;
foreach ( $clusters AS $cluster_name => $cluster ) {
	print "<div class='group'>" ;
	print "<h3>$cluster_name</h3>" ;
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
	foreach ( $cluster AS $article_author ) {
		$sep_pos = strpos($article_author, ':');
		$q = substr($article_author, 0, $sep_pos);
		$auth_num = substr($article_author, $sep_pos + 1) ;
		$article = $article_items[$q];

		$formatted_authors = array();
		foreach ( $article->author_names AS $num => $a ) {
			if ( $num == $auth_num ) {
				$formatted_authors[$num] = "[$num]" .
			"<input type='checkbox' name='papers[$q:$num]' value='$q:$num' " .
			($is_first_group?'checked':'') . " /><b>$a</b>" ;
			} else {
				$formatted_authors[$num] = "[$num]<a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		
		foreach ( $article->authors AS $num => $qt ) {
			$display_num = $num ;
			if (isset($formatted_authors[$num])) {
				$display_num = "$num-$qt";
			}
			$label = $qid_labels[$qt];
			$formatted_authors[$display_num] = "[$display_num]<a href='author_item.php?id=$qt' target='_blank' style='color:green'>$label</a>" ;
		}
		ksort($formatted_authors);
		$authors_list = implode ( ', ' , compress_display_list($formatted_authors, [$auth_num], 20, 10, 2)) ;

		$published_in = array() ;
		foreach ( $article->published_in AS $qt ) {
			$label = $qid_labels[$qt];
			$published_in[] = wikidata_link($qt, $label, 'black') . "&nbsp;[<a href='https://scholia.toolforge.org/venue/$qt/curation' target='_blank'>curation</a>]" ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		print "<tr>" ;
		print "<td style='width:20%;font-size:10pt'>" . wikidata_link($q, $article->title, '') . "</td>" ;
		print "<td style='width:50%;font-size:9pt'>$authors_list <a href='work_item.php?id=$q'>[Full author list]</a></td>" ;
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
				$topics[] = wikidata_link($qt, $label, 'brown') . "&nbsp;[<a href='https://scholia.toolforge.org/topic/$qt/curation' target='_blank'>curation</a>]" ;
			}
			print implode ( '; ' , $topics ) ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		print $article->formattedPublicationDate () ;
		print "</td><td style='font-size:10pt'>" ;

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
	print "<td><a href='author_item.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a></td>" ;
	print "<td>" . $i->getDesc() . "</td>" ;
	print "<td>$author_data->article_count</td>" ;
	print "<td>" ;
	foreach ( $author_data->identifiers AS $id_prop => $id_value ) {
		$id_prop_details = $identifier_details[$id_prop];
		$prop_label = $id_prop_details['label'];
		$prop_url = $id_prop_details['url_prefix'] . $id_value;
		if ( $prop_label == 'ISNI' ) {
			$prop_url = $id_prop_details['url_prefix'] .
				preg_replace( '/\s+/', '', $id_value );
		}
		print "$prop_label: <a target='_blank' href='$prop_url'>$id_value</a><br/>" ;
	}
	print "</td><td style='font-size:9pt'>" ;
	foreach ( $author_data->employer_qids AS $emp_qid ) {
		$emp_item = $wil->getItem ( $emp_qid ) ;
		if ( !isset($emp_item) ) continue ;
		print wikidata_link($emp_qid, $emp_item->getLabel(), '') . "&nbsp;[<a href='https://scholia.toolforge.org/organization/$emp_qid/curation' target='_blank'>curation</a>]<br/>" ;
	}
	print "</td></tr>" ;
}
print "<tr><td><input type='radio' name='author_match' value='manual' checked /></td><td><input type='text' name='q_author' placeholder='Qxxx' /></td><td colspan='4'>Other Q number of this author</td></tr>" ;
print "</tbody></table>" ;

print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to link works to author' class='btn btn-primary' /></div>" ;
print "</form>" ;

print "<h2>New Author Item</h2>" ;
print '(if the author you are looking for is not listed above and otherwise not yet in Wikidata)';

print "<form method='post' class='form form-inline' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='author_match' value='new'/>
<div>Author name: <input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' /></div>";
print "<div><a href='" . getORCIDurl($name) . "' target='_blank'>Check ORCID for $name</a> | Author has ORCID ID: <input type='text' name='orcid_author' placeholder='xxxx-xxxx-xxxx-xxxx' /></div>" ;
print "<div><a href='https://viaf.org/en/viaf/search?field=local,personalNames&index=VIAF&searchTerms=$name' target='_blank'>Check VIAF for $name</a> | Author has VIAF ID: <input type='text' name='viaf_author' placeholder='xxxxxxxxxxxxxxxxxxxx' /></div>" ;
print "<div><a href='https://www.researchgate.net/search/researcher?q=$name' target='_blank'>Check ResearchGate for $name</a> | Author has ResearchGate Profile ID: <input type='text' name='researchgate_author' placeholder='Xxxxxxx_Xxxxxx' /></div>" ;
print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to create author item' class='btn btn-primary' /></div>" ;
print "</form>" ;
print '<div>After creating the new author item, enter the Wikidata ID in the "Other Q number of this author" field above to link to their works.</div>' ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='?fuzzy=$fuzzy&wbsearch=$wbsearch&limit=$article_limit&name=" . urlencode($a) . "'>$a</a> ($cnt&times;)</li>" ;
}
print "</ul>" ;

print_footer() ;

?>
