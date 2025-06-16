<?php
require_once 'autoload.php';

$envPath = dirname(__DIR__) . "/credentials/.env";

if (file_exists($envPath)) {
    foreach (file($envPath) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
    }
}

class DropboxProvider implements CloudProviderInterface 
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;
    private $apiUrl = 'https://api.dropboxapi.com/2';
    private $contentUrl = 'https://content.dropboxapi.com/2';
    
    public function __construct() 
    {
        $this->clientId = $_ENV['DROPBOX_CLIENT_ID'];
        $this->clientSecret = $_ENV['DROPBOX_CLIENT_SECRET'];
        $this->redirectUri = 'http://localhost/WEB_2024-2025/dropbox/login';
    }
    
    public function getAuthorizationUrl(): string 
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'token_access_type' => 'offline'
        ];
        
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);
    }
    
    public function handleAuthCallback(string $authCode): array 
    {
        $params = [
            'code' => $authCode,
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri
        ];
        
        $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Token request failed: ' . $error);
        }
        
        $token = json_decode($response, true);
        if (!isset($token['access_token'])) {
            throw new Exception('Invalid token response: ' . $response);
        }
        
        return $token;
    }
    
    public function refreshAccessToken(string $refreshToken): array 
    {
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ];
        
        $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $token = json_decode($response, true);
        if (!isset($token['access_token'])) {
            throw new Exception('Token refresh failed');
        }
        
        return $token;
    }
    
    public function setAccessToken(array $tokenData): void 
    {
        $this->accessToken = $tokenData['access_token'];
    }
    
    public function isTokenValid(): bool 
    {
        if (!$this->accessToken) {
            return false;
        }
        
        try {
            $this->makeApiRequest('/users/get_current_account', 'POST');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function uploadFile(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new Exception("Could not determine file size");
        }
        
        if ($fileSize <= 150 * 1024 * 1024) {
            return $this->simpleUpload($fileName, $filePath, $progressCallback);
        }
        
        return $this->chunkedUpload($fileName, $filePath, $progressCallback);
    }
    
    private function simpleUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $content = file_get_contents($filePath);
        $args = json_encode([
            "path" => "/" . $fileName,
            "mode" => "add",
            "autorename" => true,
            "mute" => false
        ]);
        
        $ch = curl_init($this->contentUrl . '/files/upload');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->accessToken,
            "Dropbox-API-Arg: " . $args,
            "Content-Type: application/octet-stream"
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        if (!isset($result['id'])) {
            throw new Exception('Upload failed: ' . $response);
        }
        
        return [
            'id' => $result['id'],
            'name' => $result['name'],
            'size' => $result['size'],
            'mime_type' => $result['.tag']
        ];
    }
    
    private function chunkedUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $file = fopen($filePath, 'rb');
        $fileSize = filesize($filePath);
        $chunkSize = 4 * 1024 * 1024;
        $offset = 0;
        $sessionId = null;
        
        while (!feof($file)) {
            $chunk = fread($file, $chunkSize);
            $chunkSize = strlen($chunk);
            
            if ($offset === 0) {
               
                $ch = curl_init($this->contentUrl . '/files/upload_session/start');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $this->accessToken,
                    "Dropbox-API-Arg: {\"close\": false}",
                    "Content-Type: application/octet-stream"
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $result = json_decode($response, true);
                $sessionId = $result['session_id'];
            } else {
               
                $args = json_encode([
                    "cursor" => [
                        "session_id" => $sessionId,
                        "offset" => $offset
                    ],
                    "close" => ($offset + $chunkSize >= $fileSize)
                ]);
                
                $ch = curl_init($this->contentUrl . '/files/upload_session/append_v2');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $this->accessToken,
                    "Dropbox-API-Arg: " . $args,
                    "Content-Type: application/octet-stream"
                ]);
                
                curl_exec($ch);
                curl_close($ch);
            }
            
            $offset += $chunkSize;
            
            if ($progressCallback) {
                $progressCallback([
                    'progress_percentage' => ($offset / $fileSize) * 100,
                    'uploaded_bytes' => $offset,
                    'total_bytes' => $fileSize,
                    'status' => $offset >= $fileSize ? 'completed' : 'uploading'
                ]);
            }
        }
        
        fclose($file);
        
       
        $args = json_encode([
            "cursor" => [
                "session_id" => $sessionId,
                "offset" => $offset
            ],
            "commit" => [
                "path" => "/" . $fileName,
                "mode" => "add",
                "autorename" => true,
                "mute" => false
            ]
        ]);
        
        $ch = curl_init($this->contentUrl . '/files/upload_session/finish');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->accessToken,
            "Dropbox-API-Arg: " . $args,
            "Content-Type: application/octet-stream"
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        return [
            'id' => $result['id'],
            'name' => $result['name'],
            'size' => $result['size'],
            'mime_type' => $result['.tag']
        ];
    }
    
    public function downloadFile(string $fileId, string $savePath = null, callable $progressCallback = null): string 
    {
        $args = json_encode(["path" => $fileId]);
        
        if ($savePath === null) {
            $savePath = sys_get_temp_dir() . '/dropbox_' . basename($fileId);
        }
        
        $fp = fopen($savePath, 'wb');
        if (!$fp) {
            throw new Exception("Could not open file for writing: $savePath");
        }
        
        $ch = curl_init($this->contentUrl . '/files/download');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->accessToken,
            "Dropbox-API-Arg: " . $args
        ]);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $downloadTotal, $downloaded) use ($progressCallback) {
            if ($progressCallback && $downloadTotal > 0) {
                $progressCallback([
                    'progress_percentage' => ($downloaded / $downloadTotal) * 100,
                    'downloaded_bytes' => $downloaded,
                    'total_bytes' => $downloadTotal,
                    'status' => $downloaded >= $downloadTotal ? 'completed' : 'downloading'
                ]);
            }
        });
        
        $success = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        
        if (!$success) {
            throw new Exception('Download failed');
        }
        
        return $savePath;
    }
    
    public function deleteFile(string $fileId): bool 
    {
        try {
            $this->makeApiRequest('/files/delete_v2', 'POST', ['path' => $fileId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getFileInfo(string $fileId): array 
    {
        $response = $this->makeApiRequest('/files/get_metadata', 'POST', ['path' => $fileId]);
        
        return [
            'id' => $response['id'],
            'name' => $response['name'],
            'size' => $response['size'],
            'mime_type' => $response['.tag'],
            'created_at' => $response['client_modified'],
            'modified_at' => $response['server_modified']
        ];
    }

    public function getAllFiles(int $limit)
    {
        $files = [];
        $cursor = null;
        $hasMore = true;

        while ($hasMore && count($files) < $limit) {
            $params = [
                'path' => '',
                'recursive' => false,
                'include_media_info' => false,
                'include_deleted' => false,
                'include_has_explicit_shared_members' => false,
                'include_mounted_folders' => true,
                'limit' => min(2000, $limit) 
            ];

            if ($cursor) {
                $response = $this->makeApiRequest('/files/list_folder/continue', 'POST', ['cursor' => $cursor]);
            } else {
                $response = $this->makeApiRequest('/files/list_folder', 'POST', $params);
            }

            foreach ($response['entries'] as $entry) {
               
                if ($entry['.tag'] !== 'file') {
                    continue;
                }

                $files[] = [
                    'id' => $entry['path_display'],
                    'name' => $entry['name'],
                    'size' => $entry['size'],
                    'mimeType' => $entry['.tag'],
                    'createdTime' => $entry['client_modified'],
                    'modifiedTime' => $entry['server_modified'],
                    'parents' => [dirname($entry['path_display'])]
                ];

                if (count($files) >= $limit) {
                    break 2;
                }
            }

            $hasMore = $response['has_more'];
            if ($hasMore) {
                $cursor = $response['cursor'];
            }
        }

        return $files;
    }
    
    public function getRemainingStorage(): array 
    {
        $response = $this->makeApiRequest('/users/get_space_usage', 'POST');
        
        $used = $response['used'];
        $allocation = $response['allocation']['allocated'];
        
        return [
            'total' => $allocation,
            'used' => $used,
            'remaining' => $allocation - $used,
            'percentage_used' => ($used / $allocation) * 100
        ];
    }
    
    public function getAccountInfo(): array 
    {
        $response = $this->makeApiRequest('/users/get_current_account', 'POST');
        
        return [
            'id' => $response['account_id'],
            'email' => $response['email'],
            'name' => $response['name']['display_name'],
            'picture' => $response['profile_photo_url'] ?? null,
            'verified_email' => $response['email_verified']
        ];
    }
    
    private function makeApiRequest(string $endpoint, string $method = 'POST', array $data = null) 
    {
        if (!$this->accessToken) {
            throw new Exception('No access token set');
        }
        
        $ch = curl_init($this->apiUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        $headers = ["Authorization: Bearer " . $this->accessToken];
        
        if ($data !== null) {
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API request failed with code $httpCode: $response");
        }
        
        return json_decode($response, true);
    }
}