<?php

/**
 * When varnish is in front we use ESI and a custom messages getter action
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Block_Messages extends Mage_Core_Block_Messages
{
    protected function _toHtml()
    {
        $helper = Mage::helper('cache/varnish');
        $request = Mage::app()->getRequest();
        if ($helper->shouldUse()
            && $helper->getRequestTtl($request)
            && !$this->getBypassVarnish()
        ) {
            return Mage::helper('cache/varnish')
                    ->getEsiTag('madecache/varnish/messages');
        }

        return parent::_toHtml();
    }
}
