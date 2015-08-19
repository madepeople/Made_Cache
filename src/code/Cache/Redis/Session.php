<?php

/**
 * Redis session read/write implementation. Uses document _ids as session IDs
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */

class Made_Cache_Redis_Session
    implements Zend_Session_SaveHandler_Interface
{
    private $_client;

    protected $_options = array(
        'hostname' => '127.0.0.1',
        'port' => 6379,
        'timeout' => '1',
        'prefix' => '',
        'database' => 1,
    );
    protected $_keySet = 'magento_session';
    protected $_defaultExpiry = 259200; // Expire a key after a month, regardless

    const XML_BASE_PATH = 'global/redis_session';

    /**
     * Set up possible variable overrides from local XML definitions
     */
    public function __construct()
    {
        $this->_maxLifetime = Mage::getStoreConfig('web/cookie/cookie_lifetime');

        foreach (array_keys($this->_options) as $key) {
            $xmlPath = self::XML_BASE_PATH . '/' . $key;
            $value = Mage::getConfig()->getNode($xmlPath);
            if ($value !== false) {
                $value = trim((string)$value);
                $this->_options[$key] = $value;
            }
        }
    }

    /**
     * Get the redis client
     *
     * @return Redis
     */
    private function _getClient()
    {
        if ($this->_client === null) {
            $this->_client = new Redis();
            $this->_client->connect(
                $this->_options['hostname'],
                $this->_options['port'],
                $this->_options['timeout']
            );
            $this->_client->select((int)$this->_options['database']);
            $this->_client->setOption(Redis::OPT_PREFIX, $this->_options['prefix']);
        }
        return $this->_client;
    }

    /**
     * Setup save handler
     *
     * @return Made_Cache_Redis_Session
     */
    public function setSaveHandler()
    {
        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );

        register_shutdown_function('session_write_close');

        return $this;
    }

    /**
     * Delete a session from the storage. If PHP calls this we need to fetch
     * the latest revision manually.
     *
     * @param string $id
     * @param string|null $revision
     * @return boolean
     */
    public function destroy($id, $revision = null)
    {
        if (empty($id)) {
            return false;
        }
        $client = $this->_getClient();
        $result = $client->del($id);
        return empty($result);
    }

    /**
     * We don't need to garbage collect, redis handles this on its own
     *
     * @param type $maxlifetime
     */
    public function gc($maxlifetime = null)
    {
        return true;
    }

    /**
     * Unused, we open on demand
     *
     * @param type $save_path
     * @param type $name
     * @return boolean
     */
    public function open($save_path, $name)
    {
        return true;
    }

    /**
     * Unused, we close on demand
     *
     * @return boolean
     */
    public function close()
    {
        return true;
    }

    /**
     * Retrieve the associated session document
     *
     * @param string $id
     */
    public function read($id)
    {
        $client = $this->_getClient();
        $result = $client->get($id);

        if (empty($result)) {
            // We're writing a new session
            return '';
        }

        return gzuncompress($result);
    }

    /**
     * Write stuff with check-and-set
     *
     * @param $id
     * @param $data
     * @return bool
     */
    protected function _multiWrite($id, $data)
    {
        $client = $this->_getClient();
        $client->watch($id);
        $client->multi();
        $client->set($id, gzcompress($data, 6));
        $client->expire($id, $this->_maxLifetime);
        $result = $client->exec();
        if (empty($result[0])) {
            return false;
        }
        return $result;
    }

    /**
     * Optimistic locking using WATCH to write that mudder securely. Everything
     * is quick and direct, so we don't need to sleep timeout or guess stuff
     *
     * @see http://redis.io/topics/transactions
     * @param string $id
     * @param array $data
     */
    public function write($id, $data)
    {
        if (Mage::registry('skip_session_write')) {
            // Fake a session write if the cookie doesn't exist in an ESI
            // request
            return true;
        }
        while (($result = $this->_multiWrite($id, $data)) === false);
        return true;
    }
}
