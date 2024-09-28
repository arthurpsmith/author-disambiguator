<?PHP

# Modified to allow for multiple author entries with same ordinal

class WikidataArticleEntry2 {
	public $q = '' ;
	public $title = '' ;
	public $author_names = array() ;
	public $authors = array() ;
	public $authors_stated_as = array() ;
	public $published_in = array() ;
	public $claims_with_multiple_ordinals = array();
	public $doi = '' ;
	public $pmid = '' ;
	public $topics = array() ;
	public $publication_date = '';
	public $shifted_numbers = array();
	public $use_scholarly_subgraph = true;

	public function __construct ( $use_scholarly_subgraph, $item = NULL ) {
		global $doi_prop_id, $pubmed_prop_id, $title_prop_id,
			$published_date_prop_id, $published_in_prop_id,
			$topic_prop_id ;

		$this->use_scholarly_subgraph = $use_scholarly_subgraph;

		if (! isset($item) ) {
			return ;
		}
		$this->q = $item->getQ() ;

		$title = $item->getStrings ( $title_prop_id ) ;
		if ( count($title) == 0 ) $this->title = $item->getLabel() ;
		else $this->title = $title[0] ;

		$claims = $item->getClaims ( 'P2093' ) ; // Author strings
		foreach ( $claims AS $c ) {
			$author_name = $c->mainsnak->datavalue->value ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$ordinal_values = $c->qualifiers->P1545 ;
				if (count($ordinal_values) > 1) {
					$this->claims_with_multiple_ordinals[] = $c->id;
				}
				foreach ($ordinal_values AS $tmp) {
					$num = $tmp->datavalue->value ;
					if ( ! isset($this->author_names[$num]) ) {
						$this->author_names[$num] = [] ;
					}
					$this->author_names[$num][$c->id] = $author_name;
				}
			} else {
				if ( ! isset($this->author_names['unordered']) ) {
					$this->author_names['unordered'] = [] ;
				}
				$this->author_names['unordered'][$c->id] = $author_name ;
			}
		}
		ksort($this->author_names) ;

		$claims = $item->getClaims ( 'P50' ) ;
		foreach ( $claims AS $c ) {
			$author_q = $item->getTarget($c) ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$ordinal_values = $c->qualifiers->P1545 ;
				if (count($ordinal_values) > 1) {
					$this->claims_with_multiple_ordinals[] = $c->id;
				}
				foreach ($ordinal_values AS $tmp) {
					$num = $tmp->datavalue->value ;
					if ( ! isset($this->authors[$num]) ) {
						$this->authors[$num] = [] ;
					}
					$this->authors[$num][$c->id] = $author_q ;
				}
			} else {
				if ( ! isset($this->authors['unordered']) ) {
					$this->authors['unordered'] = [] ;
				}
				$this->authors['unordered'][$c->id] = $author_q ;
			}
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1932) ) {
				$tmp = $c->qualifiers->P1932 ;
				$name = $tmp[0]->datavalue->value ;
				$this->authors_stated_as[$author_q] = $name ;
			}
		}
		ksort($this->authors) ;

		$claims = $item->getClaims ( $published_in_prop_id ) ;
		foreach ( $claims AS $c ) {
			$this->published_in[] = $item->getTarget($c) ;
		}
		$x = $item->getStrings ( $doi_prop_id ) ;
		if ( count($x) > 0 ) {
			$this->doi = $x[0] ;
		}
		$x = $item->getStrings ( $pubmed_prop_id ) ;
		if ( count($x) > 0 ) {
			$this->pmid = $x[0] ;
		}
		if ( $item->hasClaims( $topic_prop_id ) ) { // main subject
			$claims = $item->getClaims( $topic_prop_id ) ;
			foreach ( $claims AS $c ) {
				$qt = $item->getTarget ( $c ) ;
				$this->topics[] = $qt ;
			}
		}
		if ( $item->hasClaims( $published_date_prop_id ) ) { // publication date
			$claims = $item->getClaims( $published_date_prop_id ) ;
			if ( count($claims) > 0 ) $this->publication_date = $claims[0]->mainsnak->datavalue->value->time ;
		}
	}

	public function formattedPublicationDate () {
		$formatted_date = '' ;
		if ( $this->publication_date != '' ) $formatted_date = DateTime::createFromFormat( '\+Y-m-d\TH:i:s\Z', $this->publication_date )->format( "Y-m-d" );
		return $formatted_date ;
	}

	public static function dateCompare ($a, $b) {
		$adate = $a->publication_date ;
		$bdate = $b->publication_date ;
		if ($adate == $bdate) {
			return 0;
		}
		return ($adate > $bdate) ? -1 : 1 ;
	}

