<?php
declare(strict_types=1);

namespace Vasilyevmichail\MmpToKdenlive;

use DOMDocument;
use DOMElement;

/**
 * Мелкие helper-ы для работы с MLT XML.
 */
final class MltXml
{
    public static function propNode(DOMDocument $doc, string $name, string $value): DOMElement
    {
        $p = $doc->createElement('property');
        $p->setAttribute('name', $name);
        $p->appendChild($doc->createTextNode($value));
        return $p;
    }

    public static function getProp(DOMElement $el, string $name): ?string
    {
        foreach ($el->getElementsByTagName('property') as $p) {
            if ($p->getAttribute('name') === $name) return $p->textContent;
        }
        return null;
    }

    public static function removeProp(DOMElement $el, string $name): void
    {
        $toRemove = [];
        foreach ($el->getElementsByTagName('property') as $p) {
            if ($p->getAttribute('name') === $name) $toRemove[] = $p;
        }
        foreach ($toRemove as $p) $el->removeChild($p);
    }

    public static function findPlaylistById(DOMDocument $doc, string $id): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('playlist') as $pl) {
            if ($pl->getAttribute('id') === $id) return $pl;
        }
        return null;
    }

    public static function findTractorById(DOMDocument $doc, string $id): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('tractor') as $tr) {
            if ($tr->getAttribute('id') === $id) return $tr;
        }
        return null;
    }

    public static function findChainByResource(DOMDocument $doc, string $resource): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('chain') as $ch) {
            if (self::getProp($ch, 'resource') === $resource) return $ch;
        }
        return null;
    }

    public static function getMaxClipId(DOMDocument $doc): int
    {
        $max = 0;
        foreach ($doc->getElementsByTagName('chain') as $ch) {
            $id = self::getProp($ch, 'kdenlive:id');
            if ($id !== null && ctype_digit($id)) $max = max($max, (int)$id);
        }
        return $max;
    }

    public static function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}