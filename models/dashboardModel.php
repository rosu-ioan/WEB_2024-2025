<?php

class DashboardModel {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    public function getFileUploadStatus($userId, $fileId) {
        try {
            $stmt = $this->db->prepare("
                SELECT uploaded
                FROM files
                WHERE user_id = ? AND file_id = ?
            ");
            $stmt->execute([$userId, $fileId]);
            $result = $stmt->fetch();
            if ($result) {
                return ['uploaded' => (bool)$result['uploaded']];
            }
            return null;
        } catch (PDOException $e) {
            error_log("DashboardModel::getFileUploadStatus Error: " . $e->getMessage());
            return null;
        }
    }
    
    
    public function getFilesAndFolders($userId, $path) {
        try {
            $directoryId = $this->getDirectoryIdByPath($userId, $path);
            
            $files = [];
            
            if ($directoryId === null) {
                $stmt = $this->db->prepare("
                    SELECT 
                        directory_id as id,
                        'folder' as type,
                        SUBSTRING_INDEX(directory_path, '/', -1) as name,
                        NULL as size,
                        created_at as modified
                    FROM directories 
                    WHERE user_id = ? AND parent_directory_id IS NULL
                    ORDER BY directory_path ASC
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT 
                        directory_id as id,
                        'folder' as type,
                        SUBSTRING_INDEX(directory_path, '/', -1) as name,
                        NULL as size,
                        created_at as modified
                    FROM directories 
                    WHERE user_id = ? AND parent_directory_id = ?
                    ORDER BY directory_path ASC
                ");
                $stmt->execute([$userId, $directoryId]);
            }
            $folders = $stmt->fetchAll();
            
            foreach ($folders as &$folder) {
                $folder['icon'] = '📁';
            }
            
            if ($directoryId === null) {
                $stmt = $this->db->prepare("
                    SELECT 
                        file_id as id,
                        'file' as type,
                        original_filename as name,
                        file_size as size,
                        created_at as modified,
                        uploaded
                    FROM files 
                    WHERE user_id = ? AND directory_id IS NULL
                    ORDER BY original_filename ASC
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT 
                        file_id as id,
                        'file' as type,
                        original_filename as name,
                        file_size as size,
                        created_at as modified,
                        uploaded
                    FROM files 
                    WHERE user_id = ? AND directory_id = ?
                    ORDER BY original_filename ASC
                ");
                $stmt->execute([$userId, $directoryId]);
            }
            $fileResults = $stmt->fetchAll();
            
            foreach ($fileResults as &$file) {
                $file['icon'] = $this->getFileIcon($file['name']);
                $file['size'] = $this->formatFileSize($file['size']);
            }
            
            return array_merge($folders, $fileResults);
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getFilesAndFolders Error: " . $e->getMessage());
            return [];
        }
    }
    
   
    public function uploadFile($userId, $fileData, $path) {
        try {
            $this->db->beginTransaction();
            
            $directoryId = $this->getDirectoryIdByPath($userId, $path);
            
            if ($directoryId === null) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM files 
                    WHERE user_id = ? AND directory_id IS NULL AND original_filename = ?
                ");
                $stmt->execute([$userId, $fileData['name']]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM files 
                    WHERE user_id = ? AND directory_id = ? AND original_filename = ?
                ");
                $stmt->execute([$userId, $directoryId, $fileData['name']]);
            }
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'error' => 'File with same name already exists'
                ];
            }
            
            $fileSize = $fileData['size'];
            $threshold = 50 * 1024 * 1024;
            
            if ($fileSize < $threshold) {
                return $this->uploadFileLocal($userId, $directoryId, $fileData);
            } else {
                return $this->uploadFileCloud($userId, $directoryId, $fileData);
            }
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("DashboardModel::uploadFile Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to upload file'
            ];
        }
    }

    private function uploadFileLocal($userId, $directoryId, $fileData) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO files (user_id, directory_id, original_filename, file_size, total_chunks, is_cached, uploaded, created_at)
                VALUES (?, ?, ?, ?, 1, 1, 1, NOW())
            ");
            $stmt->execute([$userId, $directoryId, $fileData['name'], $fileData['size']]);
            $fileId = $this->db->lastInsertId();
            
            $fileContent = file_get_contents($fileData['temp_path']);
            if ($fileContent === false) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Failed to read uploaded file'
                ];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO file_cache (file_id, chunk_index, chunk_data)
                VALUES (?, 0, ?)
            ");
            $stmt->execute([$fileId, $fileContent]);
            
            $stmt = $this->db->prepare("
                UPDATE users 
                SET total_storage_used = total_storage_used + ? 
                WHERE user_id = ?
            ");
            $stmt->execute([$fileData['size'], $userId]);
            
            $this->db->commit();
            
            error_log("DashboardModel: Small file uploaded to local storage, ID: $fileId");
            return [
                'success' => true,
                'file' => [
                    'id' => $fileId,
                    'name' => $fileData['name'],
                    'size' => $this->formatFileSize($fileData['size']),
                    'type' => 'file',
                    'icon' => $this->getFileIcon($fileData['name'])
                ]
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("DashboardModel::uploadFileLocal Error: " . $e->getMessage());
            throw $e;
        }
    }
   
    private function uploadFileCloud($userId, $directoryId, $fileData) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO files (user_id, directory_id, original_filename, file_size, total_chunks, is_cached, uploaded, created_at)
                VALUES (?, ?, ?, ?, 1, 0, 0, NOW())
            ");
            $stmt->execute([$userId, $directoryId, $fileData['name'], $fileData['size']]);
            $fileId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            $cloudResult = $this->uploadFileToCloud($userId, $fileId, $fileData['temp_path']);
            
