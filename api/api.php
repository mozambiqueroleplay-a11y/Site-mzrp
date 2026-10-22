
<?php

/*
 * Mozambique Roleplay - JBL Music API
 *
 * Resposta por música:
 * titulo TAB canal TAB duracao TAB video_id
 *
 * Variável obrigatória na Vercel:
 * YOUTUBE_API_KEY
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const JBL_MAX_QUERY_LENGTH = 200;
const JBL_MAX_RESULTS = 20;
const JBL_TIMEOUT = 12;

/**
 * Termina o pedido com um erro simples.
 */
function jblError(int $status, string $message): void
{
    http_response_code($status);
    echo $message;
    exit;
}

/**
 * Limpa campos para não quebrar o formato por linhas e TABs.
 */
function jblCleanField($value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }

    $value = strip_tags((string) $value);

    // Substitui tabs, quebras de linha e caracteres de controlo.
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';

    // Normaliza espaços repetidos.
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return trim($value);
}

/**
 * Converte a duração ISO 8601 para MM:SS ou H:MM:SS.
 */
function jblFormatDuration(string $iso): string
{
    try {
        $duration = new DateInterval($iso);

        $days = $duration->days === false ? 0 : $duration->days;

        $seconds = ($days * 86400)
            + ($duration->h * 3600)
            + ($duration->i * 60)
            + $duration->s;

        if ($seconds <= 0) {
            return '00:00';
        }

        if ($seconds >= 3600) {
            return sprintf(
                '%d:%02d:%02d',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
                $seconds % 60
            );
        }

        return sprintf(
            '%02d:%02d',
            intdiv($seconds, 60),
            $seconds % 60
        );
    } catch (Throwable $e) {
        return '00:00';
    }
}

/**
 * Faz um pedido à YouTube Data API v3.
 * Nunca devolve nem imprime a chave da API.
 */
function jblYouTubeRequest(
    string $endpoint,
    array $params,
    string $apiKey
): ?array {
    $params['key'] = $apiKey;

    $url = 'https://www.googleapis.com/youtube/v3/'
        . $endpoint
        . '?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => JBL_TIMEOUT,
            'ignore_errors' => true,
            'header' =>
                "Accept: application/json\r\n" .
                "User-Agent: MozambiqueRoleplay-JBL/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('JBL API: falha de ligacao ao YouTube.');
        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        error_log('JBL API: resposta JSON invalida.');
        return null;
    }

    if (isset($json['error'])) {
        // Regista apenas o motivo, sem expor a chave nem o URL.
        $reason = $json['error']['errors'][0]['reason'] ?? 'unknown';

        error_log(
            'JBL YouTube API: ' . jblCleanField($reason)
        );

        return null;
    }

    return $json;
}

/*
 * 1. Aceitar apenas pedidos GET.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    header('Allow: GET');
    jblError(405, 'ERRO: Metodo nao permitido.');
}

/*
 * 2. Ler a chave secreta da variavel de ambiente.
 */
$apiKey = trim((string) (getenv('YOUTUBE_API_KEY') ?: ''));

if ($apiKey === '') {
    error_log('JBL API: variavel YOUTUBE_API_KEY ausente ou vazia.');
    jblError(500, 'ERRO: Chave da API nao configurada.');
}

/*
 * 3. Validar a pesquisa.
 */
$q = trim((string) ($_GET['q'] ?? ''));

if ($q === '') {
    jblError(400, 'ERRO: Escreve o nome da musica.');
}

if (strlen($q) > JBL_MAX_QUERY_LENGTH) {
    jblError(400, 'ERRO: Pesquisa demasiado longa.');
}

if (preg_match('/[\x00-\x1F\x7F]/', $q)) {
    jblError(400, 'ERRO: Pesquisa invalida.');
}

/*
 * 4. Pesquisar ate 20 videos.
 */
$search = jblYouTubeRequest('search', [
    'part' => 'snippet',
    'type' => 'video',
    'maxResults' => JBL_MAX_RESULTS,
    'q' => $q,
], $apiKey);

if ($search === null) {
    jblError(502, 'ERRO_API: Falha na pesquisa do YouTube.');
}

$videos = [];

foreach (($search['items'] ?? []) as $item) {
    $id = $item['id']['videoId'] ?? '';

    if (
        !is_string($id) ||
        !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)
    ) {
        continue;
    }

    $title = jblCleanField(
        $item['snippet']['title'] ?? ''
    );

    $artist = jblCleanField(
        $item['snippet']['channelTitle'] ?? ''
    );

    if ($title === '') {
        continue;
    }

    $videos[$id] = [
        'id' => $id,
        'title' => $title,
        'artist' => $artist,
    ];
}

if (count($videos) === 0) {
    jblError(200, 'SEM_RESULTADOS');
}

/*
 * 5. Obter as duracoes dos videos num unico pedido.
 */
$ids = array_keys($videos);

$details = jblYouTubeRequest('videos', [
    'part' => 'contentDetails',
    'id' => implode(',', $ids),
], $apiKey);

if ($details === null) {
    jblError(502, 'ERRO_API: Falha ao obter as duracoes.');
}

$durations = [];

foreach (($details['items'] ?? []) as $item) {
    $id = $item['id'] ?? '';
    $iso = $item['contentDetails']['duration'] ?? '';

    if (
        is_string($id) &&
        isset($videos[$id]) &&
        is_string($iso)
    ) {
        $durations[$id] = jblFormatDuration($iso);
    }
}

/*
 * 6. Devolver os resultados para o Pawn.
 *
 * Formato:
 * titulo TAB canal TAB duracao TAB video_id
 *
 * O ID do video nao e um URL de audio.
 */
$count = 0;

foreach ($videos as $video) {
    $id = $video['id'];

    if (!isset($durations[$id])) {
        continue;
    }

    echo $video['title'] . "\t"
        . $video['artist'] . "\t"
        . $durations[$id] . "\t"
        . $id . "\n";

    $count++;
}

if ($count === 0) {
    jblError(200, 'SEM_RESULTADOS');
}
