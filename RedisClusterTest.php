<?php

/**
 * This file is part of CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */


namespace CodeIgniter\Cache\Handlers;

require_once('AbstractHandlerTest.php');

use CodeIgniter\CLI\CLI;
use Config\Cache;
use \App\Libraries\RedisCluster;

/**
 * @group CacheLive
 *
 * @internal
 */
final class RedisClusterTest extends AbstractHandlerTest
{
    private Cache $config;

    private static function getKeyArray()
    {
        return [
            self::$key1,
            self::$key2,
            self::$key3,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('redis extension not loaded.');
        }

        $this->config = new Cache();

        $this->handler = new RedisCluster($this->config);

        $this->handler->initialize();

        $this->handler->clean();
    }

    protected function tearDown(): void
    {
        foreach (self::getKeyArray() as $key) {
            $this->handler->delete($key);
        }
    }

    public function testNew()
    {
        $this->assertInstanceOf(RedisCluster::class, $this->handler);
    }

    public function testDestruct()
    {
        $this->handler = new RedisCluster($this->config);
        $this->handler->initialize();

        $this->assertInstanceOf(RedisCluster::class, $this->handler);
    }

    /**
     * This test waits for 3 seconds before last assertion so this
     * is naturally a "slow" test on the perspective of the default limit.
     *
     * @timeLimit 3.5
     */
    public function testGet()
    {
        $this->handler->save(self::$key1, 'value', 2);

        $this->assertSame('value', $this->handler->get(self::$key1));
        $this->assertNull($this->handler->get(self::$dummy));

        CLI::wait(3);
        $this->assertNull($this->handler->get(self::$key1));
    }

    /**
     * This test waits for 3 seconds before last assertion so this
     * is naturally a "slow" test on the perspective of the default limit.
     *
     * @timeLimit 3.5
     */
    public function testRemember()
    {
        $this->handler->remember(self::$key1, 2, static fn () => 'value');

        $this->assertSame('value', $this->handler->get(self::$key1));
        $this->assertNull($this->handler->get(self::$dummy));

        CLI::wait(3);
        $this->assertNull($this->handler->get(self::$key1));
    }

    public function testSave()
    {
        $this->assertTrue($this->handler->save(self::$key1, 'value'));
    }

    public function testSavePermanent()
    {
        $this->assertTrue($this->handler->save(self::$key1, 'value', 0));
        $metaData = $this->handler->getMetaData(self::$key1);

        $this->assertNull($metaData['expire']);
        $this->assertLessThanOrEqual(1, $metaData['mtime'] - time());
        $this->assertSame('value', $metaData['data']);

        $this->assertTrue($this->handler->delete(self::$key1));
    }

    public function testDelete()
    {
        $this->handler->save(self::$key1, 'value');

        $this->assertTrue($this->handler->delete(self::$key1));
        $this->assertFalse($this->handler->delete(self::$dummy));
    }

    public function testDeleteMatchingPrefix()
    {
        // Save 101 items to match on
        for ($i = 1; $i <= 101; $i++) {
            $this->handler->save('key_' . $i, 'value' . $i);
        }

        $dbInfo = explode(',', $this->handler->getCacheInfo()['db0']);
        $this->assertSame('keys=101', $dbInfo[0]);

        // Checking that given the prefix "key_1", deleteMatching deletes 13 keys:
        // (key_1, key_10, key_11, key_12, key_13, key_14, key_15, key_16, key_17, key_18, key_19, key_100, key_101)
        $this->assertSame(13, $this->handler->deleteMatching('key_1*'));

        // check that there remains (101 - 13) = 88 items is cache store
        $dbInfo = explode(',', $this->handler->getCacheInfo()['db0']);
        $this->assertSame('keys=88', $dbInfo[0]);
    }

    public function testDeleteMatchingSuffix()
    {
        $this->handler->clean();
        // Save 101 items to match on
        for ($i = 1; $i <= 101; $i++) {
            $this->handler->save('key_' . $i, 'value' . $i);
        }

        $dbInfo = explode(',', $this->handler->getCacheInfo()['db0']);
        $this->assertSame('keys=101', $dbInfo[0]);

        // Checking that given the suffix "1", deleteMatching deletes 11 keys:
        // (key_1, key_11, key_21, key_31, key_41, key_51, key_61, key_71, key_81, key_91, key_101)
        $this->assertSame(11, $this->handler->deleteMatching('*1'));

        // check that there remains (101 - 11) = 90 items is cache store
        $dbInfo = explode(',', $this->handler->getCacheInfo()['db0']);
        $this->assertSame('keys=90', $dbInfo[0]);
    }

    public function testIncrement()
    {
        $key = 'keyToIncrement';
        $this->handler->delete($key);
        $this->assertSame(1, $this->handler->increment($key));
        $this->assertSame(1, (int) $this->handler->get($key));
        $this->assertSame(11, $this->handler->increment($key, 10));
        $this->assertSame(11, (int) $this->handler->get($key));
    }

    public function testDecrement()
    {
        $key = 'keyToDecrement';
        $this->handler->delete($key);
        // Set an initial value to decrement from
        $this->assertSame(10, $this->handler->increment($key, 10));
        $this->assertSame(9, $this->handler->decrement($key));
        $this->assertSame(9, (int) $this->handler->get($key));
        $this->assertSame(4, $this->handler->decrement($key, 5));
        $this->assertSame(4, (int) $this->handler->get($key));
    }

    public function testClean()
    {
        $this->handler->save(self::$key1, 1);

        $this->assertTrue($this->handler->clean());
    }

    public function testGetCacheInfo()
    {
        $this->handler->save(self::$key1, 'value');

        $this->assertIsArray($this->handler->getCacheInfo());
    }

    public function testIsSupported()
    {
        $this->assertTrue($this->handler->isSupported());
    }
}
