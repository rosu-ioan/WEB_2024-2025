<?php

require_once 'autoload.php';

use Dotenv\Dotenv;

class MicrosoftOneDriveProvider implements CloudProviderInterface 
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;
    private $tenant = 'common';
    private $apiBaseUrl = 'https://graph.microsoft.com/v1.0';
    private $scope = 'offline_access Files.Read Files.Read.All Files.ReadWrite Files.ReadWrite.All User.Read User.Read.All Sites.Read.All';
    
    public function __construct() 
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
        $dotenv->load();
        
        $this->clientId = $_ENV['AZURE_CLIENT_ID'];
        $this->clientSecret = $_ENV['AZURE_CLIENT_SECRET'];
        $this->redirectUri = 'http://localhost/WEB_2024-2025/profile/oauth-callback';
        
        error_log("Microsoft Client ID: " . ($this->clientId ?? 'NULL'));
        error_log("Microsoft Client Secret: " . (isset($this->clientSecret) ? 'SET' : 'NULL'));
        
        if (!$this->clientId || !$this->clientSecret) {
            throw new Exception('Microsoft Azure credentials not found in .env file');
        }
    }
    
    public function getAuthorizationUrl(): string 
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'response_mode' => 'query',
            'prompt' => 'consent' 

        ];
        
        return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/authorize?' . 
               http_build_query($params);
    }

    public function handleAuthCallback(string $authCode): array 
    {
        error_log("=== MICROSOFT ONEDRIVE handleAuthCallback START ===");
        error_log("Auth code received: " . substr($authCode, 0, 20) . "...");
        
        try {
            $params = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $authCode,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code'
            ];
            
            $ch = curl_init('https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log("cURL error: $error");
                throw new Exception('Token request failed: ' . $error);
            }
            
            if ($httpCode !== 200) {
                error_log("HTTP error: $httpCode, Response: $response");
                throw new Exception("Token request failed with HTTP $httpCode: $response");
            }
            
            $token = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('Invalid JSON response from Microsoft: ' . json_last_error_msg());
            }
            
            error_log("Raw token response from Microsoft: " . print_r($token, true));
            
            if (!isset($token['access_token'])) {
                error_log("No access_token in response");
                throw new Exception('Invalid token response: ' . $response);
            }
            
            $normalizedToken = [
                'access_token' => $token['access_token'],
                'token_type' => $token['token_type'] ?? 'Bearer',
                'refresh_token' => $token['refresh_token'] ?? null,
                'scope' => $token['scope'] ?? $this->scope
            ];
            
            if (isset($token['expires_in'])) {
                $expiresIn = (int)$token['expires_in'];
                error_log("Microsoft expires_in: $expiresIn seconds");
              
                if ($expiresIn > 0 && $expiresIn <= 86400) { 
                    $normalizedToken['expires_in'] = $expiresIn;
                } else {
                    error_log("WARNING: Invalid expires_in from Microsoft ($expiresIn), using default 3600");
                    $normalizedToken['expires_in'] = 3600; 
                }
            } else {
                error_log("WARNING: No expires_in from Microsoft, using default 3600");
                $normalizedToken['expires_in'] = 3600; 
            }
            
            if (isset($token['user_id'])) {
                $normalizedToken['account_id'] = $token['user_id'];
            }
            
            if (isset($token['ext_expires_in'])) {
                $normalizedToken['ext_expires_in'] = $token['ext_expires_in'];
            }
            
            error_log("Normalized Microsoft token: " . print_r($normalizedToken, true));
            error_log("=== MICROSOFT ONEDRIVE handleAuthCallback SUCCESS ===");
            
            return $normalizedToken;
            
        } catch (Exception $e) {
            error_log("=== MICROSOFT ONEDRIVE handleAuthCallback ERROR ===");
            error_log("Exception: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function refreshAccessToken(string $refreshToken): array 
    {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ];
        
        $ch = curl_init('https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/token');
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
            $this->makeApiRequest('/me/drive');
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
    
    private function simpleUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $content = file_get_contents($filePath);
        $response = $this->makeApiRequest(
            "/me/drive/root:/". rawurlencode($fileName) .":/content",
            'PUT',
            $content,
            ['Content-Type: application/octet-stream']
        );
        
        return [
            'id' => $response['id'],
            'name' => $response['name'],
            'size' => $response['size'],
            'mime_type' => $response['file']['mimeType'] ?? 'application/octet-stream'
        ];
    }
    
    private function complexUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
        $token = $this->accessToken;

        $url = "https://graph.microsoft.com/v1.0/me/drive/root:/". rawurlencode($fileName) .":/createUploadSession";
        $data = json_encode([
            "item" => [
                "@microsoft.graph.conflictBehavior" => "rename",
                "description" => "Upload via resumableUpload()",
                "name" => $fileName
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "Content-Type: application/json",
                "Cache-Control: no-cache",
                "Pragma: no-cache"
            ],

            CURLOPT_TIMEOUT => 300 
        ]);

        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($result['uploadUrl'])) {
            throw new Exception('Upload session creation failed: ' . json_encode($result));
        }

        $uploadUrl = $result['uploadUrl'];
        $fileSize = filesize($filePath);
        $chunkSize = 60*1024 * 1024;
        $numChunks = ceil($fileSize / $chunkSize);
        $uploaded = 0;

        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            throw new Exception("Could not open file for reading: $filePath");
        }

        for ($i = 0; $i < $numChunks; $i++) {
            $start = $i * $chunkSize;
            $end = min($start + $chunkSize, $fileSize) - 1;
            $length = $end - $start + 1;

            fseek($fp, $start);
            $chunk = fread($fp, $length);

            $headers = [
                "Content-Length: $length",
                "Content-Range: bytes $start-$end/$fileSize"
            ];

            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $chunk,
                
                CURLOPT_TIMEOUT => 300
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                fclose($fp);
                throw new Exception("Chunk upload failed: HTTP $httpCode - $response");
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

            if (in_array($httpCode, [200, 201])) {
                $finalResult = json_decode($response, true);
                fclose($fp);
                return [
                    'id' => $finalResult['id'] ?? null,
                    'name' => $finalResult['name'] ?? $fileName,
                    'size' => $finalResult['size'] ?? $fileSize,
                    'mime_type' => $finalResult['file']['mimeType'] ?? 'application/octet-stream'
                ];
            }
        }

        fclose($fp);
        throw new Exception('Upload completed but no final metadata received.');
    }
    
    public function downloadFile(string $fileId, string $savePath = null, callable $progressCallback = null): string 
    {
        error_log("=== MICROSOFT ONEDRIVE DOWNLOAD START ===");
        error_log("File ID: $fileId");
        
        try {
            $fileInfo = $this->getFileInfo($fileId);
            error_log("File info retrieved: " . print_r($fileInfo, true));
            
            if ($savePath === null) {
                $safeFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileInfo['name']);
                $savePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . 
                        DIRECTORY_SEPARATOR . 
                        'download_' . 
                        preg_replace('/[^a-zA-Z0-9_-]/', '', $fileId) . '_' . 
                        $safeFileName;
            }

            $fp = fopen($savePath, 'wb');
            if (!$fp) {
                throw new Exception("Could not open file for writing: $savePath");
            }

            error_log("Starting direct download from Graph API...");

            $headers = [
                'Authorization: Bearer ' . $this->accessToken,
                'User-Agent: PHP-OneDrive-Client/1.0'
            ];

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => $this->apiBaseUrl . "/me/drive/items/$fileId/content",
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true, 
                CURLOPT_NOPROGRESS => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_MAXREDIRS => 5, 
                CURLOPT_TIMEOUT => 300 
            ];

            curl_setopt_array($ch, $curlOptions);

            $success = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $downloadedBytes = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            curl_close($ch);
            fclose($fp);

            error_log("Download complete - Success: " . ($success ? 'YES' : 'NO'));
            error_log("HTTP Code: $httpCode");
            error_log("Downloaded bytes: $downloadedBytes");
            error_log("Effective URL: $effectiveUrl");

            if (!$success || $httpCode >= 400) {
                if (file_exists($savePath)) {
                    unlink($savePath);
                }
                throw new Exception('Download failed: ' . ($error ?: "HTTP $httpCode"));
            }

            $actualSize = filesize($savePath);
            error_log("Final file size: $actualSize bytes");

            if ($actualSize === 0) {
                unlink($savePath);
                throw new Exception('Download failed: File is empty');
            }

            error_log("=== MICROSOFT ONEDRIVE DOWNLOAD SUCCESS ===");
            return $savePath;

        } catch (Exception $e) {
            error_log("=== MICROSOFT ONEDRIVE DOWNLOAD ERROR ===");
            error_log("Exception: " . $e->getMessage());
            
            if (isset($fp) && is_resource($fp)) {
                fclose($fp);
            }
            if (isset($savePath) && file_exists($savePath)) {
                unlink($savePath);
            }
            throw $e;
        }
    }

    public function deleteFile(string $fileId): bool 
    {
        try {
            $this->ensureAuthenticated();

            $this->makeApiRequest(
                "/me/drive/items/{$fileId}",
                'DELETE'
            );

            return true;

        } catch (Exception $e) {
            error_log("Delete file error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getFileInfo(string $fileId): array 
    {
        $response = $this->makeApiRequest("/me/drive/items/$fileId");
        
        return [
            'id' => $response['id'],
            'name' => $response['name'],
            'size' => $response['size'],
            'mime_type' => $response['file']['mimeType'] ?? 'application/octet-stream',
            'created_at' => $response['createdDateTime'],
            'modified_at' => $response['lastModifiedDateTime']
        ];
    }

    public function getAllFiles(int $limit): array
    {
        $this->ensureAuthenticated();

        try {
            $files = [];
            $endpoint = "/me/drive/root/children";
            $params = [
                '$top' => min($limit, 999),
                '$select' => 'id,name,size,file,mimeType,createdDateTime,lastModifiedDateTime,webUrl,parentReference',
                '$orderby' => 'lastModifiedDateTime desc'
            ];

            $nextLink = $endpoint . '?' . http_build_query($params);

            do {
                $response = $this->makeApiRequest($nextLink);
                
                if (!isset($response['value']) || !is_array($response['value'])) {
                    throw new Exception('Invalid response format from OneDrive API');
                }

                foreach ($response['value'] as $file) {
                    if (!isset($file['file'])) {
                        continue;
                    }

                    $files[] = [
                        'id' => $file['id'],
                        'name' => $file['name'],
                        'size' => $file['size'] ?? 0,
                        'mimeType' => $file['file']['mimeType'] ?? 'application/octet-stream',
                        'createdTime' => $file['createdDateTime'],
                        'modifiedTime' => $file['lastModifiedDateTime'],
                        'webViewLink' => $file['webUrl'] ?? null,
                        'parents' => isset($file['parentReference']['id']) ? [$file['parentReference']['id']] : []
                    ];

                    if (count($files) >= $limit) {
                        break 2;
                    }
                }

                if (isset($response['@odata.nextLink'])) {
                    $urlParts = parse_url($response['@odata.nextLink']);
                    $nextLink = $urlParts['path'];
                    if (isset($urlParts['query'])) {
                        $nextLink .= '?' . $urlParts['query'];
                    }
                    $nextLink = str_replace($this->apiBaseUrl, '', $nextLink);
                } else {
                    $nextLink = null;
                }

            } while ($nextLink !== null && count($files) < $limit);

            return $files;

        } catch (Exception $e) {
            error_log('Error in getAllFiles: ' . $e->getMessage());
            throw new Exception('Failed to get all files: ' . $e->getMessage());
        }
    }
    
    public function getRemainingStorage(): array 
    {
        $response = $this->makeApiRequest('/me/drive');
        $quota = $response['quota'];
        
        $total = $quota['total'];
        $used = $quota['used'];
        $remaining = $quota['remaining'];
        
        return [
            'total' => $total,
            'used' => $used,
            'remaining' => $remaining,
            'percentage_used' => ($used / $total) * 100
        ];
    }
    
    public function getAccountInfo(): array 
    {
        $response = $this->makeApiRequest('/me');
        
        return [
            'id' => $response['id'],
            'email' => $response['userPrincipalName'],
            'name' => $response['displayName'],
            'picture' => null,
            'verified_email' => true
        ];
    }
    
    private function makeApiRequest(string $endpoint, string $method = 'GET', $data = null, array $headers = [], bool $returnHeaders = false) 
    {
        if (!$this->accessToken) {
            throw new Exception('No access token set');
        }
        
        $url = $this->apiBaseUrl . $endpoint;
        $ch = curl_init($url);
        
        $defaultHeaders = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json'
        ];
        
        if ($data && $method !== 'PUT') {
            $defaultHeaders[] = 'Content-Type: application/json';
        }
        
        $finalHeaders = array_merge($defaultHeaders, $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        if ($data) {
            if ($method !== 'PUT') {
                $data = json_encode($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        
        if ($returnHeaders) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API request failed with code $httpCode: $response");
        }
        
        if ($returnHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            if (preg_match('/Location: (.+)/', $headers, $matches)) {
                return trim($matches[1]);
            }
        }
        
        if ($method === 'DELETE') {
            return true;
        }
        
        if ($response && !$returnHeaders) {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to decode API response: ' . json_last_error_msg());
            }
            return $decoded;
        }
        
        return $response;
    }

    private function ensureAuthenticated(): void 
    {
        if (!$this->accessToken) {
            throw new Exception('Provider not authenticated. Call setAccessToken() first.');
        }

        try {
            $this->makeApiRequest('/me/drive');
        } catch (Exception $e) {
            throw new Exception('Access token has expired or is invalid. Please refresh the token.');
        }
    }
}