<?php
declare(strict_types=1);

namespace Vasilyevmichail\MmpToKdenlive;

use DOMDocument;
use DOMElement;

/**
 * Основной класс: читает JSON с описанием треков и добавляет клипы
 * в существующий проект Kdenlive (.kdenlive).
 */
final class KdenliveEditor
{
    /** Корень, относительно которого лежат клипы (для относительных путей) */
    private string $clipsRoot = '/home/patti/Videos/';

    /** Корень проекта (root в <mlt>) */
    private string $projectRoot = '/home/patti/Videos';

    public function setClipsRoot(string $clipsRoot): self
    {
        $this->clipsRoot = rtrim($clipsRoot, '/') . '/';
        return $this;
    }

    public function setProjectRoot(string $projectRoot): self
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        return $this;
    }

    /**
     * Применяет JSON к базовому .kdenlive и сохраняет результат.
     *
     * @return int 0 при успехе
     */
    public function apply(string $jsonPath, string $basePath, string $outPath): int
    {
        $json = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $clips = $json['tracks'] ?? null;
        if (!is_array($clips) || !array_is_list($clips)) {
            throw new \RuntimeException('JSON должен содержать список tracks');
        }

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = true;
        $doc->load($basePath);
        $root = $doc->documentElement;

        $this->addChains($doc, $root, $clips);

        $needTracks = min(2, count($clips));
        $videoTractors = $this->findFreeVideoTractors($doc, $needTracks);
        $audioTractors = $this->findFreeAudioTractors($doc, $needTracks);

        if (count($videoTractors) < $needTracks) {
            throw new \RuntimeException(
                "Недостаточно свободных видеодорожек: нужно $needTracks, найдено "
                . count($videoTractors)
            );
        }
        if (count($audioTractors) < $needTracks) {
            throw new \RuntimeException(
                "Недостаточно свободных аудиодорожек: нужно $needTracks, найдено "
                . count($audioTractors)
            );
        }

        $seqTractor = $this->findSequenceTractor($doc);
        $maxEndFrames = $this->placeClips(
            $doc, $clips, $videoTractors, $audioTractors, $seqTractor
        );

        $this->updateSequenceProperties($doc, $root, $seqTractor, $maxEndFrames, $clips);

        $doc->save($outPath);
        return 0;
    }

    // -----------------------------------------------------------------
    // Chains
    // -----------------------------------------------------------------

    private array $chainIdByPath = [];

    private function addChains(DOMDocument $doc, DOMElement $root, array $clips): void
    {
        $maxId = MltXml::getMaxClipId($doc);
        $profile = $root->getElementsByTagName('profile')->item(0);

        foreach ($clips as $clip) {
            $fp = $clip['file_path'] ?? null;
            if (!$fp) throw new \RuntimeException('У клипа нет file_path');

            $resource = $this->resolveResourcePath($fp);
            if (isset($this->chainIdByPath[$resource])) continue;

            $existing = MltXml::findChainByResource($doc, $resource);
            if ($existing !== null) {
                $this->chainIdByPath[$resource] = $existing->getAttribute('id');
                continue;
            }

            $maxId++;
            $meta = FFProbe::metadata($resource);
            $chainId = "chain_auto_$maxId";
            $ch = $this->makeFullChain($doc, $chainId, $fp, $meta, $maxId);

            if ($profile && $profile->nextSibling) {
                $root->insertBefore($ch, $profile->nextSibling);
            } else {
                $root->insertBefore($ch, $root->firstChild);
            }

            $this->chainIdByPath[$resource] = $chainId;
        }
    }

    /**
     * Создаёт <chain> с полным набором свойств, как Kdenlive после «Заменить клип».
     */
    private function makeFullChain(
        DOMDocument $doc,
        string $chainId,
        string $resource,
        array $meta,
        int $clipId
    ): DOMElement {
        $ch = $doc->createElement('chain');
        $ch->setAttribute('id', $chainId);

        $durationSec = isset($meta['format']['duration'])
            ? (float)$meta['format']['duration'] : 0.0;
        $lengthFrames = $durationSec > 0
            ? max(1, Time::secondsToFrames($durationSec))
            : 4879;

        $ch->setAttribute('out', Time::framesToTimecode($lengthFrames));

        // базовые
        $ch->appendChild(MltXml::propNode($doc, 'length', (string)$lengthFrames));
        $ch->appendChild(MltXml::propNode($doc, 'eof', 'pause'));
        $ch->appendChild(MltXml::propNode($doc, 'resource', $resource));
        $ch->appendChild(MltXml::propNode($doc, 'mlt_service', 'avformat-novalidate'));
        $ch->appendChild(MltXml::propNode($doc, 'seekable', '1'));
        $ch->appendChild(MltXml::propNode($doc, 'format', '3'));

        // индексы потоков
        $videoIdx = null;
        $audioIdx = null;
        foreach ($meta['streams'] ?? [] as $i => $st) {
            if (($st['codec_type'] ?? '') === 'video' && $videoIdx === null) $videoIdx = $i;
            if (($st['codec_type'] ?? '') === 'audio' && $audioIdx === null) $audioIdx = $i;
        }

        $ch->appendChild(MltXml::propNode($doc, 'video_index',
            $videoIdx !== null ? (string)$videoIdx : '-1'));
        $ch->appendChild(MltXml::propNode($doc, 'vstream', '0'));
        $ch->appendChild(MltXml::propNode($doc, 'audio_index',
            $audioIdx !== null ? (string)$audioIdx : '-1'));
        if ($audioIdx !== null) {
            $ch->appendChild(MltXml::propNode($doc, 'astream', '0'));
        }

        // kdenlive-специфичные
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:folderid', '-1'));
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:id', (string)$clipId));
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:clip_type', '0'));
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:control_uuid',
            '{' . MltXml::uuid4() . '}'));
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:proxy', '-'));
        $ch->appendChild(MltXml::propNode($doc, 'kdenlive:originalurl', $resource));
        $ch->appendChild(MltXml::propNode($doc, 'mute_on_pause', '0'));

        if (!empty($meta['size'])) {
            $ch->appendChild(MltXml::propNode($doc, 'kdenlive:file_size', (string)$meta['size']));
        }
        if (!empty($meta['md5'])) {
            $ch->appendChild(MltXml::propNode($doc, 'kdenlive:file_hash', $meta['md5']));
        }

        // полные meta.media.*
        $streams = $meta['streams'] ?? [];
        $ch->appendChild(MltXml::propNode($doc, 'meta.media.nb_streams',
            (string)count($streams)));

        foreach ($streams as $i => $st) {
            $type = $st['codec_type'] ?? '';
            $prefix = "meta.media.{$i}.";
            $ch->appendChild(MltXml::propNode($doc, $prefix . 'stream.type', $type));

            if ($type === 'video') {
                $fr = $st['avg_frame_rate'] ?? ($st['r_frame_rate'] ?? '');
                if (strpos($fr, '/') !== false) {
                    [$num, $den] = explode('/', $fr);
                    $fr = ((int)$den !== 0) ? (string)((int)$num / (int)$den) : '25';
                }
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'stream.frame_rate', $fr));

                $sar = $st['sample_aspect_ratio'] ?? '1:1';
                $ch->appendChild(MltXml::propNode($doc,
                    $prefix . 'stream.sample_aspect_ratio',
                    $sar === '0:1' ? '0' : $sar));

                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.width',
                    (string)($st['width'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.height',
                    (string)($st['height'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.rotate',
                    (string)($st['tags']['rotate'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.pix_fmt',
                    (string)($st['pix_fmt'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.sample_aspect_ratio',
                    (string)($st['sample_aspect_ratio'] ?? '1')));

                $cs = str_replace('bt', '', $st['color_space'] ?? '');
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.colorspace', $cs));

                $trc = str_replace('bt', '', $st['color_transfer'] ?? '');
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.color_trc', $trc));

                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.name',
                    (string)($st['codec_name'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.long_name',
                    (string)($st['codec_long_name'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.bit_rate',
                    (string)($st['bit_rate'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'media_type', 'video'));

            } elseif ($type === 'audio') {
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.sample_fmt',
                    (string)($st['sample_fmt'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.sample_rate',
                    (string)($st['sample_rate'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.channels',
                    (string)($st['channels'] ?? 0)));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.layout',
                    (string)($st['channel_layout'] ?? 'stereo')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.name',
                    (string)($st['codec_name'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.long_name',
                    (string)($st['codec_long_name'] ?? '')));
                $ch->appendChild(MltXml::propNode($doc, $prefix . 'codec.bit_rate',
                    (string)($st['bit_rate'] ?? 0)));
            }
        }

        // format.tags
        $fmt = $meta['format'] ?? [];
        if (!empty($fmt['tags']['major_brand'])) {
            $ch->appendChild(MltXml::propNode($doc, 'meta.attr.major_brand.markup',
                (string)$fmt['tags']['major_brand']));
        }
        if (!empty($fmt['tags']['encoder'])) {
            $ch->appendChild(MltXml::propNode($doc, 'meta.attr.encoder.markup',
                (string)$fmt['tags']['encoder']));
        }

        return $ch;
    }

    // -----------------------------------------------------------------
    // Поиск свободных дорожек
    // -----------------------------------------------------------------

    /**
     * @return string[] ID tractor'ов без kdenlive:audio_track и с пустыми playlist
     */
    private function findFreeVideoTractors(DOMDocument $doc, int $needed): array
    {
        $result = [];
        foreach ($doc->documentElement->getElementsByTagName('tractor') as $tr) {
            if (MltXml::getProp($tr, 'kdenlive:projectTractor') !== null) continue;
            if (MltXml::getProp($tr, 'kdenlive:uuid') !== null) continue;
            if (MltXml::getProp($tr, 'kdenlive:audio_track') !== null) continue;

            if ($this->isEmptyTractor($doc, $tr)) {
                $result[] = $tr->getAttribute('id');
                if (count($result) >= $needed) break;
            }
        }
        return $result;
    }

    /**
     * @return string[] ID tractor'ов с kdenlive:audio_track и с пустыми playlist
     */
    private function findFreeAudioTractors(DOMDocument $doc, int $needed): array
    {
        $result = [];
        foreach ($doc->documentElement->getElementsByTagName('tractor') as $tr) {
            if (MltXml::getProp($tr, 'kdenlive:projectTractor') !== null) continue;
            if (MltXml::getProp($tr, 'kdenlive:uuid') !== null) continue;
            if (MltXml::getProp($tr, 'kdenlive:audio_track') === null) continue;

            if ($this->isEmptyTractor($doc, $tr)) {
                $result[] = $tr->getAttribute('id');
                if (count($result) >= $needed) break;
            }
        }
        return $result;
    }

    private function isEmptyTractor(DOMDocument $doc, DOMElement $tr): bool
    {
        $tracks = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof DOMElement && $c->tagName === 'track') $tracks[] = $c;
        }
        if (!$tracks) return false;

        foreach ($tracks as $t) {
            $plId = $t->getAttribute('producer');
            $pl = MltXml::findPlaylistById($doc, $plId);
            if (!$pl) continue;
            foreach ($pl->childNodes as $c) {
                if ($c instanceof DOMElement &&
                    ($c->tagName === 'entry' || $c->tagName === 'blank')) {
                    return false;
                }
            }
        }
        return true;
    }

    private function findSequenceTractor(DOMDocument $doc): DOMElement
    {
        foreach ($doc->documentElement->getElementsByTagName('tractor') as $tr) {
            if (MltXml::getProp($tr, 'kdenlive:uuid') !== null) return $tr;
        }
        throw new \RuntimeException('Не найден sequence-tractor');
    }

    // -----------------------------------------------------------------
    // Раскладка клипов
    // -----------------------------------------------------------------

    private function placeClips(
        DOMDocument $doc,
        array $clips,
        array $videoTractors,
        array $audioTractors,
        DOMElement $seqTractor
    ): int {
        // Только две дорожки каждого типа (или меньше, если клипов меньше)
        $vPair = array_slice($videoTractors, 0, min(2, count($videoTractors)));
        $aPair = array_slice($audioTractors, 0, min(2, count($audioTractors)));

        $videoEnds = array_fill_keys($vPair, 0);
        $audioEnds = array_fill_keys($aPair, 0);

        $maxEndFrames = 0;
        $groups = [];
        $maxId = MltXml::getMaxClipId($doc);

        foreach ($clips as $i => $clip) {
            $fp = $clip['file_path'];
            $resource = $this->resolveResourcePath($fp);

            $cIn  = Time::toFrames($clip['trim_in_hms']);
            $cOut = Time::toFrames($clip['trim_out_hms']);
            $dur  = max(0, $cOut - $cIn);

            // start_time строго из JSON
            $start = Time::toFrames($clip['start_time_hms']);
            $end   = $start + $dur;

            // чередование дорожек
            $videoTr = $vPair[$i % count($vPair)];
            $audioTr = $aPair[$i % count($aPair)];

            // Наложение внутри дорожки — это ошибка входных данных, а не повод сдвигать
            if ($start < $videoEnds[$videoTr]) {
                throw new \RuntimeException(sprintf(
                    'Клип #%d (%s) начинается в %s, но видеодорожка %s занята до %s. '
                    . 'Данные не помещаются на %d видеодорожек.',
                    $i + 1, $clip['file_name'] ?? $fp,
                    Time::framesToTimecode($start), $videoTr,
                    Time::framesToTimecode($videoEnds[$videoTr]),
                    count($vPair)
                ));
            }
            if ($start < $audioEnds[$audioTr]) {
                throw new \RuntimeException(sprintf(
                    'Клип #%d (%s) начинается в %s, но аудиодорожка %s занята до %s. '
                    . 'Данные не помещаются на %d аудиодорожек.',
                    $i + 1, $clip['file_name'] ?? $fp,
                    Time::framesToTimecode($start), $audioTr,
                    Time::framesToTimecode($audioEnds[$audioTr]),
                    count($aPair)
                ));
            }

            $maxEndFrames = max($maxEndFrames, $end);

            // --- видео ---
            $vTractor = MltXml::findTractorById($doc, $videoTr);
            $vTracks  = $this->tractorTracks($vTractor);
            $vPl      = MltXml::findPlaylistById($doc, $vTracks[0]->getAttribute('producer'));

            MltXml::removeProp($vTractor, 'kdenlive:audio_track');
            MltXml::removeProp($vPl, 'kdenlive:audio_track');

            $gapV = $start - $videoEnds[$videoTr];
            if ($gapV > 0) $vPl->appendChild($this->makeBlank($doc, $gapV));

            $vEntry = $this->makeEntry($doc, $cIn, $cOut,
                $this->chainIdByPath[$resource], ++$maxId);
            $vPl->appendChild($vEntry);

            // --- аудио ---
            $aTractor = MltXml::findTractorById($doc, $audioTr);
            $aTracks  = $this->tractorTracks($aTractor);
            $aPl      = MltXml::findPlaylistById($doc, $aTracks[0]->getAttribute('producer'));

            $gapA = $start - $audioEnds[$audioTr];
            if ($gapA > 0) $aPl->appendChild($this->makeBlank($doc, $gapA));

            $aEntry = $this->makeEntry($doc, $cIn, $cOut,
                $this->chainIdByPath[$resource], ++$maxId);
            $aPl->appendChild($aEntry);

            // --- переходы: fade-in в начале, fade-out в конце ---
            // Длительность по умолчанию, если в JSON нет transition.duration.
            // Подберите под ваш вкус: 1.0 сек = 25 кадров.
            $defaultFadeSec = 7.0;
            $defaultFadeFrames = Time::secondsToFrames($defaultFadeSec);

            $tr = $clip['transition'] ?? [];
            $tType = $tr['type'] ?? 'none';
            $tFrames = isset($tr['duration'])
                ? Time::toFrames($tr['duration'])
                : $defaultFadeFrames;

            // fade-in: только если это не первый клип ИЛИ явно задан fade_from_black
            $isFirst = ($i === 0);
            $wantFadeIn = !$isFirst || $tType === 'fade_from_black';
            if ($wantFadeIn && $tFrames > 0) {
                $this->addFadeFilter($doc, $vEntry, "filter_auto_{$i}_in",
                    'fade_from_black', $cIn, $cIn + $tFrames);
            }

            // fade-out: только если это не последний клип ИЛИ явно задан fade_to_black
            $isLast = ($i === count($clips) - 1);
            $wantFadeOut = !$isLast || $tType === 'fade_to_black';
            if ($wantFadeOut && $tFrames > 0) {
                $this->addFadeFilter($doc, $vEntry, "filter_auto_{$i}_out",
                    'fade_to_black', $cOut - $tFrames, $cOut);
            }

            // занятость
            $videoEnds[$videoTr] = $end;
            $audioEnds[$audioTr] = $end;

            // группы: РЕАЛЬНАЯ позиция ($start), а не $tPos
            [$vIdx, $aIdx] = $this->findTrackIndexes($seqTractor, $videoTr, $audioTr);
            $groups[] = [
                'children' => [
                    ['data' => "$vIdx:$start:-1", 'leaf' => 'clip', 'type' => 'Leaf'],
                    ['data' => "$aIdx:$start:-1", 'leaf' => 'clip', 'type' => 'Leaf'],
                ],
                'type' => 'AVSplit',
            ];
        }

        $this->mergeGroups($seqTractor, $groups);
        return $maxEndFrames;
    }

    private function tractorTracks(DOMElement $tr): array
    {
        $out = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof DOMElement && $c->tagName === 'track') $out[] = $c;
        }
        return $out;
    }

    private function findTrackIndexes(DOMElement $seqTractor, string $vId, string $aId): array
    {
        $v = null; $a = null; $idx = 0;
        foreach ($seqTractor->childNodes as $c) {
            if ($c instanceof DOMElement && $c->tagName === 'track') {
                $producer = $c->getAttribute('producer');
                if ($producer === $vId) $v = $idx;
                if ($producer === $aId) $a = $idx;
                $idx++;
            }
        }
        if ($v === null || $a === null) {
            throw new \RuntimeException("Не найден track в sequence-tractor для $vId / $aId");
        }
        return [$v, $a];
    }

    private function mergeGroups(DOMElement $seqTractor, array $groups): void
    {
        $prop = null;
        foreach ($seqTractor->getElementsByTagName('property') as $p) {
            if ($p->getAttribute('name') === 'kdenlive:sequenceproperties.groups') {
                $prop = $p; break;
            }
        }
        if ($prop === null) return;

        $existing = json_decode($prop->textContent, true) ?: [];
        $merged = array_merge($existing, $groups);
        $prop->nodeValue = '';
        $prop->appendChild($prop->ownerDocument->createTextNode(
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ));
    }

    // -----------------------------------------------------------------
    // entry / blank / filters
    // -----------------------------------------------------------------

    private function makeEntry(DOMDocument $doc, int $inFrames, int $outFrames,
                               string $producerId, int $clipId): DOMElement
    {
        $e = $doc->createElement('entry');
        $e->setAttribute('in',  Time::framesToTimecode($inFrames));
        $e->setAttribute('out', Time::framesToTimecode($outFrames));
        $e->setAttribute('producer', $producerId);
        $e->appendChild(MltXml::propNode($doc, 'kdenlive:id', (string)$clipId));
        return $e;
    }

    private function makeBlank(DOMDocument $doc, int $frames): DOMElement
    {
        $b = $doc->createElement('blank');
        $b->setAttribute('length', Time::framesToTimecode($frames));
        return $b;
    }

    private function addFadeFilter(DOMDocument $doc, DOMElement $entry, string $filterId,
                                   string $kind, int $inFrames, int $outFrames): void
    {
        $f = $doc->createElement('filter');
        $f->setAttribute('id', $filterId);
        $f->setAttribute('in',  Time::framesToTimecode($inFrames));
        $f->setAttribute('out', Time::framesToTimecode($outFrames));
        $f->appendChild(MltXml::propNode($doc, 'start', '1'));
        $f->appendChild(MltXml::propNode($doc, 'level', '1'));
        $f->appendChild(MltXml::propNode($doc, 'mlt_service', 'brightness'));
        $f->appendChild(MltXml::propNode($doc, 'kdenlive_id', $kind));
        $f->appendChild(MltXml::propNode($doc, 'alpha',
            $kind === 'fade_from_black' ? '0=0;-1=1' : '0=1;-1=0'));
        $entry->appendChild($f);
    }

    // -----------------------------------------------------------------
    // Обновление sequence properties
    // -----------------------------------------------------------------

    private function updateSequenceProperties(
        DOMDocument $doc,
        DOMElement $root,
        DOMElement $seqTractor,
        int $maxEndFrames,
        array $clips
    ): void {
        $seqTractor->setAttribute('in', '00:00:00.000');
        $seqTractor->setAttribute('out', Time::framesToTimecode($maxEndFrames));

        foreach ($seqTractor->getElementsByTagName('property') as $p) {
            $name = $p->getAttribute('name');
            if ($name === 'kdenlive:duration') {
                $p->nodeValue = '';
                $p->appendChild($doc->createTextNode(Time::framesToTimecode($maxEndFrames)));
            } elseif ($name === 'kdenlive:maxduration') {
                $p->nodeValue = '';
                $p->appendChild($doc->createTextNode((string)$maxEndFrames));
            } elseif ($name === 'kdenlive:sequenceproperties.position') {
                $p->nodeValue = '';
                $p->appendChild($doc->createTextNode('0'));
            }
        }

        $producer0 = null;
        foreach ($root->getElementsByTagName('producer') as $pr) {
            if ($pr->getAttribute('id') === 'producer0') { $producer0 = $pr; break; }
        }
        if ($producer0 !== null) {
            $producer0->setAttribute('out', Time::framesToTimecode($maxEndFrames));
        }

        $trackCount = 0;
        foreach ($seqTractor->childNodes as $c) {
            if ($c instanceof DOMElement && $c->tagName === 'track') $trackCount++;
        }
        foreach ($seqTractor->getElementsByTagName('property') as $p) {
            if ($p->getAttribute('name') === 'kdenlive:sequenceproperties.tracksCount') {
                $p->nodeValue = '';
                $p->appendChild($doc->createTextNode((string)$trackCount));
            }
        }

        // main_bin
        $mainBin = MltXml::findPlaylistById($doc, 'main_bin');
        if ($mainBin !== null) {
            foreach ($this->chainIdByPath as $resource => $chainId) {
                $already = false;
                foreach ($mainBin->getElementsByTagName('entry') as $e) {
                    if ($e->getAttribute('producer') === $chainId) { $already = true; break; }
                }
                if ($already) continue;

                $clipLen = 0;
                foreach ($clips as $clip) {
                    if ($this->resolveResourcePath($clip['file_path']) === $resource) {
                        $len = Time::toFrames($clip['trim_out_hms'])
                             - Time::toFrames($clip['trim_in_hms']);
                        $clipLen = max($clipLen, $len);
                    }
                }
                if ($clipLen <= 0) $clipLen = $maxEndFrames;

                $e = $doc->createElement('entry');
                $e->setAttribute('in', '00:00:00.000');
                $e->setAttribute('out', Time::framesToTimecode($clipLen));
                $e->setAttribute('producer', $chainId);
                $mainBin->insertBefore($e, $mainBin->firstChild);
            }
        }
    }

    // -----------------------------------------------------------------
    // Утилиты
    // -----------------------------------------------------------------

    private function resolveResourcePath(string $fp): string
    {
        if (str_starts_with($fp, '/')) return $fp;
        return $this->clipsRoot . ltrim($fp, '/');
    }
}