<?php

require_once __DIR__ . '/../utils/autoload.php';

class ProfileController extends AbstractController {
    
    public function __construct($isApiRequest = false) {
        parent::__construct($isApiRequest);
    }
    
    public function executeAction($action, $params) {
        error_log("=== PROFILE CONTROLLER executeAction START ===");
        error_log("Action: '$action'");
        error_log("Params: " . print_r($params, true));
        error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
        error_log("GET params: " . print_r($_GET, true));
        
        if ($action === 'oauth-callback') {
            error_log("Executing oauth-callback action");
            return $this->oauthCallback($params);
        }
        
        error_log("Checking JWT authentication...");
        $currentUser = AuthUtils::getCurrentUser();
        error_log("Current user: " . ($currentUser ? print_r($currentUser, true) : 'NULL'));
        
        if (!$currentUser) {
            error_log("No current user - redirecting to auth/login");
            if ($this->isApiRequest) {
                http_response_code(401);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Authentication required'
                ], true);
            } else {
                error_log("Redirecting to: " . $this->getBasePath() . 'auth/login');
                header('Location: ' . $this->getBasePath() . 'auth/login');
                exit;
            }
        }
        
        error_log("JWT authentication successful - user ID: " . $currentUser['user_id']);
        
        switch ($action) {
            case 'index':
            case '':
                error_log("Executing index action");
                return $this->index($currentUser);
            case 'connect-provider':
                error_log("Executing connect-provider action");
                return $this->connectProvider($currentUser);
            case 'disconnect-provider':
                error_log("Executing disconnect-provider action");
                return $this->disconnectProvider($currentUser);
            case 'move-provider':
                error_log("Executing move-provider action");
                return $this->moveProvider($currentUser);
            default:
                error_log("Unknown action: '$action' - returning 404");
                http_response_code(404);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Action not found'
                ], $this->isApiRequest);
        }
    }
    
    
    public function index($user) {
        if ($this->isApiRequest) {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], true);
        }
        
        try {
            $userProfile = $this->model->getUserProfile($user['user_id']);
            $availableProviders = $this->model->getAvailableProviders();
            $connectedProviders = $this->model->getConnectedProviders($user['user_id']);
            
            $connected = [];
            $unconnected = [];
            
            foreach ($availableProviders as $provider) {
                $isConnected = false;
                foreach ($connectedProviders as $connectedProvider) {
                    if ($connectedProvider['provider_id'] === $provider['provider_id'] && $connectedProvider['account_id']) {
                        $connected[] = $connectedProvider;
                        $isConnected = true;
                        break;
                    }
                }
                if (!$isConnected) {
                    $unconnected[] = $provider;
                }
            }
            
            usort($connected, function($a, $b) {
                return $a['priority_rank'] <=> $b['priority_rank'];
            });
            
            $data = [
                'user' => $userProfile,
                'connected_providers' => $connected,
                'unconnected_providers' => $unconnected,
                'success_message' => $_GET['connected'] ?? null,
                'error_message' => $_GET['error'] ?? null
            ];
            
            return $this->view->renderResponse($data, false);
            
        } catch (Exception $e) {
            error_log("ProfileController::index Error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'user' => $user,
                'connected_providers' => [],
                'unconnected_providers' => [],
                'error' => 'Unable to load profile data'
            ], false);
        }
    }
    
 
    public function connectProvider($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $providerId = $_POST['provider_id'] ?? null;
            
            if (!$providerId) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Provider ID required'
                ], $this->isApiRequest);
            }
            
            $provider = $this->model->getProviderById($providerId);
            if (!$provider) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Invalid provider'
                ], $this->isApiRequest);
            }
            
            if (!class_exists($provider['provider_class'])) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Provider implementation not found'
                ], $this->isApiRequest);
            }
            
            if (!isset($_SESSION)) {
                session_start();
            }
            
            $_SESSION['oauth_user_id'] = $user['user_id'];
            $_SESSION['oauth_provider_id'] = $providerId;
            
            $_SESSION['oauth_jwt_token'] = $_COOKIE['auth_token'] ?? null;
            error_log("Storing JWT in session for OAuth: " . ($_SESSION['oauth_jwt_token'] ? 'YES' : 'NO'));
            
            $providerClass = $provider['provider_class'];
            $providerInstance = new $providerClass();
            $authUrl = $providerInstance->getAuthorizationUrl();
            
            if ($this->isApiRequest) {
                return $this->view->renderResponse([
                    'success' => true,
                    'redirect_url' => $authUrl
                ], true);
            } else {
                header('Location: ' . $authUrl);
                exit;
            }
            
        } catch (Exception $e) {
            error_log("ProfileController::connectProvider Error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to initiate connection'
            ], $this->isApiRequest);
        }
    }
    

    public function oauthCallback($params) {
        error_log("=== OAUTH CALLBACK START ===");
        error_log("OAuth callback called with URL: " . $_SERVER['REQUEST_URI']);
        error_log("OAuth callback GET params: " . print_r($_GET, true));
        
        try {
            if (!isset($_SESSION)) {
                session_start();
            }
            
            $code = $_GET['code'] ?? null;
            $error = $_GET['error'] ?? null;
            
            if ($error) {
                error_log("OAuth error parameter: $error");
                header('Location: ' . $this->getBasePath() . 'profile/index?error=oauth-error');
                exit;
            }
            
            if (!$code) {
                error_log("OAuth callback missing code parameter");
                header('Location: ' . $this->getBasePath() . 'profile/index?error=invalid-callback');
                exit;
            }
            
            error_log("OAuth code received: " . substr($code, 0, 20) . "...");
            
            $userId = $_SESSION['oauth_user_id'] ?? null;
            $providerId = $_SESSION['oauth_provider_id'] ?? null;
            $jwtToken = $_SESSION['oauth_jwt_token'] ?? null;
            
            error_log("Session user ID: $userId");
            error_log("Session provider ID: $providerId");
            error_log("Session JWT token: " . ($jwtToken ? 'PRESENT' : 'MISSING'));
            
            if (!$userId || !$providerId) {
                error_log("OAuth callback missing session data - user: $userId, provider: $providerId");
                header('Location: ' . $this->getBasePath() . 'profile/index?error=invalid-session');
                exit;
            }
            
            if ($jwtToken && !isset($_COOKIE['auth_token'])) {
                error_log("Restoring JWT cookie from session");
                setcookie('auth_token', $jwtToken, [
                    'expires' => time() + (24 * 60 * 60), 
                    'path' => '/',
                    'httponly' => true,
                    'secure' => false, 
                    'samesite' => 'Lax'
                ]);
                $_COOKIE['auth_token'] = $jwtToken; 
            }
            
            error_log("Getting provider info for ID: $providerId");
            $provider = $this->model->getProviderById($providerId);
            if (!$provider) {
                error_log("Provider not found in database: $providerId");
                header('Location: ' . $this->getBasePath() . 'profile/index?error=provider-not-found');
                exit;
            }
            error_log("Provider info: " . print_r($provider, true));
            
            error_log("Creating provider instance: " . $provider['provider_class']);
            $providerClass = $provider['provider_class'];
            $providerInstance = new $providerClass();
            
            error_log("Calling handleAuthCallback with code...");
            $tokens = $providerInstance->handleAuthCallback($code);
            error_log("Tokens received from provider: " . print_r($tokens, true));
            
            error_log("Storing connection in database...");
            $result = $this->model->storeCloudConnection($userId, $providerId, $tokens);
            error_log("Store result: " . print_r($result, true));
            
            unset($_SESSION['oauth_user_id'], $_SESSION['oauth_provider_id'], $_SESSION['oauth_jwt_token']);
            
            if ($result['success']) {
                error_log("=== OAUTH CALLBACK SUCCESS ===");
                error_log("Cloud connection successful for user $userId, provider $providerId");
                header('Location: ' . $this->getBasePath() . 'profile/index?connected=success');
            } else {
                error_log("=== OAUTH CALLBACK FAILED ===");
                error_log("Failed to store cloud connection: " . $result['error']);
                header('Location: ' . $this->getBasePath() . 'profile/index?error=connection-failed');
            }
            exit;
            
        } catch (Exception $e) {
            error_log("=== OAUTH CALLBACK EXCEPTION ===");
            error_log("Exception message: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            if (isset($_SESSION)) {
                unset($_SESSION['oauth_user_id'], $_SESSION['oauth_provider_id'], $_SESSION['oauth_jwt_token']);
            }
            
            header('Location: ' . $this->getBasePath() . 'profile/index?error=callback-failed');
            exit;
        }
    }
    

    public function disconnectProvider($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $accountId = $_POST['account_id'] ?? null;
            
            if (!$accountId) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Account ID required'
                ], $this->isApiRequest);
            }
            
            $result = $this->model->removeCloudConnection($user['user_id'], $accountId);
            
            if ($result['success']) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Provider disconnected successfully'
                ], $this->isApiRequest);
            } else {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], $this->isApiRequest);
            }
            
        } catch (Exception $e) {
            error_log("ProfileController::disconnectProvider Error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to disconnect provider'
            ], $this->isApiRequest);
        }
    }
  
    public function moveProvider($user) {
        error_log("=== MOVE PROVIDER START ===");
        error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
        error_log("isApiRequest: " . ($this->isApiRequest ? 'TRUE' : 'FALSE'));
        error_log("POST data: " . print_r($_POST, true));
        error_log("User: " . print_r($user, true));
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            error_log("ERROR: Method not allowed");
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $accountId = $_POST['account_id'] ?? null;
            $direction = $_POST['direction'] ?? null;
            
            error_log("Account ID: " . ($accountId ?? 'NULL'));
            error_log("Direction: " . ($direction ?? 'NULL'));
            
            if (!$accountId || !$direction) {
                error_log("ERROR: Missing account_id or direction");
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Account ID and direction required'
                ], $this->isApiRequest);
            }
            
            if (!in_array($direction, ['up', 'down'])) {
                error_log("ERROR: Invalid direction: $direction");
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Direction must be "up" or "down"'
                ], $this->isApiRequest);
            }
            
            error_log("Validation passed - calling model method");
            
            if ($direction === 'up') {
                error_log("Calling moveProviderUp...");
                $result = $this->model->moveProviderUp($user['user_id'], $accountId);
            } else {
                error_log("Calling moveProviderDown...");
                $result = $this->model->moveProviderDown($user['user_id'], $accountId);
            }
            
            error_log("Model result: " . print_r($result, true));
            
            if ($result['success']) {
                error_log("=== MOVE PROVIDER SUCCESS ===");
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Provider priority updated successfully'
                ], $this->isApiRequest);
            } else {
                error_log("=== MOVE PROVIDER FAILED ===");
                error_log("Error from model: " . $result['error']);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], $this->isApiRequest);
            }
            
        } catch (Exception $e) {
            error_log("=== MOVE PROVIDER EXCEPTION ===");
            error_log("Exception message: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to update provider priority'
            ], $this->isApiRequest);
        }
    }
}