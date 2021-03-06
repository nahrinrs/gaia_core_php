<?php
namespace Gaia\Stockpile\Storage\MySQL;
use \Gaia\Stockpile\Exception;

/**
 * base class for sorting.
 * Has a method that allows the app to pass in a sorted list of item ids, and those ids will
 * be added to the top of the list in the sort.
 * We can add other functions too if we find them useful.
 */
class Sorter extends Core {

const TABLE = 'sort';

    public function schema(){
        $table = $this->table();
        return "CREATE TABLE IF NOT EXISTS `{$table}` ( " .
                "`user_id` bigint unsigned NOT NULL, " .
                "`item_id` int unsigned NOT NULL, " .
                "`pos` bigint unsigned NOT NULL default '0', " . 
                "UNIQUE KEY  (`user_id`,`item_id`), " .
                "KEY `user_id_pos` ( `user_id`, `pos`) " . 
                ") ENGINE=InnoDB";
    }
    
    public function sort( $pos, array $item_ids, $ignore_dupes = FALSE ){
        $batch = array();
        foreach( $item_ids as $item_id ){
            $pos = bcadd($pos, 1);
            $batch[] = $this->db->prep('(%i, %i, %i)', $this->user_id, $item_id, $pos );
        }
        $table = $this->table();
        if( $ignore_dupes ){ 
            $sql = "INSERT IGNORE INTO `{$table}` (`user_id`, `item_id`, `pos`) VALUES  %s";
        } else {
            $sql = "INSERT INTO `{$table}` (`user_id`, `item_id`, `pos`) VALUES %s ON DUPLICATE KEY UPDATE `pos` = VALUES(`pos`)";        
        }
        $rs = $this->execute(sprintf( $sql, implode(",\n ", $batch )));
        return $rs->affected();
    }
    
    public function remove( $item_id ){
        $table = $this->table();
        $sql = "UPDATE `{$table}` SET `pos` = 0 WHERE `user_id` = %i AND `item_id` = %i";
        $rs = $this->execute( $sql, $this->user_id, $item_id );
    }
    
   /**
    * get the position for a list of item ids. triggered by the cache callback in FETCH.
    * @returns the positions, keyed by item id.
    */
    public function fetchPos( array $ids ){
        $table = $this->table();
        $sql = "SELECT `item_id`, `pos` FROM `{$table}` WHERE `user_id` = %i AND `item_id` IN ( %i )";
        $rs = $this->db->execute($sql, $this->user_id, $ids );
        $list = array();
        while( $row = $rs->fetch() ){
            $list[ $row['item_id'] ] = $row['pos'];
        }
        $rs->free();
        return $list;
    }
    
   /**
    * what is the largest position number we have in our sort list?
    */
    public function maxPos(){
        $table = $this->table();
        $sql = "SELECT MAX(`pos`) as `pos` FROM `{$table}` WHERE `user_id` = %i";
        $rs = $this->execute($sql, $this->user_id );
        $row = $rs->fetch();
        $rs->free();
        return $row['pos'];
    }
 
} // EOC