# For each numeric index check if there are multiple author names or ids
# that are compatible (names are same or could be mapped in some variation)
# Use $auth_num_shift to adjust index of entries if incompatibilties found
	public function merge_candidates ($wil, $all_stated_as, $auth_num_shift = 0) {
		$evaluation_by_index = array();
		$name_indexes = array_keys($this->author_names);
		$auth_indexes = array_keys($this->authors);
		$all_indexes = array_unique(array_merge($name_indexes, $auth_indexes));
		sort($all_indexes, SORT_NUMERIC);
		foreach ($all_indexes as $num) {
			$author_names = [] ;
			if ( isset($this->author_names[$num]) ) {
				$author_names = $this->author_names[$num] ;
			}
			$authors = [] ;
			if ( isset($this->authors[$num]) ) {
				$authors = $this->authors[$num] ;
			}
			if ( count($author_names) + count($authors) <= 1 ) {
				$evaluation_by_index[$num] = FALSE;
				continue;
			}
			$eval = evaluate_names_for_ordinal($author_names, $authors, $all_stated_as, $wil) ;
			if ( ($auth_num_shift != 0) && (! $eval ) && ( $num != 'unordered' ) ) {
				$eval = $this->try_shifting( $num, $auth_num_shift, $all_stated_as, $wil, $evaluation_by_index );
			}
			$evaluation_by_index[$num] = $eval;
		}
		return $evaluation_by_index;
	}

	private function try_shifting( $num, $auth_num_shift, $all_stated_as, $wil, &$evaluation_by_index ) {
		$shift_count = 0;
		$num2 = $num + $auth_num_shift;
		$author_names = [] ;
		if ( isset($this->author_names[$num]) ) {
			$author_names = $this->author_names[$num] ;
		}
		$author_names2 = [];
		if ( isset($this->author_names[$num2]) ) {
			$author_names2 = $this->author_names[$num2] ;
		}
		$authors = [] ;
		if ( isset($this->authors[$num]) ) {
			$authors = $this->authors[$num] ;
		}
		$authors2 = [];
		if ( isset($this->authors[$num2]) ) {
			$authors2 = $this->authors[$num2] ;
		}
		$eval = FALSE;
		if ( count($author_names2) + count($authors2) == 0 ) {
			return $eval;
		}
		foreach ( $author_names as $cid => $name ) {
# Don't shift twice:
			if ( isset($this->shifted_numbers[$cid]) ) {
				continue;
			}
			$names2_copy = $author_names2;
			$names2_copy[$cid] = $name;
			$eval2 = evaluate_names_for_ordinal($names2_copy, $authors2, $all_stated_as, $wil) ;
			if ( $eval2 ) { # Do the shift:
				$this->shifted_numbers[$cid] = 1;
				$shift_count++;
				$author_names2[$cid] = $name;
				$this->author_names[$num2] = $author_names2;
				unset($author_names[$cid]);
				$this->author_names[$num] = $author_names;
				$evaluation_by_index[$num2] = $eval2;
			}
		}
		foreach ( $authors as $cid => $qid ) {
# Don't shift twice:
			if ( isset($this->shifted_numbers[$cid]) ) {
				continue;
			}
			$authors2_copy = $authors2;
			$authors2_copy[$cid] = $qid;
			$eval2 = evaluate_names_for_ordinal($author_names2, $authors2_copy, $all_stated_as, $wil) ;
			if ( $eval2 ) { # Do the shift:
				$this->shifted_numbers[$cid] = 1;
				$shift_count++;
				$authors2[$cid] = $qid;
				$this->authors[$num2] = $authors2;
				unset($authors[$cid]);
				$this->authors[$num] = $authors;
				$evaluation_by_index[$num2] = $eval2;
			}
		}
		if ( $shift_count > 0 ) {
			$eval = evaluate_names_for_ordinal($author_names, $authors, $all_stated_as, $wil) ;
		}
	}

	public function repeated_ids () {
		return $this->repeated_author_ids( $this->authors );
	}

	public function repeated_author_ids( $author_id_map ) {
		$ids_used = [] ;
		foreach ( $author_id_map  AS $num => $auth_list ) {
			foreach ( $auth_list AS $auth_qid ) {
				if (! isset($ids_used[$auth_qid])) {
					$ids_used[$auth_qid] = [];
				}
				if ( ! in_array($num, $ids_used[$auth_qid]) ) {
					$ids_used[$auth_qid][] = $num ;
				}
			}
		}
		return array_filter($ids_used, function($a) {
				return count($a) > 1 ;
			} );
	}

	public function match_candidates($wil, $qid_list, $all_stated_as) {
		$names_to_qids = array();
		foreach ($qid_list AS $qid) {
			$stated_as_names = isset($all_stated_as[$qid]) ? $all_stated_as[$qid] : [] ;
			$names = all_names_for_author($wil, $qid, $stated_as_names) ;
			foreach ($names AS $name) {
				if (! isset($names_to_qids[$name]) ) {
					$names_to_qids[$name] = [];
				}
				$names_to_qids[$name][] = $qid;
			}
		}
		$matches = array();

		foreach ($this->author_names AS $num => $name_list) {
			foreach ($name_list AS $name) {
				$ta = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
				if ( isset($names_to_qids[$name]) || isset($names_to_qids[$ta]) ) {
					if (! isset($matches[$num]) ) {
						$matches[$num] = [];
					}
					if ( isset($names_to_qids[$name]) ) {
						$matches[$num] = array_merge($matches[$num], $names_to_qids[$name]);
					}
					if ( isset($names_to_qids[$ta]) ) {
						$matches[$num] = array_merge($matches[$num], $names_to_qids[$ta]);
					}
				}
			}
			if (isset($matches[$num])) {
				$matches[$num] = array_unique($matches[$num]);
			}
		}

		return $matches;
	}

