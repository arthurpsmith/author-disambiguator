<?PHP

class Preferences {
	var $use_scholarly_subgraph = true;
	
	function __construct ( ) {
		session_start();
		if ( isset( $_SESSION['subgraph_selection'] ) ) {
			$this->use_scholarly_subgraph = $_SESSION['subgraph_selection'];
		} else {
			$_SESSION['subgraph_selection'] = $this->use_scholarly_subgraph;
		}
		session_write_close();
	}

	function set_subgraph_selection ( $subgraph_selection ) {
		session_start();
		$this->use_scholarly_subgraph = $subgraph_selection;
		$_SESSION['subgraph_selection'] = $subgraph_selection;
		session_write_close();
	}
}

?>
