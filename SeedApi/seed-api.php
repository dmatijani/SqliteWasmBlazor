<?php
/**
 * Seed File API — proof of concept, no authentication.
 * Runs via Herd/Valet with LocalValetDriver routing /api/* here.
 *
 * Endpoints:
 *   GET    /api/seeds                     — list all seeds
 *   GET    /api/seeds/{name}              — get seed meta info
 *   GET    /api/seeds/{name}/{file}       — download a file
 *   POST   /api/seeds/{name}              — upload a file (multipart form)
 *   DELETE /api/seeds/{name}              — delete seed + all parts
 *
 * Storage: ./seeds/{seed-name}/{files}
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$seedsDir = __DIR__ . '/seeds';
if (!is_dir($seedsDir)) {
    mkdir($seedsDir, 0755, true);
}

// Parse path from Valet driver: /api/seeds/name/file → path = "seeds/name/file"
$path = trim($_GET['path'] ?? '', '/');
$segments = array_filter(explode('/', $path));
$segments = array_values($segments);

$method = $_SERVER['REQUEST_METHOD'];

function sanitize(string $name): string {
    return preg_replace('/[^a-zA-Z0-9\-_.]/', '', $name);
}

// Route: /api/seeds
if (count($segments) === 1 && $segments[0] === 'seeds') {
    if ($method === 'GET') {
        $seeds = [];
        foreach (glob("$seedsDir/*", GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $metaFiles = glob("$dir/*.msgpack-meta");

            if (empty($metaFiles)) {
                continue;
            }

            $meta = json_decode(file_get_contents($metaFiles[0]), true);

            $files = [];
            foreach (glob("$dir/*") as $file) {
                $files[] = [
                    'name' => basename($file),
                    'size' => filesize($file)
                ];
            }

            $seeds[] = [
                'name' => $name,
                'metaFile' => basename($metaFiles[0]),
                'totalRecordCount' => $meta['totalRecordCount'] ?? 0,
                'exportedAt' => $meta['exportedAt'] ?? '',
                'partCount' => count($meta['parts'] ?? []),
                'files' => $files
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($seeds, JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Route: /api/seeds/{name}
if (count($segments) === 2 && $segments[0] === 'seeds') {
    $seedName = sanitize($segments[1]);

    if ($method === 'GET') {
        $seedDir = "$seedsDir/$seedName";
        if (!is_dir($seedDir)) {
            http_response_code(404);
            echo json_encode(['error' => 'Seed not found']);
            exit;
        }

        $metaFiles = glob("$seedDir/*.msgpack-meta");
        $meta = !empty($metaFiles) ? json_decode(file_get_contents($metaFiles[0]), true) : null;

        $files = [];
        foreach (glob("$seedDir/*") as $file) {
            $files[] = ['name' => basename($file), 'size' => filesize($file)];
        }

        header('Content-Type: application/json');
        echo json_encode([
            'name' => $seedName,
            'meta' => $meta,
            'files' => $files
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'No file uploaded',
                'phpMaxUpload' => ini_get('upload_max_filesize'),
                'phpMaxPost' => ini_get('post_max_size'),
                'contentLength' => $_SERVER['CONTENT_LENGTH'] ?? 'not set'
            ]);
            exit;
        }

        $seedDir = "$seedsDir/$seedName";
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }

        $uploadedName = sanitize(basename($_FILES['file']['name']));
        $targetPath = "$seedDir/$uploadedName";

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'seed' => $seedName,
            'file' => $uploadedName,
            'size' => filesize($targetPath)
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $seedDir = "$seedsDir/$seedName";
        if (!is_dir($seedDir)) {
            http_response_code(404);
            echo json_encode(['error' => 'Seed not found']);
            exit;
        }

        foreach (glob("$seedDir/*") as $file) {
            unlink($file);
        }
        rmdir($seedDir);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'deleted' => $seedName]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Route: /api/seeds/{name}/{file}
if (count($segments) === 3 && $segments[0] === 'seeds') {
    $seedName = sanitize($segments[1]);
    $fileName = sanitize($segments[2]);

    if ($method === 'GET') {
        $filePath = "$seedsDir/$seedName/$fileName";
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }

        $size = filesize($filePath);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"$fileName\"");
        header("Content-Length: $size");
        readfile($filePath);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Default
http_response_code(400);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'Unknown route',
    'usage' => [
        'list' => 'GET /api/seeds',
        'info' => 'GET /api/seeds/{name}',
        'download' => 'GET /api/seeds/{name}/{file}',
        'upload' => 'POST /api/seeds/{name} (multipart file)',
        'delete' => 'DELETE /api/seeds/{name}'
    ]
]);