# Auto-match unordered authors:
# (1) if another entry with same qid has a series ordinal value, move there
# (2) if not, run match algorithm for these qid's with author names list
	public function auto_match_unordered( $wil ) {
		$unordered_auths = $this->authors['unordered'];
		$repeated_ids = $this->repeated_ids();

		foreach ( $unordered_auths AS $cid => $qid ) {
			if ( ! isset($repeated_ids[$qid]) ) {
				continue;
			}
			$rpt_nums = $repeated_ids[$qid];
			$fix_num = -1;
			$single_num = 1;
			foreach ($rpt_nums as $rpt_num) {
				if ($rpt_num != 'unordered') {
					if ($fix_num == -1) {
						$fix_num = $rpt_num;
					} elseif ($fix_num != $rpt_num) {
						$single_num = 0;
					}
				}
			}
			if ( $single_num && ($fix_num >= 0) ) {
				$this->authors[$fix_num][$cid] = $qid;
				unset($this->authors['unordered'][$cid]);
				$this->shifted_numbers[$cid] = 1;
			}
		}
		$unordered_auths = $this->authors['unordered'];
		$stated_as = fetch_stated_as_for_authors($unordered_auths, $this->use_scholarly_subgraph);
		$matches = $this->match_candidates( $wil, $unordered_auths, $stated_as );
		$candidate_match_nums = [];
		foreach ( $matches AS $num => $qid_list ) {
			foreach ( $qid_list AS $qid ) {
				if (! isset($candidate_match_nums[$qid] ) ) {
					$candidate_match_nums[$qid] = [$num];
				} else {
					$candidate_match_nums[$qid][] = $num;
				}
			}
		}
		foreach ( $unordered_auths AS $cid => $qid ) {
			if ( ! isset($candidate_match_nums[$qid]) ) {
				continue;
			}
			$nums = array_unique($candidate_match_nums[$qid]);
			if (count($nums) > 1) {
				continue;
			}
			$this->authors[$nums[0]][$cid] = $qid;
			unset($this->authors['unordered'][$cid]);
			$this->shifted_numbers[$cid] = 1;
		}
		if (count($this->authors['unordered']) == 0 ) {
			unset($this->authors['unordered']);
			return TRUE;
		}
		return FALSE;
	}

	public function author_statistics() {
		$stats = [];

		$auth_nums = array_keys($this->authors);
		$auth_name_nums = array_keys($this->author_names);

		$stats['identified_count'] = count($auth_nums);
		$stats['name_count'] = count($auth_name_nums);

		if (($key = array_search('unordered', $auth_nums)) !== false) {
			unset($auth_nums[$key]);
		}
		if (($key = array_search('unordered', $auth_name_nums)) !== false) {
			unset($auth_name_nums[$key]);
		}

		$max_auth_num = 0;
		if (count($auth_nums) > 0) {
			$max_auth_num = max($auth_nums);
		}
		$max_auth_name_num = 0;
		if (count($auth_name_nums) > 0) {
			$max_auth_name_num = max($auth_name_nums);
		}
		$stats['max_num'] = max([$max_auth_num, $max_auth_name_num]);
		return $stats;
	}
}

