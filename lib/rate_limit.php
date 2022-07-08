<?PHP

function limit_requests( $db_conn, $time_interval ) {
	$return_value = false;

	$dbquery = "SELECT last_time from rate_limit_table";
	$results = $db_conn->query($dbquery);
	$last_request_time = 0;
	if ($row = $results->fetch_row()) {
		$last_request_time = $row[0];
	}
	
	$cur_time = time();
	if ($cur_time - $last_request_time < $time_interval) {
		http_response_code(429);
		$return_value = true;
	} else {
		$dbquery = "UPDATE rate_limit_table SET last_time = '$cur_time'";
		$db_conn->query($dbquery);
	}
	return $return_value;
}

?>
