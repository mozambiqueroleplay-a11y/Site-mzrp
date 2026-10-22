<?php
$host = "localhost";
$usuario = "root";
$senha_db = "SUA_SENHA_AQUI"; // Coloque a senha que você definiu no instalador
$banco = "mozambique_db";

$conn = new mysqli($host, $usuario, $senha_db, $banco);

if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}

// Recebe os dados do formulário do site
$id_passaporte = $_POST['IDF'];

// Procura o jogador na tabela 'contas' que criamos no Workbench
$sql = "SELECT * FROM contas WHERE IDF = '$id_passaporte";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    // Retorna os dados para o site em formato JSON
    echo json_encode([
        "success" => true,
        "nome" => $row['nome'],
        "dinheiro" => $row['dinheiro'],
        "nivel" => $row['nivel']
    ]);
} else {
    echo json_encode(["success" => false]);
}

$conn->close();
?>