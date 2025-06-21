<?php

require_once 'autoload.php';

use Dotenv\Dotenv;

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
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
        $dotenv->load();
        
        $this->clientId = $_ENV['DROPBOX_APP_KEY'];
        $this->clientSecret = $_ENV['DROPBOX_APP_SECRET'];
        $this->redirectUri = 'http://localhost/WEB_2024-2025/profile/oauth-callback';
        
        error_log("Dropbox Client ID: " . ($this->clientId ?? 'NULL'));
        error_log("Dropbox Client Secret: " . (isset($this->clientSecret) ? 'SET' : 'NULL'));
        
        if (!$this->clientId || !$this->clientSecret) {
            throw new Exception('Dropbox credentials not found in .env file');
        }
    }
    
    public function getAuthorizationUrl(): string 
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'token_access_type' => 'offline',
            'scope' => 'files.metadata.read files.content.read files.content.write account_info.read'
        ];
        
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);
    }
    
    public function handleAuthCallback(string $authCode): array 
    {
        error_log("=== DROPBOX handleAuthCallback START ===");
        error_log("Auth code received: " . $authCode);

        $params = [
        'code' => $authCode,
        'grant_type' => 'authorization_code',
        'client_id' => $this->clientId,
        'client_secret' => $this->clientSecret,
        'redirect_uri' => $this->redirectUri,
        'scope' => 'files.metadata.read files.content.read files.content.write account_info.read'
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

        error_log("Raw token response from Dropbox: " . print_r($token, true));

        if (!isset($token['access_token'])) {
            throw new Exception('Invalid token response: ' . $response);
        } else {
            $expiresIn = (int)$token['expires_in'];
            error_log("Dropbox expires_in: $expiresIn seconds");
        }

        error_log("=== DROPBOX handleAuthCallback SUCCESS ===");
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

        if ($fileSize < 60 * 1024 * 1024) {
            return $this->simpleUpload($fileName, $filePath, $progressCallback);
        }

        return $this->complexUpload($fileName, $filePath, $progressCallback);
    }
    
    public function simpleUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new Exception("Could not determine file size");
        }

            $fileHandle = fopen($filePath, 'rb');
            if (!$fileHandle) {
                throw new Exception("Could not open file for reading");
            }

            $args = json_encode([
                "path" => $fileName,
                "mode" => "add",
                "autorename" => true,
                "mute" => false
            ]);

            $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_INFILE => $fileHandle,
                CURLOPT_INFILESIZE => $fileSize,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $this->accessToken,
                    "Dropbox-API-Arg: " . $args,
                    "Content-Type: application/octet-stream"
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            fclose($fileHandle);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Upload failed. HTTP code: $httpCode, Error: $error, Response: " . $response);
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }

            return [
                'id' => $result['id'],
                'name' => $result['name'],
                'size' => $result['size'],
                'path' => $result['path_display']
            ];
    }
    
    private function complexUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $fileSize = filesize($filePath);
        $chunkSize = 60 * 1024 * 1024; 
        $numChunks = ceil($fileSize / $chunkSize);
        $uploaded = 0;
        $sessionId = null;

        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            throw new Exception("Could not open file for reading: $filePath");
        }

        try {
            for ($i = 0; $i < $numChunks; $i++) {
                $start = $i * $chunkSize;
                $end = min($start + $chunkSize, $fileSize) - 1;
                $length = $end - $start + 1;

                fseek($fp, $start);
                $chunk = fread($fp, $length);

                if ($i === 0) {
                    $ch = curl_init($this->contentUrl . '/files/upload_session/start');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $chunk,
                        CURLOPT_HTTPHEADER => [
                            "Authorization: Bearer " . $this->accessToken,
                            "Dropbox-API-Arg: " . json_encode(["close" => false]),
                            "Content-Type: application/octet-stream"
                        ]
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode !== 200) {
                        throw new Exception("Upload session start failed: HTTP $httpCode - $response");
                    }

                    $result = json_decode($response, true);
                    $sessionId = $result['session_id'];

                } else {
                    $args = json_encode([
                        "cursor" => [
                            "session_id" => $sessionId,
                            "offset" => $start
                        ],
                        "close" => false
                    ]);

                    $ch = curl_init($this->contentUrl . '/files/upload_session/append_v2');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $chunk,
                        CURLOPT_HTTPHEADER => [
                            "Authorization: Bearer " . $this->accessToken,
                            "Dropbox-API-Arg: " . $args,
                            "Content-Type: application/octet-stream"
                        ]
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode !== 200) {
                        throw new Exception("Chunk upload failed: HTTP $httpCode - $response");
                    }
                }

                $uploaded += $length;

                if ($progressCallback) {
                    $progressCallback([
                        'progress_percentage' => round(($uploaded / $fileSize) * 100, 2),
                        'uploaded_bytes' => $uploaded,
                        'total_bytes' => $fileSize,
                        'status' => ($uploaded >= $fileSize) ? 'completed' : 'uploading'
                    ]);
                }
            }

            $args = json_encode([
                "cursor" => [
                    "session_id" => $sessionId,
                    "offset" => $uploaded
                ],
                "commit" => [
                    "path" => $fileName,
                    "mode" => "add",
                    "autorename" => true,
                    "mute" => false
                ]
            ]);

            $ch = curl_init($this->contentUrl . '/files/upload_session/finish');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => "",
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $this->accessToken,
                    "Dropbox-API-Arg: " . $args,
                    "Content-Type: application/octet-stream"
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Upload finish failed: HTTP $httpCode - $response");
            }

            $finalResult = json_decode($response, true);
            fclose($fp);

            return [
                'id' => $finalResult['id'],
                'name' => $finalResult['name'],
                'size' => $finalResult['size'],
                'mime_type' => $finalResult['.tag'] ?? 'application/octet-stream'
            ];

        } catch (Exception $e) {
            fclose($fp);
            throw $e;
        }
    }
    
    public function downloadFile(string $fileId, string $savePath = null, callable $progressCallback = null): string 
    {
        $decodedFileId = urldecode($fileId);

        try {
            $metadata = $this->makeApiRequest('/files/get_metadata', 'POST', ['path' => $decodedFileId]);
            $originalFileName = $metadata['name'];
            $fileSize = $metadata['size'];
        } catch (Exception $e) {
            throw new Exception("Failed to get file metadata: " . $e->getMessage());
        }

        if ($savePath === null) {
            $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
            $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalFileName, PATHINFO_FILENAME));
            $savePath = sys_get_temp_dir() . 
                    DIRECTORY_SEPARATOR . 
                    'download_' . 
                    preg_replace('/[^a-zA-Z0-9_-]/', '_', $decodedFileId) . 
                    '_' . $baseName .
                    ($extension ? '.' . $extension : '');
        }

        $saveDir = dirname($savePath);
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0777, true);
        }

        $fp = fopen($savePath, 'wb');
        if (!$fp) {
            throw new Exception("Could not open file for writing: $savePath");
        }

        try {
            $ch = curl_init($this->contentUrl . '/files/download');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $this->accessToken,
                    "Dropbox-API-Arg: " . json_encode(["path" => $decodedFileId])
                ],
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function($ch, $downloadTotal, $downloaded) use ($progressCallback, $fileSize) {
                    if ($progressCallback) {
                        $progressCallback([
                            'progress_percentage' => ($downloaded / $fileSize) * 100,
                            'downloaded_bytes' => $downloaded,
                            'total_bytes' => $fileSize,
                            'status' => $downloaded >= $fileSize ? 'completed' : 'downloading'
                        ]);
                    }
                }
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (!$success || $httpCode !== 200) {
                throw new Exception("Download failed: " . ($error ?: "HTTP $httpCode"));
            }

            $downloadedSize = filesize($savePath);
            if ($downloadedSize !== $fileSize) {
                throw new Exception("Downloaded file size ($downloadedSize) does not match expected size ($fileSize)");
            }

            return $savePath;

        } catch (Exception $e) {
            fclose($fp);
            if (file_exists($savePath)) {
                unlink($savePath);
            }
            throw $e;
        }
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

    public function getAllFiles(int $limit):array
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
                    'id' => $entry['id'],
                    'name' => $entry['name'],
                    'size' => $entry['size'],
                    'mimeType' => $entry['.tag'],
                    'createdTime' => $entry['client_modified'],
                    'modifiedTime' => $entry['server_modified'],
                    'parents' => [dirname($entry['name'])]
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
        
        $photoUrl = null;
        if (isset($response['profile_photo_url'])) {
            try {
                $ch = curl_init($response['profile_photo_url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $photoData = curl_exec($ch);
                curl_close($ch);
                
                if ($photoData) {
                    $photoUrl = 'data:image/jpeg;base64,' . base64_encode($photoData);
                }
            } catch (Exception $e) {
                $photoUrl = null;
            }
        }
        
        return [
            'id' => $response['account_id'],
            'email' => $response['email'],
            'name' => $response['name']['display_name'],
            'picture' => $photoUrl,
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