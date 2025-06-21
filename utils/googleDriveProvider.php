<?php
require_once 'autoload.php';

define('CREDENTIALS_PATH', dirname(__DIR__) . "/credentials/credentials.json");

use Google\Client;
use Google\Service\Drive;
use Google\Service\Oauth2;

class GoogleDriveProvider implements CloudProviderInterface 
{
    private Client $client;
    private ?Drive $driveService = null;
    private ?Oauth2 $oauth2Service = null;
    private string $basePath;
    private int $chunkSize =60*1024*1024; 
    
    public function __construct() 
    {
        $scopes = [
            'https://www.googleapis.com/auth/drive.file',//sau drive pur si simplu pt toate fisierele
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile'
        ];
        $this->basePath = '/';

        $this->client = new Client();
        $this->client->setAuthConfig(CREDENTIALS_PATH);
        $this->client->setAccessType('offline'); 
        $this->client->setApprovalPrompt('force'); 
        $this->client->setPrompt('select_account');
        $this->client->setScopes($scopes);
    }
    
   
    public function getAuthorizationUrl(): string 
    {
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');
        $this->client->setPrompt('consent');
        
        return $this->client->createAuthUrl();
    }

   
    public function getReauthorizationUrl(): string 
    {
        if ($this->client->getAccessToken()) {
            $this->client->revokeToken();
        }
        
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');
        $this->client->setPrompt('consent');
        
        return $this->client->createAuthUrl();
    }
    

