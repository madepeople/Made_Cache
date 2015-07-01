<?php

/**
 * Experimental redis cache implementation that uses SCAN for performance,
 * and also keeps an expiry on every key to make it easier to calculate
 * larger amounts of visitors when caching carts, vs the amount of memory.
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */

class Made_Cache_Redis_Backend extends Zend_Cache_Backend
    implements Zend_Cache_Backend_ExtendedInterface
{
    private $_client;

    protected $_options = array(
        'hostname' => '127.0.0.1',
        'port' => 6379,
        'timeout' => '60',
        'prefix' => 'mc:',
        'database' => 0,
    );
    protected $_metadataPrefix = 'metadata_';
    protected $_defaultExpiry = 259200; // Expire a key after a month, regardless

    /**
     * The idea with not returning the client is to bypass caching in case of
     * an error, instead of breaking the world
     *
     * @return null|Redis
     */
    private function _getClient()
    {
        if ($this->_client === null) {
            $this->_client = new Redis();
            $this->_client->connect(
                $this->_options['hostname'],
                $this->_options['port'],
                $this->_options['timeout'],
                100
            );
            $this->_client->select($this->_options['database']);
            $this->_client->setOption(Redis::OPT_PREFIX, $this->_options['prefix']);
        }
        return $this->_client;
    }

    /**
     * Expose the client so we can do custom magic directly Redis
     *
     * @see Made_Cache_Helper_Varnish::saveTagsUrl
     * @return null|\Predis\Client
     */
    public function getClient()
    {
        return $this->_getClient();
    }

    /**
     * SSCAN away to find all keys in a set. We use sets because grouping
     * within the same prefix (means we can have different prefixes for normal
     * cache and the full page cache)
     *
     * @param $client
     * @param $setName
     * @return array
     */
    protected function _getSetArray($client, $setName)
    {
        $keys = array();
        $cursor = 0;
        while ((list ($cursor, $content) = $client->sscan($setName, $cursor))
            && !empty($content)) {
            $keys = array_merge($keys, $content);

            if ($cursor === 0) {
                break;
            }
        }
        return $keys;
    }

    /**
     * Save updated metadata for the specified ID
     *
     * @param $client
     * @param $id
     * @param $metadatas
     */
    protected function _saveMetadata($client, $id, $metadata)
    {
        $metadataKey = $this->_metadataPrefix . $id;
        $metadata['mtime'] = time();
        $client->set($metadataKey, gzcompress(serialize($metadata), 6));
        $client->expireAt($metadataKey, $metadata['expire']);
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        return array();
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return array();
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return array();
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        return array();
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return array();
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        // Redis cleans up itself
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        $client = $this->_getClient();
        $metadataKey = $this->_metadataPrefix . $id;
        $result = $client->get($metadataKey);
        if (!empty($result)) {
            $result = @gzuncompress($result);
            if ($result === false) {
                $client->del($metadataKey);
                return false;
            }
        }
        return unserialize($result);
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $client = $this->_getClient();
        $ttl = $client->ttl($id);
        $lifetime = $ttl + $extraLifetime;
        $result = $client->expireAt($id, $lifetime);
        $metadata = $this->getMetadatas($id);
        $metadata['expire'] = $lifetime;
        $this->_saveMetadata($client, $id, $metadata);
        return (bool)$result;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => false,
            'tags' => true,
            'expired_read' => false,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => false
        );
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * Note : return value is always "string" (unserialization is done by the core not by the backend)
     *
     * @param  string $id Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $metadata = $this->getMetadatas($id);
        if ($metadata === false) {
            return false;
        }

        $client = $this->_getClient();
        $data = $client->get($id);
        if ($data === null) {
            return false;
        }
        $data = @gzuncompress($data);
        if ($data === false) {
            return false;
        }

        $tags = $metadata['tags'];
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                if (!$client->exists($tag)) {
                    $this->remove($id);
                    return false;
                }
            }
        }

        return $data;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $client = $this->_getClient();
        if ($client->exists($id)) {
            $metadataKey = $this->_metadataPrefix . $id;
            $result = $client->get($metadataKey);
        }

        if (!empty($result) === 1) {
            // It failed at EXISTS
            return false;
        }
        $metadata = @gzuncompress($result[1]);
        if ($metadata === false) {
            return false;
        }
        $metadata = unserialize($metadata);
        if (!is_array($metadata) || empty($metadata['mtime'])) {
            return false;
        }

        $tags = $metadata['tags'];
        $pipe = $client->multi();
        foreach ($tags as $tag) {
            $pipe->exists($tag);
        }
        $results = $pipe->exec();
        foreach ($results as $result) {
            if (!$result) {
                return false;
            }
        }

        return $metadata['mtime'];
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data Datas to cache
     * @param  string $id Cache id
     * @param  array $tags Array of strings, the cache record will be tagged by each string entry
     * @param  int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $client = $this->_getClient();
        $client->set($id, gzcompress($data, 6));
        $lifetime = $this->getLifetime($specificLifetime);
        if ($lifetime === null) {
            $lifetime = $this->_defaultExpiry;
        }
        $lifetime += time();
        $client->expireAt($id, $lifetime);

        $now = time();
        $tags = array_unique(array_values($tags));
        foreach ($tags as $tag) {
            $tagCacheTimestamp = $client->get($tag);
            if (!$tagCacheTimestamp) {
                $tagCacheTimestamp = $now;
                $client->set("{$tag}_{$tagCacheTimestamp}", 1);
                $client->expireAt("{$tag}_{$tagCacheTimestamp}", $lifetime);
                $client->set($tag, $tagCacheTimestamp);
                $client->expireAt($tag, $lifetime);
            }
            $saveTags[] = "{$tag}_{$tagCacheTimestamp}";
        }

        $this->_saveMetadata($client, $id, array(
            'expire' => $lifetime,
            'tags' => $saveTags
        ));

        return true;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        $client = $this->_getClient();
        $client->del($id);
        $client->del($this->_metadataPrefix . $id);
        return true;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array $tags Array of tags
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if (!empty($tags)) {
            $tags = array_unique($tags);
        }

        $client = $this->_getClient();
        $keys = null;

        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                $client->flushDB();
                break;

            // Both TAG and ANY_TAG use the same method because we can't differ
            // between AND and OR with our cleaning method
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                foreach ($tags as $tag) {
                    $tagCacheTimestamp = $client->get($tag);
                    if ($tagCacheTimestamp) {
                        $client->del($tag);
                        $client->del("{$tag}_{$tagCacheTimestamp}");
                    }
                }
                break;
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                // Not used by Magento
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                // Redis handles expiration on its own
                break;
        }

        return true;
    }

    /**
     * Redis simple, single instance locking. Acquire a lock using a token
     * to identify the specific lock
     *
     * @see http://redis.io/commands/set
     * @param $token
     * @param $timeout
     * @return boolean
     */
    public function acquireLock($lockName, $token, $timeout, &$counter = null)
    {
        $client = $this->_getClient();
        $result = $client->set($lockName, $token,
            array(
                'nx',
                'ex' => $timeout
            )
        );
        if ($result === false && $counter === false) {
            $counter = $client->incr($lockName);
        }
        return $result === true;
    }

    /**
     * Release the single instance lock
     *
     * @param $lockName
     * @param $token
     */
    public function releaseLock($lockName, $token, &$counter = null)
    {
        $client = $this->_getClient();

        if ($counter !== null && $counter > 0) {
            $counter = $client->decr($lockName);
        }

        $script = '
            if redis.call("get",KEYS[1]) == ARGV[1]
            then
                return redis.call("del",KEYS[1])
            else
                return 0
            end
            ';

        $result = $client->eval($script, 1, $lockName, $token);

        return $result !== 0;
    }
}
