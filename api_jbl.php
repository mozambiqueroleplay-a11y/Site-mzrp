
<?php
header('Content-Type: text/plain; charset=utf-8');

$apiKey = 'AIzaSyApyHnAOO2Sax36fdgT4Y6K4qM9oiyniL4';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '' || strlen($q) > 200) {
    http_response_code(400);
    exit("ERRO: Pesquisa vazia ou demasiado longa.");
}

if ($apiKey === 'AIzaSyApyHnAOO2Sax36fdgT4Y6K4qM9oiyniL4') {
    http_response_code(500);
    exit("ERRO: Configura a chave da API.");
}

function youtubeRequest($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return false;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        return false;
    }

    if (isset($json['error'])) {
        return false;
    }

    return $json;
}

function cleanField($value) {
    $value = strip_tags((string)$value);
    $value = str_replace(["\t", "\r", "\n"], ' ', $value);
    return trim($value);
}

function formatDuration($iso) {
    try {
        $duration = new DateInterval($iso);
        $seconds = ($duration->d * 86400)
                 + ($duration->h * 3600)
                 + ($duration->i * 60)
                 + $duration->s;

        return sprintf(
            '%02d:%02d',
            intdiv($seconds, 60),
            $seconds % 60
        );
    } catch (Exception $e) {
        return '00:00';
    }
}

$searchUrl = 'https://www.googleapis.com/youtube/v3/search?' .
    http_build_query([
        'part' => 'snippet',
        'type' => 'video',
        'maxResults' => 20,
        'q' => $q,
        'key' => $apiKey
    ]);

$search = youtubeRequest($searchUrl);

if ($search === false) {
    http_response_code(502);
    exit("ERRO_API");
}

$videos = [];

foreach (($search['items'] ?? []) as $item) {
    $id = $item['id']['videoId'] ?? '';

    if ($id === '') {
        continue;
    }

    $videos[] = [
        'id' => $id,
        'title' => cleanField(
            $item['snippet']['title'] ?? ''
        ),
        'artist' => cleanField(
            $item['snippet']['channelTitle'] ?? ''
        )
    ];
}

if (count($videos) === 0) {
    exit("SEM_RESULTADOS");
}

$ids = array_column($videos, 'id');

$detailsUrl = 'https://www.googleapis.com/youtube/v3/videos?' .
    http_build_query([
        'part' => 'contentDetails',
        'id' => implode(',', $ids),
        'key' => $apiKey
    ]);

$details = youtubeRequest($detailsUrl);

if ($details === false) {
    http_response_code(502);
    exit("ERRO_API");
}

$durations = [];

foreach (($details['items'] ?? []) as $item) {
    $id = $item['id'] ?? '';
    $iso = $item['contentDetails']['duration'] ?? '';

    if ($id !== '' && $iso !== '') {
        $durations[$id] = formatDuration($iso);
    }
}

foreach ($videos as $video) {
    $id = $video['id'];

    // Formato: título TAB canal TAB duração TAB ID do vídeo
    $line = $video['title'] . "\t"
          . $video['artist'] . "\t"
          . ($durations[$id] ?? '00:00') . "\t"
          . $id;

    echo $line . "\n";
}
?>
