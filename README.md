
# mmp-to-kdenlive-converter

Библиотека на PHP 8.1+ для конвертации JSON-описания клипов (экспорт из MMP)
в проект Kdenlive (`.kdenlive`, формат MLT XML).

## Установка

```bash
composer require vasilyevmichail/mmp-to-kdenlive-converter
```

Для работы желательно иметь ffprobe в PATH — библиотека использует его
для заполнения метаданных <chain>.

```php
php
use Vasilyevmichail\MmpToKdenlive\KdenliveEditor;

$editor = (new KdenliveEditor())
    ->setClipsRoot('/home/patti/Videos/')
    ->setProjectRoot('/home/patti/Videos');

$source_mmp_json_file = 'los002.json';
$source_kdenlive_file = 'viva clips_2025.kdenlive';
$output_kdenlive_file = 'out.kdenlive';

$editor->apply(
    $source_mmp_json_file,
    $source_kdenlive_file,
    $output_kdenlive_file
);
```


Или через CLI:

```bash
php bin/mmp-to-kdenlive los002.json "viva clips_2025.kdenlive" out.kdenlive
```

## Что делает

- Читает JSON со списком треков (tracks[], поля file_path, trim_in_hms,
trim_out_hms, start_time_hms, transition).
- Для каждого уникального файла создаёт <chain> с полным набором
свойств (out, kdenlive:proxy, kdenlive:originalurl, mute_on_pause,
meta.media.*) — как это делает Kdenlive после «Заменить клип».
- Раскладывает клипы по свободным видеодорожкам (без kdenlive:audio_track)
и аудиодорожкам (с kdenlive:audio_track), чередуя их.
- Расставляет fade_from_black / fade_to_black на видео.
- Обновляет main_bin, kdenlive:sequenceproperties.groups,
tracksCount, tractor30@out, producer0@out, position.
- Сохраняет новый .kdenlive.


## Требования

- PHP >= 8.1
- ext-dom, ext-json
- ffprobe (опционально, но рекомендуется)

## Лицензия
MIT