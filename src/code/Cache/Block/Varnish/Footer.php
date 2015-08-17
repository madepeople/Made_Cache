<?php

/**
 * Block that echoes a resource whose purpose is to maintain the cookie for the
 * client. This is needed in order to make sure that the cookie the browser
 * sends actually corresponds to a session within Magento.
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Block_Varnish_Footer extends Mage_Core_Block_Abstract
{
    /**
     * Generate the script resource AJAX request. This could be a good place
     * to fetch placeholders (such as recently viewed) in a general way,
     * by adding product id data from a cookie to a placeholder element ID,
     * for instance
     *
     * @return string
     */
    protected function _toHtml()
    {
        $scriptUrl = Mage::getUrl('madecache/varnish/cookie');

        $html = <<<EOF
<script type="text/javascript">
new Ajax.Request('$scriptUrl', {method: 'get'});
</script>
EOF;
        return $html;
    }
}