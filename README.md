# Redis Cluster Compatibility for CodeIgniter4

### Installation
- Copy RedisCluster.php and RedisSession.php to app/Libraries
- In your base directory (one level up from 'app'), create a directory for the tests: `mkdir -p tests/cache`
- Copy tests to new directory: `cp RedisClusterTest.php AbstractHandlerTest.php tests/cache`

### Configuration
- Edit app/Config/Cache.php.  Add `'redisCluster' => \App\Libraries\RedisCluster::class` to the `$validHandlers` array.
- If you want to toggle persistence, TLS, and/or use Redis ACL, add those keys to the `$redis` array, like this. Note that
if don't have a key in your config array (like `$redis`), and you put the key in your .env file, that key will be ignored
and will not be automatically loaded:
```
    public $redis = [
        'host'     => '127.0.0.1',
        'password' => null,
        'port'     => 6379,
        'timeout'  => 0,
        'database' => 0,
        'username' => null,
        'persistent' => true,
        'tls' => false
    ];
```
- In your app/Config/Cache.php file, set `$handler` to `'redisCluster'` _OR_ in your .env file, set `cache.handler = redisCluster`
- In either your Cache.php or .env file, set `host` to your cluster address.  You can pass in a comma-separated list of 
hosts like this: `node-1,node-2,node-3,<etc>`. Note that having a list is not
required, but it is supported.
- If you want your sessions to be handled in your cluster as well, then in app/Config/App.php _OR_ in .env, set `app.sessionDriver`
to `\App\Libraries\RedisSession::class`

### Testing
- In your base directory, run `vendor/phpunit/phpunit/phpunit tests/cache/RedisClusterTest.php`

### FYI
This was assembled kind of quick and dirty but it should work.  If I get some time I'll try to get this into packagist.
