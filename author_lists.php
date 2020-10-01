<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);
$oauth->interactive = false;

$action = get_request ( 'action' , '' ) ;

$list_id = get_request ( 'list_id' , '' ) ;
$compare_id = get_request ( 'compare_id' , '' ) ;
$page = intval(get_request ( 'page', '1' ));
$limit = intval(get_request ( 'limit', '100' ));

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'author_lists.php');
	exit(0);
}

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');

if ($action == 'delete') {
	$delete_list = [$list_id];
	if ($list_id == '') {
		$delete_list = get_request ( 'deletions' , array() ) ;
	}
	if ($oauth->isAuthOK()) {
		foreach ($delete_list AS $delete_id) {
			$auth_list = new AuthorList($delete_id);
			$auth_list->load($db_conn);
			$auth_list->delete($oauth->userinfo->name, $db_conn);
		}
	}
}

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

$owner = $oauth->userinfo->name;

if ($action == 'create') {
	$params = array();
	$params['label'] = get_request ( 'label', 'Unlabeled');
	$author_qids = get_request ( 'author_qids', '');
	$params['qids'] = preg_split('/[\r\n]+/', $author_qids);
	$params['owner'] = $owner;

	$auth_list = new AuthorList(NULL, $params);
	$auth_list->save($db_conn);
}

if ($action == 'update') {
	$auth_list = new AuthorList($list_id);
	$auth_list->load($db_conn);
	if ($auth_list->owner === $owner) {
		$auth_list->label = get_request ( 'label', 'Unlabeled');
		$author_qids = preg_split('/[\r\n]+/', get_request ( 'author_qids', ''));
		$article_qids = preg_split('/[\r\n]+/', get_request ( 'article_qids', ''));
		$articles = generate_article_entries2( $article_qids );
		$author_qid_map = array();
		foreach ($auth_list->author_qids AS $qid) {
			if (substr($qid, 0, 1) === 'Q') {
				$author_qid_map[$qid] = 1;
			}
		}
		foreach ( $author_qids AS $qid ) {
			if (substr($qid, 0, 1) === 'Q') {
				$author_qid_map[$qid] = 1;
			}
		}
		foreach ( $articles AS $article_entry ) {
			foreach ( $article_entry->authors AS $art_auth_list ) {
				foreach ( $art_auth_list AS $qid ) {
					$author_qid_map[$qid] = 1;
				}
			}
		}
		$auth_list->author_qids = array_keys($author_qid_map);
		$auth_list->save($db_conn);
	} else {
		print "You don't own this author list; updating disallowed.";
	}
}

if ($action == 'remove_from_list') {
	$auth_list = new AuthorList($list_id);
	$auth_list->load($db_conn);
	if ($auth_list->owner === $owner) {
		$removal_list = get_request ( 'removal' , array() ) ;
		$auth_list->author_qids = array_diff($auth_list->author_qids, $removal_list);
		$auth_list->save($db_conn);
	} else {
		print "You don't own this author list; updating disallowed.";
	}
}

$viewall = get_request ('viewall', '');

