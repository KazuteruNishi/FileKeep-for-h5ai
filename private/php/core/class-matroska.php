<?php

/** Bounded, seek-based Matroska subtitle, font and chapter reader. */
class Matroska {
    private $fp;
    private $size;
    private $deadline;
    private $elements = 0;
    private $scale = 1000000;
    private $tracks = [];
    private $events = [];
    private $fonts = [];
    private $bytes = 0;
    private $fontBytes = 0;
    private $warnings = [];
    private $editions = [];
    private $chapterCount = 0;

    public function extract($path) {
        if (PHP_INT_SIZE < 8) { throw new RuntimeException('La lecture MKV nécessite PHP 64 bits.'); }
        $this->fp = @fopen($path, 'rb');
        if (!$this->fp) { throw new RuntimeException('Vidéo illisible.'); }
        try {
            $this->size = fstat($this->fp)['size'];
            $this->deadline = microtime(true) + 25;
            $header = $this->element($this->size);
            if (!$header || $header['id'] !== 0x1A45DFA3) { throw new RuntimeException('Ce fichier ne contient pas un en-tête Matroska valide.'); }
            fseek($this->fp, $header['end']);
            while ($element = $this->element($this->size)) {
                if ($element['id'] === 0x18538067) { $this->segment($element['end']); break; }
                fseek($this->fp, $element['end']);
            }
            $result = [];
            foreach ($this->tracks as $number => $track) {
                if (!in_array($track['codec'] ?? '', ['S_TEXT/ASS', 'S_TEXT/SSA'], true)) { continue; }
                if (!empty($track['encoded']) || ($track['timeScale'] ?? 1) != 1) {
                    $this->warnings['encoded'] = 'Certaines pistes compressées ou à horloge spéciale ne sont pas prises en charge.';
                    continue;
                }
                $header = $track['header'] ?? '';
                if (strpos($header, '[Script Info]') === false) { continue; }
                $events = $this->events[$number] ?? [];
                if (!$events) {
                    $this->warnings['empty'] = 'Certaines pistes ne contiennent aucune ligne ASS exploitable avec une durée explicite.';
                    continue;
                }
                usort($events, function ($a, $b) { return $a[0] <=> $b[0]; });
                $ass = rtrim($header) . "\n";
                if (stripos($header, '[Events]') === false) {
                    $ass .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
                }
                foreach ($events as $event) { $ass .= $event[1] . "\n"; }
                $result[] = ['id' => (int) $number, 'label' => $track['name'] ?? ('Piste ' . $number),
                    'language' => $track['language'] ?? 'und', 'ass' => $ass];
            }
            $chapters = $this->chapterList();
            return ['tracks' => $result, 'fonts' => $this->fonts, 'chapters' => $chapters, 'warnings' => array_values($this->warnings)];
        } finally { fclose($this->fp); }
    }

    private function vint($id = false) {
        $first = fread($this->fp, 1);
        if ($first === '') { throw new RuntimeException('Élément MKV tronqué.'); }
        $value = ord($first); $mask = 128; $length = 1;
        while (($value & $mask) === 0 && $length <= 8) { $mask >>= 1; $length++; }
        if ($length > ($id ? 4 : 8)) { throw new RuntimeException('Entier MKV invalide.'); }
        if (!$id) { $value &= $mask - 1; }
        $unknown = !$id && $value === $mask - 1;
        for ($i = 1; $i < $length; $i++) {
            $byte = fread($this->fp, 1);
            if ($byte === '') { throw new RuntimeException('Entier MKV tronqué.'); }
            $byte = ord($byte); $unknown = $unknown && $byte === 255;
            $value = ($value << 8) | $byte;
        }
        return $unknown ? null : $value;
    }

    private function element($limit) {
        if (ftell($this->fp) >= $limit) { return null; }
        if (++$this->elements > 3000000 || microtime(true) > $this->deadline) {
            throw new RuntimeException('Analyse MKV trop longue : limite de lecture atteinte.');
        }
        $id = $this->vint(true); $size = $this->vint(); $start = ftell($this->fp);
        if ($size === null && !in_array($id, [0x18538067, 0x1F43B675], true)) {
            throw new RuntimeException('Taille MKV inconnue non prise en charge.');
        }
        if ($size !== null && ($size > $limit - $start || $size < 0)) { throw new RuntimeException('Taille MKV invalide.'); }
        return ['id' => $id, 'start' => $start, 'end' => $size === null ? $limit : $start + $size, 'unknown' => $size === null];
    }

    private function data($e, $max = 1048576) {
        $length = $e['end'] - $e['start'];
        if ($length > $max) { throw new RuntimeException('Métadonnée MKV trop volumineuse.'); }
        $data = $length ? fread($this->fp, $length) : '';
        if (strlen($data) !== $length) { throw new RuntimeException('Métadonnée MKV tronquée.'); }
        return $data;
    }

