<?PHP

class AuthorData {
	public $qid ;
	public $label ;
	public $desc ;
	public $complete = false;
	public $redirect = NULL;
	public $article_count = 0;
	public $coauthors = array() ;
	public $coauthor_names = array() ;
	public $journal_qids = array() ;
	public $topic_qids = array() ;
	public $employer_qids = array() ;
	public $identifiers = array() ;

	public function __construct ( $author_item ) {
		global $affiliation_prop_ids;
		global $identifier_prop_ids;
		$this->qid = $author_item->getQ() ;
		$this->label = $author_item->getLabel() ;
		$this->desc = $author_item->getDesc() ;
		foreach ( $identifier_prop_ids AS $identifier_prop ) {
			$x = $author_item->getStrings ( $identifier_prop ) ;
			if ( count($x) > 0 ) {
				$this->identifiers[$identifier_prop] = $x[0] ;
			}
		}
		$org_qid_list = array();
		foreach ( $affiliation_prop_ids AS $affiliation_prop ) {
			if ( $author_item->hasClaims( $affiliation_prop ) ) {
				$claims = $author_item->getClaims( $affiliation_prop ) ;
				foreach ( $claims AS $c ) {
					$q = $author_item->getTarget ( $c ) ;
					$org_qid_list[$q] = 1 ;
				}
			}
		}
		$this->employer_qids = array_keys($org_qid_list);
	}

	public function add_coauthors ( $coauthors ) {
		$this->coauthors = array_merge($this->coauthors, $coauthors) ;
	}

	public function add_coauthor_names ( $coauthor_names ) {
		$this->coauthor_names = array_merge($this->coauthor_names, $coauthor_names) ;
	}

	public function add_journals ( $journal_qids ) {
		$this->journal_qids = array_merge($this->journal_qids, $journal_qids) ;
	}

	public function add_topics ( $topic_qids ) {
		$this->topic_qids = array_merge($this->topic_qids, $topic_qids) ;
	}

	private static function _article_id_query_list($author_items) {
		$wd_author_list = array() ;
		foreach ($author_items AS $qid) {
			$qid = 'Q' . preg_replace ( '/\D/' , '' , $qid ) ;
			$wd_author_list[] = "wd:" . $qid ;
		}
		return implode( ' ', $wd_author_list ) ;
	}

	private static function _extract_item_map($query_result, $item_label, $value_label) {
		global $wikibase_endpoint ;
		$item_map = array() ;
		if (! isset($query_result->results) ) {
			return $item_map;
		}
		$bindings = $query_result->results->bindings ;
		foreach ( $bindings AS $binding ) {
			$item_uri = $binding->$item_label->value ;
			$value_uri = $binding->$value_label->value ;
			$item_qid = preg_replace ( "/http:\/\/$wikibase_endpoint\/entity\//" , '' , $item_uri ) ;
			$value_qid = preg_replace ( "/http:\/\/$wikibase_endpoint\/entity\//" , '' , $value_uri ) ;
			if (! isset( $item_map[$item_qid] ) ) $item_map[$item_qid] = array() ;
			$item_map[$item_qid][] = $value_qid ;
		}
		return $item_map ;
	}

	private static function _extract_string_map($query_result, $item_label, $value_label) {
		global $wikibase_endpoint;
		$item_map = array() ;
		if (! isset($query_result->results) ) {
			return $item_map;
		}
		$bindings = $query_result->results->bindings ;
		foreach ( $bindings AS $binding ) {
			$item_uri = $binding->$item_label->value ;
			$value = $binding->$value_label->value ;
			$item_qid = preg_replace ( "/http:\/\/$wikibase_endpoint\/entity\//" , '' , $item_uri ) ;
			if (! isset( $item_map[$item_qid] ) ) $item_map[$item_qid] = array() ;
			$item_map[$item_qid][] = $value ;
		}
		return $item_map ;
	}

