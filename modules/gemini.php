<?php
/**
 * Integrasi Google Gemini AI untuk pembuatan soal
 * (padanan modul 8.B pada Code.gs).
 */
declare(strict_types=1);

/** Permintaan HTTP JSON sederhana memakai cURL. */
function http_json(string $url, ?array $payload, string $method = 'GET', int $timeout = 60): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['code' => 0, 'body' => '', 'error' => $curlErr !== '' ? $curlErr : 'Permintaan HTTP gagal.'];
    }

    return ['code' => $code, 'body' => (string) $body, 'error' => ''];
}

/** Ambil pesan error dari respons JSON Gemini. */
function gemini_error_message(string $body, int $code): string
{
    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['error']['message'])) {
        return (string) $decoded['error']['message'];
    }
    return $body !== '' ? $body : ('HTTP ' . $code);
}

/** Daftar model Gemini yang mendukung generateContent. */
function getGeminiModels($apiKey = ''): array
{
    try {
        $key = trim(tostr($apiKey));
        if ($key === '') {
            $settings = settings_map();
            $key = trim(tostr($settings['gemini_api_key'] ?? ''));
        }
        if ($key === '') {
            return err('API Key belum diisi. Masukkan API Key terlebih dahulu.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key);
        $response = http_json($url, null, 'GET', 30);

        if ($response['code'] !== 200) {
            return err('Gagal mengambil model (' . $response['code'] . '): ' . gemini_error_message($response['body'], $response['code']));
        }

        $data = json_decode($response['body'], true);
        $rawModels = is_array($data['models'] ?? null) ? $data['models'] : [];
        $validModels = [];

        foreach ($rawModels as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            $name = strtolower((string) ($m['name'] ?? ''));
            $isGenerateContent = in_array('generateContent', $methods, true);
            $isGemini = str_contains($name, 'gemini');
            $isExcluded = str_contains($name, 'embedding')
                || str_contains($name, 'imagen')
                || str_contains($name, 'aqa')
                || str_contains($name, 'robotics');

            if ($isGenerateContent && $isGemini && !$isExcluded) {
                $cleanId = preg_replace('#^models/#', '', (string) $m['name']);
                $validModels[] = [
                    'id'          => $cleanId,
                    'displayName' => tostr($m['displayName'] ?? '') !== '' ? tostr($m['displayName']) : $cleanId,
                    'description' => tostr($m['description'] ?? ''),
                ];
            }
        }

        if (count($validModels) === 0) {
            $validModels = [
                ['id' => 'gemini-2.0-flash', 'displayName' => 'Gemini 2.0 Flash'],
                ['id' => 'gemini-2.0-flash-lite', 'displayName' => 'Gemini 2.0 Flash Lite'],
                ['id' => 'gemini-1.5-flash', 'displayName' => 'Gemini 1.5 Flash'],
                ['id' => 'gemini-1.5-pro', 'displayName' => 'Gemini 1.5 Pro'],
            ];
        }

        return ['success' => true, 'models' => $validModels];
    } catch (Throwable $e) {
        return err('Kesalahan saat memuat model: ' . $e->getMessage());
    }
}

/** Uji koneksi Gemini dengan permintaan minimal. */
function testGeminiConnection($apiKey = '', $modelId = ''): array
{
    try {
        $key = trim(tostr($apiKey));
        if ($key === '') {
            $settings = settings_map();
            $key = trim(tostr($settings['gemini_api_key'] ?? ''));
        }
        if ($key === '') {
            return err('API Key belum diisi. Masukkan API Key terlebih dahulu.');
        }

        $model = preg_replace('#^models/#', '', trim(tostr($modelId)));
        if ($model === '') {
            $settings = settings_map();
            $model = trim(tostr($settings['gemini_model'] ?? '')) ?: 'gemini-2.0-flash';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model)
            . ':generateContent?key=' . urlencode($key);
        $payload = [
            'contents' => [
                ['parts' => [['text' => 'Halo! Jawab dengan satu kata: Terhubung.']]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 10,
                'temperature'     => 0.1,
            ],
        ];

        $startTime = microtime(true);
        $response = http_json($url, $payload, 'POST', 30);
        $latency = (int) round((microtime(true) - $startTime) * 1000);

        if ($response['code'] === 200) {
            return [
                'success' => true,
                'latency' => $latency,
                'model'   => $model,
                'message' => 'Koneksi ke Gemini AI berhasil! Waktu respons: ' . $latency . ' ms',
            ];
        }

        return [
            'success' => false,
            'latency' => $latency,
            'message' => 'Koneksi gagal (HTTP ' . $response['code'] . '): ' . gemini_error_message($response['body'], $response['code']),
        ];
    } catch (Throwable $e) {
        return err('Terjadi kesalahan sistem saat uji koneksi: ' . $e->getMessage());
    }
}

/**
 * Generate butir soal pilihan ganda dengan Gemini.
 *
 * @param array $params mata_pelajaran, kelas, topik, jumlah_soal, jumlah_opsi,
 *                      tingkat_kesulitan, dan opsional `model` (pilihan model
 *                      dari form Generate Soal AI; disimpan sebagai model aktif).
 */
function generateQuestionsWithGemini($username, $token, $params = []): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid. Silakan login kembali.');
    }

    try {
        $settings = settings_map();
        $apiKey = trim(tostr($settings['gemini_api_key'] ?? ''));
        $modelId = preg_replace('#^models/#', '', trim(tostr($settings['gemini_model'] ?? '')) ?: 'gemini-2.0-flash');

        // Pilihan model kini berada pada form Generate Soal AI (dipindahkan dari
        // halaman Pengaturan). Bila dikirim, model dipakai untuk request ini dan
        // disimpan sebagai model aktif agar tetap terpilih pada kunjungan berikutnya.
        $params = is_array($params) ? $params : [];
        $modelParam = preg_replace('#^models/#', '', trim(tostr($params['model'] ?? '')));
        if ($modelParam !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $modelParam) && $modelParam !== $modelId) {
            $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE kunci = ?', ['gemini_model'], 0);
            if ($exists > 0) {
                db_exec('UPDATE settings SET nilai = ? WHERE kunci = ?', [$modelParam, 'gemini_model']);
            } else {
                $desc = settings_descriptions()['gemini_model'] ?? 'Model Gemini yang digunakan untuk generate konten';
                db_exec('INSERT INTO settings (kunci, nilai, keterangan) VALUES (?,?,?)', ['gemini_model', $modelParam, $desc]);
            }
            log_activity(tostr($username), 'Update Settings', 'Model Gemini generate soal diubah ke "' . $modelParam . '".');
        }
        if ($modelParam !== '') {
            $modelId = $modelParam;
        }

        if ($apiKey === '') {
            return err('API Key Gemini belum disetting. Silakan minta Administrator untuk mengatur API Key di menu Pengaturan Aplikasi.');
        }

        $mapel = tostr($params['mata_pelajaran'] ?? '') !== '' ? tostr($params['mata_pelajaran']) : 'Umum';
        $kelas = tostr($params['kelas'] ?? '') !== '' ? tostr($params['kelas']) : 'Umum';
        $topik = tostr($params['topik'] ?? '') !== '' ? tostr($params['topik']) : 'Umum';

        $jmlOpsi = (int) ($params['jumlah_opsi'] ?? 0);
        if ($jmlOpsi <= 0) {
            $jmlOpsi = 5;
        }
        $jmlOpsi = max(3, min(10, $jmlOpsi));

        // Tanpa batas atas jumlah soal. Permintaan besar dipecah menjadi
        // beberapa panggilan kecil oleh klien (AI_GEN_CHUNK_SIZE di Index.html)
        // agar aman dari batas output token model.
        $jmlSoal = (int) ($params['jumlah_soal'] ?? 0);
        if ($jmlSoal <= 0) {
            $jmlSoal = 5;
        }

        $kesulitan = tostr($params['tingkat_kesulitan'] ?? '') !== '' ? tostr($params['tingkat_kesulitan']) : 'Sedang';

        // Opsi "Tambahkan pembahasan" pada form Generate Soal AI: bila aktif,
        // setiap butir soal wajib menyertakan field pembahasan.
        $withPembahasan = in_array(
            strtolower(trim(tostr($params['with_pembahasan'] ?? ''))),
            ['1', 'true', 'ya', 'yes', 'on'],
            true
        );

        $alphabet = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $activeLetters = array_slice($alphabet, 0, $jmlOpsi);
        $opsiStr = implode(', ', $activeLetters);
        $lastLetter = $activeLetters[count($activeLetters) - 1];

        $prompt = "Anda adalah guru ahli dan pembuat soal evaluasi profesional di Indonesia.\n"
            . "Tugas Anda adalah membuat $jmlSoal butir soal pilihan ganda berkualitas tinggi dengan ketentuan berikut:\n"
            . "- Mata Pelajaran: $mapel\n"
            . "- Tingkat / Kelas: $kelas\n"
            . "- Topik / Materi: $topik\n"
            . "- Tingkat Kesulitan: $kesulitan\n"
            . "- Jumlah Pilihan Opsi per Soal: $jmlOpsi opsi ($opsiStr)\n\n"
            . "Petunjuk Pembuatan Soal:\n"
            . "1. Soal harus jelas, tidak berbelit-belit, dan menguji pemahaman konsep atau penalaran sesuai tingkat kesulitan $kesulitan.\n"
            . "2. Setiap butir soal HARUS memiliki tepat $jmlOpsi pilihan jawaban (opsi_a sampai opsi_" . strtolower($lastLetter) . ").\n"
            . "3. Pilihan pengecoh (distractor) harus masuk akal, logis, dan mendidik.\n"
            . "4. Kunci jawaban HARUS tepat salah satu dari huruf: $opsiStr.\n";

        if ($withPembahasan) {
            $prompt .= "5. Setiap butir soal WAJIB menyertakan field \"pembahasan\": 2-4 kalimat bahasa Indonesia yang "
                . "menjelaskan MENGAPA kunci jawaban benar dan mengapa opsi pengecoh salah "
                . "(jangan menyalin ulang seluruh teks soal).\n"
                . "6. Output WAJIB HANYA berupa JSON Array murni tanpa tanda kutip markdown (```json) dan tanpa teks pembuka/penutup.\n\n";
        } else {
            $prompt .= "5. Output WAJIB HANYA berupa JSON Array murni tanpa tanda kutip markdown (```json) dan tanpa teks pembuka/penutup.\n\n";
        }

        $prompt .= "Struktur JSON yang WAJIB dipatuhi:\n[\n  {\n    \"teks_soal\": \"Pertanyaan soal...\",\n";

        for ($i = 0; $i < $jmlOpsi; $i++) {
            $prompt .= '    "opsi_' . strtolower($alphabet[$i]) . '": "Pilihan teks ' . $alphabet[$i] . "...\",\n";
        }
        $prompt .= "    \"kunci_jawaban\": \"A\""
            . ($withPembahasan
                ? ",\n    \"pembahasan\": \"Penjelasan alasan kunci jawaban benar dan mengapa opsi lain salah.\""
                : '')
            . "\n  }\n]";

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($modelId)
            . ':generateContent?key=' . urlencode($apiKey);
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature'      => 0.7,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = http_json($url, $payload, 'POST', 120);

        if ($response['code'] !== 200) {
            return err('Gemini API Error (' . $response['code'] . '): ' . gemini_error_message($response['body'], $response['code']));
        }

        $respData = json_decode($response['body'], true);
        if (!is_array($respData)
            || !isset($respData['candidates'][0]['content']['parts'][0]['text'])) {
            return err('Respon dari Gemini tidak memiliki konten jawaban yang valid.');
        }

        $rawAnswer = (string) $respData['candidates'][0]['content']['parts'][0]['text'];
        $cleanedJson = preg_replace('#^```json\s*#i', '', $rawAnswer);
        $cleanedJson = preg_replace('#^```\s*#i', '', $cleanedJson);
        $cleanedJson = preg_replace('#\s*```$#i', '', $cleanedJson);
        $cleanedJson = trim((string) $cleanedJson);

        $parsedQuestions = json_decode($cleanedJson, true);
        if ($parsedQuestions === null) {
            return [
                'success' => false,
                'message' => 'Gagal mem-parsing format JSON hasil Gemini: ' . json_last_error_msg(),
                'raw'     => $rawAnswer,
            ];
        }

        if (!is_array($parsedQuestions) || !array_is_list($parsedQuestions)) {
            if (is_array($parsedQuestions) && isset($parsedQuestions['questions']) && is_array($parsedQuestions['questions'])) {
                $parsedQuestions = $parsedQuestions['questions'];
            } elseif (is_array($parsedQuestions) && isset($parsedQuestions['soal']) && is_array($parsedQuestions['soal'])) {
                $parsedQuestions = $parsedQuestions['soal'];
            } else {
                return err('Format keluaran AI bukan berupa daftar (array) butir soal.');
            }
        }

        $sanitizedQuestions = [];
        foreach ($parsedQuestions as $q) {
            if (!is_array($q) || tostr($q['teks_soal'] ?? '') === '') {
                continue;
            }
            $cleanQ = [
                'teks_soal'     => tostr($q['teks_soal']),
                'url_gambar'    => tostr($q['url_gambar'] ?? ''),
                'kunci_jawaban' => strtoupper(tostr($q['kunci_jawaban'] ?? 'A') ?: 'A'),
                'bobot'         => 1,
            ];
            // Pembahasan hanya diambil bila opsi "Tambahkan pembahasan" diminta.
            if ($withPembahasan) {
                $cleanQ['pembahasan'] = tostr($q['pembahasan'] ?? ($q['penjelasan'] ?? ''));
            }
            for ($oIdx = 0; $oIdx < $jmlOpsi; $oIdx++) {
                $letter = strtolower($alphabet[$oIdx]);
                $oKey = 'opsi_' . $letter;
                $cleanQ[$oKey] = tostr($q[$oKey] ?? ($q['opsi' . strtoupper($letter)] ?? ''));
            }
            $sanitizedQuestions[] = $cleanQ;
        }

        if (count($sanitizedQuestions) === 0) {
            return err('Tidak ada butir soal yang berhasil diproses dari hasil AI.');
        }

        return [
            'success'   => true,
            'questions' => $sanitizedQuestions,
            'modelUsed' => $modelId,
            'count'     => count($sanitizedQuestions),
        ];
    } catch (Throwable $ex) {
        return err('Terjadi kesalahan sistem saat generate soal: ' . $ex->getMessage());
    }
}
