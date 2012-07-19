<?php
namespace Gaia\Skein;

interface Iface {
    public function count();
    public function get( $id );
    public function add( array $data );
    public function store( $id, array $data );
    public function ascending( $limit = 1000, $start_after = NULL );
    public function descending( $limit = 1000, $start_after = NULL );
    public function shardSequences(); /* used internally only, or for admin purposes */
}