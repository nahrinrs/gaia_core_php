<?php
namespace Gaia\Skein;

/**
* skein creates s list of sequential entries that are sharded across many tables.
*/
interface Iface {
    /**
    * how many entries total?
    */
    public function count();
    
    /**
    * get an entry by id. can pass in a single id, or an array of ids.
    */
    public function get( $id );
    
    /*
    * create a new entry.
    * optionally specify which shard to put it in. by default it uses the current month:
    *   $shard = date('Ym');
    * returns an id in the form of a string of digits ... can be stored as an unsigned big integer.
    */
    public function add( $data, $shard = NULL );
    
    /*
    * overwrite an existing entry. 
    * Pass in the id and data.
    * returns void ...
    * throws an exception if anything goes wrong.
    */
    public function store( $id, $data );
    
    /**
    * get a list of ids in ascending or decending order.
    * instead of limit, offset we give you limit and a starting id.
    * This will allow you to pick up where you left off.
    *
    * allowed params:
    *
    *       limit int
    *       start_at int  an id number
    *       sort string   ascending or descending. defaults to ascending.
    */
    public function ids( array $params = array() );
    
    /**
    * iterates through the data set in ascending or descending order, passing each entry to a closure.
    * will continue until it reaches the end of the entries, or your closure method returns false.
    * conceptually, it works like this:
    *
    *   foreach( $entries as $id => $entry ){ 
    *        if( $closure( $id, $entry) === FALSE ) break; 
    *   }
    *
    * of course, the actual iteration is more efficient, moving through the entries in chunks so 
    * if you break early in your iteration, we won't have pulled down all the data for nothing.
    */
    public function filter( array $params );
    
    
    /**
    * used internally only, or for admin purposes.
    * lists how many entries associated with each shard.
    * from this tiny list, you can construct every entry id:
    * 
    *   foreach( $skein->shardSequences() as $shard => $max ){
    *       for( $sequence = 1; $sequence <= $max; $sequence++) {
    *           $id = \Gaia\Skein\Util::composeId( $shard, $sequence );
    *       }
    *   }
    */
    public function shardSequences(); 
}