    private function uint($e) {
        $data = $this->data($e, 8); $n = 0;
        foreach (unpack('C*', $data) ?: [] as $byte) { $n = ($n << 8) | $byte; }
        if ($n < 0) { throw new RuntimeException('Valeur MKV hors limites.'); }
        return $n;
    }

    private function segment($end) {
        while ($e = $this->element($end)) {
            switch ($e['id']) {
                case 0x1549A966:
                    while ($child = $this->element($e['end'])) {
                        if ($child['id'] === 0x2AD7B1) { $this->scale = $this->uint($child); }
                        fseek($this->fp, $child['end']);
                    }
                    break;
                case 0x1654AE6B: $this->tracks($e['end']); break;
                case 0x1941A469: $this->attachments($e['end']); break;
                case 0x1043A770: $this->chapters($e['end']); break;
                case 0x1F43B675: $this->cluster($e); continue 2;
            }
            fseek($this->fp, $e['end']);
        }
    }

    private function chapters($end) {
        while ($entry = $this->element($end)) {
            if ($entry['id'] === 0x45B9) {
                if (count($this->editions) >= 32) { throw new RuntimeException('Trop d’éditions de chapitres MKV.'); }
                $edition = ['default' => false, 'ordered' => false, 'chapters' => []];
                while ($e = $this->element($entry['end'])) {
                    if ($e['id'] === 0x45DB) { $edition['default'] = (bool) $this->uint($e); }
                    elseif ($e['id'] === 0x45DD) { $edition['ordered'] = (bool) $this->uint($e); }
                    elseif ($e['id'] === 0xB6) { $edition['chapters'] = array_merge($edition['chapters'], $this->chapterAtom($e['end'], 0)); }
                    fseek($this->fp, $e['end']);
                }
                $this->editions[] = $edition;
            }
            fseek($this->fp, $entry['end']);
        }
    }

    private function chapterAtom($end, $depth) {
        if ($depth > 8 || ++$this->chapterCount > 2048) { throw new RuntimeException('Chapitres MKV trop complexes.'); }
        $start = null; $hidden = false; $enabled = true; $linked = false; $labels = []; $children = [];
        while ($e = $this->element($end)) {
            if ($e['id'] === 0x91) { $start = $this->uint($e) / 1000000000; }
            elseif ($e['id'] === 0x98) { $hidden = (bool) $this->uint($e); }
            elseif ($e['id'] === 0x4598) { $enabled = (bool) $this->uint($e); }
            elseif ($e['id'] === 0x6E67) { $linked = true; }
            elseif ($e['id'] === 0xB6) { $children = array_merge($children, $this->chapterAtom($e['end'], $depth + 1)); }
            elseif ($e['id'] === 0x80) {
                $label = ''; $language = 'eng';
                while ($field = $this->element($e['end'])) {
                    if ($field['id'] === 0x85) { $label = trim($this->data($field, 4096)); }
                    elseif (in_array($field['id'], [0x437C, 0x437D], true)) { $language = strtolower($this->data($field, 64)); }
                    fseek($this->fp, $field['end']);
                }
                if ($label !== '' && count($labels) < 32) { $labels[] = ['title' => $label, 'language' => $language]; }
            }
            fseek($this->fp, $e['end']);
        }
        if (!$enabled || $linked) { return []; }
        $own = [];
        if (!$hidden && $start !== null) {
            $title = $labels[0]['title'] ?? '';
            foreach ($labels as $label) {
                if (preg_match('/^(fra|fre|fr)(-|$)/', $label['language'])) { $title = $label['title']; break; }
            }
            $own[] = ['start' => $start, 'title' => $title];
        }
        // A hidden parent can still contain visible children.
        return array_merge($own, $children);
    }

    private function chapterList() {
        if (!$this->editions) { return []; }
        $edition = $this->editions[0];
        foreach ($this->editions as $candidate) { if ($candidate['default']) { $edition = $candidate; break; } }
        // Ordered editions describe another timeline, sometimes across files.
        // Do not present their timestamps as markers in this native video.
        if ($edition['ordered']) { return []; }
        $chapters = $edition['chapters'];
        usort($chapters, function ($a, $b) { return $a['start'] <=> $b['start']; });
        $result = [];
        foreach ($chapters as $chapter) {
            $index = count($result) - 1;
            if ($index >= 0 && $result[$index]['start'] === $chapter['start']) { continue; }
            if ($chapter['title'] === '') { $chapter['title'] = 'Chapitre ' . (count($result) + 1); }
            $result[] = $chapter;
        }
        return $result;
    }

