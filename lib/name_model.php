<?PHP

class NameModel {
	public $name_provided = '' ;
	public $first_name = '';
	public $last_name = '';
	public $middle_names = array();
	public $prefixes = array();
	public $suffixes = array();
	public $ascii_nm = NULL;
	public $latin1_nm = NULL;
	public $nodash_nm = NULL;

	const PREFIX_PATTERN = '^(Dr\.?|Mr\.?|Ms\.?|Mrs\.?|Herr|Doktor|Prof\.?|Professor)$' ;
	const SUFFIX_PATTERN = '^([SJ]r\.?|I{1,3}V?|VI{0,3})$' ;
	const SURNAME_PART = '^(van|der|de|la|von|del|den|della|da|mac|das|ter|di|vander|vanden|le|das|sant|st|\'t)$' ;

	public function __construct ( $name ) {
		$this->name_provided = $name ;
		$name = trim($name);
		$name = rtrim($name, '.'); # mb_split below doesn't like trailing .
		$utf8_name = mb_convert_encoding($name, 'UTF-8', 'UTF-8'); 
		$ascii_name = iconv('UTF-8', 'ASCII//TRANSLIT', $utf8_name);
		if (($ascii_name) && ($ascii_name != $name) && (mb_strlen($ascii_name) > 2)) {
			$this->ascii_nm = new NameModel($ascii_name);
		}
		$l1_name = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8_name);
		$latin1_name = iconv('ISO-8859-1', 'UTF-8',  $l1_name);
		if (($latin1_name) && ($latin1_name != $name) && ($latin1_name != $ascii_name) && (mb_strlen($latin1_name) > 2)) {
			$this->latin1_nm = new NameModel($latin1_name);
		}
		if (mb_strpos($name, '-') !== false) {
			$nodash_name = mb_ereg_replace('-', ' ', $name);
			$this->nodash_nm = new NameModel($nodash_name);
		}
// Split if there's a '.' and 0 or more spaces, or no 1+ spaces with no '.' but not if there's a '-' character after
		$name_parts = mb_split('((?<=\.)\s*|\s+)(?!-)', $name);
		if (! is_array($name_parts)) {
			error_log("mb_split failed for '$name'");
			$name_parts = [$name];
		}
		$name_prefixes = array();
		$first_name = '';
// Pull out prefix(es) and first name:
		while ( count($name_parts) > 0 ) {
			$part = array_shift($name_parts) ;
			if ( mb_ereg_match(NameModel::PREFIX_PATTERN, $part, 'i') ) {
				$name_prefixes[] = $part ;
			} else {
				array_unshift($name_parts, $part);
				break ;
			}
		}
// Pull out suffix(es) and surname:
		$name_suffixes = array();
		$surname_pieces = array();
		while ( count($name_parts) > 0 ) {
			$part = array_pop($name_parts) ;
			if ($part == '') continue;
			if ( mb_ereg_match(NameModel::SUFFIX_PATTERN, $part, 'i') ) {
				array_unshift($name_suffixes, $part);
				continue ;
			} else if (count($surname_pieces) == 0) {
				$surname_pieces[] = $part ;
				continue;
			} else if ( mb_ereg_match(NameModel::SURNAME_PART, $part, 'i') ) {
				array_unshift($surname_pieces, $part);
				continue;
			} else {
				$name_parts[] = $part;
				break;
			}
		}
		$this->first_name = array_shift($name_parts) ;
		$this->last_name = implode(' ', $surname_pieces);
		$this->middle_names = $name_parts; // What's left
		$this->prefixes = $name_prefixes;
		$this->suffixes = $name_suffixes;
	}

	public function name_with_middles_and_suffixes() {
		$name_parts = array_merge([$this->first_name],
			$this->middle_names, [$this->last_name],
			$this->suffixes);
		return implode(' ', $name_parts);
	}

	public function name_with_middles() {
		$name_parts = array_merge([$this->first_name],
			$this->middle_names, [$this->last_name]);
		return implode(' ', $name_parts);
	}

	public function name_with_middle_initials() {
		$first_name = $this->first_name;
		if (NameModel::is_initial($first_name)) {
			$first_name = NameModel::to_initial($first_name);
		}
		$middle_initials = array_map('NameModel::to_initial', $this->middle_names);
		$name_parts = array_merge([$first_name],
			$middle_initials, [$this->last_name]);
		return implode(' ', $name_parts);
	}

	public function name_with_middle_first_letters() {
		$first_name = $this->first_name;
		if (NameModel::is_initial($first_name)) {
			$first_name = NameModel::first_letter($first_name);
		}
		$middle_initials = array_map(function($value) { return NameModel::first_letter($value); }, $this->middle_names);
		$name_parts = array_merge([$first_name],
			$middle_initials, [$this->last_name]);
		return implode(' ', $name_parts);
	}

	public function first_last_name() {
		$name_parts = array();
		$name_parts[] = $this->first_name;
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function first_last_suffixes() {
		$name_parts = array();
		$name_parts[] = $this->first_name;
		$name_parts[] = $this->last_name;
		$name_parts = array_merge($name_parts, $this->suffixes);
		return implode(' ', $name_parts);
	}

	public function first_initial_last_name() {
		$name_parts = array();
		$name_parts[] = NameModel::to_initial($this->first_name);
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function first_letter_last_name() {
		$name_parts = array();
		$name_parts[] = NameModel::first_letter($this->first_name);
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function first_initials_rest() {
		$name_versions = array();
		$name_parts = array_merge([$this->first_name],
			$this->middle_names, [$this->last_name]);
		$count = count($name_parts);
		for ($i = 1; $i < $count; $i++) {
			$tmp_names = array();
			for ($j = 0; $j < $count; $j++) {
				if ($j < $i ) {
					$tmp_names[] = NameModel::to_initial($name_parts[$j]);
				} else {
					$tmp_names[] = $name_parts[$j];
				}
			}
			$name_versions[] = implode(' ', $tmp_names);
			$tmp_names = array();
			for ($j = 0; $j < $count; $j++) {
				if ($j < $i ) {
					$tmp_names[] = NameModel::first_letter($name_parts[$j]);
				} else {
					$tmp_names[] = $name_parts[$j];
				}
			}
			$name_versions[] = implode(' ', $tmp_names);
		}
		return $name_versions;
	}

	public function initials_no_dot() {
		$name_parts = array();
		$first = $this->first_name;
		$name_parts[] = mb_ereg_replace('\.', '', $first);
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = mb_ereg_replace('\.', '', $middle_name);
		}
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function add_missing_dot() {
		$name_parts = array();
		$first = $this->first_name ;
		if (mb_strlen($first) == 1) {
			$first = $first . '.' ;
		}
		$name_parts[] = $first ;
		foreach ($this->middle_names AS $middle_name) {
			if (mb_strlen($middle_name) == 1) {
				$middle_name = $middle_name . '.' ;
			}
			$name_parts[] = $middle_name ;
		}
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function name_with_squashed_initials() {
		$name_parts = array();
		$name_parts[] = NameModel::to_initial($this->first_name) ;
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = NameModel::to_initial($middle_name);
		}
		return implode('', $name_parts) . ' ' . $this->last_name;
	}

	public function name_with_squashed_initials_minus_dot() {
		$name_parts = array();
		$name_parts[] = NameModel::to_initial($this->first_name) ;
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = NameModel::to_initial($middle_name);
		}
		$num_given = count($name_parts);
		if ($num_given > 1) {
			$last_middle = $name_parts[$num_given-1];
			$name_parts[$num_given-1] = NameModel::first_letter($last_middle);
		}
		return implode('', $name_parts) . ' ' . $this->last_name;
	}

	public function squashed_forward_name() {
		$name_parts = array();
		$name_parts[] = NameModel::first_letter($this->first_name);
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = NameModel::first_letter($middle_name);
		}
		return implode('', $name_parts) . ' ' . $this->last_name;
	}

	public function squashed_reversed_name() {
		$name_parts = array();
		$name_parts[] = NameModel::first_letter($this->first_name);
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = NameModel::first_letter($middle_name);
		}
		return $this->last_name . ' ' . implode('', $name_parts);
	}

	public function squashed_reversed_hyphen_name() {
		$name_parts = array();
		$name_parts[] = NameModel::first_letter($this->first_name) ;
		foreach ($this->middle_names AS $middle_name) {
			$name_parts[] = NameModel::first_letter($middle_name);
		}
		return $this->last_name . '-' . implode('', $name_parts);
	}

	public function default_search_strings() {
		$search_strings = array();
		if (isset($this->ascii_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->ascii_nm->default_search_strings(), 1));
		}
		if (isset($this->latin1_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->latin1_nm->default_search_strings(), 1));
		}
		if (isset($this->nodash_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->nodash_nm->default_search_strings(), 1));
		}
		$search_strings[$this->name_provided] = 1;
		$search_strings[$this->name_with_middles_and_suffixes()] = 1;
		$search_strings[$this->name_with_middles()] = 1;
		$search_strings[$this->first_last_name()] = 1;
		$search_strings[$this->first_last_suffixes()] = 1;
		$search_strings[$this->name_with_middle_initials()] = 1;
		$search_strings[$this->name_with_middle_first_letters()] = 1;
		$search_strings[$this->initials_no_dot()] = 1;
		$search_strings[$this->add_missing_dot()] = 1;
		if ($this->all_initials()) {
			$search_strings[$this->name_with_squashed_initials()] = 1;
		}
		return array_keys($search_strings);
	}

	public function fuzzy_search_strings() {
		$search_strings = array_fill_keys($this->default_search_strings(), 1);
		if (isset($this->ascii_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->ascii_nm->fuzzy_search_strings(), 1));
		}
		if (isset($this->latin1_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->latin1_nm->fuzzy_search_strings(), 1));
		}
		if (isset($this->nodash_nm)) {
			$search_strings = array_merge($search_strings, array_fill_keys($this->nodash_nm->fuzzy_search_strings(), 1));
		}
