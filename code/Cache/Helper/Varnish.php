<?php
/**
 * Contains functions related to Varnish
 *
 * Methods from https://www.varnish-software.com/static/book/Cache_invalidation.html
 * are used in order to create a dynamic cache invalidation approach
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Helper_Varnish extends Mage_Core_Helper_Abstract
{
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
        return Mage::app()->useCache('varnish')
                && $this->isInFront();
    }

    /**
     * Returns an array of defined Varnish servers
     * @return type
     */
    public function getServers()
    {
        $serversConfig = preg_split('/[\r\n]+/', Mage::getStoreConfig('cache/varnish/servers'), null, PREG_SPLIT_NO_EMPTY);
        $servers = array();

        foreach ($serversConfig as $server) {
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
     * @param string $url
     */
    public function getEsiTag($url)
    {
        $url = preg_replace('#^/#', '', $url);
        return '<esi:include src="' . Mage::getUrl($url) . '"/>';
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
        $nodeList = $xpath->query("//block[@name='".$block->getNameInLayout()."']");
        return sha1($doc->saveXML($nodeList->item(0)));
    }

    /**
     * Helper function that purges the user session cache for cached ESI
     * blocks
     */
    public function purgeUserCache()
    {
        $sessionId = Mage::getSingleton('core/session')->getSessionId();
        if (!empty($sessionId)) {
            $this->_callVarnish('/', 'BAN', array('X-Ban-String: req.url ~ madecache/varnish/(esi|messages) && req.http.X-Session-UUID == ' . $sessionId));
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

        $noCacheRoutes = Mage::getStoreConfig('cache/varnish/nocache_routes');

        if ($this->_matchRoutesAgainstRequest($noCacheRoutes, $request)
                || $this->_matchRoutesAgainstRequest('madecache/varnish/cookie', $request)) {
            return null;
        }

        // Messages should only be cached if they are empty
        if ($this->_matchRoutesAgainstRequest('madecache/varnish/messages', $request)
                && Mage::helper('cache')->responseHasMessages()) {
            return null;
        }

        if ($this->_matchRoutesAgainstRequest('madecache/varnish/esi', $request)) {
            // All ESI requests should have the same TTL as the session itself
            return intval(Mage::getStoreConfig('web/cookie/cookie_lifetime')) . 's';
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

        foreach ($routes as $key => $handle) {
            if (($handle = trim($handle)) === '') {
                continue;
            }
            $routes[$key] = $handle;
        }

        if (in_array($request->getModuleName(), $routes)
                || in_array($request->getModuleName() . '/' . $request->getControllerName(), $routes)
                || in_array($request->getModuleName() . '/' . $request->getControllerName() . '/' . $request->getActionName(), $routes))
        {
            return true;
        }

        return false;
    }

}
