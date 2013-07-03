<?php

namespace Jackalope\Transport\DoctrineDBAL\Util;

/**
 * Xpath utilities
 *
 */
class Xpath 
{

    /**
     * Escapes a string to be used in an xpath query
     *
     * @param $query
     * @param string $delimiter
     * @return string
     */
    public static function escape($query, $delimiter = '"')
    {
        if ((strpos($query, '\'') !== false) ||
            (strpos($query, '"') !== false))
        {
            $quotechars = array('\'','"');
            $parts = array();
            $current = '';

            foreach (str_split($query) as $character) {

                if (in_array($character, $quotechars)) {
                    $parts[] = '\'' . $current . '\'';

                    if ($character == '\'') {
                        $parts[] = '"' . $character . '"';
                    } else {
                        $parts[] = '\'' . $character . '\'';
                    }

                    $current = '';
                } else {
                    $current .= $character;
                }
            }

            if ($current) {
                $parts[] = '\''.$current.'\'';
            }

            $ret = 'concat(' . implode(',', $parts) . ')';
        } else {
            $ret = $delimiter . $query . $delimiter;
        }

        return $ret;
    }

}