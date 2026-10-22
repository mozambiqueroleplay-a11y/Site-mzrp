
<?php

/*
 * Mozambique Roleplay - JBL Music API
 *
 * Resposta por linha:
 * titulo TAB canal TAB duracao TAB video_id
 *
 * Exemplo:
 * Musica Exemplo    Artista    03:45    dQw4w9WgXcQ
 *
 * Configurar na Vercel:
 * YOUTUBE_API_KEY = tua chave do YouTube Data API v3
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_QUERY_LENGTH = 200;
const MAX_RESULTS = 20;
const REQUEST_TIMEOUT = 12;

/**
 * Termina a resposta com um erro legível.
 */
function apiError(int $status, string $message): never
{
    http_response_code($status);
    echo $message;
    exit;
}

/**
 * Limpa campos para manter uma linha por música
 * e evitar separadores que quebrariam o parser Pawn.
 */
function cleanField(mixed $value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }

    $value = strip_tags((string) $value);

    // Remove caracteres de controlo, tabs e quebras de linha.
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';

    // Normaliza espaços repetidos.
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return trim($value);
}

/**
 * Converte a duração ISO 8601 do YouTube para MM:SS
 * ou H:MM:SS para músicas com uma hora ou mais.
 */
function formatDuration(string $iso): string
{
    try {
        $duration = new DateInterval($iso);

        $seconds =
            ($duration->days !== false ? $duration->days * 86400 : 0) +
            ($duration->h * 3600) +
            ($duration->i * 60) +
            $duration->s;

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
 * Faz um pedido à API oficial do YouTube.
 * Devolve o JSON ou null se houver falha.
 */
function youtubeRequest(string $endpoint, array $params, string $apiKey): ?array
{
    $params['key'] = $apiKey;

    $url = 'https://www.googleapis.com/youtube/v3/'
        . $endpoint
        . '?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => REQUEST_TIMEOUT,
            'ignore_errors' => true,
            'header' =>
                "Accept: application/json\r\n" .
                "User-Agent: MozambiqueRoleplay-JBL/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('JBL API: falha de ligação ao YouTube.');
        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        error_log('JBL API: resposta JSON inválida.');
        return null;
    }

    if (isset($json['error'])) {
        $reason = $json['error']['errors'][0]['reason'] ?? 'unknown';
        error_log('JBL YouTube API error: ' . cleanField($reason));
        return null;
    }

    return $json;
}

/*
 * Apenas aceitar pedidos GET.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    apiError(405, 'ERRO: Metodo nao permitido.');
}

/*
 * Ler a chave secreta das variáveis de ambiente da Vercel.
 */
$apiKey = trim((string) (getenv('YOUTUBE_API_KEY') ?: ''));

if ($apiKey === '') {
    apiError(500, 'ERRO: Chave da API nao configurada.');
}

/*
 * Validar a pesquisa.
 */
$q = trim((string) ($_GET['q'] ?? ''));

if ($q === '') {
    apiError(400, 'ERRO: Escreve o nome da musica.');
}

if (strlen($q) > MAX_QUERY_LENGTH) {
    apiError(400, 'ERRO: Pesquisa demasiado longa.');
}

if (preg_match('/[\x00-\x1F\x7F]/', $q)) {
    apiError(400, 'ERRO: Pesquisa invalida.');
}

/*
 * 1. Pesquisar vídeos públicos no YouTube.
 */
$search = youtubeRequest('search', [
    'part' => 'snippet',
    'type' => 'video',
    'maxResults' => MAX_RESULTS,
    'q' => $q,
    'safeSearch' => 'none',
], $apiKey);

if ($search === null) {
    apiError(502, 'ERRO_API: Falha na pesquisa do YouTube.');
}

$videos = [];

foreach (($search['items'] ?? []) as $item) {
    $id = $item['id']['videoId'] ?? '';

    // IDs de vídeos do YouTube têm normalmente 11 caracteres.
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        continue;
    }

    $title = cleanField($item['snippet']['title'] ?? '');
    $artist = cleanField($item['snippet']['channelTitle'] ?? '');

    if ($title === '') {
        continue;
    }

    $videos[$id] = [
        'id' => $id,
        'title' => $title,
        'artist' => $artist,
    ];
}

if (!$videos) {
    apiError(200, 'SEM_RESULTADOS');
}

/*
 * 2. Consultar as durações em lote.
 */
$ids = array_keys($videos);

$details = youtubeRequest('videos', [
    'part' => 'contentDetails',
    'id' => implode(',', $ids),
], $apiKey);

if ($details === null) {
    apiError(502, 'ERRO_API: Falha ao obter as duracoes.');
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
        $durations[$id] = formatDuration($iso);
    }
}

/*
 * 3. Enviar resultados em formato simples para o Pawn.
 *
 * Não enviar cabeçalho de colunas: cada linha é uma música.
 * O ID permite identificar o vídeo, mas não é um URL de áudio.
 */
$count = 0;

foreach ($videos as $video) {
    if (!isset($durations[$video['id']])) {
        continue;
    }

    $line = $video['title'] . "\t"
        . $video['artist'] . "\t"
        . $durations[$video['id']] . "\t"
        . $video['id'];

    echo $line . "\n";
    $count++;
}

if ($count === 0) {
    apiError(200, 'SEM_RESULTADOS');
}
