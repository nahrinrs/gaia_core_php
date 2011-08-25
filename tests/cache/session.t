#!/usr/bin/env php
<?php
include __DIR__ . '/../common.php';
use Gaia\Test\Tap;
use Gaia\Cache;
ob_start();

$limit = 10;


$cache = new Cache\Memcache;
$result = $cache->addServer('127.0.0.1', '11211');
Tap::plan(2);
Tap::ok( $result, 'connected to localhost server');

$s = Cache\Session::init( $o = new Cache\Observe( $cache ) );
session_start();
$id = session_id();



Tap::is( session_id(), $id ,'session id working');

$_SESSION['test'] = 'foo';

session_write_close();

$calls = $o->calls();

$call = array_shift( $calls );

Tap::is( $call['method'], 'get', 'read handler called cache get');
Tap::like( $call['args'][0], "/$id/", 'read handler invoked get with the session id');
Tap::ok( ! $call['result'], 'no data returned');

$call = array_shift( $calls );
Tap::is( $call['method'], 'set', 'write handler called cache set');
Tap::like( $call['args'][0], "/$id/", 'write handler invoked set with the session id');

Tap::is( $call['args'][1]['data'], 'test|s:3:"foo";', 'serialized test=>foo written');

$call = array_shift( $calls );

Tap::is( $call['method'], 'add', 'write handler called add to lock the key');
