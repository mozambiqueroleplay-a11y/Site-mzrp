<?php
// api_jbl.php - API de Pesquisa Dinâmica do YouTube para o Mozambique Roleplay
header('Content-Type: application/json; charset=utf-8');

$API_KEY = "AIzaSyDHeC9SSpMgSe-w1wTQIBB-tlN6V7WGExs"; // Sua chave do YouTube

if(!isset($_GET['q']) || empty($_GET['q'])){
    echo json_encode([]);
    exit;
}

$query = urlencode($_GET['q']);
$maxResults = 10; // Quantidade de resultados na busca

$youtubeAPI = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&q=$query&maxResults=$maxResults&key=$API_KEY";

$response = @file_get_contents($youtubeAPI);
if($response === FALSE){
    echo json_encode([]);
    exit;
}

$data = json_decode($response, true);
$musicas = [];

if(isset($data['items'])){
    foreach($data['items'] as $item){
        $videoId = $item['id']['videoId'];
        $titulo = html_entity_decode($item['snippet']['title'], ENT_QUOTES, 'UTF-8');
        $canal = html_entity_decode($item['snippet']['channelTitle'], ENT_QUOTES, 'UTF-8');
        
        // Link direto do YouTube (Para tocar no SA-MP, o servidor usa services de conversão ou stream compatível)
        $linkStream = "https://www.youtube.com/watch?v=" . $videoId;

        $musicas[] = [
            "titulo" => $titulo,
            "artista" => $canal,
            "duracao" => "YouTube",
            "url" => $linkStream
        ];
    }
}

echo json_encode($musicas, JSON_UNESCAPED_UNICODE);
?>