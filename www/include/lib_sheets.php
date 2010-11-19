<?php

	#
	# $Id$
	#

	#################################################################

	$GLOBALS['sheets_lookup_local_cache'] = array();
	$GLOBALS['sheets_local_cache'] = array();

	#################################################################

	function sheets_create_sheet(&$user, $more=array()){

		$sheet_id = dbtickets_create(32);

		if (! $sheet_id){

			return array(
				'ok' => 0,
				'error' => 'Ticket server failed',
			);
		}

		$now = time();

		$sheet = array(
			'user_id' => $user['id'],
			'created' => $now,
			'last_modified' => $now,
			'id' => $sheet_id,
		);

		$optional = array(
			'label',
			'mime_type',
		);

		foreach ($optional as $o){

			if (isset($more[$o])){
				$sheet[$o] = $more[$o];
			}
		}

		$hash = array();

		foreach ($sheet as $k => $v){
			$hash[$k] = AddSlashes($v);
		}

		$rsp = db_insert_users($user['cluster_id'], 'Sheets', $hash);

		if (! $rsp['ok']){
			return null;
		}

		#
		# Store in the lookup table
		#

		$lookup = array(
			'sheet_id' => AddSlashes($sheet_id),
			'user_id' => AddSlashes($user['id']),
			'created' => AddSlashes($now),
		);

		$lookup_rsp = db_insert('SheetsLookup', $lookup);

		if (! $lookup_rsp['ok']){
			# What ?
		}

		#
		# Okay!
		#

		sheets_load_extras($sheet, $user['id']);

		$rsp['sheet'] = $sheet;
		return $rsp;
	}

	#################################################################

	#
	# Whatever else happens in this function, it should be
	# done in a way that allows it to be run out of band (as
	# in: some kind of offline task) should that ever become
	# necessary.
	#

	function sheets_delete_sheet(&$sheet){

		#
		# First, purge search
		#

		dots_search_remove_sheet($sheet);

		#
		# Okay, go
		#

		$user = users_get_by_id($sheet['user_id']);

		$enc_id = AddSlashes($sheet['id']);
		$sql = "SELECT * FROM Dots WHERE sheet_id='{$enc_id}'";

		$more = array(
			'page' => 1,
			'per_page' => 1000,
		);

		$page_count = null;
		$total_count = null;
		$dots_deleted = 0;

		while((! isset($page_count)) || ($page_count >= $more['page'])){

			$rsp = db_fetch_paginated_users($user['cluster_id'], $sql, $more);		

			if (! $rsp['ok']){
				$rsp['dots_deleted'] = $dots_deleted;
				$rsp['dots_count'] = $total_count;
				return $rsp;
			}

			if (! isset($page_count)){
				$page_count = $rsp['pagination']['page_count'];
				$total_count = $rsp['pagination']['total_count'];
			}

			foreach ($rsp['rows'] as $dot){

				$dot_more = array(
					'skip_update_sheet' => 1,
					'skip_update_search' => 1,
				);

				$dot_rsp = dots_delete_dot($dot, $dot_more);

				if ($dot_rsp['ok']){
					$dots_deleted ++;
				}
			}

			$more['page'] ++;
		}

		$sql = "DELETE FROM Sheets WHERE id='{$enc_id}'";
		$rsp = db_write_users($user['cluster_id'], $sql);

		#
		# Update the lookup table
		#

		$update = array(
			'deleted' => time(),
		);

		$where = "sheet_id='{$enc_id}'";

		$lookup_rsp = db_update('SheetsLookup', $update, $where);

		if (! $lookup_rsp['ok']){
			# what?
		}

		#
		# Happy happy!
		#

		$rsp['dots_deleted'] = $dots_deleted;
		$rsp['dots_count'] = $total_count;

		return $rsp;
	}

	#################################################################

	#
	# Whatever else happens in this function, it should be
	# done in a way that allows it to be run out of band (as
	# in: some kind of offline task) should that ever become
	# necessary.
	#

	function sheets_delete_sheets_for_user(&$user){

		$enc_id = AddSlashes($user['id']);
		$sql = "SELECT * FROM Sheets WHERE user_id='{$enc_id}'";

	
		$more = array(
			'page' => 1,
			'per_page' => 100,
		);

		$page_count = null;

		$sheets_count = 0;
		$sheets_deleted = 0;

		$dots_count = 0;
		$dots_deleted = 0;

		while((! isset($page_count)) || ($page_count >= $more['page'])){

			$rsp = db_fetch_paginated_users($user['cluster_id'], $sql, $more);		

			if (! $rsp['ok']){
				$rsp['sheets_deleted'] = $sheets_deleted;
				$rsp['sheets_count'] = $sheets_count;
				$rsp['dots_deleted'] = $dots_deleted;
				$rsp['dots_count'] = $dots_count;

				return $rsp;
			}

			if (! isset($page_count)){
				$page_count = $rsp['pagination']['page_count'];
				$sheets_count = $rsp['pagination']['total_count'];
			}

			foreach ($rsp['rows'] as $sheet){

				$sheet_rsp = sheets_delete_sheet($sheet);

				if ($sheet_rsp['ok']){

					$dots_count += $sheet_rsp['dots_count'];
					$dots_deleted += $sheet_rsp['dots_deleted'];

					$sheets_deleted ++;
				}
			}

			$more['page'] ++;
		}

		return array(
			'ok' => 1,
			'sheets_deleted' => $sheets_deleted,
			'sheets_count' => $sheets_count,
			'dots_deleted' => $dots_deleted,
			'dots_count' => $dots_count,
		);
	}

	#################################################################

	#
	# Note the pass-by-ref
	#

	function sheets_load_extras(&$sheet, $viewer_id=0, $more=array()){

		$sheet['extent'] = dots_get_extent_for_sheet($sheet, $viewer_id);

		if ($more['load_dots']){
			$sheet['dots'] = dots_get_dots_for_sheet($sheet, $viewer_id);
		}
	}

	#################################################################

	#
	# Grab the sheet from db_main
	#

	function sheets_lookup_sheet($sheet_id){

		if (isset($GLOBALS['sheets_lookup_local_cache'][$sheet_id])){
			return $GLOBALS['sheets_lookup_local_cache'][$sheet_id];
		}

		$enc_id = AddSlashes($sheet_id);

		$sql = "SELECT * FROM SheetsLookup WHERE sheet_id='{$enc_id}'";
		$rsp = db_fetch($sql);

		if ($rsp['ok']){
			$GLOBALS['sheets_lookup_local_cache'][$sheet_id] = $rsp;
		}

		return db_single($rsp);
	}

	#################################################################

	#
	# Grab the sheet from the shards
	#

	function sheets_get_sheet($sheet_id, $viewer_id=0, $more=array()){

		if (isset($GLOBALS['sheets_local_cache'][$sheet_id])){
			return $GLOBALS['sheets_local_cache'][$sheet_id];
		}

		$lookup = sheets_lookup_sheet($sheet_id);

		if ((! $lookup) || ($lookup['deleted'])){
			return;
		}

		$user = users_get_by_id($lookup['user_id']);

		$enc_id = AddSlashes($sheet_id);
		$enc_user = AddSlashes($user['id']);

		$sql = "SELECT * FROM Sheets WHERE id='{$enc_id}'";

		$rsp = db_fetch_users($user['cluster_id'], $sql);
		$sheet = db_single($rsp);

		if ($sheet){

			sheets_load_extras($sheet, $viewer_id, $more);
			$GLOBALS['sheets_local_cache'][$sheet_id] = $sheet;
		}

		return $sheet;
	}

	#################################################################

	function sheets_can_view_sheet(&$sheet, $viewer_id=0){

		if ($sheet['user_id'] == $viewer_id){
			return 1;
		}

		if ($sheet['count_dots_public'] >= 1){
			return 1;
		}

		return 0;
	}

	#################################################################

	function sheets_update_sheet(&$sheet, $update){

		$user = users_get_by_id($sheet['user_id']);

		$enc_id = AddSlashes($sheet['id']);
		$where = "id='{$enc_id}'";

		foreach ($update as $k => $v){
			$update[$k] = AddSlashes($v);
		}

		$update['last_modified'] = time();

		$rsp = db_update_users($user['cluster_id'], 'Sheets', $update, $where);

		if ($rsp['ok']){
			unset($GLOBALS['sheets_local_cache'][$sheet['id']]);
		}

		return $rsp;
	}

	#################################################################

	function sheets_sheets_for_user($user, $viewer_id=0, $args=array()){

		$enc_id = AddSlashes($user['id']);

		$sql = "SELECT * FROM Sheets WHERE user_id='{$enc_id}'";

		if ($user['id'] != $viewer_id){

			$sql .= " AND count_dots_public > 0";
		}

		$order_by = 'created';
		$order_sort = 'DESC';

		# check $args for alternate sorting

		$order_by = AddSlashes($order_by);
		$order_sort = AddSlashes($order_sort);

		$sql .= " ORDER BY {$order_by} {$order_sort}";

		$rsp = db_fetch_paginated_users($user['cluster_id'], $sql, $args);
		$sheets = array();

		foreach ($rsp['rows'] as $row){
			sheets_load_extras($row, $viewer_id);
			$sheets[] = $row;
		}

		return $sheets;
	}

	#################################################################

	function sheets_update_dot_count_for_sheet(&$sheet){

		$counts = dots_count_dots_for_sheet($sheet);

		$update = array(
			'count_dots' => $counts['total'],
			'count_dots_public' => $counts['public'],
		);

		return sheets_update_sheet($sheet, $update);
	}

	#################################################################

	function sheets_counts_for_user(&$user, $viewer_id=0){

		$enc_id = AddSlashes($user['id']);

		if ($viewer_id == $user['id']){
			$sql = "SELECT COUNT(id) AS count_sheets, SUM(count_dots) AS count_dots FROM Sheets WHERE user_id='{$enc_id}'";
			return db_single(db_fetch_users($user['cluster_id'], $sql));
		}

		$sql = "SELECT COUNT(id) AS count_sheets, SUM(count_dots_public) AS count_dots FROM Sheets WHERE user_id='{$enc_id}' AND count_dots_public > 0";
		return db_single(db_fetch_users($user['cluster_id'], $sql));
	}

	#################################################################
?>