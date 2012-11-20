<?php
/**
 * Block that displays a simple block rendering profiler
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Block_Profiler extends Mage_Core_Block_Abstract
{
    /**
     * Render basic HTML
     * 
     * @return string 
     */
    protected function _toHtml()
    {
        $profilerData = Made_Cache_Model_Profiler::getProfilerData();
        if (empty($profilerData)) {
            return;
        }

        $totalTime = 0;

        $html = '<table>';
        $html .= '<thead><tr><th>Block</th><th>Time (ms)</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($profilerData as $blockName => $time) {
            $html .= '<tr>'
                    . '<td>' . $blockName . '</td>'
                    . '<td>' . intval($time*1000) . '</td>'
                    . '</tr>';
            
            $totalTime += $time;
        }
        
        $html .= '<tr><th>Total time spent rendering uncached blocks:</th>'
                . '<th>' . intval($totalTime*1000) . '</th></tr>';
        $html .= '</tbody>';
        $html .= '</table>';
        
        return $html;
    }
}