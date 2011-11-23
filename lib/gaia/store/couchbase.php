<?php
/**
 * EXPERIMENTAL.
 * This API could change dramatically over the next few cycles of iteration.
 * unstable, for demonstration purposes only.
 *
 * Couchbase works like memcache for key/value access, but it writes the data into couchdb-like
 * storage so you can access views of the data through the rest API. Instead of databases like couchdb
 * has, couchbase has buckets. If you don't use the dedicated port for that bucket you need to use the 
 * binary memcached protocol with SASL authentication to access one of the other buckets. Since most php 
 * clients don't do this well, you need to set up a dedicated port for each bucket.
 *
 * In the development environment, that would mean a new bucket for each developer for each application.
 * I found it was easier for everyone to share the same bucket, but just use key prefixes to avoid
 * data collisions. If we apply the same key prefix idea to the views transparently, we can all share 
 * the same bucket and still avoid data collisions.
 *
 * That means use of different buckets is a performance optimization, not a design constraint. I namespace
 * the views in design document locations, and then transparently wrap the emit and map reduce calls 
 * with a special javascript function to make the keys match what we put in without the namespace. 
 *
 * An example may help explain. I am building an app called zoo. during development, I name my app:
 * 
 *    $app = 'dev_jloehrer.zoo';
 * 
 * The prefix of dev_  let's couchbase know that the view can be applied to a subset of the data most 
 * recently being manipulated but doesn't have to be applied across the cluster to all the data in the
 * bucket. the jloehrer part is my username so that my keys don't collide with anyone else's keys.
 *
 * I instantiate my object:
 *
 *      $cb = new Couchbase( array(
 *                      'app'       => 'dev_jloehrer-zoo',
 *                      'rest'      => 'http://127.0.0.1:5984/default/',
 *                      'socket'    => '127.0.0.1:11211',
 *              ));
 *
 * The rest url provided allows us to communicate with the couchdb core of couchbase to get view
 * information, while the socket is the uber-fast memcached protocol connection to couchbase for 
 * key/value access.
 *
 * Now let's set a key into our application:
 *
 *      $cb->set('bear', array('type'=>'mammal', 'eats'=>'meat', legs'=>4 ) );
 *
 * Gaia\Store\Couchbase stores the data into couchbase with the key: 
 *
 *      dev_jloehrer-zoo/bear:
 *
 *  The value is automatically serialized json:
 *  
 *      telnet localhost 11211
 *      Trying 127.0.0.1...
 *      Connected to localhost.localdomain (127.0.0.1).
 *      Escape character is '^]'.
 *      get dev_jloehrer-zoo/bear
 *      VALUE dev_jloehrer-zoo/bear 0 40
 *      {"type":"mammal","eats":"meat","legs":4}
 *      END
 *
 * This allows couchbase to do map reduce operations on the data in the views. For more on how views
 * work, read up on how couchdb views work: 
 *
 *      http://wiki.apache.org/couchdb/Introduction_to_CouchDB_views
 *
 *  couchbase works almost identically (with a few very subtle differences).
 *
 * Let's create our first view:
 *
 *      $cb->saveView('mammals', 'function(doc){ if( doc.type=="mammal" ){ emit(doc._id, doc); }}');
 *
 * this is a very simple javascript map function that we'll use to find all the mammals in our app.
 * Views are just documents like any other object stored in couchbase.  We can take a look at it by 
 * viewing the url:
 *
 *    $http = new \Gaia\Http\Request("http://127.0.0.1:5984/default/_design/dev_jloehrer-zoo/");
 *    $result = json_decode( $http->exec()->body, TRUE);
 *    echo $result['views']['mammals']['map'];

 * 
 * outputs:
 *
 *        function(doc){ 
 *            if( doc._id.substr(0, 17) == 'dev_jloehrer-zoo/') { 
 *                var d = eval(uneval(doc));
 *                d._id = doc._id.substr(17);
 *                var inner = function(doc){ if( doc.type=="mammal" ){ emit(doc._id, doc); }}; 
 *                inner(d);  
 *            }
 *        }
 *    
 *
 * Gaia\Store\Couchbase decorated our map function with some extra javascript. It makes sure that
 * it only emits if the key has our application prefix. the internal doc._id function is rewritten 
 * to not have the prefix. This makes our calls to the view work properly.
 *
 * Now we are ready to query our new view:
 *
 *    $result = $cb->view('mammals', array('full_set'=>'true'));
 *    print_r( $result );
 *
 * This will output something similar to:
 *
 *     Array
 *     (
 *         [total_rows] => 1
 *         [rows] => Array
 *             (
 *                 [0] => Array
 *                     (
 *                         [id] => bear
 *                         [key] => bear
 *                         [value] => Array
 *                             (
 *                                 [_id] => bear
 *                                 [_rev] => 14-17c29c395ff470849cbec896b17df884
 *                                 [$flags] => 0
 *                                 [$expiration] => 0
 *                                 [type] => mammal
 *                                 [eats] => meat
 *                                 [legs] => 4
 *                             )
 *     
 *                     )
 *     
 *             )
 *     
 *     )
 *
 * If you don't specify an app, it doesn't do key prefixes for you, and all of the views are stuck in
 * the default design document:
 *
 *    http://127.0.0.1:5984/default/_design/default/
 *
 * For more examples or details, see:
 *    
 *    tests/store/couchbase.t
 */
namespace Gaia\Store;
use Gaia\Http;
use Gaia\Serialize\Json;
use Gaia\Container;
use Gaia\Exception;

class Couchbase extends Wrap {
    
    protected $rest = '';
    protected $s;
    protected $app = '';
    protected $http;
    
