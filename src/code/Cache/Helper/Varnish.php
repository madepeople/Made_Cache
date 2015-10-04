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
    const HTTP_TAG_HEADER_LIMIT = 3;
    const HTTP_TAG_PREFIX = 'X-Made-Cache-Tags';

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
     * Returns an array of defined Varnish servers
     *
     * @return array
     */
    public function getServers()
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

        return $servers;
    }

    /**
     * Flush varnish cache by banning all content
     */
    public function flush()
    {
        return $this->_callVarnish('', 'FLUSH');
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
            $header = 'X-Ban-String: req.url ~ ' . $url;
            $status = array_merge($this->_callVarnish('/', 'BAN', array($header)), $status);
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
            '/^quote$/',
            '/^quote_.*/'
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
                $status = array_merge($this->_callVarnish('/', 'BAN', array($header)), $status);
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
        return $this->_callVarnish($urls, 'PURGE');
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
        return $this->_callVarnish($urls, 'REFRESH');
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
        $urls = (array)$urls;
        $servers = $this->getServers();

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
        foreach ($curlHandlers as $ch) {
            $info = curl_getinfo($ch);

            if (curl_errno($ch)) {
                $errors[] = "Cannot purge url {$info['url']} due to error" . curl_error($ch);
            } else if ($info['http_code'] != 200 && $info['http_code'] != 404) {
                $errors[] = "Cannot purge url {$info['url']}, http code: {$info['http_code']}";
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $errors;
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
            $this->_callVarnish('/', 'BAN', array('X-Ban-String: req.url ~ ' . $url . ' && req.http.X-Session-UUID == ' . $sessionId));
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
            // Refresh the cookie pinger every minute
            return '60s';
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
