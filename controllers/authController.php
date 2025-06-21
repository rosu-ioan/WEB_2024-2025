<?php

require_once __DIR__ . '/../utils/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

class AuthController extends AbstractController {
    
    private $jwtSecret;
    private $jwtExpiration;
    
    public function __construct($isApiRequest = false) {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
        $dotenv->load();
        
        parent::__construct($isApiRequest);
        
        $this->jwtSecret = $_ENV['JWT_SECRET'];
        $this->jwtExpiration = (int)($_ENV['JWT_EXPIRATION']);
        
        if (!$this->jwtSecret) {
            throw new Exception('JWT_SECRET not configured in .env file');
        }
    }
  
    
    public function executeAction($action, $params)     {
        switch ($action) {
            case 'login':
                return $this->login();
            case 'register':
                return $this->register();
            case 'logout':
                return $this->logout();
            default:
                http_response_code(404);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Action not found'
                ], $this->isApiRequest);
        }
    }
    
    public function login() {
        
        $currentUser = AuthUtils::getCurrentUser();
        
        if ($currentUser) {
            if ($this->isApiRequest) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Already authenticated',
                    'redirect' => $this->getBasePath() . 'dashboard/index',
                    'user' => $currentUser
                ], true);
            } else {
                header('Location: ' . $this->getBasePath() . 'dashboard/index');
                exit;
            }
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->handleLogin();
        }
        
        if ($this->isApiRequest) {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], true);
        }
        
        return $this->view->renderResponse([], false);
    }
    
    public function register() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->handleRegister();
        }
        
        if (!$this->isApiRequest) {
            header('Location: ' . $this->getBasePath() . 'auth/login');
            exit;
        }
        
        http_response_code(405);
        return $this->view->renderResponse([
            'success' => false,
            'error' => 'Method not allowed'
        ], true);
    }
    
    private function handleLogin() {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $errors = $this->validateLoginInput($email, $password);
        if (!empty($errors)) {
            return $this->view->renderResponse([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ], $this->isApiRequest);
        }
        
        $user = $this->model->authenticateUser($email, $password);
        if (!$user) {
            return $this->view->renderResponse([
                'success' => false,
                'message' => 'Invalid email or password'
            ], $this->isApiRequest);
        }
        
        $this->model->updateLastLogin($user['user_id']);
        
        $token = $this->generateJWT($user);
        $this->setAuthCookie($token, false);
        
        return $this->view->renderResponse([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => $this->getBasePath() . 'dashboard/index',
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]
        ], $this->isApiRequest);
    }
    
    private function handleRegister() {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        $errors = $this->validateRegisterInput($username, $email, $password, $confirmPassword);
        if (!empty($errors)) {
            return $this->view->renderResponse([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ], $this->isApiRequest);
        }
        
        if ($this->model->userExists($email, $username)) {
            return $this->view->renderResponse([
                'success' => false,
                'message' => 'User already exists with this email or username'
            ], $this->isApiRequest);
        }
        
        $userId = $this->model->createUser($username, $email, $password);
        if (!$userId) {
            return $this->view->renderResponse([
                'success' => false,
                'message' => 'Failed to create account. Please try again.'
            ], $this->isApiRequest);
        }
        
        $user = [
            'user_id' => $userId,
            'username' => $username,
            'email' => $email
        ];
        $token = $this->generateJWT($user);
        $this->setAuthCookie($token, false);
        
        return $this->view->renderResponse([
            'success' => true,
            'message' => 'Account created successfully',
            'redirect' => $this->getBasePath() . 'dashboard/index',
            'user' => $user
        ], $this->isApiRequest);
    }
    
    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $this->isApiRequest) {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], true);
        }
        
        $this->clearAuthCookie();
        
        if ($this->isApiRequest) {
            return $this->view->renderResponse([
                'success' => true,
                'message' => 'Logged out successfully'
            ], true);
        }
        
        header('Location: ' . $this->getBasePath() . 'auth/login');
        exit;
    }
    
    
    private function generateJWT($user) {
        $payload = [
            'iss' => $_SERVER['HTTP_HOST'] ?? 'localhost', 
            'iat' => time(), 
            'exp' => time() + $this->jwtExpiration, 
            'data' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]
        ];
        
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }
    
    private function setAuthCookie($token, $remember = false) {
        $expires = $remember ? time() + (30 * 24 * 60 * 60) : time() + $this->jwtExpiration; 
        
        setcookie('auth_token', $token, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '', 
            'secure' => isset($_SERVER['HTTPS']), 
            'httponly' => true, 
            'samesite' => 'Strict' 
        ]);
    }
    
    private function clearAuthCookie() {
        setcookie('auth_token', '', [
            'expires' => time() - 3600, 
            'path' => '/',
            'httponly' => true
        ]);
    }
    
    
    
    private function validateLoginInput($email, $password) {
        $errors = [];
        
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }
        
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        }
        
        return $errors;
    }
    
    private function validateRegisterInput($username, $email, $password, $confirmPassword) {
        $errors = [];
        
        if (empty($username)) {
            $errors['username'] = 'Username is required';
        } elseif (strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        } elseif (strlen($username) > 50) {
            $errors['username'] = 'Username must not exceed 50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors['username'] = 'Username can only contain letters, numbers, and underscores';
        }
        
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        } elseif (strlen($email) > 100) {
            $errors['email'] = 'Email must not exceed 100 characters';
        }
        
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif (strlen($password) > 255) {
            $errors['password'] = 'Password is too long';
        }
        
        if (empty($confirmPassword)) {
            $errors['confirm_password'] = 'Please confirm your password';
        } elseif ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        return $errors;
    }
}