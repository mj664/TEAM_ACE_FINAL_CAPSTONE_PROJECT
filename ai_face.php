<?php
/**
 * Registration face check: compares the face on the uploaded ID with the applicant's Google profile photo,
 * using the same AI vision provider as the ID reader (ai_config.php).
 * verdict: 'match' | 'mismatch' | 'no_profile_face' | 'no_id_face' | 'unsure' | 'unavailable'
 * A clear mismatch, or a Google photo without a face, blocks the registration; 'unsure' and 'unavailable'
 * let it through for the Head Admin to review.
 */
require_once __DIR__ . '/ai_ocr.php';

// Downloads the Google profile photo (only from Google's image servers) in a larger size.
function ai_face_google_photo($url) {
    $url = (string)$url;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)googleusercontent\.com$/', $host)) return null;
    $url = preg_replace('/=s\d+(-c)?$/', '=s400-c', $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 3 * 1024 * 1024]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $status !== 200 || strlen($bytes) < 200) return null;
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    return [$bytes, $mime];
}

function ai_face_schema() {
    return [
        'type' => 'object',
        'properties' => [
            'id_has_face' => ['type' => 'boolean'],
            'profile_has_face' => ['type' => 'boolean'],
            'same_person' => ['type' => 'string', 'enum' => ['yes', 'no', 'unsure']],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['id_has_face', 'profile_has_face', 'same_person', 'reason'],
        'additionalProperties' => false,
    ];
}

// Sends two images in one request (ID first, profile photo second). Same return shape as ai_vision_json().
function ai_face_request($system, $instruction, array $a, array $b, $timeout = 60) {
    $schema = ai_face_schema();
    if (ai_provider() === 'gemini') {
        $config = ai_config();
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => 'Image 1: the ID card.'], ['inline_data' => ['mime_type' => $a[1], 'data' => base64_encode($a[0])]],
                ['text' => 'Image 2: the Google profile photo.'], ['inline_data' => ['mime_type' => $b[1], 'data' => base64_encode($b[0])]],
                ['text' => $instruction],
            ]]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json', 'responseSchema' => ai_gemini_schema($schema)],
        ];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string)$config['gemini_model']) . ':generateContent';
        [$status, $data, $error] = ai_gemini_post($url, $body, (string)$config['gemini_api_key'], $timeout);
        if ($status !== 200 || !is_array($data)) return ['ok' => false, 'code' => 'AI_UNAVAILABLE', 'detail' => $error ?: ('HTTP ' . $status)];
        $cand = $data['candidates'][0] ?? null;
        if (!$cand || ($cand['finishReason'] ?? '') !== 'STOP') return ['ok' => false, 'code' => 'AI_INCOMPLETE', 'detail' => (string)($cand['finishReason'] ?? 'no answer')];
        $text = '';
        foreach ((array)($cand['content']['parts'] ?? []) as $p) if (isset($p['text']) && empty($p['thought'])) $text .= $p['text'];
        return ['ok' => true, 'text' => $text];
    }
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $config = ai_config();
        $options = ['apiKey' => $config['api_key'], 'requestOptions' => ['timeout' => (float)$timeout, 'maxRetries' => 1]];
        if ($config['base_url'] !== '') $options['baseUrl'] = $config['base_url'];
        $client = new \Anthropic\Client(...$options);
        $img = function ($x) { return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $x[1], 'data' => base64_encode($x[0])]]; };
        $message = $client->messages->create(
            model: $config['model'], maxTokens: 1000, system: $system,
            outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            messages: [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Image 1: the ID card.'], $img($a),
                ['type' => 'text', 'text' => 'Image 2: the Google profile photo.'], $img($b),
                ['type' => 'text', 'text' => $instruction],
            ]]],
        );
        $text = '';
        foreach ($message->content as $blk) if ($blk->type === 'text') $text .= $blk->text;
        return ['ok' => true, 'text' => $text];
    } catch (\Throwable $e) {
        return ['ok' => false, 'code' => 'AI_UNAVAILABLE', 'detail' => get_class($e)];
    }
}

// Returns ['verdict' => ..., 'message' => text for the applicant, 'blocked' => bool, 'reason' => model's short reason].
function ai_face_check($id_path, $id_mime, $google_picture_url) {
    $out = function ($verdict, $message, $reason = '') {
        return ['verdict' => $verdict, 'message' => $message, 'reason' => $reason, 'blocked' => in_array($verdict, ['mismatch', 'no_profile_face'], true)];
    };
    if (!ai_ocr_configured()) return $out('unavailable', '');
    $profile = ai_face_google_photo($google_picture_url);
    if (!$profile) return $out('no_profile_face', 'Your Google account has no profile photo we can use. Set a clear photo of your face as your Google profile picture, then sign in again and register.');
    $id_bytes = @file_get_contents($id_path);
    if ($id_bytes === false) return $out('unavailable', '');

    $system = 'You verify sign-ups for a government disaster-office system. You get an ID card photo and a Google profile photo. '
        . 'Decide only whether the face printed on the ID and the face in the profile photo look like the same person. '
        . 'profile_has_face is false for logos, letters, cartoons, scenery, pets or group photos where no single person is clear. '
        . 'Answer "no" only when both faces are clearly visible and clearly belong to different people; answer "unsure" when quality, angle, age or lighting make it uncertain. '
        . 'Do not guess identity, name, age, ethnicity or any other attribute. reason: one short neutral sentence.';
    $reply = ai_face_request($system, 'Compare the two faces and return the JSON.', [$id_bytes, $id_mime], $profile);
    if (!$reply['ok']) return $out('unavailable', '');
    $r = json_decode($reply['text'], true);
    if (!is_array($r)) return $out('unavailable', '');
    $reason = mb_substr(trim((string)($r['reason'] ?? '')), 0, 200);
    if (empty($r['id_has_face'])) return $out('no_id_face', 'No face photo was found on this ID. The Head Admin will check it.', $reason);
    if (empty($r['profile_has_face'])) return $out('no_profile_face', 'Your Google profile picture does not show your face, so it cannot be matched with your ID. Set a clear photo of your face as your Google profile picture, then sign in again and register.', $reason);
    if (($r['same_person'] ?? '') === 'no') return $out('mismatch', 'The face on your ID does not match your Google account photo, so this registration cannot be submitted. Use your own ID and your own Google account.', $reason);
    if (($r['same_person'] ?? '') === 'yes') return $out('match', 'Face on your ID matches your Google account photo.', $reason);
    return $out('unsure', 'The face check was not certain; the Head Admin will compare your ID and photo.', $reason);
}
