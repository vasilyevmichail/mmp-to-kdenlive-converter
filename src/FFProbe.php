<?php
declare(strict_types=1);

namespace Vasilyevmichail\MmpToKdenlive;

/**
 * Обёртка над ffprobe: возвращает массив с метаданными файла.
 */
final class FFProbe
{
    public static function which(string $cmd): ?string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $p) {
            $full = rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cmd;
            if (is_file($full) && is_executable($full)) return $full;
        }
        return null;
    }

    /**
     * @return array{size:int, md5:string, streams:array, format:array}
     */
    public static function metadata(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл не найден: $path");
        }

        $meta = [
            'size'    => filesize($path),
            'md5'     => md5_file($path),
            'streams' => [],
            'format'  => [],
        ];

        if (!self::which('ffprobe')) {
            return $meta;
        }

        $cmd = 'ffprobe -v error -print_format json -show_streams -show_format '
             . escapeshellarg($path) . ' 2>&1';
        $out = shell_exec($cmd);
        if (!$out) return $meta;

        $data = json_decode($out, true);
        if (!is_array($data)) return $meta;

        $meta['streams'] = $data['streams'] ?? [];
        $meta['format']  = $data['format']  ?? [];
        return $meta;
    }
}