<?php

/**
 * Interface for cache modifiers. The purpose of modifiers is to add and remove
 * keys that make out the final cache key identifier.
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
interface Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block);
}