function all_names_for_author($wil, $qid, $stated_as_names) {
	$author_item = $wil->getItem($qid);
	$name = $author_item->getLabel();
	$nm = new NameModel($name);
	$names = $nm->fuzzy_ignore_nonascii();
	$names = array_merge($names, $stated_as_names);
	$search_strings = array_fill_keys($names, 1) ;
	$aliases = $author_item->getAllAliases(); # multi-dimensional...
	array_walk_recursive($aliases, function($a)
		use (&$search_strings) {
			$search_strings[$a] = 1; } ) ;
	$names_so_far = array_keys($search_strings);
	foreach ($names_so_far AS $name_str) {
		$ta = strtoupper(preg_replace('/[^A-Za-z]/', '', $name_str));
		$search_strings[$ta] = 1;
	}
	return array_keys($search_strings);
}

function evaluate_names_for_ordinal($author_names, $authors, $all_stated_as, $wil) {
	$name_count = 0;
	$auth_count = 0;
	$eval = TRUE ;

	if ( isset($author_names) ) {
		$name_count = count($author_names);
	}
	if ( isset($authors) ) {
		$auth_count = count($authors);
	}
	if ($name_count + $auth_count <= 1) {
		return FALSE ;
	}
	if ($auth_count > 0) {
		$qid = NULL;
		foreach ($authors as $auth_qid) {
			if ($qid == NULL ) {
				$qid = $auth_qid ;
			}
			if ($auth_qid != $qid) {
				$eval = FALSE;
				break;
			}
		}
		if ($name_count > 0) {
			$stated_as_names = isset($all_stated_as[$qid]) ? $all_stated_as[$qid] : [] ;
			$names = all_names_for_author($wil, $qid, $stated_as_names) ;
			$search_strings = array_fill_keys($names, 1) ;
			foreach ($author_names as $a) {
				$ta = strtoupper(preg_replace('/[^A-Za-z]/', '', $a));
				if ( ! (isset($search_strings[$a]) || isset($search_strings[$ta]) ) ) {
					$eval = FALSE;
					break;
				}
			}
		}
	} else { # No linked authors yet
		$mapping = array_combine($author_names, array_map('strlen', $author_names));
		$longest_names = array_keys($mapping, max($mapping));
		foreach ($longest_names AS $longest_name) {
			$eval = TRUE;
			$nm = new NameModel($longest_name);
			$names = $nm->fuzzy_ignore_nonascii();
			foreach ($author_names as $a) {
				$ta = strtoupper(preg_replace('/[^A-Za-z]/', '', $a));
				if ( ! (in_array ( $a , $names ) || in_array( $ta, $names) ) ) {
					$eval = FALSE;
					break;
				}
			}
			if ( $eval ) break;
		}
	}
	return $eval;
}

function fetch_stated_as_for_authors($author_qids, $use_scholarly_subgraph) {
	$names = array();

	$batch_size = 250 ;
	$batches = [ [] ] ;
	foreach ( $author_qids AS $k => $v ) {
		if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
		$batches[count($batches)-1][$k] = $v ;
	}
	foreach ( $batches as $batch ) {
		$new_names = fetch_stated_for_batch($batch, $use_scholarly_subgraph);
		$names = array_merge($names, $new_names);
	}
	return $names;
}

function fetch_stated_for_batch($author_qids, $use_scholarly_subgraph) {
	$author_qids_for_sparql = 'wd:' . implode ( ' wd:' , $author_qids) ;

	$sparql = "SELECT DISTINCT ?author_qid ?name WHERE { VALUES ?author_qid { $author_qids_for_sparql } .
	?auth_statement ps:P50 ?author_qid ;
                        pq:P1932 ?name .
}" ;
	$query_result = getSPARQL( $sparql, $use_scholarly_subgraph ) ;
	$bindings = $query_result->results->bindings ;
	$names = array();
	foreach ( $bindings AS $binding ) {
		$author_qid = item_id_from_uri($binding->author_qid->value) ;
		$names[$author_qid][] = $binding->name->value ;
	}
	return $names;
}

