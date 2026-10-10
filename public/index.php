<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Jwt;
use App\Core\Database;
use App\Services\AuthService;
use App\Services\BookService;
use App\Services\MemberService;
use App\Services\LoanService;
use App\Services\ReportService;

// Manejo de CORS Preflight
Response::setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// Auto-ejecución de migraciones si la tabla de usuarios no existe
try {
    $db = Database::getConnection();
    $check = $db->query("SELECT to_regclass('library.user')")->fetchColumn();
    if (!$check) {
        require_once __DIR__ . '/../database/migrations/runner.php';
        ob_start();
        run_migrations();
        ob_end_clean();
    }
} catch (\Exception $e) {
    // Si la BD aún está inicializando, continuar y reportar en el endpoint
}

$request = new Request();
$method = $request->getMethod();
$path = $request->getPath();

// Normalizar prefijo de ruta (soportar /api/v1/.. y directos)
if (str_starts_with($path, '/api/v1')) {
    $subPath = substr($path, 7) ?: '/';
} else {
    $subPath = $path;
}

// -------------------------------------------------------------
// Rutas Públicas
// -------------------------------------------------------------
if ($method === 'GET' && ($subPath === '/health' || $subPath === '/')) {
    Response::json(['status' => 'ok']);
}

if ($method === 'POST' && $subPath === '/auth/login') {
    $username = $request->get('username', '');
    $password = $request->get('password', '');
    $result = AuthService::login($username, $password);
    Response::json($result, 200);
}

// -------------------------------------------------------------
// Middleware de Autenticación
// -------------------------------------------------------------
$token = $request->getBearerToken();
if (!$token) {
    Response::error('UNAUTHORIZED', 'Se requiere autenticación Bearer token.', 401);
}

$payload = Jwt::verifyToken($token);
if (!$payload) {
    Response::error('UNAUTHORIZED', 'Token inválido o expirado.', 401);
}

$user = [
    'id' => $payload['sub'],
    'role' => $payload['role']
];
$request->setUser($user);

// -------------------------------------------------------------
// Rutas Autenticadas
// -------------------------------------------------------------

// --- Categorías ---
if ($method === 'GET' && $subPath === '/categories') {
    $db = Database::getConnection();
    $cats = $db->query("SELECT id, name FROM library.category ORDER BY name ASC")->fetchAll();
    Response::json($cats, 200);
}

// --- Usuarios (Admin Only) ---
if ($method === 'POST' && $subPath === '/users') {
    if ($user['role'] !== 'admin') {
        Response::error('FORBIDDEN_ROLE', 'Permisos insuficientes. Requiere rol admin.', 403);
    }
    $created = AuthService::createLibrarian(
        $request->get('username', ''),
        $request->get('password', ''),
        $request->get('role', '')
    );
    Response::json($created, 201);
}

// --- Libros ---
if ($method === 'GET' && $subPath === '/books') {
    $q = $request->getQuery('q');
    $catId = $request->getQuery('category_id');
    $page = (int) $request->getQuery('page', 1);
    $size = (int) $request->getQuery('size', 20);
    $result = BookService::list($q, $catId, $page, $size);
    Response::json($result, 200);
}

if (preg_match('#^/books/([0-9a-fA-F-]+)$#', $subPath, $m)) {
    $bookId = $m[1];
    if ($method === 'GET') {
        $book = BookService::get($bookId);
        Response::json($book, 200);
    }
    if ($method === 'PUT') {
        if ($user['role'] !== 'admin') {
            Response::error('FORBIDDEN_ROLE', 'Acceso denegado.', 403);
        }
        $updated = BookService::update($bookId, $request->getBody());
        Response::json($updated, 200);
    }
    if ($method === 'DELETE') {
        if ($user['role'] !== 'admin') {
            Response::error('FORBIDDEN_ROLE', 'Acceso denegado.', 403);
        }
        BookService::delete($bookId);
        Response::noContent();
    }
}

if ($method === 'POST' && $subPath === '/books') {
    if ($user['role'] !== 'admin') {
        Response::error('FORBIDDEN_ROLE', 'Acceso denegado.', 403);
    }
    $created = BookService::create($request->getBody());
    Response::json($created, 201);
}

