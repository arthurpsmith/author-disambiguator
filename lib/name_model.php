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

	public function default_search_strings() {
	}

	public function fuzzy_search_strings() {
	}
}
?>
