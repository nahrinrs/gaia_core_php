<?php
namespace Gaia\Serialize;

class PHP implements Iface {

    protected $prefix;
    protected $len = 0;
    
    public function __construct( $prefix = '#__PHP__:' ){
        $this->prefix = $prefix;
        $this->len = strlen( $this->prefix );
    }

    public function serialize($v){
        $scalar = is_scalar( $v );
        if( is_bool($v) || ! $scalar ) return $this->prefix . serialize( $v );
        if( $scalar && ctype_digit( (string) $v ) ) return $v;
        if( ! $this->len ) return serialize( $v );
        return $v;
    }
    
    public function unserialize( $v ){
        if( $v === NULL ) return NULL;
        if( ! is_scalar( $v ) ) return $v;
        if(  ctype_digit( (string) $v ) ) return $v;
        if( $this->len < 1 ) return unserialize( $v );
        if( substr( $v, 0, $this->len) != $this->prefix) return $v;
        return unserialize(substr( $v, $this->len) );
    }
}