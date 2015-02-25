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
        if (Mage::helper('cache/varnish')->shouldUse()
            && !$this->getBypassVarnish()
            && !Mage::app()->getStore()->isAdmin()) {
            return Mage::helper('cache/varnish')
                    ->getEsiTag('madecache/varnish/messages');
        }

        return parent::_toHtml();
    }
}