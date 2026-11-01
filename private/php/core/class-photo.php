<?php

class Photo {
    public static function validReference($photo) {
        if (!is_array($photo) || !is_string($photo['file'] ?? null)
            || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $photo['file'])) { return false; }
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        return ($photo['mime'] ?? '') === $types[pathinfo($photo['file'], PATHINFO_EXTENSION)];
    }

    public static function upload($upload) {
        if ($upload === null || (is_array($upload) && ($upload['error'] ?? null) === UPLOAD_ERR_NO_FILE)) { return null; }
        if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])) { throw new InvalidArgumentException('Téléversement de la photo impossible.'); }
        $size = filesize($upload['tmp_name']);
        if ($size === false || $size === 0 || $size > 524288) { throw new InvalidArgumentException('La photo doit peser au maximum 512 Ko.'); }
        $image = @getimagesize($upload['tmp_name']);
        $types = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
        if (!$image || !isset($types[$image[2]]) || $image[0] < 1 || $image[1] < 1 || $image[0] > 2048 || $image[1] > 2048) {
            throw new InvalidArgumentException('Choisissez une image JPEG, PNG ou WebP de 2048 × 2048 pixels maximum.');
        }
        $type = $types[$image[2]];
        $directory = Users::directory() . '/avatars';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Stockage des photos indisponible.');
        }
        $file = bin2hex(random_bytes(16)) . '.' . $type[0];
        if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $file)) { throw new RuntimeException('Enregistrement de la photo impossible.'); }
        chmod($directory . '/' . $file, 0600);
        return ['file' => $file, 'mime' => $type[1]];
    }

    public static function remove($photo) {
        if (self::validReference($photo)) { @unlink(Users::directory() . '/avatars/' . $photo['file']); }
    }

    public static function send($photo) {
        if (!self::validReference($photo)) { Auth::stop(404, 'Photo introuvable.'); }
        $path = Users::directory() . '/avatars/' . $photo['file'];
        if (!is_file($path) || is_link($path)) { Auth::stop(404, 'Photo introuvable.'); }
        header('Content-Type: ' . $photo['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Security-Policy: sandbox; default-src \'none\'');
        header('Content-Disposition: inline; filename="profile.' . pathinfo($photo['file'], PATHINFO_EXTENSION) . '"');
        session_write_close();
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { readfile($path); }
        exit;
    }
}
