<?php

namespace App\Libraries;

use CodeIgniter\Exceptions\CriticalError;

/**
 * In addition to the normal $config options in RedisHandler.php, this also supports:
 * username - username for Redis ACL
 * password - password for Redis ACL
 * persistent - bool - toggle persistent connections
 * tls - bool or string - toggle/configure TLS
 *
 * NOTE: RedisCluster constructor accepts an array of ssl context options (defined here: https://www.php.net/manual/en/context.ssl.php).
 * If you pass 'true' for the tls option, then we'll turn on tls with ['verify_peer' => false]. But if you pass a string,
 * we'll run parse_str on it to pull it apart and feed it into RedisCluster. You should know what you're doing if you
 * pass in a string.
 */
class RedisCluster extends \CodeIgniter\Cache\Handlers\RedisHandler
{
    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->handleConnect();

        // Use the php serializer to handle everything we set/get from redis.
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        // Better scan behavior
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        if (! empty($this->prefix)) {
            // Let the driver handle the prefix.
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
        }
    }

    /**
     * NOTE: You can connect to redis cluster via a singular endpoint, the redis extension will automatically run
     * 'CLUSTER NODES' to discover what other nodes are available. We're preserving the ability to list an array
     * of comma-separated servers here in case someone still wants to configure it that way.
     * @return array
     */
    protected function parseClusterConfig(): array
    {
        if (empty($hosts = str_getcsv($this->config['host']))) {
            throw new CriticalError("Must specify one or more comma-separated hosts to work with in 'host' configuration.");
        }

        $port = $this->config['port'] ?? 6379;

        if ($port > 0) {
            // User defined a port so let's make sure it's setup for all of the cluster hosts.
            foreach ($hosts as &$host) {
                if (! preg_match('/:\d+$/', $host)) {
                    // User didn't append :port to their cluster server name so let's do that for them.
                    $host .= ":{$port}";
                }
            }
        }

        return $hosts;
    }

    /**
     * Instantiate a RedisCluster given our configs.
     * @return void
     * @throws \RedisClusterException
     * @throws \RedisException
     */
    protected function handleConnect()
    {
        $timeout    = (int)($this->config['timeout'] ?? 0);
        $persistent = isset($this->config['persistent']) && $this->config['persistent'];
        $hosts      = $this->parseClusterConfig();
        $auth       = null;
        $tls        = null;

        if(isset($this->config['tls'])) {
            if(is_bool($this->config['tls']) && $this->config['tls']) {
                $tls = ['verify_peer' => false];
            } else if(is_string($this->config['tls'])) {
                parse_str($this->config['tls'], $tls);
            }
        }

        if (isset($this->config['password'])) {
            if (isset($this->config['username'])) {
                // Redis 6+ accepts username/password (see Redis ACL)
                $auth = [
                    'user' => $this->config['username'],
                    'pass' => $this->config['password'],
                ];
            } else {
                // Previous Redis versions only support password.
                $auth = $this->config['password'];
            }
        }


        /*
        * This instantiates the cluster connection
        * Note: RedisCluster supports a first argument of $name which is a 'seed name', which serves as a pointer
        * to a named cluster with an array of 'seeds'/hosts, which is defined in php.ini. Predis doesn't support this.
        * My intent is to only offer configuration for RedisCluster/Predis for features that are shared for both.
        * Since $name isn't a shared feature, I'm not setting it up here, although it wouldn't be hard to have a new
        * 'clusterName' parameter in $config and be able to plug that in here.
        */
        $this->redis = new \RedisCluster(null, $hosts, $timeout, $timeout, $persistent, $auth, $tls);
        $this->redis->setOption(\RedisCluster::OPT_SLAVE_FAILOVER, \RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key)
    {
        $key  = static::validateKey($key);
        $data = $this->redis->get($key);

        return $data === false ? null : $data;
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $key, $value, int $ttl = 60)
    {
        $key = static::validateKey($key);

        return $ttl > 0 ? $this->redis->setEx($key, $ttl, $value) : $this->redis->set($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMatching(string $pattern)
    {
        $matchedKeys = [];
        // When running a scan, the driver doesn't prefix the pattern with the configured prefix.
        if(isset($this->prefix)) {
            $pattern = $this->prefix . $pattern;
        }

        foreach ($this->redis->_masters() as $m) {
            $iterator = null;

            do {
                $keys = $this->redis->scan($iterator, $m, $pattern);

                // Redis may return empty results, so protect against that
                if ($keys) {
                    array_push($matchedKeys, ...$keys);
                }
            } while ($iterator > 0);
        }

        if (! empty($this->prefix)) {
            // Prefix is set so remove it, because otherwise we'll double up on the prefix.
            $this->redis->setOption(\Redis::OPT_PREFIX, null);
        }

        $rtnVal = $this->redis->del($matchedKeys);

        if (! empty($this->prefix)) {
            // Restore the prefix if it's set.
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
        }
        return $rtnVal;
    }

    /**
     * {@inheritDoc}
     */
    public function clean()
    {
        foreach ($this->redis->_masters() as $m) {
            $this->redis->flushAll($m);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheInfo()
    {
        $rtnVal = [];
        $nodeCount = 0;

        foreach ($this->redis->_masters() as $m) {
            $nodeCount++;

            foreach ($this->redis->info($m) as $key => $value) {
                $rtnVal[$key][] = $value;
            }
        }
        // Summarize the keyspace counts for backwards compatibility with tests.
        // if we have other stats we need to combine, that can be done here as well.
        $db   = "db{$this->config['database']}";
        $sums = ['keys' => 0, 'expires' => 0, 'avg_ttl' => 0];

        foreach ($rtnVal[$db] as $ks) {
            foreach (explode(',', $ks) as $stat) {
                [$key, $value] = explode('=', $stat);
                $sums[$key] += (int)$value;
            }
        }
        $sums['avg_ttl'] = (int) ($sums['avg_ttl'] / $nodeCount);
        $finalData       = [];

        foreach ($sums as $k => $v) {
            $finalData[] = "{$k}={$v}";
        }
        $rtnVal[$db] = implode(',', $finalData);

        return $rtnVal;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetaData(string $key)
    {
        $key    = static::validateKey($key);
        $rtnVal = null;
        if (null !== ($data = $this->get($key))) {
            $time   = time();
            $ttl    = $this->redis->ttl($key);
            $rtnVal = [
                'expire' => $ttl > 0 ? time() + $ttl : null,
                'mtime'  => $time,
                'data'   => $data,
            ];
        }

        return $rtnVal;
    }

    /**
     * The default increment() function uses 'hIncrBy', which means that when we issue a get() on the key, we get a
     * WRONGTYPE operation, since it's storing the incremented value in a hash. Override it to use a regular key.
     * {@inheritDoc}
     */
    public function increment(string $key, int $offset = 1)
    {
        return $this->redis->incrBy($key, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function isSupported(): bool
    {
        return extension_loaded('redis');
    }

    /**
     * Closes the connection to Redis if present.
     */
    public function __destruct()
    {
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    /**
     * Get a direct instance of the redis driver.
     * @return \Redis|\RedisCluster
     */
    public function getDriver() {
        return $this->redis;
    }

    /**
     * We're letting the driver handle the prefix, there is pretty much no such thing as a key too long for redis, and
     * redis doesn't have reserved characters, so bother adjusting/checking the key names.
     * @param $key
     * @param $prefix
     * @return string
     */
    public static function validateKey($key, $prefix = ''): string
    {
        return $key;
    }


}
