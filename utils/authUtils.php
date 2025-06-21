<?php

require_once __DIR__ . '/../utils/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

class AuthUtils {
    private static $jwtSecret = null;
    
   
    private static function init() {
        if (self::$jwtSecret === null) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
            $dotenv->load();
            
            self::$jwtSecret = $_ENV['JWT_SECRET'];
            
            if (!self::$jwtSecret) {
                throw new Exception('JWT_SECRET not configured in .env file');
            }
        }
    }
    
   
    public static function getCurrentUser() {
        error_log("=== AuthUtils::getCurrentUser START ===");
        
        self::init();
        
        $token = $_COOKIE['auth_token'] ?? null;
        error_log("JWT cookie present: " . ($token ? 'YES' : 'NO'));
        
        if (!$token) {
            error_log("No JWT cookie found");
            return null;
        }
        
        try {
            $decoded = JWT::decode($token, new Key(self::$jwtSecret, 'HS256'));
            error_log("JWT decoded successfully - user ID: " . $decoded->data->user_id);
            
            $userId = $decoded->data->user_id;
            
            $model = new AuthModel();
            $user = $model->getUserById($userId);
            
            if (!$user) {
                error_log("AuthUtils: User $userId from JWT token no longer exists");
                return null;
            }
            
            return [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'total_storage_used' => $user['total_storage_used']
            ];
            
        } catch (Exception $e) {
            error_log("AuthUtils: JWT validation error - " . $e->getMessage());
            return null;
        }
    }
    
   
    public static function requireAuth() {
        $user = self::getCurrentUser();
        
        if (!$user) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Authentication required']);
            } else {
                header('Location: ' . AbstractView::getStaticBaseUrl() . 'auth/login');
            }
            exit;
        }
        
        return $user;
    }
}