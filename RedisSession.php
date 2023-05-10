<?php

namespace App\Libraries;

class RedisSession extends \CodeIgniter\Session\Handlers\RedisHandler
{
    /**
     * Overriding this since we are just pulling the already-connected driver here.
     * {@inheritDoc}
     */
    public function open($path, $name): bool
    {
        // NOTE: This only works if you're using the RedisCluster library too
        $this->redis = service('cache')->getDriver();
        return true;
    }

    /**
     * Overriding this since the parent method calls $this->redis->ping() which isn't valid for RedisCluster, it also
     * attempts to close the connection, set $this->redis to null, which we don't want to happen here.
     * {@inheritDoc}
     */
    public function close(): bool
    {
        if (isset($this->redis)) {
            if (isset($this->lockKey)) {
                $this->redis->del($this->lockKey);
            }
        }
        return true;
    }
}