// Portadas
if (preg_match('#^/books/([0-9a-fA-F-]+)/cover$#', $subPath, $m)) {
    $bookId = $m[1];
    if ($method === 'PUT') {
        if ($user['role'] !== 'admin') {
            Response::error('FORBIDDEN_ROLE', 'Acceso denegado.', 403);
        }
        $file = $request->getFile('file');
        if (!$file) {
            Response::error('VALIDATION_ERROR', 'No se envió ningún archivo de imagen.', 422);
        }
        $updated = BookService::uploadCover($bookId, $file);
        Response::json($updated, 200);
    }
    if ($method === 'GET') {
        $book = BookService::get($bookId);
        if (!$book['cover_key']) {
            Response::error('NOT_FOUND', 'El libro no tiene portada.', 404);
        }
        $filePath = __DIR__ . '/../var/covers/' . $book['cover_key'];
        if (!file_exists($filePath)) {
            Response::error('NOT_FOUND', 'Archivo no encontrado.', 404);
        }
        $mime = mime_content_type($filePath);
        header("Content-Type: $mime");
        readfile($filePath);
        exit(0);
    }
    if ($method === 'DELETE') {
        if ($user['role'] !== 'admin') {
            Response::error('FORBIDDEN_ROLE', 'Acceso denegado.', 403);
        }
        $db = Database::getConnection();
        $db->prepare("UPDATE library.book SET cover_key = NULL WHERE id = :id")->execute(['id' => $bookId]);
        Response::noContent();
    }
}

// --- Socios ---
if ($method === 'GET' && $subPath === '/members') {
    $q = $request->getQuery('q');
    $page = (int) $request->getQuery('page', 1);
    $size = (int) $request->getQuery('size', 20);
    $result = MemberService::list($q, $page, $size);
    Response::json($result, 200);
}

if ($method === 'POST' && $subPath === '/members') {
    $created = MemberService::create($request->getBody());
    Response::json($created, 201);
}

if ($method === 'GET' && preg_match('#^/members/([0-9a-fA-F-]+)$#', $subPath, $m)) {
    $member = MemberService::get($m[1]);
    Response::json($member, 200);
}

// --- Préstamos ---
if ($method === 'POST' && $subPath === '/loans') {
    $loan = LoanService::create($user['id'], $request->getBody());
    Response::json($loan, 201);
}

if ($method === 'GET' && $subPath === '/loans/overdue') {
    $overdue = LoanService::getOverdue();
    Response::json($overdue, 200);
}

if ($method === 'GET' && $subPath === '/loans') {
    $from = $request->getQuery('from');
    $to = $request->getQuery('to');
    $status = $request->getQuery('status');
    $mid = $request->getQuery('member_id');
    $page = (int) $request->getQuery('page', 1);
    $size = (int) $request->getQuery('size', 20);
    $result = LoanService::list($from, $to, $status, $mid, $page, $size);
    Response::json($result, 200);
}

if ($method === 'GET' && preg_match('#^/loans/([0-9a-fA-F-]+)$#', $subPath, $m)) {
    $loan = LoanService::get($m[1]);
    Response::json($loan, 200);
}

if ($method === 'POST' && preg_match('#^/loans/([0-9a-fA-F-]+)/items/([0-9a-fA-F-]+)/return$#', $subPath, $m)) {
    $returned = LoanService::returnItem($m[1], $m[2]);
    Response::json($returned, 200);
}

// --- Reporte (Admin Only) ---
if ($method === 'GET' && $subPath === '/reports/most-borrowed') {
    if ($user['role'] !== 'admin') {
        Response::error('FORBIDDEN_ROLE', 'Permisos insuficientes.', 403);
    }
    $from = $request->getQuery('from', '');
    $to = $request->getQuery('to', '');
    $report = ReportService::getMostBorrowed($from, $to);
    Response::json($report, 200);
}

// Ruta no encontrada
Response::error('NOT_FOUND', "La ruta «{$method} {$path}» no existe.", 404);