if ( $list_id == '') {
	print '<div>';
	if ($viewall == '') {
		print '<b>My author lists</b> - <a href="?viewall=true">View author lists from all users</a>';
	} else {
		print '<b>All author lists</b> - <a href="?viewall=">View just my author lists</a>';
	}
	print '</div>';

	$author_lists = [];
	if ($viewall == '') {
		$lists_count = AuthorList::lists_count($db_conn, $owner);
		$max_page = ($lists_count - 1)/$limit + 1;
		print "<div>$lists_count total lists</div>";
		print "<div>";
		print "<a href='?page=1'>latest</a> | <a href='?page=$max_page'>earliest</a>";
		$prev_page = $page - 1;
		$next_page = $page + 1;
		if ($prev_page > 0) {
			print " | <a href='?page=$prev_page'>newer</a>";
		} else {
			print "| newer";
		}
		if ($next_page <= $max_page) {
			print " | <a href='?page=$next_page'>older</a>";
		} else {
			print "| older";
		}
		print "</div>";
		print "<form method='post' class='form'>" ;
		print "<input type='hidden' name='action' value='delete' />";
		print '<div>
<a href="#" onclick=\'$($(this).parents("form")).find("input[type=checkbox]").prop("checked",true);return false\'>Check all</a> | 
<a href="#" onclick=\'$($(this).parents("form")).find("input[type=checkbox]").prop("checked",false);return false\'>Uncheck all</a>
</div>
';
		$author_lists = AuthorList::lists_for_owner($db_conn, $owner, $limit, $page);
		$delete_count = 0;
	} else {
		$author_lists = AuthorList::all_lists($db_conn, $limit, $page);
	}

	if (count($author_lists) == 0) {
		print "No lists found";
	} else {
	    print "<table class='table table-striped table-condensed'>";
	    print "<tr><th>";
	    if ($viewall != '') print "Owner";
	    print "</th><th>List ID</th><th>Label</th><th>Item Count</th><th>Last Updated</th></tr>";
	    foreach ($author_lists AS $author_list) {
		$id = $author_list->list_id;
		print "<tr><td>";
		if ($viewall == '') {
			$delete_count += 1;
			print "<input type='checkbox' name='deletions[$id]' value='$id'/></td>" ;
		} else {
			print $author_list->owner;
		}
		print "</td>";
		print "<td><a href='?list_id=$id'>$id</a></td>";
		print "<td>" . $author_list->label. "</td>";
		print "<td>" . $author_list->count. "</td>";
		print "<td>" . $author_list->updated_date . "</td>";
		print "</tr>";
	    }
	    print "</table>";
	}
	if ($viewall == '') {
		if ($delete_count > 0) {
			print "<div style='margin:20px'><input type='submit' name='doit' value='Delete selected lists' class='btn btn-primary' /> </div>";
		}
		print "</form>" ;
		print "<strong>Create a new list:</strong>\n";
		print "<form method='post' class='form'>" ;
		print "<input type='hidden' name='action' value='create' />";
		print "Label: <input name='label' type='text' size='40'/>\n";
		print "<div>Enter QID's for authors, 1 per line:</div>\n";
		print "<div><textarea name='author_qids' rows='5' cols='40'></textarea></div>\n";
		print "<div style='margin:20px'><input type='submit' name='doit' value='Create author list with these ids' class='btn btn-primary' /></div>" ;
		print "</form>\n" ;
	}
} else if ($compare_id != '') {
	$wil = new WikidataItemList ;

	$list1 = new AuthorList($list_id);
	$list1->load($db_conn);
	$list2 = new AuthorList($compare_id);
	$list2->load($db_conn);
	print "<h2>Comparison of " . $list1->label . " ($list_id) with " . $list2->label . " ($compare_id)</h2>\n";
	$comparison = new CompareLists($list1->author_qids, $list2->author_qids);
	print "<h3>Only in List <a href='?list_id=$list_id'>$list_id</a>:</h3>";
	$author_data_rows = author_data_rows($comparison->only1, $wil);
	print author_data_table($author_data_rows, "");
	print "<h3>Only in List <a href='?list_id=$compare_id'>$compare_id</a>:</h3>";
	$author_data_rows = author_data_rows($comparison->only2, $wil);
	print author_data_table($author_data_rows, "");
	print "<h3>In both:</h3>";
	$author_data_rows = author_data_rows($comparison->both, $wil);
	print author_data_table($author_data_rows, "");
} else {
	$wil = new WikidataItemList ;

	$author_lists = AuthorList::lists_for_owner($db_conn, $owner);

	$author_list = new AuthorList($list_id);
	$author_list->load($db_conn);
	print "<h2>" . $author_list->label . "</h2>\n";
	print "<h3>Author List $list_id for " . $author_list->owner . " last updated " . $author_list->updated_date . "</h3>\n";

	print "<form method='get' class='form form-inline'>";
	print "<input type='hidden' name='list_id' value='$list_id' />";
	print "Compare to List: <select name='compare_id'>" ;
	print "<option value='' selected>select one...</option>";
	foreach ($author_lists AS $auth_list) {
		$id = $auth_list->list_id;
		$label = $auth_list->label;
		print "<option value='$id'>$label</option>" ;
	}
	print "</select><input type='submit' class='btn btn-primary' name='doit' value='Compare' /></form>";

	$author_data_rows = author_data_rows($author_list->author_qids, $wil);
	print "<form method='post' class='form'>" ;
	print "<input type='hidden' name='action' value='remove_from_list' />";
	print "<input type='hidden' name='list_id' value='$list_id' />";

	print author_data_table($author_data_rows, "removal");
	print "<div style='margin:20px'><input type='submit' name='doit' value='Remove checked authors' class='btn btn-primary' /></div>" ;
	print "</form>";

	print "<strong>Update this list:</strong>\n";
	print "<form method='post' class='form'>" ;
	print "<input type='hidden' name='action' value='update' />";
	print "<input type='hidden' name='list_id' value='$list_id' />";
	print "Label: <input name='label' type='text' size='40' value='$author_list->label'/>\n";
	print "<div>QID's for additional authors, 1 per line:</div>\n";
	print "<div><textarea name='author_qids' rows='5' cols='40'>";
	print "</textarea></div>\n";
	print "<div>QID's for articles (add identified authors):</div>\n";
	print "<div><textarea name='article_qids' rows='5' cols='40'>";
	print "</textarea></div>\n";
	print "<div style='margin:20px'><input type='submit' name='doit' value='Update author list' class='btn btn-primary' /></div>" ;
	print "</form>";
}
$db_conn->close();

print_footer() ;

?>
