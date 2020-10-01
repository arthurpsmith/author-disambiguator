<?PHP

class CompareLists {
	public $only1 = [] ;
	public $only2 = [] ;
	public $both = [] ;

	public function __construct ( $list1, $list2 ) {
		$in_list1 = array_fill_keys($list1, 1);
		$in_list2 = array_fill_keys($list2, 1);
		foreach ( $list1 AS $qid ) {
			if ( isset( $in_list2[$qid] ) ) {
				$this->both[] = $qid;
			} else {
				$this->only1[] = $qid;
			}
		}
		foreach ( $list2 AS $qid ) {
			if ( ! isset( $in_list1[$qid] ) ) {
				$this->only2[] = $qid;
			}
		}
	}
}