function generate_article_entries2($id_list, $use_scholarly_subgraph) {
	$id_uris = array_map(function($id) { return "wd:" . prepend_q($id); }, $id_list);

	$batch_size = 20 ;
	$batches = [ [] ] ;
	foreach ( $id_uris AS $k => $v ) {
		if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
		$batches[count($batches)-1][$k] = $v ;
	}

	$article_entries = array();
	foreach ( $batches as $batch ) {
		$new_article_entries = generate_entries_for_batch2($batch, $use_scholarly_subgraph);
		$article_entries = array_merge($article_entries, $new_article_entries);
	}
	return $article_entries;
}

function claim_uri_to_id($claim_uri) {
	global $wikibase_endpoint ;
	$claim_id = preg_replace ( "/http:\/\/$wikibase_endpoint\/entity\/statement\//" , '' , $claim_uri) ;
	$pos = strpos($claim_id, '-');
	return substr_replace($claim_id, '$', $pos, 1);
}

function generate_entries_for_batch2( $uri_list, $use_scholarly_subgraph ) {
	global $doi_prop_id, $pubmed_prop_id, $title_prop_id,
		$published_date_prop_id, $published_in_prop_id,
		$topic_prop_id ;

	$id_uris = implode(' ', $uri_list);
	$keyed_article_entries = array() ;

	$sparql = "SELECT ?q ?qLabel ?title ?published_in ?doi ?pmid ?topic ?pub_date WHERE {
  VALUES ?q { $id_uris } .
  OPTIONAL { ?q wdt:$title_prop_id ?title } .
  OPTIONAL { ?q wdt:$published_in_prop_id ?published_in } .
  OPTIONAL { ?q wdt:$doi_prop_id ?doi } .
  OPTIONAL { ?q wdt:$pubmed_prop_id ?pmid }.
  OPTIONAL { ?q wdt:$topic_prop_id ?topic }.
  OPTIONAL { ?q wdt:$published_date_prop_id ?pub_date }.
  SERVICE wikibase:label { bd:serviceParam wikibase:language '[AUTO_LANGUAGE],en'. }
}" ;
	$query_result = getSPARQL( $sparql, $use_scholarly_subgraph ) ;
	$bindings = $query_result->results->bindings ;
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = NULL ;
		if (isset ( $keyed_article_entries[$qid] ) ) {
			$article_entry = $keyed_article_entries[$qid] ;
		} else {
			$article_entry = new WikidataArticleEntry2($use_scholarly_subgraph);
			$article_entry->q = $qid ;
			$keyed_article_entries[$qid] = $article_entry ;
		}
		if ( isset( $binding->title ) ) {
			$article_entry->title = $binding->title->value ;
		} else if ( isset( $binding->qLabel ) ) {
			$article_entry->title = $binding->qLabel->value ;
		}
		if ( isset( $binding->published_in ) ) {
			$article_entry->published_in[] = item_id_from_uri( $binding->published_in->value );
		}
		if ( isset( $binding->doi ) ) {
			$article_entry->doi = $binding->doi->value ;
		}
		if ( isset( $binding->pmid ) ) {
			$article_entry->pmid = $binding->pmid->value ;
		}
		if ( isset( $binding->topic ) ) {
			$article_entry->topics[] = item_id_from_uri( $binding->topic->value );
		}
		if ( isset( $binding->pub_date ) ) {
			$article_entry->publication_date = '+' . $binding->pub_date->value ; // Prepend '+' to match what wikidata API returns
		}
	}

	$sparql = "SELECT ?q ?name_statement ?name_string ?ordinal WHERE {
  VALUES ?q { $id_uris } .
  ?q p:P2093 ?name_statement .
  ?name_statement ps:P2093 ?name_string .
  OPTIONAL { ?name_statement pq:P1545 ?ordinal } .
}" ;
	$query_result = getSPARQL( $sparql, $use_scholarly_subgraph ) ;
	if (! isset($query_result->results ) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	$bindings = $query_result->results->bindings ;
	if (! is_array($bindings) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = $keyed_article_entries[$qid] ;
		$claim_uri = $binding->name_statement->value ;
		$claim_id = claim_uri_to_id($claim_uri) ;
		$name = $binding->name_string->value ;
		if ( isset( $binding->ordinal ) ) {
			$num = $binding->ordinal->value ;
		} else {
			$num = 'unordered';
		}
		if ( isset($article_entry->author_names[$num]) ) {
			$article_entry->author_names[$num][$claim_id] = $name ;
		} else {
			$article_entry->author_names[$num] = [$claim_id => $name] ;
		}
	}

	$sparql = "SELECT ?q ?author ?author_statement ?stated_as ?ordinal WHERE {
  VALUES ?q { $id_uris } .
  ?q p:P50 ?author_statement .
  ?author_statement ps:P50 ?author .
  OPTIONAL { ?author_statement pq:P1932 ?stated_as } .
  OPTIONAL { ?author_statement pq:P1545 ?ordinal } .
}" ;
	$query_result = getSPARQL( $sparql, $use_scholarly_subgraph ) ;
	if (! isset($query_result->results ) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	$bindings = $query_result->results->bindings ;
	if (! is_array($bindings) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = $keyed_article_entries[$qid] ;
		$claim_id = claim_uri_to_id($binding->author_statement->value) ;
		$author_q = item_id_from_uri($binding->author->value) ;
		if ( isset( $binding->ordinal ) ) {
			$num = $binding->ordinal->value ;
		} else {
			$num = 'unordered' ;
		}
		if ( isset ( $article_entry->authors[$num] ) ) {
			$article_entry->authors[$num][$claim_id] = $author_q ;
		} else {
			$article_entry->authors[$num] = [$claim_id => $author_q] ;
		}
		if ( isset( $binding->stated_as ) ) {
			$article_entry->authors_stated_as[$author_q] = $binding->stated_as->value ;
		}
	}

	foreach( $keyed_article_entries AS $article_entry ) {
		ksort($article_entry->author_names) ;
		ksort($article_entry->authors) ;
		if (count($article_entry->published_in) > 1) {
			$article_entry->published_in = array_unique($article_entry->published_in);
		}
		if (count($article_entry->topics) > 1) {
			$article_entry->topics = array_unique($article_entry->topics);
		}
	}

	return $keyed_article_entries;
}

