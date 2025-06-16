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

class MicrosoftOneDriveProvider implements CloudProviderInterface 
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;
    private $tenant = 'common';
    private $apiBaseUrl = 'https://graph.microsoft.com/v1.0';
    private $scope = 'offline_access Files.Read Files.Read.All Files.ReadWrite Files.ReadWrite.All User.Read';
    
    public function __construct() 
    {
       
        $this->clientId = $_ENV['AZURE_CLIENT_ID'];
        $this->clientSecret = $_ENV['AZURE_CLIENT_SECRET'];
        $this->redirectUri = 'http://localhost/WEB_2024-2025/microsoftonedrive/login';
    }
    
    public function getAuthorizationUrl(): string 
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'response_mode' => 'query'
        ];
        
        return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/authorize?' . 
               http_build_query($params);
    }
    
    public function handleAuthCallback(string $authCode): array 
    {
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
        
       
        if ($fileSize < 4 * 1024 * 1024) {
            return $this->simpleUpload($fileName, $filePath, $progressCallback);
        }
        
       
        return $this->resumableUpload($fileName, $filePath, $progressCallback);
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
    
    private function resumableUpload(string $fileName, string $filePath, callable $progressCallback = null): array 
    {
       
        $response = $this->makeApiRequest(
            "/me/drive/root:/". rawurlencode($fileName) .":/createUploadSession",
            'POST'
        );
        
        if (!isset($response['uploadUrl'])) {
            throw new Exception('Failed to create upload session');
        }
        
        $uploadUrl = $response['uploadUrl'];
        $fileSize = filesize($filePath);
        $chunkSize = 320 * 1024;
        
        $fp = fopen($filePath, 'rb');
        $uploaded = 0;
        
        while (!feof($fp)) {
            $chunk = fread($fp, $chunkSize);
            $chunkLength = strlen($chunk);
            
            $start = $uploaded;
            $end = $uploaded + $chunkLength - 1;
            
            $ch = curl_init($uploadUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Length: $chunkLength",
                "Content-Range: bytes $start-$end/$fileSize"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 400) {
                throw new Exception("Upload failed with code $httpCode: $response");
            }
            
            $uploaded += $chunkLength;
            
            if ($progressCallback) {
                $progressCallback([
                    'progress_percentage' => ($uploaded / $fileSize) * 100,
                    'uploaded_bytes' => $uploaded,
                    'total_bytes' => $fileSize,
                    'status' => $uploaded >= $fileSize ? 'completed' : 'uploading'
                ]);
            }
            
            if ($httpCode === 201 || $httpCode === 200) {
                $result = json_decode($response, true);
                return [
                    'id' => $result['id'],
                    'name' => $result['name'],
                    'size' => $result['size'],
                    'mime_type' => $result['file']['mimeType'] ?? 'application/octet-stream'
                ];
            }
        }
        
        fclose($fp);
        throw new Exception('Upload failed to complete');
    }
    
    public function downloadFile(string $fileId, string $savePath = null, callable $progressCallback = null): string 
    {
        $fileInfo = $this->getFileInfo($fileId);
        $downloadUrl = $this->makeApiRequest("/me/drive/items/$fileId/content", 'GET', null, [], true);
        
        if ($savePath === null) {
            $savePath = sys_get_temp_dir() . '/download_' . $fileId . '_' . $fileInfo['name'];
        }
        
        $fp = fopen($savePath, 'wb');
        if (!$fp) {
            throw new Exception("Could not open file for writing: $savePath");
        }
        
        $ch = curl_init($downloadUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
            $this->makeApiRequest("/me/drive/items/$fileId", 'DELETE');
            return true;
        } catch (Exception $e) {
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

    public function getAllFiles(int $limit)
    {
        $files = [];
        $endpoint = "/me/drive/root/children";
        $params = [
            '$top' => $limit,
            '$select' => 'id,name,size,file,createdDateTime,lastModifiedDateTime,parentReference',
            '$orderby' => 'name'
        ];

        $nextLink = $endpoint . '?' . http_build_query($params);

        try {
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
            throw new Exception('Failed to retrieve files: ' . $e->getMessage());
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
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        
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
        
        if ($returnHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            
           
            if (preg_match('/Location: (.+)/', $headers, $matches)) {
                return trim($matches[1]);
            }
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API request failed with code $httpCode: $response");
        }
        
        if ($method === 'DELETE') {
            return true;
        }
        
        if ($response && !$returnHeaders) {
            return json_decode($response, true);
        }
        
        return $response;
    }
}