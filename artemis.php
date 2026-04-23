<?php

/**
 * Artemis II - API RESTful per la gestione dell'equipaggio
 * 
 * Router principale che gestisce tutti gli endpoint:
 *   POST   /artemis.php                        → Inserimento membro equipaggio
 *   GET    /artemis.php                         → Lettura di tutti i membri
 *   POST   /artemis.php/<ID>/portrait           → Caricamento ritratto ufficiale
 *   DELETE /artemis.php/<ID>                    → Rimozione membro equipaggio
 */

require_once __DIR__ . '/vendor/autoload.php';

// ─── Configurazione ──────────────────────────────────────────────────────────

// Connessione a MongoDB (il servizio "mongo" è definito nel docker-compose)
$client     = new MongoDB\Client("mongodb://mongo:27017");
$database   = $client->selectDatabase("artemis_db");
$collection = $database->selectCollection("artemis_crew");

// Directory per il salvataggio delle immagini
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ─── Routing ─────────────────────────────────────────────────────────────────

// Metodo HTTP e PATH_INFO per determinare l'azione
$method   = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// Impostazione header di risposta JSON
header('Content-Type: application/json; charset=UTF-8');

// ─── 1. POST /artemis.php  →  Inserimento nuovo membro ──────────────────────
if ($method === 'POST' && $pathInfo === '') {

    // Legge il body JSON della richiesta
    $body = json_decode(file_get_contents('php://input'), true);

    // Validazione dei campi obbligatori
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

    // Preparazione del documento con stato di default
    $document = [
        'name'   => $body['name'],
        'role'   => $body['role'],
        'agency' => $body['agency'],
        'status' => 'In addestramento'   // stato di default aggiunto automaticamente
    ];

    // Inserimento nel database
    $result = $collection->insertOne($document);

    http_response_code(201);
    echo json_encode([
        "message" => "Membro dell'equipaggio inserito con successo",
        "_id"     => (string) $result->getInsertedId()
    ]);
    exit;
}

// ─── 2. GET /artemis.php  →  Lettura di tutti i membri ──────────────────────
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

// ─── 3. POST /artemis.php/<ID>/portrait  →  Caricamento ritratto ─────────────
if ($method === 'POST' && preg_match('#^/([a-f0-9]{24})/portrait$#', $pathInfo, $matches)) {

    $astronautId = $matches[1];

    // Verifica che l'astronauta esista nel database
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

    // Verifica che sia stato inviato un file nel campo "image"
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "error" => "Nessun file immagine valido inviato. Usa il campo 'image'."
        ]);
        exit;
    }

    $file = $_FILES['image'];

    // Genera un nome file univoco per evitare sovrascritture
    $extension    = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName   = uniqid('portrait_', true) . '.' . $extension;
    $destPath     = $uploadDir . $uniqueName;
    $relativePath = 'uploads/' . $uniqueName;

    // Sposta il file dalla cartella temporanea alla cartella uploads/
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode([
            "error" => "Errore nel salvataggio del file sul server."
        ]);
        exit;
    }

    // Aggiorna il documento dell'astronauta con il percorso dell'immagine
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

// ─── 4. DELETE /artemis.php/<ID>  →  Rimozione membro ───────────────────────
if ($method === 'DELETE' && preg_match('#^/([a-f0-9]{24})$#', $pathInfo, $matches)) {

    $astronautId = $matches[1];

    // Cerca l'astronauta prima di eliminarlo (per gestire il file immagine)
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

    // Sfida Extra: se esiste un'immagine associata, elimina anche il file fisico
    if (isset($astronaut['image_path'])) {
        $filePath = __DIR__ . '/' . $astronaut['image_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // Elimina il documento dal database
    $collection->deleteOne([
        '_id' => new MongoDB\BSON\ObjectId($astronautId)
    ]);

    http_response_code(200);
    echo json_encode([
        "message" => "Membro dell'equipaggio eliminato con successo"
    ]);
    exit;
}

// ─── Fallback: rotta non trovata ─────────────────────────────────────────────
http_response_code(405);
echo json_encode([
    "error" => "Metodo o rotta non supportata"
]);
