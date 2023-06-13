<?PHP

// Various display handlers
function wikidata_link ( $q, $text, $color ) {
	global $wikibase_endpoint;
	$style = '';
	if ( $color != '' ) {
		$style = "style='color:$color'" ;
	}
	return "<a href='https://$wikibase_endpoint/wiki/$q' target='_blank' $style'>$text</a>" ;
}

function getORCIDurl ( $s ) {
	return "https://orcid.org/orcid-search/search?searchQuery=" . urlencode($s) ;
}

function print_footer () {
	print "<hr/><a href='https://github.com/arthurpsmith/author-disambiguator/issues' target='_blank'>Feedback</a><br/><a href='https://github.com/arthurpsmith/author-disambiguator/'>Source and documentation (at github)</a><br/>" ;
	print "<a href='https://www.wikidata.org/wiki/Wikidata:Tools/Author_Disambiguator'>Wikidata page</a><br/>";
	print "</div></div></body></html>" ;
}

function print_name_example() {
	print "<hr/>Some example names with works needing to be matched:<ul>";

	$offset = rand(0,20000);
	$sparql = "SELECT ?name WHERE { ?work p:P2093 [ps:P2093 ?name; pq:P1545 ?ord] . FILTER(STRLEN(?name) > 5) } OFFSET $offset LIMIT 20" ;
	$query_result = getSPARQL($sparql);
	$used_names = [];
	if ( isset($query_result->results) ) {
		$bindings = $query_result->results->bindings ;
		$name = '';
		foreach ( $bindings AS $binding ) {
			$name  = $binding->name->value ;
			if (! isset($used_names[$name]) ) {
				print "<li><a href='?name=" . urlencode($name) . "'>$name</a></li>";
				$used_names[$name] = 1;
			}
		}
	}
	print "</ul>";
}

function print_auth_example() {
	print "<hr/>Some example authors:<ul>";

	print "<li><a href='?id=Q42'>Douglas Adams</a></li>";
	print "<li><a href='?id=Q937'>Albert Einstein</a></li>";
	print "<li><a href='?id=Q193803'>Roger Penrose</a></li>";
	print "</ul>";
}

function print_work_example() {
	print "<hr/>Some example works needing authors matched:<ul>";

	$offset = rand(0,10000);
	$sparql = "SELECT ?work ?workLabel WHERE { { SELECT ?work WHERE { ?work p:P2093 [ps:P2093 ?name; pq:P1545 ?ord] . } LIMIT 10010 } SERVICE wikibase:label { bd:serviceParam wikibase:language \"[AUTO_LANGUAGE],en\". } } OFFSET $offset LIMIT 5" ;
	$query_result = getSPARQL($sparql);
	$used_qids = [];
	if ( isset($query_result->results) ) {
		$bindings = $query_result->results->bindings ;
		$name = '';
		foreach ( $bindings AS $binding ) {
			$work_qid  = item_id_from_uri($binding->work->value) ;
			$work_label  = $binding->workLabel->value ;
			if (! isset($used_qids[$work_qid]) ) {
				print "<li><a href='?id=$work_qid'>$work_label</a></li>";
				$used_qids[$work_qid] = 1;
			}
		}
	}
	print "</ul>";
}

function compress_display_list($list, $highlights, $total_limit, $limit_first, $limit_nbr) {
	if (count($list) <= $total_limit) return $list ;
	$compressed_list = array();

        $int_highlights = array();
	foreach ($highlights as $hl_index) {
                $hl_value = $hl_index ;
		if (! is_numeric($hl_index) ) {
		    $hl_value = preg_replace('/-.*$/', '', $hl_index);
		}
		$int_highlights[] = $hl_value ;
        }
	foreach ($list AS $index => $item) {
		if ($index == 'unordered') {
			$compressed_list[] = $item;
			continue;
		}
		if ($index <= $limit_first) {
			$compressed_list[] = $item;
			continue;
		}
		$in_highlight = 0;
		foreach ($int_highlights as $hl_index) {
			if ( $hl_index == 'unordered' ) {
				continue ;
			}
			if (($index >= $hl_index - $limit_nbr) && ($index <= $hl_index + $limit_nbr)) {
				$compressed_list[] = $item ;
				$in_highlight = 1;
				break(1) ;
			}
		}
		if ($in_highlight == 0) {
			if ($compressed_list[count($compressed_list) - 1] != '...') $compressed_list[] = '...' ;
		}
	}
	return $compressed_list ;
}