            if ($cloudResult['success']) {
                $this->setFileCloudStatus($fileId, true, false);
                
                error_log("DashboardModel: Large file uploaded to cloud, ID: $fileId");
                return [
                    'success' => true,
                    'file' => [
                        'id' => $fileId,
                        'name' => $fileData['name'],
                        'size' => $this->formatFileSize($fileData['size']),
                        'type' => 'file',
                        'icon' => $this->getFileIcon($fileData['name'])
                    ]
                ];
            } else {
                $stmt = $this->db->prepare("DELETE FROM files WHERE file_id = ?");
                $stmt->execute([$fileId]);
                
                return [
                    'success' => false,
                    'error' => 'Failed to upload to cloud storage: ' . $cloudResult['error']
                ];
            }
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("DashboardModel::uploadFileCloud Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    
    public function uploadFileToCloud($userId, $fileId, $tempFilePath) {

        $refreshAllUserTokens_result = $this->refreshAllUserTokens($userId);
        error_log("=== REFRESH ALL USER TOKENS RESULT ===". json_encode($refreshAllUserTokens_result)."SPER CA O MERS");

        error_log("=== UPLOAD FILE TO CLOUD START ===");
        error_log("User ID: $userId, File ID: $fileId, Temp Path: $tempFilePath");
        
        try {
            $profileModel = new ProfileModel();
            $providers = $profileModel->getConnectedProviders($userId);
            
            $connectedProviders = array_filter($providers, function($provider) {
                return !empty($provider['account_id']);
            });
            
            if (empty($connectedProviders)) {
                error_log("ERROR: No connected cloud providers found for user $userId");
                return ['success' => false, 'error' => 'No cloud storage providers connected'];
            }
            
            error_log("Found " . count($connectedProviders) . " connected providers");
            
            $fileSize = filesize($tempFilePath);
            if ($fileSize === false) {
                return ['success' => false, 'error' => 'Could not determine file size'];
            }
            
            foreach ($connectedProviders as $provider) {
                error_log("Trying provider: " . $provider['provider_name'] . " (Priority: " . $provider['priority_rank'] . ")");
                
                $availableSpace = $provider['storage_max'] - $provider['storage_used'];
                error_log("Available space: " . $availableSpace . " bytes, Required: " . $fileSize . " bytes");
                
                if ($availableSpace >= $fileSize) {
                    error_log("Provider has sufficient space - attempting upload");
                    
                    $result = $this->uploadToSingleProvider($tempFilePath, $provider, $fileId);
                    if ($result['success']) {
                        $profileModel->refreshProviderStorageInfo($userId, $provider['account_id']);
                        error_log("=== UPLOAD FILE TO CLOUD SUCCESS ===");
                        return $result;
                    } else {
                        error_log("Upload to provider failed: " . $result['error']);
                    }
                } else {
                    error_log("Provider does not have sufficient space");
                }
            }
            
            error_log("Attempting split upload across multiple providers");
            return $this->uploadSplitFile($tempFilePath, $connectedProviders, $fileId, $fileSize);
            
        } catch (Exception $e) {
            error_log("=== UPLOAD FILE TO CLOUD EXCEPTION ===");
            error_log("Exception: " . $e->getMessage());
            error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
            return ['success' => false, 'error' => 'Upload to cloud failed: ' . $e->getMessage()];
        }
    }
   
    private function uploadToSingleProvider($filePath, $provider, $fileId, $userId = null) {
    error_log("=== UPLOAD TO SINGLE PROVIDER START ===");
    error_log("Provider: " . $provider['provider_name']);
    error_log("User ID: " . ($userId ?: 'NOT PROVIDED'));
    error_log("Account ID: " . $provider['account_id']);

    try {
        if (!$userId) {
            $stmt = $this->db->prepare("SELECT user_id FROM user_cloud_accounts WHERE account_id = ?");
            $stmt->execute([$provider['account_id']]);
            $result = $stmt->fetch();
            
            if (!$result) {
                throw new Exception("Could not find user for account " . $provider['account_id']);
            }
            
            $userId = $result['user_id'];
            error_log("Retrieved user ID from database: $userId");
        }
        
        $validTokenInfo = $this->ensureValidCloudAccess($userId, $provider['account_id']);
        
        $providerClass = $provider['provider_class'];
        if (!class_exists($providerClass)) {
            throw new Exception("Provider class not found: $providerClass");
        }
        
        $providerInstance = new $providerClass();
        
        $tokens = [
            'access_token' => $validTokenInfo['access_token']
        ];
        if (!empty($validTokenInfo['refresh_token'])) {
            $tokens['refresh_token'] = $validTokenInfo['refresh_token'];
        }
        
        $providerInstance->setAccessToken($tokens);
        
        $fileName = basename($filePath) . '_' . $fileId . '_' . time();
        
        if ($provider['provider_name'] === 'Dropbox') {
            $fileName = '/' . $fileName;
        }
        
        error_log("Uploading file as: $fileName");
        
        $uploadResult = $providerInstance->uploadFile($fileName, $filePath);
        
        error_log("Upload result: " . print_r($uploadResult, true));
        
        $stmt = $this->db->prepare("
            INSERT INTO file_chunks (file_id, account_id, chunk_index, chunk_size, cloud_file_id, cloud_file_path, created_at)
            VALUES (?, ?, 0, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $fileId,
            $provider['account_id'],
            filesize($filePath),
            $uploadResult['id'],
            $fileName
        ]);
        
        error_log("=== UPLOAD TO SINGLE PROVIDER SUCCESS ===");
        return [
            'success' => true,
            'provider' => $provider['provider_name'],
            'cloud_file_id' => $uploadResult['id']
        ];
        
    } catch (Exception $e) {
        error_log("=== UPLOAD TO SINGLE PROVIDER ERROR ===");
        error_log("Exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
   
    private function uploadSplitFile($filePath, $providers, $fileId, $fileSize) {
        error_log("=== UPLOAD SPLIT FILE START ===");
        error_log("File size: $fileSize bytes, Providers: " . count($providers));
        
        try {
            $fileHandle = fopen($filePath, 'rb');
            if (!$fileHandle) {
                throw new Exception("Could not open file for reading: $filePath");
            }
            
            if (!file_exists($filePath)) {
                throw new Exception("Source file does not exist: $filePath");
            }
            
            $actualSize = filesize($filePath);
            if ($actualSize === false) {
                throw new Exception("Could not determine file size: $filePath");
            }
            
            if ($actualSize != $fileSize) {
                error_log("WARNING: File size mismatch. Expected: $fileSize, Actual: $actualSize");
                $fileSize = $actualSize; 
            }
            
            $locations = [];
            $chunkNumber = 0;
            $remainingSize = $fileSize;
            $totalBytesRead = 0;
            
            foreach ($providers as $provider) {
                if ($remainingSize <= 0) break;
                
                $availableSpace = $provider['storage_max'] - $provider['storage_used'];
                $chunkSizeForProvider = min($remainingSize, $availableSpace);
                
                if ($chunkSizeForProvider <= 0) {
                    error_log("Provider {$provider['provider_name']} has no available space");
                    continue;
                }
                
                error_log("Creating chunk $chunkNumber of size $chunkSizeForProvider for provider {$provider['provider_name']}");
                error_log("Current file position: " . ftell($fileHandle) . ", Remaining size: $remainingSize");
                
                $chunkTempPath = sys_get_temp_dir() . '/chunk_' . $fileId . '_' . $chunkNumber . '_' . uniqid() . '.tmp';
                $chunkHandle = fopen($chunkTempPath, 'wb');
                
                if (!$chunkHandle) {
                    throw new Exception("Could not create chunk temp file: $chunkTempPath");
                }
                
                try {
                    $bytesToRead = $chunkSizeForProvider;
                    $bufferSize = 8 * 1024 * 1024; 
                    $chunkBytesWritten = 0;
                    
                    while ($bytesToRead > 0 && $totalBytesRead < $fileSize) {
                        $readSize = min($bytesToRead, $bufferSize);
                        
                        if (feof($fileHandle)) {
                            error_log("Reached end of file unexpectedly. Total read: $totalBytesRead, Expected: $fileSize");
                            break;
                        }
                        
                        $chunkData = fread($fileHandle, $readSize);
                        
                        if ($chunkData === false) {
                            throw new Exception("Failed to read from source file at position " . ftell($fileHandle));
                        }
                        
                        $actualReadSize = strlen($chunkData);
                        if ($actualReadSize === 0) {
                            if (!feof($fileHandle)) {
                                throw new Exception("Unexpected empty read from file at position " . ftell($fileHandle));
                            }
                            error_log("Reached end of file naturally");
                            break;
                        }
                        
                        if (fwrite($chunkHandle, $chunkData) === false) {
                            throw new Exception("Failed to write to chunk temp file");
                        }
                        
                        $bytesToRead -= $actualReadSize;
                        $chunkBytesWritten += $actualReadSize;
                        $totalBytesRead += $actualReadSize;
                        
                        error_log("Read $actualReadSize bytes, chunk written: $chunkBytesWritten, total read: $totalBytesRead");
                    }
                    
                    fclose($chunkHandle);
                    
                    if ($chunkBytesWritten === 0) {
                        error_log("No data written to chunk - skipping upload");
                        unlink($chunkTempPath);
                        continue;
                    }
                    
                    error_log("Chunk $chunkNumber created with $chunkBytesWritten bytes");
                    
                    $result = $this->uploadToSingleProvider($chunkTempPath, $provider, $fileId . '_chunk_' . $chunkNumber);
                    
                    if ($result['success']) {
                        $stmt = $this->db->prepare("
                            UPDATE file_chunks 
                            SET chunk_index = ?, chunk_size = ?
                            WHERE file_id = ? AND cloud_file_id = ?
                        ");
                        $stmt->execute([
                            $chunkNumber,
                            $chunkBytesWritten,
                            $fileId,
                            $result['cloud_file_id']
                        ]);
                        
                        $locations[] = [
                            'provider' => $provider['provider_name'],
                            'chunk_order' => $chunkNumber,
                            'chunk_size' => $chunkBytesWritten,
                            'cloud_file_id' => $result['cloud_file_id']
                        ];
                        
                        $remainingSize -= $chunkBytesWritten;
                        $chunkNumber++;
                        
                        error_log("Chunk $chunkNumber uploaded successfully to {$provider['provider_name']}");
                    } else {
                        error_log("Failed to upload chunk to {$provider['provider_name']}: " . $result['error']);
                        fclose($fileHandle);
                        unlink($chunkTempPath);
                        return ['success' => false, 'error' => 'Failed to upload chunk to ' . $provider['provider_name'] . ': ' . $result['error']];
                    }
                    
                } finally {
                    if (file_exists($chunkTempPath)) {
                        unlink($chunkTempPath);
                    }
                }
            }
            
            fclose($fileHandle);
            
            if ($remainingSize > 0) {
                error_log("ERROR: Could not upload entire file - $remainingSize bytes remaining, total read: $totalBytesRead");
                return ['success' => false, 'error' => "Incomplete upload - $remainingSize bytes remaining. Total processed: $totalBytesRead bytes"];
            }
            
            error_log("=== UPLOAD SPLIT FILE SUCCESS ===");
            return [
                'success' => true,
                'is_split' => true,
                'chunks' => count($locations),
                'locations' => $locations
            ];
            
        } catch (Exception $e) {
            if (isset($fileHandle) && is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            error_log("=== UPLOAD SPLIT FILE ERROR ===");
            error_log("Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    
    public function getFileToTemp($fileId, $userId) {
        error_log("=== GET FILE TO TEMP START ===");
        error_log("File ID: $fileId");
        
        try {
            $stmt = $this->db->prepare("
                SELECT is_cached, uploaded, original_filename, file_size FROM files WHERE file_id = ?
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();
            
            if (!$file) {
                error_log("ERROR: File not found: $fileId");
                return null;
            }
            
            error_log("File found: {$file['original_filename']}");
            error_log("File size: {$file['file_size']} bytes");
            error_log("Is cached: " . ($file['is_cached'] ? 'YES' : 'NO'));
            error_log("Is uploaded: " . ($file['uploaded'] ? 'YES' : 'NO'));
            
            if ($file['is_cached'] == 1) {
                error_log("File $fileId is cached locally - using local download");
                $tempPath = $this->getFileFromLocalCache($fileId);
            } else if ($file['uploaded'] == 1) {
                error_log("File $fileId is in cloud and uploaded - downloading from cloud");
                $tempPath = $this->downloadFileFromCloud($fileId, $userId);
            } else {
                error_log("ERROR: File $fileId is still uploading to cloud - download not available");
                return null;
            }
            
            if ($tempPath && file_exists($tempPath)) {
                $downloadedSize = filesize($tempPath);
                error_log("Downloaded temp file: $tempPath");
                error_log("Downloaded temp file size: $downloadedSize bytes");
                
                if ($downloadedSize != $file['file_size']) {
                    error_log("WARNING: Downloaded size mismatch! Expected: {$file['file_size']}, Got: $downloadedSize");
                }
                
                $testContent = file_get_contents($tempPath, false, null, 0, 50);
                error_log("First 50 bytes readable: " . (strlen($testContent)) . " bytes");
            } else {
                error_log("ERROR: No temp file returned or file doesn't exist");
            }
            
            error_log("=== GET FILE TO TEMP END ===");
            return $tempPath;
            
        } catch (PDOException $e) {
            error_log("=== GET FILE TO TEMP ERROR ===");
            error_log("Database error: " . $e->getMessage());
            return null;
        }
    }
    
    
    private function getFileFromLocalCache($fileId) {
        try {
            $stmt = $this->db->prepare("
                SELECT chunk_data 
                FROM file_cache 
                WHERE file_id = ? AND chunk_index = 0
            ");
            $stmt->execute([$fileId]);
            $result = $stmt->fetch();
            
            if (!$result) {
                return null;
            }
            
            $tempPath = sys_get_temp_dir() . '/download_' . uniqid();
            if (file_put_contents($tempPath, $result['chunk_data']) === false) {
                return null;
            }
            
            return $tempPath;
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getFileFromLocalCache Error: " . $e->getMessage());
            return null;
        }
    }
    
    public function downloadFileFromCloud($fileId, $userId) {
        error_log("=== DOWNLOAD FILE FROM CLOUD START ===");
        error_log("File ID: $fileId");

        $refreshAllUserTokens_result = $this->refreshAllUserTokens($userId);
        error_log("=== REFRESH ALL USER TOKENS RESULT ===". json_encode($refreshAllUserTokens_result)."SPER CA O MERS");
        
        try {
            $stmt = $this->db->prepare("SELECT original_filename, file_size FROM files WHERE file_id = ?");
            $stmt->execute([$fileId]);
            $fileInfo = $stmt->fetch();
            
            if ($fileInfo) {
                error_log("Expected file: {$fileInfo['original_filename']}, Size: {$fileInfo['file_size']} bytes");
            }
            
            $locations = $this->getFileCloudLocations($fileId);
            
            if (empty($locations)) {
                error_log("ERROR: No cloud locations found for file $fileId");
                return null;
            }
            
            error_log("Found " . count($locations) . " cloud location(s)");
            foreach ($locations as $i => $location) {
                error_log("Location $i: Account ID: {$location['account_id']}, Chunk: {$location['chunk_index']}, Size: {$location['chunk_size']}, Cloud ID: {$location['cloud_file_id']}");
            }
            
            if (count($locations) === 1) {
                error_log("Single provider download");
                return $this->downloadFromSingleProvider($locations[0]);
            } else {
                error_log("Multi-provider download and reassembly");
                return $this->downloadAndReassemble($locations);
            }
            
        } catch (Exception $e) {
            error_log("=== DOWNLOAD FILE FROM CLOUD ERROR ===");
            error_log("Exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }
    
  
    private function downloadFromSingleProvider($location) {
        error_log("=== DOWNLOAD FROM SINGLE PROVIDER START ===");
        error_log("Account ID: " . $location['account_id']);
        
        try {
            $stmt = $this->db->prepare("
                SELECT uca.user_id, uca.access_token, uca.refresh_token, 
                    cp.provider_class, cp.provider_name
                FROM user_cloud_accounts uca
                JOIN cloud_providers cp ON uca.provider_id = cp.provider_id
                WHERE uca.account_id = ?
            ");
            $stmt->execute([$location['account_id']]);
            $providerInfo = $stmt->fetch();
            
            if (!$providerInfo) {
                throw new Exception("Provider info not found for account " . $location['account_id']);
            }
            
            $validTokenInfo = $this->ensureValidCloudAccess($providerInfo['user_id'], $location['account_id']);
            
            error_log("Provider: " . $providerInfo['provider_name']);
            error_log("Provider class: " . $providerInfo['provider_class']);
            
            $providerClass = $providerInfo['provider_class'];
            if (!class_exists($providerClass)) {
                throw new Exception("Provider class not found: $providerClass");
            }
            
            $providerInstance = new $providerClass();
            
            $tokens = [
                'access_token' => $validTokenInfo['access_token']
            ];
            if (!empty($validTokenInfo['refresh_token'])) {
                $tokens['refresh_token'] = $validTokenInfo['refresh_token'];
            }
            
            error_log("Setting access token with verified/refreshed token");
            $providerInstance->setAccessToken($tokens);
            
            error_log("Downloading file ID: " . $location['cloud_file_id']);
            $downloadPath = $providerInstance->downloadFile($location['cloud_file_id']);
            
            if (!$downloadPath || !file_exists($downloadPath)) {
                error_log("ERROR: Download failed or file doesn't exist at path: " . ($downloadPath ?: 'null'));
                return null;
            }
            
            $actualSize = filesize($downloadPath);
            error_log("Downloaded file path: $downloadPath");
            error_log("Downloaded file size: $actualSize bytes");
            error_log("Expected file size: " . $location['chunk_size'] . " bytes");
            
            if ($actualSize != $location['chunk_size']) {
                error_log("WARNING: Downloaded size doesn't match expected size!");
            }
            
            error_log("=== DOWNLOAD FROM SINGLE PROVIDER SUCCESS ===");
            return $downloadPath;
            
        } catch (Exception $e) {
            error_log("=== DOWNLOAD FROM SINGLE PROVIDER ERROR ===");
            error_log("Exception: " . $e->getMessage());
            return null;
        }
    }
  
    private function downloadAndReassemble($locations) {
        error_log("=== DOWNLOAD AND REASSEMBLE START ===");
        error_log("Locations: " . count($locations));
        
        try {
            usort($locations, function($a, $b) {
                return $a['chunk_index'] - $b['chunk_index'];
            });
            
            $finalTempPath = sys_get_temp_dir() . '/reassembled_' . uniqid();
            $finalHandle = fopen($finalTempPath, 'wb');
            
            if (!$finalHandle) {
                throw new Exception("Could not create final temp file");
            }
            
            try {
                foreach ($locations as $location) {
                    error_log("Downloading chunk " . $location['chunk_index']);
                    
                    $chunkPath = $this->downloadFromSingleProvider($location);
                    if (!$chunkPath || !file_exists($chunkPath)) {
                        throw new Exception("Failed to download chunk " . $location['chunk_index']);
                    }
                    
                    $chunkData = file_get_contents($chunkPath);
                    if ($chunkData === false) {
                        throw new Exception("Failed to read chunk " . $location['chunk_index']);
                    }
                    
                    if (fwrite($finalHandle, $chunkData) === false) {
                        throw new Exception("Failed to write chunk " . $location['chunk_index']);
                    }
                    
                    unlink($chunkPath);
                    
                    error_log("Chunk " . $location['chunk_index'] . " reassembled successfully");
                }
                
            } finally {
                fclose($finalHandle);
            }
            
            error_log("=== DOWNLOAD AND REASSEMBLE SUCCESS ===");
            return $finalTempPath;
            
        } catch (Exception $e) {
            if (isset($finalHandle) && is_resource($finalHandle)) {
                fclose($finalHandle);
            }
            if (isset($finalTempPath) && file_exists($finalTempPath)) {
                unlink($finalTempPath);
            }
            
            error_log("=== DOWNLOAD AND REASSEMBLE ERROR ===");
            error_log("Exception: " . $e->getMessage());
            return null;
        }
    }
  
    public function getFileCloudLocations($fileId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    fc.account_id,
                    fc.chunk_index,
                    fc.chunk_size,
                    fc.cloud_file_id,
                    fc.cloud_file_path
                FROM file_chunks fc
                WHERE fc.file_id = ?
                ORDER BY fc.chunk_index ASC
            ");
            $stmt->execute([$fileId]);
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getFileCloudLocations Error: " . $e->getMessage());
            return [];
        }
    }
    
    
    public function setFileCloudStatus($fileId, $isUploaded, $isCached) {
        try {
            $stmt = $this->db->prepare("
                UPDATE files 
                SET uploaded = ?, is_cached = ?, updated_at = NOW()
                WHERE file_id = ?
            ");
            
            return $stmt->execute([
                $isUploaded ? 1 : 0,
                $isCached ? 1 : 0,
                $fileId
            ]);
            
        } catch (PDOException $e) {
            error_log("DashboardModel::setFileCloudStatus Error: " . $e->getMessage());
            return false;
        }
    }

    
    public function generateBreadcrumb($path) {
        $breadcrumb = [];
        
        $breadcrumb[] = ['name' => 'Home', 'path' => '/'];
        
        if ($path !== '/' && !empty(trim($path, '/'))) {
            $segments = explode('/', trim($path, '/'));
            $currentPath = '';
            
            foreach ($segments as $segment) {
                if (!empty($segment)) {
                    $currentPath .= '/' . $segment;
                    $breadcrumb[] = [
                        'name' => $segment,
                        'path' => $currentPath
                    ];
                }
            }
        }
        
        return $breadcrumb;
    }
    
    
    public function validateUserPath($userId, $path) {
        try {
            if ($path === '/') {
                return true;
            }
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM directories 
                WHERE user_id = ? AND directory_path = ?
            ");
            $stmt->execute([$userId, $path]);
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (PDOException $e) {
            error_log("DashboardModel::validateUserPath Error: " . $e->getMessage());
            return false;
        }
    }
    
   
    public function validateUserFiles($userId, $fileIds) {
        try {
            if (empty($fileIds)) return false;
            
            $placeholders = str_repeat('?,', count($fileIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM files 
                WHERE user_id = ? AND file_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$userId], $fileIds));
            $result = $stmt->fetch();
            
            return $result['count'] == count($fileIds);
            
        } catch (PDOException $e) {
            error_log("DashboardModel::validateUserFiles Error: " . $e->getMessage());
            return false;
        }
    }
    
    
    public function getFileById($userId, $fileId) {
        try {
            $stmt = $this->db->prepare("
                SELECT file_id, original_filename as name, file_size, uploaded, is_cached
                FROM files 
                WHERE user_id = ? AND file_id = ?
            ");
            $stmt->execute([$userId, $fileId]);
            
            return $stmt->fetch() ?: null;
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getFileById Error: " . $e->getMessage());
            return null;
        }
    }
    
   
    public function getFilesByIds($userId, $fileIds) {
        try {
            if (empty($fileIds)) return [];
            
            $placeholders = str_repeat('?,', count($fileIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT file_id, original_filename as name, file_size, uploaded, is_cached
                FROM files 
                WHERE user_id = ? AND file_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$userId], $fileIds));
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getFilesByIds Error: " . $e->getMessage());
            return [];
        }
    }
   
    public function createFolder($userId, $folderName, $currentPath) {
        try {
            $newPath = rtrim($currentPath, '/') . '/' . $folderName;
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM directories 
                WHERE user_id = ? AND directory_path = ?
            ");
            $stmt->execute([$userId, $newPath]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                return [
                    'success' => false,
                    'error' => 'Folder already exists'
                ];
            }
            
            $parentDirectoryId = $this->getDirectoryIdByPath($userId, $currentPath);
            
            $stmt = $this->db->prepare("
                INSERT INTO directories (user_id, parent_directory_id, directory_path, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $parentDirectoryId, $newPath]);
            $directoryId = $this->db->lastInsertId();
            
            error_log("DashboardModel: Folder created successfully, ID: $directoryId");
            return [
                'success' => true,
                'folder' => [
                    'id' => $directoryId,
                    'name' => $folderName,
                    'type' => 'folder',
                    'icon' => '📁'
                ]
            ];
            
        } catch (PDOException $e) {
            error_log("DashboardModel::createFolder Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to create folder'
            ];
        }
    }
    
    
    public function renameItem($userId, $itemId, $newName) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                SELECT file_id, original_filename
                FROM files 
                WHERE user_id = ? AND file_id = ?
            ");
            $stmt->execute([$userId, $itemId]);
            $file = $stmt->fetch();
            
            if ($file) {
                $stmt = $this->db->prepare("
                    UPDATE files 
                    SET original_filename = ?, updated_at = NOW()
                    WHERE user_id = ? AND file_id = ?
                ");
                $stmt->execute([$newName, $userId, $itemId]);
                
                $this->db->commit();
                error_log("DashboardModel: File renamed successfully, ID: $itemId");
                
                return [
                    'success' => true,
                    'item' => [
                        'id' => $itemId,
                        'name' => $newName,
                        'type' => 'file',
                        'icon' => $this->getFileIcon($newName)
                    ]
                ];
            }
            
            $stmt = $this->db->prepare("
                SELECT directory_id, directory_path
                FROM directories 
                WHERE user_id = ? AND directory_id = ?
            ");
            $stmt->execute([$userId, $itemId]);
            $folder = $stmt->fetch();
            
            if ($folder) {
                $oldPath = $folder['directory_path'];
                $newPath = dirname($oldPath) . '/' . $newName;
                if (dirname($oldPath) === '.') {
                    $newPath = '/' . $newName;
                }
                
                $stmt = $this->db->prepare("
                    UPDATE directories 
                    SET directory_path = ?, updated_at = NOW()
                    WHERE user_id = ? AND directory_id = ?
                ");
                $stmt->execute([$newPath, $userId, $itemId]);
                
                $stmt = $this->db->prepare("
                    UPDATE directories 
                    SET directory_path = REPLACE(directory_path, ?, ?), updated_at = NOW()
                    WHERE user_id = ? AND directory_path LIKE ?
                ");
                $stmt->execute([$oldPath, $newPath, $userId, $oldPath . '/%']);
                
                $this->db->commit();
                error_log("DashboardModel: Folder renamed successfully, ID: $itemId");
                
                return [
                    'success' => true,
                    'item' => [
                        'id' => $itemId,
                        'name' => $newName,
                        'type' => 'folder',
                        'icon' => '📁'
                    ]
                ];
            }
            
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Item not found'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("DashboardModel::renameItem Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to rename item'
            ];
        }
    }
    
   
    public function validateUserItems($userId, $itemIds) {
        try {
            if (empty($itemIds)) return false;
            
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            
            $stmt = $this->db->prepare("
                SELECT file_id as id FROM files 
                WHERE user_id = ? AND file_id IN ($placeholders)
                UNION
                SELECT directory_id as id FROM directories 
                WHERE user_id = ? AND directory_id IN ($placeholders)
            ");
            $params = array_merge([$userId], $itemIds, [$userId], $itemIds);
            $stmt->execute($params);
            
            $validIds = $stmt->fetchAll();
            
            return count($validIds) == count($itemIds);
            
        } catch (PDOException $e) {
            error_log("DashboardModel::validateUserItems Error: " . $e->getMessage());
            return false;
        }
    }
    
   
    public function deleteItems($userId, $itemIds) {
        try {
            $this->db->beginTransaction();
            
            $deletedCount = 0;
            
            foreach ($itemIds as $itemId) {
                $stmt = $this->db->prepare("
                    SELECT file_id, file_size, is_cached 
                    FROM files 
                    WHERE user_id = ? AND file_id = ?
                ");
                $stmt->execute([$userId, $itemId]);
                $file = $stmt->fetch();
                
                if ($file) {
                    if ($file['is_cached'] == 0) {
                        $this->deleteFileFromCloud($itemId, $userId);
                    }
                    
                    $stmt = $this->db->prepare("DELETE FROM file_cache WHERE file_id = ?");
                    $stmt->execute([$itemId]);
                    
                    $stmt = $this->db->prepare("DELETE FROM file_chunks WHERE file_id = ?");
                    $stmt->execute([$itemId]);
                    
                    $stmt = $this->db->prepare("DELETE FROM files WHERE user_id = ? AND file_id = ?");
                    $stmt->execute([$userId, $itemId]);
                    
                    if ($file['is_cached'] == 1) {
                        $stmt = $this->db->prepare("
                            UPDATE users 
                            SET total_storage_used = total_storage_used - ? 
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$file['file_size'], $userId]);
                    }
                    
                    $deletedCount++;
                    continue;
                }
                
                $stmt = $this->db->prepare("
                    SELECT directory_id, directory_path 
                    FROM directories 
                    WHERE user_id = ? AND directory_id = ?
                ");
                $stmt->execute([$userId, $itemId]);
                $folder = $stmt->fetch();
                
                if ($folder) {
                    $this->deleteFilesInPath($userId, $folder['directory_path']);
                    
                    $stmt = $this->db->prepare("
                        DELETE FROM directories 
                        WHERE user_id = ? AND (directory_id = ? OR directory_path LIKE ?)
                    ");
                    $stmt->execute([$userId, $itemId, $folder['directory_path'] . '/%']);
                    
                    $deletedCount++;
                }
            }
            
            $this->db->commit();
            error_log("DashboardModel: Deleted $deletedCount items successfully");
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("DashboardModel::deleteItems Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to delete items'
            ];
        }
    }
    
    private function deleteFileFromCloud($fileId, $userId) {
        error_log("=== DELETE FILE FROM CLOUD START ===");
        error_log("File ID: $fileId");

        $refreshAllUserTokens_result = $this->refreshAllUserTokens($userId);
        error_log("=== REFRESH ALL USER TOKENS RESULT ===". json_encode($refreshAllUserTokens_result)."SPER CA O MERS");
        
        try {
            $locations = $this->getFileCloudLocations($fileId);
            
            foreach ($locations as $location) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT uca.access_token, uca.refresh_token, cp.provider_class
                        FROM user_cloud_accounts uca
                        JOIN cloud_providers cp ON uca.provider_id = cp.provider_id
                        WHERE uca.account_id = ?
                    ");
                    $stmt->execute([$location['account_id']]);
                    $providerInfo = $stmt->fetch();
                    
                    if ($providerInfo) {
                        $providerClass = $providerInfo['provider_class'];
                        if (class_exists($providerClass)) {
                            $providerInstance = new $providerClass();
                            
                            $tokens = ['access_token' => $providerInfo['access_token']];
                            if (!empty($providerInfo['refresh_token'])) {
                                $tokens['refresh_token'] = $providerInfo['refresh_token'];
                            }
                            $providerInstance->setAccessToken($tokens);
                            
                            $result = $providerInstance->deleteFile($location['cloud_file_id']);
                            error_log("Delete from cloud result: " . ($result ? 'SUCCESS' : 'FAILED'));
                        }
                    }
                    
                } catch (Exception $e) {
                    error_log("Error deleting chunk from cloud: " . $e->getMessage());
                }
            }
            
            error_log("=== DELETE FILE FROM CLOUD COMPLETE ===");
            
        } catch (Exception $e) {
            error_log("=== DELETE FILE FROM CLOUD ERROR ===");
            error_log("Exception: " . $e->getMessage());
        }
    }
    
    
    private function getFileIcon($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return match($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg' => '🖼️',
            'pdf' => '📄',
            'doc', 'docx' => '📝',
            'xls', 'xlsx' => '📊',
            'ppt', 'pptx' => '📊',
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv' => '🎥',
            'mp3', 'wav', 'flac', 'aac', 'ogg' => '🎵',
            'zip', 'rar', '7z', 'tar', 'gz' => '🗜️',
            'txt', 'md', 'rtf' => '📄',
            'html', 'css', 'js', 'php', 'py', 'java', 'cpp' => '💻',
            'exe', 'msi', 'deb', 'dmg' => '⚙️',
            default => '📄'
        };
    }
    
    
    private function getDirectoryIdByPath($userId, $path) {
        if ($path === '/') {
            return null; 
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT directory_id 
                FROM directories 
                WHERE user_id = ? AND directory_path = ?
            ");
            $stmt->execute([$userId, $path]);
            $result = $stmt->fetch();
            
            return $result ? $result['directory_id'] : null;
            
        } catch (PDOException $e) {
            error_log("DashboardModel::getDirectoryIdByPath Error: " . $e->getMessage());
            return null;
        }
    }
    
  
    private function deleteFilesInPath($userId, $path) {
        try {
            $stmt = $this->db->prepare("
                SELECT f.file_id, f.file_size, f.is_cached
                FROM files f
                JOIN directories d ON f.directory_id = d.directory_id
                WHERE f.user_id = ? AND (d.directory_path = ? OR d.directory_path LIKE ?)
            ");
            $stmt->execute([$userId, $path, $path . '/%']);
            $files = $stmt->fetchAll();
            
            $totalSize = 0;
            
            foreach ($files as $file) {
                if ($file['is_cached'] == 0) {
                    $this->deleteFileFromCloud($file['file_id']);
                }
                
                $stmt = $this->db->prepare("DELETE FROM file_cache WHERE file_id = ?");
                $stmt->execute([$file['file_id']]);
                
                $stmt = $this->db->prepare("DELETE FROM file_chunks WHERE file_id = ?");
                $stmt->execute([$file['file_id']]);
                
                $stmt = $this->db->prepare("DELETE FROM files WHERE file_id = ?");
                $stmt->execute([$file['file_id']]);
                
                if ($file['is_cached'] == 1) {
                    $totalSize += $file['file_size'];
                }
            }
            
            if ($totalSize > 0) {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET total_storage_used = total_storage_used - ? 
                    WHERE user_id = ?
                ");
                $stmt->execute([$totalSize, $userId]);
            }
            
        } catch (PDOException $e) {
            error_log("DashboardModel::deleteFilesInPath Error: " . $e->getMessage());
        }
    }
    
    
    private function formatFileSize($bytes) {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }


    public function verifyAndRefreshToken($userId, $accountId) {
    error_log("=== VERIFY AND REFRESH TOKEN START ===");
    error_log("User ID: $userId, Account ID: $accountId");
    
    try {
        $stmt = $this->db->prepare("
            SELECT uca.access_token, uca.refresh_token, uca.token_expires_at, 
                   cp.provider_class, cp.provider_name, uca.account_id
            FROM user_cloud_accounts uca
            JOIN cloud_providers cp ON uca.provider_id = cp.provider_id
            WHERE uca.user_id = ? AND uca.account_id = ?
        ");
        $stmt->execute([$userId, $accountId]);
        $tokenInfo = $stmt->fetch();
        
        if (!$tokenInfo) {
            error_log("ERROR: Token info not found for user $userId, account $accountId");
            return false;
        }
        
        error_log("Found token for provider: " . $tokenInfo['provider_name']);
        error_log("Token expires at: " . ($tokenInfo['token_expires_at'] ?: 'NULL'));
        
        $isExpired = $this->isTokenExpired($tokenInfo['token_expires_at']);
        error_log("Token expired: " . ($isExpired ? 'YES' : 'NO'));
        
        if (!$isExpired) {
            error_log("Token still valid, no refresh needed");
            return $tokenInfo;
        }
        
        if (!$tokenInfo['refresh_token']) {
            error_log("ERROR: No refresh token available for expired access token");
            return false;
        }
        
        error_log("Attempting to refresh token...");
        return $this->refreshExpiredToken($tokenInfo);
        
    } catch (Exception $e) {
        error_log("=== VERIFY AND REFRESH TOKEN ERROR ===");
        error_log("Exception: " . $e->getMessage());
        return false;
    }
}

private function isTokenExpired($tokenExpiresAt) {
    if (!$tokenExpiresAt) {
        error_log("No expiry time set - assuming token is valid");
        return false;
    }
    
    $currentTime = time();
    $expiryTime = strtotime($tokenExpiresAt);
    
    $bufferTime = 5 * 60; 
    
    $willExpireSoon = ($currentTime + $bufferTime) >= $expiryTime;
    
    error_log("Current time: " . date('Y-m-d H:i:s', $currentTime));
    error_log("Token expires: " . date('Y-m-d H:i:s', $expiryTime));
    error_log("Will expire in 5 minutes: " . ($willExpireSoon ? 'YES' : 'NO'));
    
    return $willExpireSoon;
}

private function refreshExpiredToken($tokenInfo) {
    error_log("=== REFRESH EXPIRED TOKEN START ===");
    error_log("Provider: " . $tokenInfo['provider_name']);
    
    try {
        $providerInstance = $this->createProviderInstance($tokenInfo['provider_class']);
        
        error_log("Calling refreshAccessToken on provider...");
        $newTokens = $providerInstance->refreshAccessToken($tokenInfo['refresh_token']);
        error_log("New tokens received: " . print_r($newTokens, true));

        $newExpiry = null;
        if (isset($tokens['expires_in'])) {
            $newExpiry = date('Y-m-d H:i:s',time() + $newTokens['expires_in']+3601);
            error_log("Token expiry calculated: $tokenExpiry and tokens['expires_in'] is " . ($newTokens['expires_in']+3601));
        } elseif (isset($tokens['expires_at'])) {
            $newExpiry = date('Y-m-d H:i:s',time() + $newTokens['expires_at']+3601);
            error_log("Token expiry set from expires_at: $tokenExpiry");
        }elseif (isset($tokens['expire_at'])) {
            $newExpiry = date('Y-m-d H:i:s',time() + $newTokens['expire_at']+3601);
            error_log("Token expiry set from expires_at: $tokenExpiry");
        }elseif (isset($tokens['expiry'])) {
            $newExpiry = date('Y-m-d H:i:s',time() + $newTokens['expiry']+3601);
            error_log("Token expiry set from expiry: $tokenExpiry");
        } else {
            error_log("No expires_in in new tokens - keeping old expiry");
        }
        
        $stmt = $this->db->prepare("
            UPDATE user_cloud_accounts 
            SET access_token = ?, 
                refresh_token = COALESCE(?, refresh_token),
                token_expires_at = ?,
                updated_at = NOW()
            WHERE account_id = ?
        ");
        
        $updateResult = $stmt->execute([
            $newTokens['access_token'],
            $newTokens['refresh_token'] ?? null,
            $newExpiry,
            $tokenInfo['account_id']
        ]);
        
        if (!$updateResult) {
            error_log("ERROR: Failed to update database with new tokens");
            error_log("SQL Error: " . print_r($stmt->errorInfo(), true));
            return false;
        }
        
        error_log("Token refresh successful - database updated");
        
        $updatedTokenInfo = array_merge($tokenInfo, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'] ?? $tokenInfo['refresh_token'],
            'token_expires_at' => $newExpiry
        ]);
        
        error_log("=== REFRESH EXPIRED TOKEN SUCCESS ===");
        return $updatedTokenInfo;
        
    } catch (Exception $e) {
        error_log("=== REFRESH EXPIRED TOKEN ERROR ===");
        error_log("Exception: " . $e->getMessage());
        error_log("Provider class: " . $tokenInfo['provider_class']);
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

private function createProviderInstance($providerClass) {
    error_log("Creating provider instance: $providerClass");
    
    if (!class_exists($providerClass)) {
        throw new Exception("Provider class not found: $providerClass");
    }
    
    return new $providerClass();
}


public function getValidToken($userId, $accountId) {
    error_log("=== GET VALID TOKEN START ===");
    error_log("User ID: $userId, Account ID: $accountId");
    
    $tokenInfo = $this->verifyAndRefreshToken($userId, $accountId);
    
    if (!$tokenInfo) {
        error_log("ERROR: Could not get valid token");
        return null;
    }
    
    error_log("Valid token obtained for provider: " . $tokenInfo['provider_name']);
    return [
        'access_token' => $tokenInfo['access_token'],
        'refresh_token' => $tokenInfo['refresh_token'] ?? null,
        'provider_class' => $tokenInfo['provider_class'],
        'provider_name' => $tokenInfo['provider_name']
    ];
}


public function refreshAllUserTokens($userId) {
    error_log("=== REFRESH ALL USER TOKENS START ===");
    error_log("User ID: $userId");
    
    try {
        $stmt = $this->db->prepare("
            SELECT account_id, provider_id 
            FROM user_cloud_accounts 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        $results = [];
        foreach ($accounts as $account) {
            error_log("Checking account ID: " . $account['account_id']);
            
            $result = $this->verifyAndRefreshToken($userId, $account['account_id']);
            $results[$account['account_id']] = [
                'success' => $result !== false,
                'provider_id' => $account['provider_id'],
                'refreshed' => $result !== false && $result !== true
            ];
            
            if ($result === false) {
                error_log("Failed to refresh token for account " . $account['account_id']);
            } else {
                error_log("Token check/refresh successful for account " . $account['account_id']);
            }
        }
        
        error_log("Token refresh complete for " . count($accounts) . " accounts");
        error_log("Results: " . print_r($results, true));
        
        return $results;
        
    } catch (Exception $e) {
        error_log("=== REFRESH ALL USER TOKENS ERROR ===");
        error_log("Exception: " . $e->getMessage());
        return [];
    }
}

private function ensureValidCloudAccess($userId, $accountId) {
    $tokenInfo = $this->verifyAndRefreshToken($userId, $accountId);
    
    if (!$tokenInfo) {
        throw new Exception("Could not obtain valid access token for account $accountId");
    }
    
    return $tokenInfo;
}
}