	public static function articleCountsForAuthors($author_items) {
		$query_list = self::_article_id_query_list( $author_items ) ;
		$sparql = "SELECT ?q (count(?article) as ?count) WHERE {
          SELECT ?q ?article WHERE {
                    VALUES ?q { $query_list } .
            ?article wdt:P50 ?q . }
 } group by ?q" ;
		$article_counts = getSPARQL( $sparql ) ;
		return self::_extract_string_map( $article_counts, 'q', 'count' ) ;
	}

	public static function coauthorsForAuthors($author_items) {
		$query_list = self::_article_id_query_list( $author_items ) ;
		$sparql = "SELECT DISTINCT ?q ?q2 WHERE {VALUES ?q { $query_list } . ?article wdt:P50 ?q, ?q2 . FILTER (?q != ?q2) }" ;
		$potential_coauthors = getSPARQL( $sparql ) ;
		return self::_extract_item_map( $potential_coauthors, 'q', 'q2' ) ;
	}

	public static function coauthorNamesForAuthors($author_items) {
		$query_list = self::_article_id_query_list( $author_items ) ;
		$sparql = "SELECT DISTINCT ?q ?name WHERE {VALUES ?q { $query_list } . ?article wdt:P50 ?q; wdt:P2093 ?name . }" ;
		$potential_coauthors = getSPARQL( $sparql ) ;
		return self::_extract_string_map( $potential_coauthors, 'q', 'name' ) ;
	}

	public static function journalsForAuthors($author_items) {
		global $published_in_prop_id ;

		$query_list = self::_article_id_query_list( $author_items ) ;
		$sparql = "SELECT DISTINCT ?q ?journal WHERE {VALUES ?q { $query_list } . ?article wdt:P50 ?q; wdt:$published_in_prop_id ?journal . }" ;
		$journals = getSPARQL( $sparql ) ;
		return self::_extract_item_map( $journals, 'q', 'journal' ) ;
	}

	public static function topicsForAuthors($author_items) {
		global $topic_prop_id ;

		$query_list = self::_article_id_query_list( $author_items ) ;
		$sparql = "SELECT DISTINCT ?q ?topic WHERE {VALUES ?q { $query_list } . ?article wdt:P50 ?q; wdt:$topic_prop_id ?topic . }" ;
		$topics = getSPARQL( $sparql ) ;
		return self::_extract_item_map( $topics, 'q', 'topic' ) ;
	}

	public static function authorDataFromItems( $author_items, $wil, $complete, $resorted = true) {
		$batch_size = 200 ;
		$batches = [ [] ] ;
		foreach ( $author_items AS $k => $v ) {
			if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
			$batches[count($batches)-1][$k] = $v ;
		}

		$author_data = array();
		foreach ( $batches as $batch ) {
			$new_data = self::_auth_data_for_batch($batch, $wil, $complete);
			$author_data = array_merge($author_data, $new_data);
		}
		if ($resorted) {
			uasort ( $author_data, function ($a, $b) {
				return $b->article_count - $a->article_count ;
			} );
		}
		return $author_data;
	}

	private static function _auth_data_for_batch ( $author_items, $wil, $complete) {
		$author_data = array() ;
		$direct_author_items = array();
		foreach ($author_items AS $qid) {
			$item = $wil->getItem( $qid ) ;
			if (! isset($item) ) continue;

			$author_data_entry = new AuthorData($item) ;
     			if (property_exists($item->j, 'redirects')) {
				$author_data_entry->redirect = $item->j->redirects->to;
			}
			$author_data_entry->complete = $complete ;
			$author_data[$author_data_entry->qid] = $author_data_entry ;
			$direct_author_items[] = $author_data_entry->qid;
		}

		$author_paper_counts = self::articleCountsForAuthors( $direct_author_items );
		foreach ($author_paper_counts AS $qid => $count) {
			$author_data[$qid]->article_count = intval($count[0]) ;
		}
		if ($complete) {
			$coauthors = self::coauthorsForAuthors( $direct_author_items ) ;
			foreach ( $coauthors AS $qid => $coauthor_qids ) {
				$author_data[$qid]->add_coauthors($coauthor_qids) ;
			}
			$coauthor_names = self::coauthorNamesForAuthors( $direct_author_items ) ;
			foreach ( $coauthor_names AS $qid => $names ) {
				$author_data[$qid]->add_coauthor_names($names) ;
			}
		}
		$journals = self::journalsForAuthors( $direct_author_items ) ;
		foreach ( $journals AS $qid => $journal_qids) {
			$author_data[$qid]->add_journals($journal_qids) ;
		}
		$topics = self::topicsForAuthors( $direct_author_items ) ;
		foreach ( $topics AS $qid => $topic_qids ) {
			$author_data[$qid]->add_topics($topic_qids) ;
		}
		return $author_data ;
	}

	// Utility to quickly get labels for a list of items - list needs to have no duplicates!
	public static function labelsForItems( $items ) {
		$id_uris = array_map(function($id) { return "wd:$id"; }, $items);

		$batch_size = 200 ;
		$batches = [ [] ] ;
		foreach ( $id_uris AS $k => $v ) {
			if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
			$batches[count($batches)-1][$k] = $v ;
		}

		$labels = array();
		foreach ( $batches as $batch ) {
			$new_labels = self::_labels_for_batch($batch);
			$labels = array_merge($labels, $new_labels);
		}
# Normalize result:
		foreach ($items as $qid) {
			if ( isset($labels[$qid]) ) {
				$labels[$qid] = $labels[$qid][0];
			} else {
				$labels[$qid] = $qid;
			}
		}
		return $labels;
	}

	private static function _labels_for_batch ( $uri_list ) {
		$id_uris = implode(' ', $uri_list);
		$sparql = "SELECT ?q ?qLabel WHERE {
  VALUES ?q { $id_uris } .
  SERVICE wikibase:label { bd:serviceParam wikibase:language '[AUTO_LANGUAGE],en,de,es,fr,nl'. }
}" ;
		$query_result = getSPARQL( $sparql ) ;
		return self::_extract_string_map( $query_result , 'q', 'qLabel' ) ;
	}
}

?>
