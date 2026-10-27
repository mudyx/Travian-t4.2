<?php
/*
 * Map data for map_block.php / map_ajax.php: the rows of a whole block in a few
 * queries instead of ~8 per field (a 10x10 block used to cost ~800 queries).
 * Same SQL as Database::getMInfo() (village fields), getOMInfo() (oases),
 * getUserField() and getAllianceName(), only for all fields at once.
 */
class MapData {

	private $tiles = array();   // wref => row
	private $users = array();   // uid => array(username, tribe, alliance)
	private $tags = array();    // alliance id => tag

	public function __construct($ids) {
		global $database;
		$ids = array_map('intval', $ids);
		if(empty($ids)) return;
		$in = implode(',', array_unique($ids));
		$p = TB_PREFIX;
		foreach($database->query_return("SELECT *, {$p}wdata.id AS map_id FROM {$p}wdata LEFT JOIN {$p}vdata ON {$p}vdata.wref = {$p}wdata.id".
			" WHERE {$p}wdata.id IN ($in) AND {$p}wdata.oasistype = 0") as $row) {
			$this->tiles[$row['map_id']] = $row;
		}
		foreach($database->query_return("SELECT *, {$p}wdata.id AS map_id FROM {$p}wdata LEFT JOIN {$p}odata ON {$p}odata.wref = {$p}wdata.id".
			" WHERE {$p}wdata.id IN ($in) AND {$p}wdata.oasistype > 0") as $row) {
			$this->tiles[$row['map_id']] = $row;
		}
		$uids = array();
		foreach($this->tiles as $t) { if(!empty($t['owner'])) $uids[intval($t['owner'])] = true; }
		if($uids) {
			foreach($database->query_return("SELECT id, username, tribe, alliance FROM {$p}users WHERE id IN (".implode(',', array_keys($uids)).")") as $u) {
				$this->users[$u['id']] = $u;
			}
		}
		$aids = array();
		foreach($this->users as $u) { if($u['alliance']) $aids[intval($u['alliance'])] = true; }
		if($aids) {
			foreach($database->query_return("SELECT id, tag FROM {$p}alidata WHERE id IN (".implode(',', array_keys($aids)).")") as $a) {
				$this->tags[$a['id']] = $a['tag'];
			}
		}
	}

	public function tile($id) {
		return isset($this->tiles[$id]) ? $this->tiles[$id] : array();
	}

	public function user($uid, $field) {
		return isset($this->users[$uid][$field]) ? $this->users[$uid][$field] : null;
	}

	public function tag($aid) {
		return isset($this->tags[$aid]) ? $this->tags[$aid] : '';
	}
}

// A map tile image, loaded from disk once per request (a block draws ~600 of them).
// Many layer names have no file (an empty layer, e.g. 'tilespbgimg', 'wall-1'): a
// transparent tile draws the same nothing without filling the error log.
function map_tile_image($name) {
	static $cache = array();
	if(!isset($cache[$name])) {
		$file = 'img/m/'.$name.'.gif';
		if(is_file($file)) {
			$cache[$name] = imagecreatefromgif($file);
		} else {
			$img = imagecreatetruecolor(60, 60);
			imagesavealpha($img, true);
			imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
			$cache[$name] = $img;
		}
	}
	return $cache[$name];
}
?>
