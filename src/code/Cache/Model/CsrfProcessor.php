<?php

/**
 * The purpose of this model is to make the CSRF token work with Varnish.
 * Ideally we would want to keep the synchronizer pattern but we can't without
 * compromising a lot of performance. We would need to cache the key in session
 * level in Redis or something and inject it dynamically using ESI into the
 * header on each affected page, but that would require always hitting Magento.
 *
 * @author jonathan@madepeople.se
 */
class Made_Cache_Model_CsrfProcessor
{

    const CSRF_PLACEHOLDER = '_MADE_CACHE_CSRF_';
    const CSRF_KEY = 'CSRF_KEY';

    /**
     * Generate a random string and use it as a CSRF cookie instead of the
     * input hidden field (which is actually more secure than this)
     *
     * @param $content
     * @return mixed
     */
    public function extractContent($content)
    {
        if (isset($_COOKIE[self::CSRF_KEY])) {
            $formKey = $_COOKIE[self::CSRF_KEY];
        } else {
            $formKey = substr(md5(rand()), 0, 16);
            setcookie(self::CSRF_KEY, $formKey, 0, '/');
        }

        $content = str_replace(self::CSRF_PLACEHOLDER, $formKey, $content);
        return $content;
    }
}