    // a simple wrapper that matches on the design name prefix.
    const MAP_TPL = "
    function(doc){ 
        if( doc._id.substr(0, %d) == '%s') { 
            var d = eval(uneval(doc));
            d._id = doc._id.substr(%d);
            var inner = %s; 
            inner(d);  
        }
    }";
    
    
    
    /**
    * Instantiate the couchbase object. pass in a set of named params.
    * Example:
    *      $cb = new Couchbase( array(
    *                      'app'       => 'myapp',
    *                      'rest'      => 'http://127.0.0.1:5984/default/',
    *                      'socket'    => '127.0.0.1:11211',
    *              ));
    */
    public function __construct(array $params ){
        if( ! isset( $params['app'] ) ) $params['app'] = '';
        $app = isset( $params['app'] ) ? trim($params['app'], '/ ') : '';
        if( strlen( $app ) > 0 ) $this->app = $app .'/';
        if( ! isset( $params['rest'] ) )  $params['rest'] = '';
        $this->rest = $params['rest'];
        $this->s = new Json('');
        $core = isset( $params['socket'] ) ? $params['socket'] : NULL;
        if( ! $core instanceof Memcache ) $core = new Memcache( $core );
        $core = new Prefix( new Serialize($core, $this->s), $this->app);
        parent::__construct( $core );
    }
    
    /**
    * get back a the view results.
    * Example:
    *    $params = array(
    *       'startkey'=>'bear',
    *       'endkey'=>'zebra',
    *       'connection_timeout'=> 60000,
    *       'limit'=>10,
    *       'skip'=>0,
    *       'full_set'=>'true',
    *    );
    *    $result = $cb->view('mammals' $params);
    *
    */
    public function view( $view, $params = NULL ){
        $params = new Container( $params );
        foreach( $params as $k => $v ) {
            if( ! is_scalar( $v ) || preg_match('#key#i', $k) ){
                $params->$k = json_encode( $v );
            }
        }
        $len = strlen( $this->app );
        $app = ( $len > 0 ) ? $this->app : 'default/';
        $http = $this->request( '_design/' . $app . '_view/' . $view . '/?' . http_build_query( $params->all()) );
        $response = $this->validateResponse( $http->exec(), array(200) );
        $result = $response->body;
        if( $len < 1 ) return $result;
        foreach($result['rows'] as & $row ){
            if( isset( $row['id'] ) ) {
                $row['id'] = substr( $row['id'], $len );
            }
        }
        
        //print_r( $result );
        return $result;
    }
    
    /*
    * create or overwrite a view.
    * Example:
    *
    *    $res = $cb->saveView('amount', 'function(doc){ emit(doc._id, doc.amount);}', '_sum');
    *
    * This uses the built-in _sum reduce function that can give you the sum of all the results.
    * or just specify a map function:
    *
    *    $res = $cb->saveView('full', 'function(doc){ emit(doc._id, {foo: doc.foo});}');
    *
    * returns an ok status along with a document rev id.
    */
    public function saveView($name, $map, $reduce ='' ){
        $len = strlen( $this->app );
        $app = ( $len > 0 ) ? $this->app : 'default/';
        $http = $this->request( '_design/' . $app);
        $response = $this->validateResponse( $http->exec(), array(200, 201, 404) );
        $result = $response->body;
        if( ! is_array( $result ) ) $result = array();
        if( isset( $result['error'] ) ){
            if( $result['error']  == 'not_found' ){
                $result = array();
            } else {
                throw new Exception('query failed', $http );
            }
        }
        if( ! isset ( $result['views'] ) ) $result['views'] = array();
        if( $map === NULL ){
            unset( $result['views'][$name] );
        } else {
            if( $len ) $map = sprintf( self::MAP_TPL, $len, $this->app, $len, $map );
            $result['views'][$name] = array('map'=>$map);
            if( $reduce ) $result['views'][$name]['reduce'] = $reduce;
        }
        $http->post = $result;
        $http->method = 'PUT';
        $response = $this->validateResponse( $http->exec(), array(200, 201) );
        return $response->body;
    }
    
    /**
    * deletes all of the views created in a given app namespace.
    */
    public function deleteAllViews(){
        $len = strlen( $this->app );
        $app = ( $len > 0 ) ? $this->app : 'default/';
        $http = $this->request( '_design/' . $app);
        $response = $this->validateResponse( $http->exec(), array(404, 200) );
        $result = $response->body;
        if( $response->http_code == 404 ) return TRUE;
        $http->url = $http->url . '?rev=' . $result['_rev'];
        $http->method = 'DELETE';
        $response = $this->validateResponse( $http->exec(), array(200, 201) );
        return $response->body;
    }

    /**
    * deletes a specific view in a given app namespace.
    * Example:
    *
    *   $cb->deleteView('amount');
    *
    * throws an exception on error, returns an ok status and rev id on success.
    */
    public function deleteView( $name ){
        return $this->saveView( $name, $map = NULL, $reduce = NULL );
    }
        
    /**
    * haven't quite figured out how to do this yet. Need the equivalent of _all_docs, but narrowed 
    * down to just the docs in my app.
    */
    public function flush(){
        
    }
    
    /*
    * temporary debug method, do not use.
    */
    public function http(){
        return $this->http;
    }
    
    /*
    * temporary debug method, do not use.
    */
    public function request($path){
        $http = $this->http = new Http\Request( $this->rest . $path );
        $http->serializer = $this->s;
        return $http;
    }
    
    /**
    * handle a response.
    */
    protected function validateResponse( \Gaia\Container $response, array $allowed_codes ){
        if( ! in_array( $response->http_code, $allowed_codes )  ) throw new Exception('query failed', $response );
        if( ! is_array( $response->body ) ) throw new Exception('invalid response', $response );
        return $response;
    }
}