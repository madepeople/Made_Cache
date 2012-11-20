<?php
/**
 * Model for keeping track of time spent rendering uncached blocks
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Profiler
{
    /**
     * Holds both calculated and raw profiler data
     * 
     * @var array
     */
    private static $_profilerData = array(
        'raw' => array(),
        'calculated' => array()
    );
    
    /**
     * Starting profiler timestamp
     * 
     * @param string $key
     * @return void 
     */
    public static function start($key)
    {
        if (empty($key)) {
            return;
        }
        
        self::$_profilerData['raw'][$key]['start'] = microtime(true);
    }
    
    /**
     * Ending profiler timestamp
     * 
     * @param string $key
     * @return void 
     */
    public static function end($key)
    {
        if (empty($key)) {
            return;
        }
        
        self::$_profilerData['raw'][$key]['end'] = microtime(true);
        self::$_profilerData['calculated'][$key] =
                self::$_profilerData['raw'][$key]['end'] -
                self::$_profilerData['raw'][$key]['start'];
    }
    
    /**
     * Return sorted profiler data, longest time first
     * 
     * @return array
     */
    public static function getProfilerData()
    {
        $profilerData = self::$_profilerData['calculated'];
        arsort($profilerData);
        return $profilerData;
    }
}