    public function handleAuthCallback(string $authCode): array 
    {
        error_log("=== GOOGLE DRIVE handleAuthCallback START ===");
        error_log("Auth code received: " . substr($authCode, 0, 20) . "...");
        
        try {
            $this->client->setAccessType('offline');
            $this->client->setApprovalPrompt('force');
            
            $token = $this->client->fetchAccessTokenWithAuthCode($authCode);

            $token = json_decode(json_encode($token), true);
            error_log("Raw token response from Google: " . print_r($token, true));
            
            if (isset($token['error'])) {
                error_log("Google OAuth error: " . ($token['error_description'] ?? $token['error']));
                throw new Exception('OAuth error: ' . ($token['error_description'] ?? $token['error']));
            }
            
            if (!isset($token['refresh_token'])) {
                error_log("WARNING: No refresh token received from Google");
                error_log("Available token keys: " . implode(', ', array_keys($token)));
            } else {
                error_log("SUCCESS: Refresh token received from Google");
            }
            
            $normalizedToken = [
                'access_token' => $token['access_token'],
                'token_type' => $token['token_type'] ?? 'Bearer',
                'refresh_token' => $token['refresh_token'] ?? null,
                'scope' => $token['scope'] ?? implode(' ', [
                    'https://www.googleapis.com/auth/drive.file',
                    'https://www.googleapis.com/auth/userinfo.email',
                    'https://www.googleapis.com/auth/userinfo.profile'
                ])
            ];
            
            if (isset($token['expires_in'])) {
                $expiresIn = (int)$token['expires_in'];
                error_log("Google expires_in: $expiresIn seconds");
                
                
                if ($expiresIn > 0 && $expiresIn <= 86400) { 
                    $normalizedToken['expires_in'] = $expiresIn;
                } else {
                    error_log("WARNING: Invalid expires_in from Google ($expiresIn), using default 3600");
                    $normalizedToken['expires_in'] = 3600; 
                }
            } else {
                error_log("WARNING: No expires_in from Google, using default 3600");
                $normalizedToken['expires_in'] = 3600; 
            }
            
            
            if (isset($token['id_token'])) {
                try {
                    $jwtParts = explode('.', $token['id_token']);
                    if (count($jwtParts) === 3) {
                        $payload = json_decode(base64_decode($jwtParts[1]), true);
                        if (isset($payload['sub'])) {
                            $normalizedToken['account_id'] = $payload['sub'];
                        }
                    }
                } catch (Exception $e) {
                    error_log("Could not decode JWT for account_id: " . $e->getMessage());
                }
            }
            
            error_log("Normalized Google token: " . print_r($normalizedToken, true));
            error_log("=== GOOGLE DRIVE handleAuthCallback SUCCESS ===");
            
            return $normalizedToken;
            
        } catch (Exception $e) {
            error_log("=== GOOGLE DRIVE handleAuthCallback ERROR ===");
            error_log("Exception: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function refreshAccessToken(string $refreshToken): array 
    {
        $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
        
        if (isset($token['error'])) {
            throw new Exception('Token refresh error: ' . ($token['error_description'] ?? $token['error']));
        }
        
        return $token;
    }
    
    public function setAccessToken(array $tokenData): void 
    {
        error_log("=== GOOGLE DRIVE setAccessToken START ===");
        error_log("Token data received: " . print_r($tokenData, true));
        
        try {
            
            $this->client->setAccessToken($tokenData);
            $this->driveService = new Drive($this->client);
            $this->oauth2Service = new Oauth2($this->client);
            
            error_log("Google Drive services initialized successfully");
            error_log("=== GOOGLE DRIVE setAccessToken SUCCESS ===");
            
        } catch (Exception $e) {
            error_log("=== GOOGLE DRIVE setAccessToken ERROR ===");
            error_log("Exception: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function isTokenValid(): bool 
    {
        $this->ensureAuthenticated();//DE ASTA MERGE
        return !$this->client->isAccessTokenExpired();
    }
    
    public function uploadFile(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $this->ensureAuthenticated();
        
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new Exception("Could not determine file size: $filePath");
        }
        
        try {
            $fileMetadata = new \Google\Service\Drive\DriveFile();
            $fileMetadata->setName($fileName);
            
            //!!
            $this->client->setDefer(true);
            //!!
            
            $uploadRequest = $this->driveService->files->create($fileMetadata);

            $media = new \Google\Http\MediaFileUpload(
                $this->client,
                $uploadRequest, 
                mime_content_type($filePath) ?: 'application/octet-stream',
                null,
                true, 
                $this->chunkSize
            );
            $media->setFileSize($fileSize);
            
            $fileHandle = fopen($filePath, 'rb');
            if (!$fileHandle) {
                throw new Exception("Could not open file for reading: $filePath");
            }
            
            $status = false;
            $uploadedBytes = 0;
            
            try {
                while (!$status && !feof($fileHandle)) {
                    $chunk = fread($fileHandle, $this->chunkSize);

                    if (strlen($chunk) === 0) {
                        break;
                    }
                    
                    $uploadedBytes += strlen($chunk);
                    $status = $media->nextChunk($chunk);
                    
                    $progressPercentage = ($uploadedBytes / $fileSize) * 100;
                    
                    if ($progressCallback) {
                        $progressCallback([
                            'progress_percentage' => round($progressPercentage, 2),
                            'uploaded_bytes' => $uploadedBytes,
                            'total_bytes' => $fileSize,
                            'status' => $status ? 'completed' : 'uploading'
                        ]);
                    }
                }
                
            } finally {
                $this->client->setDefer(false);
                fclose($fileHandle);
            }
            
            if ($status) {
                return [
                    'id' => $status->getId(),
                    'name' => $status->getName(),
                    'size' => (int)$status->getSize(),
                    'mime_type' => $status->getMimeType()
                ];
            } else {
                throw new Exception('Upload did not complete successfully');
            }
            
        } catch (Exception $e) {
            $this->client->setDefer(false);
            
            if ($progressCallback) {
                $progressCallback([
                    'progress_percentage' => 0,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ]);
            }
            throw new Exception('Upload failed: ' . $e->getMessage());
        }
    }
    
    public function downloadFile(string $fileId, string $savePath = null, callable $progressCallback = null): string 
    {
        $this->ensureAuthenticated();
        
        try {
            $fileInfo = $this->driveService->files->get($fileId, ['fields' => 'id,name,size']);
            $fileSize = (int)$fileInfo->getSize();
            $fileName = $fileInfo->getName();
            
            if ($savePath === null) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
            $savePath = sys_get_temp_dir() . 
                       DIRECTORY_SEPARATOR . 
                       'download_' . 
                       $fileId . '_' . 
                       $baseName . 
                       ($extension ? '.' . $extension : '');
        }
            
            $saveDir = dirname($savePath);
            if (!is_dir($saveDir)) {
                if (!mkdir($saveDir, 0777, true)) {
                    throw new Exception("Failed to create directory: $saveDir");
                }
            }
            
            $accessToken = $this->client->getAccessToken();
            if (!$accessToken || !isset($accessToken['access_token'])) {
                throw new Exception('No valid access token available');
            }
            
            $fileHandle = fopen($savePath, 'wb');
            if (!$fileHandle) {
                throw new Exception("Could not open file for writing: $savePath");
            }
            
            $downloadedBytes = 0;
            
            try {
                while ($downloadedBytes < $fileSize) {
                    $startByte = $downloadedBytes;
                    $endByte = min($downloadedBytes + $this->chunkSize - 1, $fileSize - 1);
                    
                    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $accessToken['access_token'],
                            'Range: bytes=' . $startByte . '-' . $endByte
                        ],
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT => 300
                    ]);
                    
                    $chunkData = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);
                    
                    if ($error) {
                        throw new Exception("cURL error: $error");
                    }
                    
                    if ($httpCode !== 206) { 
                        throw new Exception("Failed to download chunk. HTTP status: $httpCode");
                    }
                    
                    $bytesWritten = fwrite($fileHandle, $chunkData);
                    if ($bytesWritten === false) {
                        throw new Exception("Failed to write chunk to file");
                    }
                    
                    $downloadedBytes += $bytesWritten;
                    
                    if ($progressCallback) {
                        $progressCallback([
                            'progress_percentage' => round(($downloadedBytes / $fileSize) * 100, 2),
                            'downloaded_bytes' => $downloadedBytes,
                            'total_bytes' => $fileSize,
                            'status' => $downloadedBytes >= $fileSize ? 'completed' : 'downloading'
                        ]);
                    }
                }
                
            } finally {
                fclose($fileHandle);
            }
            
            return $savePath;
            
        } catch (Exception $e) {
            if ($progressCallback) {
                $progressCallback([
                    'progress_percentage' => 0,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ]);
            }
            throw new Exception('Download failed: ' . $e->getMessage());
        }
    }
    
