<?php

declare(strict_types=1);

namespace Leap\LaravelSentry\Xml\XmlParser;

use DOMDocument;
use DOMNode;

/**
 * @link https://github.com/gaarf/XML-string-to-PHP-array
 */
class XmlParser
{
    /**
     * @param string $xml
     *
     * @return array|string
     */
    public static function toArray(string $xml)
    {
        $doc = new DOMDocument();

        $doc->loadXML($xml);

        $root = $doc->documentElement;
        $res  = static::nodeToArray($root);

        static::castValues($res);

        return $res;
    }

    /**
     * @param array $array
     */
    protected static function castValues(array &$array): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (isset($value['@attributes']['type'], $value['@content'])) {
                    switch ($value['@attributes']['type']) {
                        case 'decimal':
                            $array[$key] = floatval($value['@content']);
                            break;
                        case 'integer':
                            $array[$key] = intval($value['@content']);
                            break;
                        default:
                            $array[$key] = strval($value['@content']);
                    }
                } elseif (isset($value['@attributes']['nil']) && $value['@attributes']['nil'] === 'true') {
                    $array[$key] = null;
                } else {
                    static::castValues($value);
                    $array[$key] = $value;
                }
            }
        }
    }

    /**
     * @param \DOMNode $node
     *
     * @return array|string
     */
    protected static function nodeToArray(DOMNode $node)
    {
        $res = [];

        switch ($node->nodeType) {
            case XML_CDATA_SECTION_NODE:
            case XML_TEXT_NODE:
                $res = trim($node->textContent);
                break;
            case XML_ELEMENT_NODE:
                for ($i = 0, $m = $node->childNodes->length; $i < $m; $i++) {
                    $child = $node->childNodes->item($i);
                    $value = static::nodeToArray($child);

                    if (isset($child->tagName)) {
                        $tag = $child->tagName;

                        if (!isset($res[$tag])) {
                            $res[$tag] = [];
                        }

                        $res[$tag][] = $value;
                    } elseif ($value || '0' === $value) {
                        $res = (string) $value;
                    }
                }

                if ($node->attributes->length && !is_array($res)) { // Has attributes but isn't an array
                    $res = ['@content' => $res]; // Change output into an array.
                }

                if (is_array($res)) {
                    if ($node->attributes->length) {
                        $attributes = [];

                        foreach ($node->attributes as $attrName => $attrNode) {
                            $attributes[$attrName] = (string) $attrNode->value;
                        }

                        $res['@attributes'] = $attributes;
                    }

                    foreach ($res as $tag => $value) {
                        if (is_array($value) && 1 === count($value) && '@attributes' !== $tag) {
                            $res[$tag] = $value[0];
                        }
                    }
                }

                break;
        }

        return $res;
    }
}
