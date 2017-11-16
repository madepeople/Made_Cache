<?php

/**
 * Contains functions related to Varnish
 *
 * Methods from https://www.varnish-software.com/static/book/Cache_invalidation.html
 * are used in order to create a dynamic cache invalidation approach
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Helper_Varnish extends Mage_Core_Helper_Abstract
{
    const USER_CACHE_TYPE_ALL = 'all';
    const USER_CACHE_TYPE_ESI = 'esi';
    const USER_CACHE_TYPE_MESSAGES = 'messages';

    const HTTP_HEADER_MAX_SIZE = 8192;
    const HTTP_TAG_HEADER_LIMIT = 1;
    const HTTP_TAG_PREFIX = 'X-Made-Cache-Tags';

    const XML_PATH_TIMEOUT = 'cache/varnish/timeout';
    const XML_PATH_CONNECTION_TIMEOUT = 'cache/varnish/connection_timeout';

    protected $_callVarnish = true;
    
    protected static $_calls = array();
    protected static $_servers = null;

    /**
     * Initializes the server list upon instantiation
     */
    public function __construct()
    {
        $this->initServers();
    }

    /**
     * Perform the real varnish calls on object destruction
     */
    public function __destruct()
    {
        foreach (self::$_calls as $row) {
            $this->_callVarnish($row['urls'], $row['type'], $row['headers']);
        }
    }

    /**
     * If set to false we don't call varnish. This can be a good idea for long
     * imports that do a lot of product saving for instance.
     *
     * @param $bool
     */
    public function setCallVarnish($callVarnish)
    {
        $this->_callVarnish = (bool)$callVarnish;
    }

    /**
     * Get what is set
     *
     * @return bool
     */
    public function getCallVarnish()
    {
        return $this->_callVarnish;
    }

    /**
     * Determine if varnish is in front of Magento
     *
     * @return boolean
     */
    public function isInFront()
    {
        return !!Mage::app()->getFrontController()
            ->getRequest()
            ->getHeader('X-Varnish');
    }

    /**
     * Determine if Varnish functions should be used
     *
     * @return boolean
     */
    public function shouldUse()
    {
        return Mage::app()->useCache('varnish') && $this->isInFront();
    }

    /**
     * Returns an array of tags to store with the Varnish object in cache in
     * order to clear routes using the same tags as Magento modules use
     *
     * @param $tags
     * @return array
     */
    public function getTagHeaders($tags)
    {
        $headers = array();
        for ($i = 1; count($tags) > 0 && $i <= self::HTTP_TAG_HEADER_LIMIT;) {
            $tag = array_pop($tags);
            $header = self::HTTP_TAG_PREFIX . '-' . $i;

            if (!isset($headers[$header])) {
                $headers[$header] = '|';
            }

            $headerString = $headers[$header];
            $headerString .= $tag . '|';

            if (strlen($headerString) > self::HTTP_HEADER_MAX_SIZE) {
                $i++;
                $tags[] = $tag;
                continue;
            }

            $headers[$header] = $headerString;
        }

        if (count($tags)) {
            $tagCount = count($tags);
            $message =<<<EOF
Too many block tags present, unable to store as HTTP headers in Varnish:
    Request: {$_SERVER['REQUEST_URI']}
    Number of remaining tags: $tagCount
EOF;

            Mage::log($message, null, 'made-cache.log');
        }

        return $headers;
    }

    /**
     * Initializes the varnish server list
     *
     * @return array
     */
    public function initServers()
    {
        $serversConfig = Mage::getStoreConfig('cache/varnish/servers');
        $serversArray = preg_split('/[\r\n]+/', $serversConfig, null, PREG_SPLIT_NO_EMPTY);
        $servers = array();

        foreach ($serversArray as $server) {
            $server = trim($server);

            // Skip new lines
            if (empty($server)) {
                continue;
            }

            $servers[] = $server;
        }

        $result = new Varien_Object([
            'servers' => $servers
        ]);
        Mage::dispatchEvent('made_cache_get_varnish_servers', [
            'result' => $result
        ]);
        $servers = $result->getServers();
        $this->setServers($servers);
    }

    /**
     * Returns an array of varnish servers
     *
     * @return array
     */
    public function getServers()
    {
        return self::$_servers;
    }

    /**
     * Manually set the varnish server list
     *
     * @param array $servers
     */
    public function setServers(array $servers)
    {
        self::$_servers = $servers;
    }

    /**
     * Flush varnish cache by banning all content
     */
    public function flush()
    {
        return $this->callVarnish('', 'FLUSH');
    }

    /**
     * Bans an URL or more from the Varnish cache
     *
     * @param string|array $urls
     */
    public function ban($urls)
    {
        $urls = (array)$urls;
        $status = array();
        foreach ($urls as $url) {
            $header = 'X-Ban-String: obj.http.url ~ ' . $url;
            $status = array_merge($this->callVarnish('/', 'BAN', array($header)), $status);
        }
        return $status;
    }

    /**
     * Bans an URL or more from the Varnish cache using Magento cache tags
     *
     * @param string|array $urls
     */
    public function banTags($tags)
    {
        // Ignore the quote tag because it's cleared regardless via the
        // X-Session-UUID
        $ignoreTagPatterns = array(
            '/^quote/',
        );

        $tags = (array)$tags;
        $status = array();
        foreach ($tags as $tag) {
            foreach ($ignoreTagPatterns as $ignorePattern) {
                if (preg_match($ignorePattern, $tag)) {
                    continue 2;
                }
            }

            $tag = '\|' . $tag . '\|';

            for ($i = 1; $i <= self::HTTP_TAG_HEADER_LIMIT; $i++) {
                $header = 'X-Ban-String: obj.http.' . self::HTTP_TAG_PREFIX . '-' . $i . ' ~ ' . $tag;
                $status = array_merge($this->callVarnish('/', 'BAN', array($header)), $status);
            }
        }
        return $status;
    }

    /**
     * Purge specific object in varnish cache
     *
     * @param string|array $urls
     */
    public function purge($urls)
    {
        return $this->callVarnish($urls, 'PURGE');
    }

    /**
     * Refresh specific content in varnish, might be more costly than PURGE
     * because backend is called, but also doesn't invalidate cache if the
     * backend is acting up
     *
     * @param string|array $urls
     */
    public function refresh($urls)
    {
        return $this->callVarnish($urls, 'REFRESH');
    }

    /**
     * Instead of calling varnish directly we merge all calls into one and 
     * do the real calls on destruct to prevent multiple purges of the same 
     * URL within one request
     * 
     * @param $urls
     * @param string $type
     * @param array $headers
     */
    public function callVarnish($urls, $type = 'PURGE', $headers = array())
    {
        if (!$this->getCallVarnish()) {
            return array();
        }
        $urls = (array)$urls;
        asort($urls);
        asort($headers);
        $key = md5(join('|', array(
            join('|', $urls),
            $type,
            join('|', $headers),
        )));
        self::$_calls[$key] = array(
            'urls' => $urls,
            'type' => $type,
            'headers' => $headers
        );
        return array();
    }

    /**
     * Send a message to all defined Varnish servers
     *
     * Uses code from magneto-varnish.
     *
     * @see https://github.com/madalinoprea/magneto-varnish/blob/master/code/Varnish/Helper/Data.php#L48
     * @param string|array $urls
     * @param string $type
     * @param array $headers
     */
    protected function _callVarnish($urls, $type = 'PURGE', $headers = array())
    {
        $servers = $this->getServers();
        $timeout = $this->getTimeout();
        $connectionTimeout = $this->getConnectionTimeout();
        if (empty($servers)) {
            // De nada
            return [];
        }

        // Init curl handler
        $curlHandlers = array(); // keep references for clean up
        $mh = curl_multi_init();

        foreach ($servers as $varnishServer) {
            foreach ($urls as $url) {
                $varnishUrl = "http://" . $varnishServer . $url;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $varnishUrl);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

                if ($timeout) {
                    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);
                }

                if ($connectionTimeout) {
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $connectionTimeout);
                }

                if (!empty($headers)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                }

                curl_multi_add_handle($mh, $ch);
                $curlHandlers[] = $ch;
            }
        }

        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active);

        // Error handling and clean up
        $errors = array();

        // For curl multi, the errors are fetched only via curl_multi_info_read, we need to add error info into raw error
        while ($done = curl_multi_info_read($mh)) {
            $id = (int)$done['handle'];

            if ($done['result'] !== CURLM_OK) {
                $errors[$id] = curl_getinfo($done['handle']);
                $errors[$id]['errno'] = $done['result'];
                $errors[$id]['error'] = curl_strerror($done['result']);
            }
        }

        foreach ($curlHandlers as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $errors = $this->parseErrors($errors);
        if ($errors) {
            Mage::log($errors, null, 'made-cache.log');
        }

        return $errors;
    }

    /**
     * Parsing raw errors and flattening them
     *
     * @param $errors
     * @return array
     */
    public function parseErrors($errors)
    {
        $parsedErrors = [];
        if ($errors) {
            foreach ($errors as $error) {
                $errorString = "Cannot purge url {$error['url']} due to curl error {$error['errno']} - {$error['error']}";
                if ($error['http_code'] != 200 && $error['http_code'] != 404) {
                    $errorString = $errorString . ", http code: {$error['http_code']}";
                }
                $parsedErrors[] = $errorString;
            }
        }
        return $parsedErrors;
    }

    /**
     * Getting config value for curl timeout
     *
     * @return int
     */
    public function getTimeout() {
        return (int)Mage::getStoreConfig(self::XML_PATH_TIMEOUT);
    }

    /**
     * Getting config value for curl connection timeout
     *
     * @return int
     */
    public function getConnectionTimeout() {
        return (int)Mage::getStoreConfig(self::XML_PATH_CONNECTION_TIMEOUT);
    }

    /**
     * Retreive an ESI tag for the specified URL
     *
     * The reason we change https to http is because varnish doesn't speak
     * SSL anyway, and if we terminate SSL before varnish it needs to be
     * able to crawl ESI links
     *
     * @param string $url
     */
    public function getEsiTag($url)
    {
        $url = preg_replace('#^/#', '', $url);
        $url = preg_replace('#^https#', 'http', Mage::getUrl($url));
        $esiTag = '<esi:include src="' . $url . '"/>';
        return $esiTag;
    }

    /**
     * Return a hash of the block layout XML in the current configuration,
     * this is used to identify a unique rendering of the block as we cache
     * all ESI requests
     *
     * @param Mage_Core_Block_Abstract $block
     */
    public function getLayoutHash(Mage_Core_Block_Abstract $block)
    {
        $xml = $block->getLayout()->getNode();
        $doc = new DOMDocument;
        $doc->loadXML($xml->asXML());
        $xpath = new DOMXpath($doc);
        $nodeList = $xpath->query("//block[@name='" . $block->getNameInLayout() . "']");
        return sha1($doc->saveXML($nodeList->item(0)));
    }

    /**
     * Helper function that purges the user session cache for cached ESI
     * blocks
     */
    public function purgeUserCache($type = self::USER_CACHE_TYPE_ALL)
    {
        $sessionId = Mage::getSingleton('core/session')->getSessionId();
        if (!empty($sessionId)) {
            switch ($type) {
                case self::USER_CACHE_TYPE_ALL:
                    $url = 'madecache/varnish/(esi|messages)';
                    break;
                case self::USER_CACHE_TYPE_ESI:
                    $url = 'madecache/varnish/esi';
                    break;
                case self::USER_CACHE_TYPE_MESSAGES:
                    $url = 'madecache/varnish/messages';
                    break;
            }
            $this->callVarnish('/', 'BAN', array('X-Ban-String: obj.http.url ~ ' . $url . ' && obj.http.X-Session-UUID == ' . $sessionId));
        }
    }

    /**
     * Retrieve the TTL for the current request
     *
     * @param type $request
     */
    public function getRequestTtl($request)
    {
        if ($request->isPost()) {
            // Never cache POST
            return null;
        }

        if ($this->_matchRoutesAgainstRequest('madecache/varnish/esi', $request)) {
            // All ESI requests should have the same TTL - 1 as the session itself
            return intval(Mage::getStoreConfig('web/cookie/cookie_lifetime') - 1) . 's';
        }

        if ($this->_matchRoutesAgainstRequest('madecache/varnish/cookie', $request)) {
            // Never cache the cookie pinger
            return null;
        }

        // Messages should only be cached if they are empty
        if ($this->_matchRoutesAgainstRequest('madecache/varnish/messages', $request)) {
            if (Mage::helper('cache')->responseHasMessages()) {
                return null;
            }
        } else {
            $cacheRoutes = Mage::getStoreConfig('cache/varnish/cache_routes');
            if (!$this->_matchRoutesAgainstRequest($cacheRoutes, $request)) {
                return null;
            }
        }

        return Mage::getStoreConfig('cache/varnish/ttl');
    }

    /**
     * Match routes against the current request for cache exclusion
     *
     * @param array|string $routes
     * @param object $request
     * @return boolean
     */
    protected function _matchRoutesAgainstRequest($routes, $request)
    {
        if (!is_array($routes)) {
            $routes = explode("\n", $routes);
        }

        $routesToMatch = array();
        foreach ($routes as $key => $handle) {
            $handle = trim($handle);
            if (empty($handle)) {
                continue;
            }
            $routesToMatch[] = $handle;
        }

        if (in_array($request->getModuleName(), $routesToMatch)
            || in_array($request->getModuleName() . '/' . $request->getControllerName(), $routesToMatch)
            || in_array($request->getModuleName() . '/' . $request->getControllerName() . '/' . $request->getActionName(), $routesToMatch)
        ) {
            return true;
        }

        return false;
    }

}