    public function deleteFile(string $fileId): bool 
    {
        $this->ensureAuthenticated();
        
        try {
            $this->driveService->files->delete($fileId);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getFileInfo(string $fileId): array 
    {        
        try {
            $file = $this->driveService->files->get($fileId, [
                'fields' => 'id,name,size,mimeType,createdTime,modifiedTime'
            ]);
            
            return [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'size' => (int)$file->getSize(),
                'mime_type' => $file->getMimeType(),
                'created_at' => $file->getCreatedTime(),
                'modified_at' => $file->getModifiedTime()
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to get file info: ' . $e->getMessage());
        }
    }

    public function getAllFiles(int $limit):array 
    {
        $this->ensureAuthenticated();

        try {
            $files = [];
            $pageToken = null;
            do {
                $response = $this->driveService->files->listFiles([
                    'pageSize' => $limit,
                    'q' => "trashed = false",
                    'orderBy' => 'modifiedTime desc',
                    'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime, size, parents)',
                    'pageToken' => $pageToken,
                ]);
                foreach ($response->getFiles() as $file) {
                    $files[] = [
                        'id' => $file->getId(),
                        'name' => $file->getName(),
                        'size' => $file->getSize(),
                        'mimeType' => $file->getMimeType(),
                        'createdTime' => $file->getCreatedTime(),
                        'modifiedTime' => $file->getModifiedTime(),
                        'parents' => $file->getParents(),
                    ];
                    if (count($files) >= $limit) break 2;
                }
                $pageToken = $response->getNextPageToken();
            } while ($pageToken && count($files) < $limit);

            return $files;
        } catch (Exception $e) {
            throw new Exception('Failed to get all files: ' . $e->getMessage());
        }
    }
    
    
    public function getRemainingStorage(): array 
    {
        error_log("=== GOOGLE DRIVE getRemainingStorage START ===");

        try {
            $this->ensureAuthenticated();
            error_log("Authentication check passed");
            
            $accessToken = $this->client->getAccessToken();
            if (!$accessToken || !isset($accessToken['access_token'])) {
                throw new Exception('No valid access token available');
            }
            
            $maxRetries = 3;
            $retryDelay = 2;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                error_log("Storage fetch attempt $attempt of $maxRetries using cURL");
                
                try {
                    if ($attempt > 1) {
                        error_log("Waiting {$retryDelay} seconds for Google Drive sync...");
                        sleep($retryDelay);
                    }
                    
                    $cacheBuster = time() . '_' . rand(1000, 9999);
                    $url = "https://www.googleapis.com/drive/v3/about?fields=storageQuota&quotaUser=cache_bust_" . $cacheBuster;
                    
                    error_log("Making cURL request to: $url");
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $accessToken['access_token'],
                            'Accept: application/json',
                            'Cache-Control: no-cache, no-store, must-revalidate',
                            'Pragma: no-cache',
                            'Expires: 0'
                        ],
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_USERAGENT => 'GoogleDriveProvider/1.0 (cURL)'
                    ]);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);
                    
                    if ($error) {
                        throw new Exception("cURL error: $error");
                    }
                    
                    error_log("HTTP response code: $httpCode");
                    
                    if ($httpCode !== 200) {
                        error_log("HTTP error response: $response");
                        throw new Exception("Failed to get storage info. HTTP status: $httpCode");
                    }
                    
                    $data = json_decode($response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("Invalid JSON response: " . json_last_error_msg());
                    }
                    
                    error_log("Raw API response (attempt $attempt): " . print_r($data, true));
                    
                    if (!isset($data['storageQuota'])) {
                        throw new Exception("Storage quota not found in response");
                    }
                    
                    $quota = $data['storageQuota'];
                    
                    error_log("Storage quota details: " . json_encode([
                        'limit' => $quota['limit'] ?? 'not set',
                        'usage' => $quota['usage'] ?? 'not set',
                        'usageInDrive' => $quota['usageInDrive'] ?? 'not set',
                        'usageInDriveTrash' => $quota['usageInDriveTrash'] ?? 'not set'
                    ]));
                    
                    $total = isset($quota['limit']) ? (int)$quota['limit'] : 0;
                    $used = isset($quota['usage']) ? (int)$quota['usage'] : 0;
                    
                   
                    
                    $remaining = $total - $used;
                    
                    $result = [
                        'total' => $total,
                        'used' => $used,
                        'remaining' => $remaining,
                        'percentage_used' => $total > 0 ? ($used / $total) * 100 : 0
                    ];
                    
                    error_log("Storage info (attempt $attempt): " . json_encode([
                        'total_gb' => round($total / (1024*1024*1024), 2),
                        'used_gb' => round($used / (1024*1024*1024), 2),
                        'remaining_gb' => round($remaining / (1024*1024*1024), 2),
                        'percentage' => round($result['percentage_used'], 2)
                    ]));
                    
                    error_log("=== GOOGLE DRIVE getRemainingStorage SUCCESS ===");
                    return $result;
                    
                } catch (Exception $e) {
                    error_log("Attempt $attempt failed: " . $e->getMessage());
                    if ($attempt === $maxRetries) {
                        throw $e; 
                    }
                    continue;
                }
            }
            
