#!/usr/bin/env php
<?php
use Gaia\Test\Tap;
use Gaia\DB;

include __DIR__ . '/../common.php';
include __DIR__ . '/../assert/pdo_installed.php';
include __DIR__ . '/../assert/pdo_sqlite_installed.php';

try {
    DB\Connection::load( array(
    'test'=> function () {
        $db = new Gaia\DB\Driver\PDO( 'sqlite::memory:');
        return $db;
    }
    ) );
    $db = DB\Connection::instance('test');
} catch( \Exception $e ){
    Tap::plan('skip_all', $e->__toString());
}
Tap::plan(14);
Tap::ok( DB\Connection::instance('test') === $db, 'db instance returns same object we instantiated at first');

$rs = $db->execute('SELECT %s as foo, %s as bar', 'dummy\'', 'rummy');
Tap::ok( $rs, 'query executed successfully');
Tap::is($rs->fetch(PDO::FETCH_ASSOC), array('foo'=>'dummy\'', 'bar'=>'rummy'), 'sql query preparation works on strings');

$rs = $db->execute('SELECT %i as test', '111212244554333333');
Tap::is( $rs->fetch(PDO::FETCH_ASSOC), array('test'=>'111212244554333333'), 'query execute works injecting big integer in');

$rs = $db->execute('SELECT %i as test', 'dummy');
Tap::is( $rs->fetch(PDO::FETCH_ASSOC), array('test'=>'0'), 'query execute sanitizes non integer');

$rs = $db->execute('SELECT %f as test', '1112.12244554333');
Tap::is( $rs->fetch(PDO::FETCH_ASSOC), array('test'=>'1112.12244554333'), 'query execute works injecting big float in');

$rs = $db->execute('SELECT %f as test', 'dummy');
Tap::is( $rs->fetch(PDO::FETCH_ASSOC), array('test'=>'0'), 'query execute sanitizes non float');

$query = $db->format_query('%s', array('dummy', 'rummy'));
Tap::is($query, "'dummy', 'rummy'", 'format query handles arrays of strings');

$query = $db->format_query('%i', array(1,2,3));
Tap::is($query, '1, 2, 3', 'format query handles arrays of integers');

$query = $db->format_query('%f', array(1.545,2.2,3));
Tap::is($query, '1.545, 2.2, 3', 'format query handles arrays of floats');

$query = $db->format_query('test %%s ?, (?,?)', array(1, 2), 3, 4);
Tap::is($query, "test %s '1', '2', ('3','4')", 'format query question mark as string');

$db = new DB\Except( $db );

$err = NULL;
try {
    $db->execute('err');
} catch( Exception $e ){
    $err = (string) $e;
}

Tap::like($err, '/database error/i', 'When a bad query is run using execute() the except wrapper tosses an exception');


$db = new DB\Observe( $db );
Tap::is( $db->isa('pdo'), TRUE, 'isa returns true for inner class');
Tap::is( $db->isa('gaia\db\driver\pdo'), TRUE, 'isa returns true for driver');
