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
    private int $chunkSize = 256 * 1024; 
    
    public function __construct() 
    {
        $scopes = [
            'https://www.googleapis.com/auth/drive.file',//sau drive pur si simplu pt toate fisierele
            'https://www.googleapis.com/auth/userinfo.email'
        ];
        $this->basePath = '/';

        $this->client = new Client();
        $this->client->setAuthConfig(CREDENTIALS_PATH);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account');
        $this->client->setScopes($scopes);
    }
    
    public function getAuthorizationUrl(): string 
    {
        return $this->client->createAuthUrl();
    }
    
    public function handleAuthCallback(string $authCode): array 
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($authCode);
        
        if (isset($token['error'])) {
            throw new Exception('OAuth error: ' . ($token['error_description'] ?? $token['error']));
        }
        
        return $token;
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
        $this->client->setAccessToken($tokenData);
        $this->driveService = new Drive($this->client);
        $this->oauth2Service = new Oauth2($this->client);
    }
    
    public function isTokenValid(): bool 
    {
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
                $savePath = sys_get_temp_dir() . '/' . 'download_' . $fileId . '_' . $fileName;
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

    public function getAllFiles(int $limit) {
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
        $this->ensureAuthenticated();
        
        try {
            $about = $this->driveService->about->get(['fields' => 'storageQuota']);
            $quota = $about->getStorageQuota();
            
            $total = (int)$quota->getLimit();
            $used = (int)$quota->getUsage();
            $remaining = $total - $used;
            
            return [
                'total' => $total,
                'used' => $used,
                'remaining' => $remaining,
                'percentage_used' => $total > 0 ? ($used / $total) * 100 : 0
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to get storage info: ' . $e->getMessage());
        }
    }
    
    public function getAccountInfo(): array 
    {
        $this->ensureAuthenticated();
        
        try {
            $userInfo = $this->oauth2Service->userinfo->get();
            
            return [
                'id' => $userInfo->getId(),
                'email' => $userInfo->getEmail(),
                'name' => $userInfo->getName(),
                'picture' => $userInfo->getPicture(),
                'verified_email' => $userInfo->getVerifiedEmail()
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to get account info: ' . $e->getMessage());
        }
    }
    
    private function ensureAuthenticated(): void 
    {
        if (!$this->driveService) {
            throw new Exception('Provider not authenticated. Call setAccessToken() first.');
        }
        
        if ($this->client->isAccessTokenExpired()) {
            throw new Exception('Access token has expired. Please refresh the token.');
        }
    }
}