function generate_batch_id () {
	return substr(uniqid(), -6); # Last 6 letters of uniqid
}

// Generate the string the EditGroups app expects for this application
function edit_groups_string ( $batch_id = '' ) {
    if ($batch_id == '') {
	$batch_id = generate_batch_id();
    }
    return "([[:toollabs:editgroups/b/AD/$batch_id|details]])";
}

function disambig_header ($use_oauth_menu) {
	$title = 'Author Disambiguator';
	if ( !headers_sent() ) {
		header('Content-type: text/html; charset=UTF-8');
		header('Cache-Control: no-cache, must-revalidate');
	}
	$dir = __DIR__ . '/../resources/html' ;
	$f1 = file_get_contents ( "$dir/index_bs4.html" ) ;
	$f2 = "";
	if ( $use_oauth_menu ) {
		$f2 = file_get_contents( "$dir/menubar_oauth.html" );
	} else {
		$f2 = file_get_contents ( "$dir/menubar_bs4.html" ) ;
	}
	$f3 = '<div id="main_content" class="container"><div><div class="col-sm-12" style="margin-bottom:20px;margin-top:10px;">' ;
	$s = $f1 . $f2 . $f3 ;
	$s = str_replace ( '</head>' , "<title>$title</title></head>" , $s ) ;

	$s = str_replace ( '$$TITLE$$' , $title , $s ) ;
	return $s ;
}

function author_data_rows($author_qids, $wil) {
	$wil->loadItems ( $author_qids ) ;
	$auth_data = AuthorData::authorDataFromItems( $author_qids, $wil, false, false ) ;
	$to_load = array();
	foreach ($auth_data AS $author_data) {
		foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
	}
	$to_load = array_unique($to_load);
	$wil->loadItems ( $to_load ) ;

	$author_rows = array();
	foreach ($auth_data as $author_data) {
		$qid = $author_data->qid;
		$label = $author_data->label;
		$row_data = array();
		$row_data['name'] = "<a href='author_item_oauth.php?id=$qid&limit=50' target='_blank' style='color:green'>$label</a>" ;
		$row_data['count'] = $author_data->article_count ;
		if ( $author_data->redirect != NULL ) {
			$row_data['desc'] = "Redirected to " . $author_data->redirect ;
			$row_data['employers'] = '';
		} else {
			$row_data['desc'] = $author_data->desc;
			$employers = array();
			foreach ( $author_data->employer_qids AS $emp_qid ) {
				$emp_item = $wil->getItem ( $emp_qid ) ;
				if ( !isset($emp_item) ) continue ;
				$employers[] = wikidata_link($emp_qid, $emp_item->getLabel(), '') ;
			}
			$row_data['employers'] = implode(" | ", $employers) ;
		}
		$author_rows[$qid] = $row_data;
	}
	return $author_rows;
}

function  author_data_table($author_rows, $checkbox_label) {
	$html_string = "<table class='table table-striped table-condensed'><tr><th>#</th>";
	if ($checkbox_label != '') {
		$html_string .= "<th></th>";
	}
	$html_string .= "<th>Qid</th><th>Author</th><th>Description</th><th>Works</th><th>Affiliations</th></tr>";
	$index = 1;
	foreach ($author_rows as $qid => $author_row) {
		$html_string .= "<tr>";
		$html_string .="<td>$index</td>";
		if ($checkbox_label != '') {
			$html_string .= "<td><input type='checkbox' name='$checkbox_label" . '[' . $qid . "]' value='$qid'/></td>" ;
		}
		$html_string .= "<td>" . wikidata_link($qid, $qid, '') . "</td>";
		$html_string .= "<td>" . $author_row['name'] . "</td>";
		$html_string .= "<td>" . $author_row['desc'] . "</td>";
		$html_string .= "<td>" . $author_row['count'] . "</td>";
		$html_string .= "<td>" . $author_row['employers'] . "</td>";
		$html_string .= "</tr>\n";
		$index += 1;
	}
	$html_string .= "</table>";
	return $html_string;
}

?>
