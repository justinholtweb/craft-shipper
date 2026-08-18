<?php

namespace justinholtweb\shipper\helpers;

use DOMDocument;
use DOMElement;

/**
 * DOM helpers for building the ShipStation Custom Store payload.
 *
 * ShipStation's parser wants free text in CDATA and numbers and dates as bare text nodes; mixing
 * the two up is the usual cause of a store that imports orders with empty fields.
 */
abstract class Xml
{
    /**
     * Append a CDATA-wrapped child element. Null and empty values still produce the element, so
     * the shape of the document does not change between orders.
     */
    public static function cdata(DOMElement $parent, string $name, mixed $value): DOMElement
    {
        $document = $parent->ownerDocument;
        $child = $parent->appendChild($document->createElement($name));
        $child->appendChild($document->createCDATASection(self::clean($value)));

        return $child;
    }

    /**
     * Append a plain-text child element, for numerics, dates and enumerations.
     */
    public static function text(DOMElement $parent, string $name, mixed $value): DOMElement
    {
        $document = $parent->ownerDocument;
        $child = $parent->appendChild($document->createElement($name));
        $child->appendChild($document->createTextNode(self::clean($value)));

        return $child;
    }

    public static function child(DOMElement $parent, string $name): DOMElement
    {
        return $parent->appendChild($parent->ownerDocument->createElement($name));
    }

    /**
     * ShipStation's date format throughout the Custom Store API: UTC, `MM/dd/yyyy HH:mm`.
     */
    public static function date(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $utc = (new \DateTimeImmutable('@' . $date->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('m/d/Y H:i');
    }

    /**
     * Parse a date sent by ShipStation. It documents `MM/dd/yyyy HH:mm` in UTC but also emits a
     * compact `MMDDYYYYxHHMM` form, which `strtotime()` cannot read.
     */
    public static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $utc = new \DateTimeZone('UTC');

        if (strtotime($value) === false) {
            if (strlen($value) >= 13) {
                $rebuilt = sprintf(
                    '%s-%s-%s %s:%s:00',
                    substr($value, 4, 4),
                    substr($value, 0, 2),
                    substr($value, 2, 2),
                    substr($value, 9, 2),
                    substr($value, 11, 2)
                );

                try {
                    return new \DateTimeImmutable($rebuilt, $utc);
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }

        try {
            return new \DateTimeImmutable($value, $utc);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strip characters XML 1.0 cannot carry. A single stray control byte from a pasted customer
     * note otherwise makes the whole document unparseable and the store silently imports nothing.
     */
    public static function clean(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? 'true' : '';
        }

        $string = (string)$value;

        return (string)preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $string);
    }

    public static function document(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;

        return $document;
    }
}
