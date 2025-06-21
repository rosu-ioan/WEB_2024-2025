<?php
require_once __DIR__ . '/utils/autoload.php';

session_start();

define('APP_DEBUG', true);

$isApiRequest = false;

$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));

// remove if hosted
if ($segments[0] === 'WEB_2024-2025') {
    array_shift($segments);
}

if (count($segments) && $segments[0] === 'api') {
    $isApiRequest = true;
    array_shift($segments); 
}

$controller = $segments[0] ?? 'auth';
if (!empty($segments[0])) {
    array_shift($segments);
}

$action = $segments[0] ?? 'login';
if (!empty($segments[0])) {
    array_shift($segments);
}

$params = $segments;

function sendApiError($message, $code = 500) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

function sendHtmlError($message, $code = 500) {
    http_response_code($code);
    $errorTemplate = __DIR__ . '/templates/error.html';
    
    if (file_exists($errorTemplate)) {
        $html = file_get_contents($errorTemplate);
        $html = str_replace([
            '{{error_code}}',
            '{{error_message}}',
            '{{home_url}}'
        ], [
            $code,
            htmlspecialchars($message),
            '/'
        ], $html);
        echo $html;
    } else {
        
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Error {$code}</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
                .error { background: #ffe6e6; border: 1px solid #ff0000; padding: 20px; border-radius: 5px; }
                .btn { display: inline-block; padding: 10px 20px; background: #007cba; color: white; text-decoration: none; border-radius: 3px; }
            </style>
        </head>
        <body>
            <div class='error'>
                <h1>Error {$code}</h1>
                <p>" . htmlspecialchars($message) . "</p>
                <a href='/' class='btn'>Go Home</a>
            </div>
        </body>
        </html>";
    }
    exit;
}

function sendError($message, $code = 500): never {
    global $isApiRequest;

    if($isApiRequest) {
        sendApiError($message, $code);
    } else {
        sendHtmlError($message, $code);
    }

    exit;
}


try {
    $controllerClass = ucfirst($controller) . 'Controller';
    
    if (!class_exists($controllerClass)) {
        $message = "Controller not found: {$controller}";
        sendError($message, 404);
    }
    
    if ($isApiRequest) {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); 
    } else {
        header('Content-Type: text/html; charset=UTF-8');
    }
    
    $controllerInstance = new $controllerClass($isApiRequest);

    $result = $controllerInstance->executeAction($action, $params);
    
    echo $result;
    
} catch (Throwable $e) {
    
    error_log("Router Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $message = 'Internal server error occurred';
    
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        $message = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }
    
    sendError($message, 500);
}
?>