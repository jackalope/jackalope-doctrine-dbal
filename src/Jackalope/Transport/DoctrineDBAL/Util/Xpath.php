<?php

namespace Jackalope\Transport\DoctrineDBAL\Util;

/**
 * Xpath utilities.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class Xpath
{
    public static function escapeBackslashes($query): string
    {
        return str_replace('\\', '\\\\', $query);

        // TODO: is this dead code relevant?
        // Escape backslahes that aren't escape characters for quotes
        return preg_replace('/([\\\\]+)([^"|\\\']{1})?/', '\1\1\2', $query);
    }

    /**
     * Escapes a string to be used in an xpath query
     * There is a lot of double escaping here because we use single
     * quote in the EXTRACTVALUE functions.
     *
     * The purpose of this method, is to escape a string quotes within a xpath expression
     * which can be kind-of hard.
     *
     * Example:
     *   query: Foo isn't bar
     *   result: concat("Foo isn", "'", "t bar")
     */
    public static function escape(string $query, string $enclosure = '"', bool $doubleEscapeSingleQuote = true): string
    {
        $escapeSingleQuote = $doubleEscapeSingleQuote ? '"\'%s"' : '"%s"';
        $escapeDoubleQuote = $doubleEscapeSingleQuote ? "''%s''" : "'%s'";

        if (str_contains($query, '\'')
            || str_contains($query, '"')
        ) {
            $quotechars = ['\'', '"'];
            $parts = [];
            $current = '';

            foreach (str_split($query) as $character) {
                if (in_array($character, $quotechars, true)) {
                    if ('' !== $current) {
                        $parts[] = $enclosure.$current.$enclosure;
                    }

                    if ('\'' === $character) {
                        $parts[] = sprintf($escapeSingleQuote, $character);
                    } else {
                        $parts[] = sprintf($escapeDoubleQuote, $character);
                    }

                    $current = '';
                } else {
                    $current .= $character;
                }
            }

            if ($current) {
                $parts[] = $enclosure.$current.$enclosure;
            }

            $ret = 'concat('.implode(', ', $parts).')';

            if (count($parts) > 2) {
                $part1 = array_shift($parts);
                $ret = 'concat('.$part1.', '.self::concatBy2($parts).')';
            }

            return $ret;
        }

        return $enclosure.$query.$enclosure;
    }

    /**
     * Because not all concat() implementations support more then 2 arguments,
     * we need this recursive function.
     */
    public static function concatBy2(array $parts): string
    {
        if (2 === count($parts)) {
            return sprintf('concat(%s, %s)', $parts[0], $parts[1]);
        }

        $part1 = array_shift($parts);

        return 'concat('.$part1.', '.self::concatBy2($parts).')';
    }
}
