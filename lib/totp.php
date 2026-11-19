<?php
/**
 * TOTP (RFC 6238) untuk 2FA opsional Admin/Guru — Bagian 3.3.
 *
 * Kompatibel dengan Google Authenticator / Authy / Microsoft Authenticator:
 * rahasia 160-bit disimpan dalam Base32, HMAC-SHA1, 6 digit, periode 30 detik.
 * Rujukan: PHP Manual hash_hmac() (counter/key HARUS binary), RFC 6238.
 */
declare(strict_types=1);

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Encode byte menjadi Base32 (RFC 4648, tanpa padding). */
function totp_base32_encode(string $bytes): string
{
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= TOTP_ALPHABET[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= TOTP_ALPHABET[($buffer << (5 - $bits)) & 31];
    }
    return $out;
}

/** Decode Base32 menjadi byte. Mengabaikan spasi, tanda hubung, dan '='. */
function totp_base32_decode(string $base32): string
{
    $clean = strtoupper(preg_replace('/[\s\-=]/', '', $base32) ?? '');
    if ($clean === '') {
        return '';
    }
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos(TOTP_ALPHABET, $clean[$i]);
        if ($pos === false) {
            return '';
        }
        $buffer = ($buffer << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** Buat rahasia Base32 baru (20 byte = 160 bit). */
function totp_new_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/** Hitung kode TOTP 6 digit untuk counter/waktu tertentu. */
function totp_at(string $secretBase32, int $timestamp, int $period = 30, int $digits = 6): string
{
    $key = totp_base32_decode($secretBase32);
    if ($key === '') {
        return '';
    }
    $counter = (int) floor($timestamp / max(1, $period));
    // counter 64-bit big-endian (binary, BUKAN string hex — lihat PHP Manual).
    $data = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $data, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary = (unpack('N', substr($hash, $offset, 4))[1] ?? 0) & 0x7FFFFFFF;
    return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Kode TOTP saat ini. */
function totp_code(string $secretBase32): string
{
    return totp_at($secretBase32, time());
}

/**
 * Verifikasi kode dengan toleransi ±1 periode (drift jam klien).
 */
function totp_verify(string $secretBase32, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', trim($code)) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $expected = totp_at($secretBase32, $now + ($i * 30));
        if ($expected !== '' && hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

/** URI otpauth:// untuk dipindai aplikasi Authenticator. */
function totp_provisioning_uri(string $secret, string $label, string $issuer = 'CBT GAS'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
