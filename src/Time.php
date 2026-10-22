<?php
declare(strict_types=1);

namespace Vasilyevmichail\MmpToKdenlive;

/**
 * Утилиты для работы со временем в формате Kdenlive/MLT.
 * Проект по умолчанию: 25 fps.
 */
final class Time
{
    public const FPS = 25;
    public const FRAME_DUR = 1.0 / self::FPS;

    /** 'HH:MM:SS' или 'HH:MM:SS.mmm' -> секунды */
    public static function parse(string $s): float
    {
        $s = trim($s);
        if (!preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/', $s, $m)) {
            throw new \InvalidArgumentException("Неверный формат времени: '$s'");
        }
        $ms = isset($m[4]) ? str_pad($m[4], 3, '0') : '000';
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] + ((int)$ms) / 1000.0;
    }

    /** секунды -> кадры */
    public static function secondsToFrames(float $sec): int
    {
        return (int)round($sec / self::FRAME_DUR);
    }

    /** кадры -> 'HH:MM:SS.mmm' */
    public static function framesToTimecode(int $frames): string
    {
        $total = $frames * self::FRAME_DUR;
        $h = (int)floor($total / 3600);
        $m = (int)floor(fmod($total, 3600) / 60);
        $s = fmod($total, 60);
        return sprintf('%02d:%02d:%06.3f', $h, $m, $s);
    }

    /** 'HH:MM:SS' -> кадры */
    public static function toFrames(string $s): int
    {
        return self::secondsToFrames(self::parse($s));
    }
}