function extract_coauthors_from_sparql_query($sparql) {
	$coauthors = array();
	$query_result = getSPARQL( $sparql, $this->use_scholarly_subgraph ) ;
	if (! isset($query_result->results) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $coauthors;
	}
	$bindings = $query_result->results->bindings ;
	foreach ( $bindings AS $binding ) {
		$coauthor_qid = item_id_from_uri($binding->coauthor_qid->value) ;
		$coauthors[$coauthor_qid] = 1;
	}
	return $coauthors;
}

function fetch_related_authors($work_qid, $author_qids) {
	global $cites_work_prop_id ;

	$work_qid_for_sparql = 'wd:' . $work_qid ;

	$coauthors = array();
	$batch_size = 20 ;
	$batches = [ [] ] ;
	foreach ( $author_qids AS $v ) {
		if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
		$batches[count($batches)-1][] = $v ;
	}
	foreach ( $batches as $batch ) {
		$author_qids_for_sparql = 'wd:' . implode ( ' wd:' , $batch) ;
		$sparql = "SELECT DISTINCT ?coauthor_qid WHERE { VALUES ?author_qid { $author_qids_for_sparql } .
	?q wdt:P50 ?author_qid ;
           wdt:P50 ?coauthor_qid .
}" ;
		$coauthors = array_merge($coauthors, extract_coauthors_from_sparql_query($sparql));
	}
	$sparql = "SELECT DISTINCT ?coauthor_qid WHERE {
        ?q2 wdt:$cites_work_prop_id $work_qid_for_sparql ;
            wdt:P50 ?coauthor_qid .
}" ;
	$coauthors = array_merge($coauthors, extract_coauthors_from_sparql_query($sparql));
	$sparql = "SELECT DISTINCT ?coauthor_qid WHERE { VALUES ?author_qid { $author_qids_for_sparql } .
	$work_qid_for_sparql wdt:$cites_work_prop_id ?q2 .
        ?q2 wdt:P50 ?coauthor_qid .
}" ;
	$coauthors = array_merge($coauthors, extract_coauthors_from_sparql_query($sparql));
	return array_keys($coauthors);
}


?>
