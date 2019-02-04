<?PHP

class NameModel {
	public $name_provided = '' ;
	public $first_name = '';
	public $last_name = '';
	public $middle_names = array();
	public $prefixes = array();
	public $suffixes = array();

	const PREFIX_PATTERN = '/^(Dr\.?|Mr\.?|Ms\.?|Mrs\.?|Herr|Doktor|Prof\.?|Professor)$/' ;
	const SUFFIX_PATTERN = '/^([SJ]r\.?|I{1,3}V?|VI{0,3})$/' ;
	const SURNAME_PART = '/^(van|der|de|la|von|del|den|della|da|mac|das|ter|di|vander|vanden|le|das|sant|st|\'t)$/' ;

	public function __construct ( $name ) {
		$this->name_provided = $name ;
		$name_parts = array_filter(explode(" ", $name), 'strlen');
		$name_prefixes = array();
		$first_name = '';
// Pull out prefix(es) and first name:
		while ( count($name_parts) > 0 ) {
			$part = array_shift($name_parts) ;
			if ( preg_match(NameModel::PREFIX_PATTERN, $part) ) {
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
			if ( preg_match(NameModel::SUFFIX_PATTERN, $part) ) {
				array_unshift($name_suffixes, $part);
				continue ;
			} else if (count($surname_pieces) == 0) {
				$surname_pieces[] = $part ;
				continue;
			} else if ( preg_match(NameModel::SURNAME_PART, $part) ) {
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
			$first_name = $first_name[0] . '.';
		}
		$middle_initials = array_map('NameModel::to_initial', $this->middle_names);
		$name_parts = array_merge([$first_name],
			$middle_initials, [$this->last_name]);
		return implode(' ', $name_parts);
	}

	public function name_with_middle_first_letters() {
		$first_name = $this->first_name;
		if (NameModel::is_initial($first_name)) {
			$first_name = $first_name[0];
		}
		$middle_initials = array_map(function($value) { return $value[0]; }, $this->middle_names);
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
		$name_parts[] = $this->first_name[0] . '.';
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function first_letter_last_name() {
		$name_parts = array();
		$name_parts[] = $this->first_name[0] ;
		$name_parts[] = $this->last_name;
		return implode(' ', $name_parts);
	}

	public function default_search_strings() {
		$search_strings = array();
		$search_strings[$this->name_provided] = 1;
		$search_strings[$this->name_with_middles_and_suffixes()] = 1;
		$search_strings[$this->name_with_middles()] = 1;
		$search_strings[$this->first_last_name()] = 1;
		$search_strings[$this->first_last_suffixes()] = 1;
		$search_strings[$this->name_with_middle_initials()] = 1;
		$search_strings[$this->name_with_middle_first_letters()] = 1;
		return array_keys($search_strings);
	}

	public function fuzzy_search_strings() {
		$search_strings = $this->default_search_strings();
		$search_strings[] = $this->first_initial_last_name();
		$search_strings[] = $this->first_letter_last_name();
		if (NameModel::is_initial($this->first_name)) {
			foreach($this->middle_names AS $middle_name) {
				$search_strings[] = $middle_name . ' ' . $this->last_name;
				$search_strings[] = $middle_name[0] . '. ' . $this->last_name;
				$search_strings[] = $middle_name[0] . ' ' . $this->last_name;
			}
		}
		return $search_strings;
	}

	public static function is_initial($name_part) {
		if (strlen($name_part) > 3) return false ;
		if ($name_part[strlen($name_part)-1] == '.') return true ; // Ends in .
		return (strlen($name_part) < 2) ; // Single letter
	}

	public static function to_initial($name_part) {
		return $name_part[0] . '.' ;
	}
}
?>