// Adopt fuzzy search strings for shorter versions of the name:
		if (count($this->middle_names) > 0) {
			$nm = new NameModel($this->first_last_name());
			$names = $nm->fuzzy_search_strings();
			foreach($names as $name) {
				$search_strings[$name] = 1;
			}
		}
		if (! $this->all_initials()) {
                        $nm = new NameModel($this->name_with_squashed_initials());
			$names = $nm->fuzzy_search_strings();
			foreach($names as $name) {
				$search_strings[$name] = 1;
			}
		}

		$search_strings[$this->first_initial_last_name()] = 1;
		$search_strings[$this->first_letter_last_name()] = 1;
		if (NameModel::is_initial($this->first_name)) {
			foreach($this->middle_names AS $middle_name) {
				$search_strings[$middle_name . ' ' . $this->last_name] = 1;
				$search_strings[NameModel::to_initial($middle_name)  . ' ' . $this->last_name] = 1;
				$search_strings[NameModel::first_letter($middle_name) . ' ' . $this->last_name] = 1;
			}
		}
		$search_strings[$this->name_with_squashed_initials()] = 1;
		$search_strings[$this->name_with_squashed_initials_minus_dot()] = 1;
		$search_strings[$this->squashed_forward_name()] = 1;
		$search_strings[$this->squashed_reversed_name()] = 1;
		$search_strings[$this->squashed_reversed_hyphen_name()] = 1;
		foreach ($this->first_initials_rest() as $string) {
			$search_strings[$string] = 1;
		}
		$lcstrings = array_keys($search_strings);
		foreach ($lcstrings as $string) {
			$search_strings[mb_strtoupper($string)] = 1 ;
		}
		return array_keys($search_strings);
	}

	public function fuzzy_ignore_nonascii() {
		$search_strings = array_fill_keys($this->fuzzy_search_strings(), 1);
		foreach (array_keys($search_strings) as $string) {
			$new_str = mb_ereg_replace('/[^A-Za-z]/', '', $string);
			if (mb_strlen($new_str) > 2) {
				$search_strings[mb_strtoupper($new_str)] = 1 ;
			}
		}
		return array_keys($search_strings);
	}

	public static function is_initial($name_part) {
		if (mb_strlen($name_part) > 3) return false ;
		if (mb_substr($name_part, mb_strlen($name_part)-1, 1) == '.') return true ; // Ends in .
		return (mb_strlen($name_part) < 2) ; // Single letter
	}

	public static function first_letter($name_part) {
		return mb_substr($name_part, 0, 1) ;
	}

	public static function to_initial($name_part) {
		return NameModel::first_letter($name_part) . '.' ;
	}

	public function all_initials() {
		$all_init = NameModel::is_initial($this->first_name);
		if ($all_init) {
			foreach($this->middle_names AS $middle_name) {
				if (! NameModel::is_initial($middle_name) ) {
					$all_init = false;
					break;
				}
			}
		}
		return $all_init;
	}

	public function names_from_wbsearch($names) {
		global $wikibase_endpoint;
		$new_names = array_fill_keys($names, 1);
		$search_strings = '"\"' . implode( '\"" "\"', $names )  . '\""' ;
		$sparql = "SELECT DISTINCT ?name WHERE {
  hint:Query hint:optimizer \"None\" .
  VALUES ?search_string { $search_strings }
  BIND(LCASE(REPLACE(?search_string, '\"', '')) AS ?lc_string)
  SERVICE wikibase:mwapi {
    bd:serviceParam wikibase:api \"Search\";
                    wikibase:endpoint \"$wikibase_endpoint\";
                    mwapi:srsearch ?search_string .
    ?page_title wikibase:apiOutput mwapi:title .
  }
  BIND(IRI(CONCAT(STR(wd:), ?page_title)) AS ?item)
  ?item wdt:P2093 ?name .
  FILTER CONTAINS(LCASE(?name), ?lc_string) .
}" ;
		$query_result = getSPARQL( $sparql ) ;
		$bindings = $query_result->results->bindings ;
		foreach ( $bindings AS $binding ) {
			$new_names[$binding->name->value] = 1 ;
		}
		$sparql = "SELECT DISTINCT ?name WHERE {
  hint:Query hint:optimizer \"None\" .
  VALUES ?search_string { $search_strings }
  BIND(LCASE(REPLACE(?search_string, '\"', '')) AS ?lc_string)
  SERVICE wikibase:mwapi {
    bd:serviceParam wikibase:api \"Search\";
                    wikibase:endpoint \"$wikibase_endpoint\";
                    mwapi:srsearch ?search_string .
    ?page_title wikibase:apiOutput mwapi:title .
  }
  BIND(IRI(CONCAT(STR(wd:), ?page_title)) AS ?item)
  ?item p:P50 ?statement .
  ?statement pq:P1932 ?name .
  FILTER CONTAINS(LCASE(?name), ?lc_string) .
}" ;
		$query_result = getSPARQL( $sparql ) ;
		$bindings = $query_result->results->bindings ;
		foreach ( $bindings AS $binding ) {
			$new_names[$binding->name->value] = 1 ;
		}
		return array_keys($new_names);
	}
}
?>
