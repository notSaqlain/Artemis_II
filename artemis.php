<?php

require_once __DIR__ . '/vendor/autoload.php';


$client     = new MongoDB\Client("mongodb://mongo:27017");
$database   = $client->selectDatabase("artemis_db");
$collection = $database->selectCollection("artemis_crew");

// Directory per salvre le foto
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$method   = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
header('Content-Type: application/json; charset=UTF-8');

// post per inserire nuovi utenti
if ($method === 'POST' && $pathInfo === '') {

    $body = json_decode(file_get_contents('php://input'), true);

    if (
        empty($body['name']) ||
        empty($body['role']) ||
        empty($body['agency'])
    ) {
        http_response_code(400);
        echo json_encode([
            "error" => "Campi obbligatori mancanti: name, role, agency"
        ]);
        exit;
    }

    $document = [
        'name'   => $body['name'],
        'role'   => $body['role'],
        'agency' => $body['agency'],
        'status' => 'In adestramento'
    ];

    // salva nel db
    $result = $collection->insertOne($document);

    http_response_code(201);
    echo json_encode([
        "message" => "Membro dell'equipaggio inserito con successo",
        "_id"     => (string) $result->getInsertedId()
    ]);
    exit;
}

//  2. GET x stampare
if ($method === 'GET' && $pathInfo === '') {

    $cursor = $collection->find();
    $crew   = [];

    foreach ($cursor as $member) {
        // Converte l'ObjectId in stringa per il JSON
        $memberArray        = (array) $member;
        $memberArray['_id'] = (string) $member['_id'];
        $crew[]             = $memberArray;
    }

    http_response_code(200);
    echo json_encode($crew);
    exit;
}

// ─── 3. POST x le foto
if ($method === 'POST' && preg_match('#^/([a-f0-9]{24})/portrait$#', $pathInfo, $matches)) {

    $astronautId = $matches[1];
    $astronaut = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($astronautId)
    ]);

    if (!$astronaut) {
        http_response_code(404);
        echo json_encode([
            "error" => "Astronauta non trovato con ID: $astronautId"
        ]);
        exit;
    }

    // verifica se ce la foto
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "error" => "Nessun file immagine valido inviato. Usa il campo 'image'."
        ]);
        exit;
    }

    $file = $_FILES['image'];

    // crea un file univoco 
    $extension    = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName   = uniqid('portrait_', true) . '.' . $extension;
    $destPath     = $uploadDir . $uniqueName;
    $relativePath = 'uploads/' . $uniqueName;


    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode([
            "error" => "Errore nel salvataggio del file sul server."
        ]);
        exit;
    }

    // Aggiorna il documento con la foto
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($astronautId)],
        ['$set' => ['image_path' => $relativePath]]
    );

    http_response_code(200);
    echo json_encode([
        "message"    => "Ritratto caricato con successo",
        "image_path" => $relativePath
    ]);
    exit;
}

// DELETE
if ($method === 'DELETE' && preg_match('#^/([a-f0-9]{24})$#', $pathInfo, $matches)) {

    $astronautId = $matches[1];

    $astronaut = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($astronautId)
    ]);

    if (!$astronaut) {
        http_response_code(404);
        echo json_encode([
            "error" => "Astronauta non trovato con ID: $astronautId"
        ]);
        exit;
    }

    if (isset($astronaut['image_path'])) {
        $filePath = __DIR__ . '/' . $astronaut['image_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }


    $collection->deleteOne([
        '_id' => new MongoDB\BSON\ObjectId($astronautId)
    ]);

    http_response_code(200);
    echo json_encode([
        "message" => "Membro dell'equipaggio eliminato con successo"
    ]);
    exit;
}

// se nn trova nnt
http_response_code(405);
echo json_encode([
    "error" => "Metodo o rotta non supportata"
]);