    private function tracks($end) {
        while ($entry = $this->element($end)) {
            if ($entry['id'] === 0xAE) {
                $track = [];
                while ($e = $this->element($entry['end'])) {
                    $fields = [0x86 => 'codec', 0x536E => 'name', 0x22B59C => 'language', 0x22B59D => 'language', 0x63A2 => 'header'];
                    if ($e['id'] === 0xD7) { $track['number'] = $this->uint($e); }
                    elseif ($e['id'] === 0x6D80) { $track['encoded'] = true; }
                    elseif ($e['id'] === 0x23314F) {
                        $value = $this->data($e, 8);
                        $track['timeScale'] = strlen($value) === 4 ? unpack('G', $value)[1] : (strlen($value) === 8 ? unpack('E', $value)[1] : 0);
                    } elseif (isset($fields[$e['id']])) { $track[$fields[$e['id']]] = $this->data($e); }
                    fseek($this->fp, $e['end']);
                }
                if (isset($track['number'])) { $this->tracks[$track['number']] = $track; }
                if (count($this->tracks) > 128) { throw new RuntimeException('Trop de pistes MKV.'); }
            }
            fseek($this->fp, $entry['end']);
        }
    }

    private function attachments($end) {
        while ($entry = $this->element($end)) {
            if ($entry['id'] === 0x61A7) {
                $name = ''; $payload = null;
                while ($e = $this->element($entry['end'])) {
                    if ($e['id'] === 0x466E) { $name = $this->data($e, 4096); }
                    elseif ($e['id'] === 0x465C) { $payload = $e; }
                    fseek($this->fp, $e['end']);
                }
                if ($payload && preg_match('/\.(?:ttf|otf|woff2?)$/i', $name)) {
                    $length = $payload['end'] - $payload['start'];
                    if ($length <= 4194304 && count($this->fonts) < 32 && $this->fontBytes + $length <= 16777216) {
                        fseek($this->fp, $payload['start']);
                        $this->fonts[] = base64_encode($this->data($payload, 4194304));
                        $this->fontBytes += $length;
                    } else { $this->warnings['fonts'] = 'Certaines polices dépassent les limites de taille et seront remplacées.'; }
                }
            }
            fseek($this->fp, $entry['end']);
        }
    }

    private function cluster($cluster) {
        $time = 0;
        while (ftell($this->fp) < $cluster['end']) {
            $position = ftell($this->fp); $e = $this->element($cluster['end']);
            if (!$e) { break; }
            if ($cluster['unknown'] && in_array($e['id'], [0x1F43B675, 0x1549A966, 0x1654AE6B, 0x1941A469, 0x1C53BB6B, 0x1254C367, 0x1043A770], true)) {
                fseek($this->fp, $position); return;
            }
            if ($e['id'] === 0xE7) { $time = $this->uint($e); }
            elseif ($e['id'] === 0xA0) {
                $block = null; $duration = null;
                while ($child = $this->element($e['end'])) {
                    if ($child['id'] === 0xA1) { $block = $child; }
                    elseif ($child['id'] === 0x9B) { $duration = $this->uint($child); }
                    fseek($this->fp, $child['end']);
                }
                if ($block && $duration !== null) { $this->block($block, $time, $duration); }
            }
            fseek($this->fp, $e['end']);
        }
    }

    private function block($e, $clusterTime, $duration) {
        fseek($this->fp, $e['start']); $track = $this->vint();
        if (!in_array($this->tracks[$track]['codec'] ?? '', ['S_TEXT/ASS', 'S_TEXT/SSA'], true)
            || !empty($this->tracks[$track]['encoded'])) { return; }
        $head = fread($this->fp, 3);
        if (strlen($head) !== 3 || (ord($head[2]) & 6)) { return; }
        $relative = unpack('n', substr($head, 0, 2))[1];
        if ($relative >= 32768) { $relative -= 65536; }
        $remaining = $e['end'] - ftell($this->fp);
        if ($remaining < 0 || $remaining > 1048576 || ($this->bytes += $remaining) > 16777216) {
            throw new RuntimeException('Les sous-titres dépassent la limite de 16 Mo.');
        }
        $payload = $remaining ? fread($this->fp, $remaining) : '';
        $parts = explode(',', $payload, 9);
        if (count($parts) !== 9) { return; }
        $factor = $this->scale / 1000000000;
        $start = max(0, ($clusterTime + $relative) * $factor);
        $stop = max(0, ($clusterTime + $relative + $duration) * $factor);
        $dialogue = 'Dialogue: ' . $parts[1] . ',' . self::timestamp($start) . ',' . self::timestamp($stop) . ',' . implode(',', array_slice($parts, 2));
        // Matroska uses layer; legacy SSA scripts label the equivalent field Marked.
        $this->events[$track][] = [(int) $parts[0], $dialogue];
        if (count($this->events[$track]) > 100000) { throw new RuntimeException('Trop de lignes de sous-titres.'); }
    }

    private static function timestamp($seconds) {
        $centiseconds = (int) round($seconds * 100);
        return sprintf('%d:%02d:%02d.%02d', intdiv($centiseconds, 360000), intdiv($centiseconds, 6000) % 60,
            intdiv($centiseconds, 100) % 60, $centiseconds % 100);
    }
}