            throw new Exception("Failed to get storage info after $maxRetries attempts");
            
        } catch (Exception $e) {
            error_log("=== GOOGLE DRIVE getRemainingStorage ERROR ===");
            error_log("Exception: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            throw $e;
        }
    }
    
    public function getAccountInfo(): array 
    {
        error_log("=== GOOGLE DRIVE getAccountInfo START ===");
    
        try {
            $this->ensureAuthenticated();
            error_log("Authentication check passed");
        
            $userInfo = $this->oauth2Service->userinfo->get();
            error_log("User info retrieved from Google");
        
            $result = [
                'id' => $userInfo->getId(),
                'email' => $userInfo->getEmail(),
                'name' => $userInfo->getName(),
                'picture' => $userInfo->getPicture(),
                'verified_email' => $userInfo->getVerifiedEmail()
            ];
        
            error_log("Account info result: " . print_r($result, true));
            error_log("=== GOOGLE DRIVE getAccountInfo SUCCESS ===");
        
            return $result;
        
        } catch (Exception $e) {
            error_log("=== GOOGLE DRIVE getAccountInfo ERROR ===");
            error_log("Exception: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            throw $e;
        }
    }
    
    private function ensureAuthenticated(): void 
{
    error_log("=== GOOGLE DRIVE ensureAuthenticated START ===");

    if (!$this->driveService) {
        error_log("ERROR: Drive service not initialized");
        throw new Exception('Provider not authenticated. Call setAccessToken() first.');
    }

    if ($this->client->isAccessTokenExpired()) {
        error_log("WARNING: Access token expired - needs refresh");
        
        $currentToken = $this->client->getAccessToken();
        if (isset($currentToken['refresh_token'])) {
            try {
                error_log("Attempting to refresh expired token...");
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($currentToken['refresh_token']);
                
                if (isset($newToken['error'])) {
                    error_log("Token refresh failed: " . $newToken['error']);
                    throw new Exception('Token refresh failed: ' . $newToken['error']);
                }
                
                $this->client->setAccessToken($newToken);
                error_log("Token refreshed successfully");
                
            } catch (Exception $e) {
                error_log("Token refresh exception: " . $e->getMessage());
                throw new Exception('Access token expired and refresh failed: ' . $e->getMessage());
            }
        } else if ($this->client->getRefreshToken()) {
            error_log("No refresh token available, but client has a refresh token set");
            try {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                
                if (isset($newToken['error'])) {
                    error_log("Token refresh failed: " . $newToken['error']);
                    throw new Exception('Token refresh failed: ' . $newToken['error']);
                }
                
                $this->client->setAccessToken($newToken);
                error_log("Token refreshed successfully using client refresh token");
                
            } catch (Exception $e) {
                error_log("Token refresh exception: " . $e->getMessage());
                throw new Exception('Access token expired and refresh failed: ' . $e->getMessage());
            }
        }else {
            error_log("ERROR: No refresh token available for expired access token");
            throw new Exception('Access token expired and no refresh token available. Please re-authorize.');
        }
    }

    error_log("Authentication check passed - token valid");
    error_log("=== GOOGLE DRIVE ensureAuthenticated SUCCESS ===");
}
}