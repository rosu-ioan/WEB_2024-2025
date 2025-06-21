<?php

require_once __DIR__ . '/../utils/autoload.php';

class DashboardController extends AbstractController {
    
    public function __construct($isApiRequest = false) {
        parent::__construct($isApiRequest);
    }
    
    public function executeAction($action, $params) {
        $currentUser = AuthUtils::getCurrentUser();
        if (!$currentUser) {
            if ($this->isApiRequest) {
                http_response_code(401);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Authentication required'
                ], true);
            } else {
                header('Location: ' . $this->getBasePath() . 'auth/login');
                exit;
            }
        }
        
        switch ($action) {
            case 'index':
                return $this->index($currentUser);
            case 'navigate':
                return $this->navigate($currentUser);
            case 'upload':
                return $this->upload($currentUser);
            case 'download':
                return $this->download($currentUser);
            case 'create-folder':
                return $this->createFolder($currentUser);
            case 'rename':
                return $this->rename($currentUser);
            case 'delete':
                return $this->delete($currentUser);
            case 'file-status':
                return $this->fileStatus($currentUser);
            default:
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
            $currentPath = $_GET['path'] ?? '/';
            
            $files = $this->model->getFilesAndFolders($user['user_id'], $currentPath);
            
            $breadcrumb = $this->model->generateBreadcrumb($currentPath);
            
            return $this->view->renderResponse([
                'user' => $user,
                'files' => $files,
                'current_path' => $currentPath,
                'breadcrumb' => $breadcrumb
            ], false);
            
        } catch (Exception $e) {
            error_log("Dashboard index error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'user' => $user,
                'files' => [],
                'current_path' => '/',
                'breadcrumb' => [['name' => 'Home', 'path' => '/']],
                'error' => 'Unable to load files'
            ], false);
        }
    }
    
  
    public function navigate($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $path = $_POST['path'] ?? '/';
            
            if (!$this->model->validateUserPath($user['user_id'], $path)) {
                http_response_code(403);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Access denied'
                ], true);
            }
            
            $files = $this->model->getFilesAndFolders($user['user_id'], $path);
            $breadcrumb = $this->model->generateBreadcrumb($path);
            
            return $this->view->renderResponse([
                'success' => true,
                'files' => $files,
                'breadcrumb' => $breadcrumb,
                'current_path' => $path
            ], true);
            
        } catch (Exception $e) {
            error_log("Dashboard navigate error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Unable to navigate to folder'
            ], true);
        }
    }
    
    public function upload($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $chunkNumber = (int)($_POST['chunk_number'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $fileName = $_POST['file_name'] ?? '';
            $fileSize = (int)($_POST['file_size'] ?? 0);
            $currentPath = $_POST['path'] ?? '/';
            $uploadId = $_POST['upload_id'] ?? '';
            
            if (empty($fileName) || empty($uploadId) || $chunkNumber < 0 || $totalChunks <= 0) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Invalid upload data'
                ], true);
            }
            
            
            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Chunk upload failed'
                ], true);
            }
            
            $chunkFile = $_FILES['chunk'];
            
            $uploadDir = sys_get_temp_dir() . '/uploads/' . $uploadId;
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    return $this->view->renderResponse([
                        'success' => false,
                        'error' => 'Failed to create upload directory'
                    ], true);
                }
            }
            
            $chunkPath = $uploadDir . '/chunk_' . str_pad($chunkNumber, 6, '0', STR_PAD_LEFT);
            if (!move_uploaded_file($chunkFile['tmp_name'], $chunkPath)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Failed to save chunk'
                ], true);
            }
            
            $uploadedChunks = glob($uploadDir . '/chunk_*');
            $uploadedCount = count($uploadedChunks);
            
            if ($uploadedCount === $totalChunks) {
                return $this->assembleChunkedFile($user, $uploadId, $fileName, $totalChunks, $currentPath, $uploadDir);
            } else {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Chunk uploaded successfully',
                    'chunks_received' => $uploadedCount,
                    'chunks_total' => $totalChunks,
                    'upload_complete' => false
                ], true);
            }
            
        } catch (Exception $e) {
            error_log("Dashboard upload error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Upload failed. Please try again.'
            ], true);
        }
    }
    
    
    private function assembleChunkedFile($user, $uploadId, $fileName, $totalChunks, $currentPath, $uploadDir) {
        $finalTempPath = sys_get_temp_dir() . '/assembled_' . uniqid();
        $finalFile = fopen($finalTempPath, 'wb');
        
        if (!$finalFile) {
            $this->cleanupUploadDir($uploadDir);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to create final file'
            ], true);
        }
        
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $uploadDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
                
                if (!file_exists($chunkPath)) {
                    throw new Exception("Missing chunk: $i");
                }
                
                $chunkData = file_get_contents($chunkPath);
                if ($chunkData === false) {
                    throw new Exception("Failed to read chunk: $i");
                }
                
                if (fwrite($finalFile, $chunkData) === false) {
                    throw new Exception("Failed to write chunk: $i");
                }
            }
            
            if (is_resource($finalFile)) {
                fclose($finalFile);
                $finalFile = null; 
            }
            
            $fileSize = filesize($finalTempPath);
            $fileType = mime_content_type($finalTempPath) ?: 'application/octet-stream';
            
            $result = $this->model->uploadFile($user['user_id'], [
                'name' => $fileName,
                'size' => $fileSize,
                'type' => $fileType,
                'temp_path' => $finalTempPath
            ], $currentPath);
            
            $this->cleanupUploadDir($uploadDir);
            if (file_exists($finalTempPath)) {
                unlink($finalTempPath);
            }
            
            if ($result['success']) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'File uploaded successfully',
                    'file' => $result['file'],
                    'file_id' => $result['file']['id'],
                    'upload_complete' => true
                ], true);
            } else {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], true);
            }
            
        } catch (Exception $e) {
            if (is_resource($finalFile)) {
                fclose($finalFile);
            }
            
            $this->cleanupUploadDir($uploadDir);
            if (file_exists($finalTempPath)) {
                unlink($finalTempPath);
            }
            
            error_log("Chunk assembly error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to assemble file chunks: ' . $e->getMessage()
            ], true);
        }
    }
    
    
    private function cleanupUploadDir($uploadDir) {
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($uploadDir);
        }
    }

   
    public function download($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $downloadId = $_POST['download_id'] ?? '';
            $chunkNumber = $_POST['chunk_number'] ?? null;
            $isChunkRequest = isset($_POST['chunk_request']) && $_POST['chunk_request'] === '1';
            
            if ($isChunkRequest && !empty($downloadId) && $chunkNumber !== null) {
                return $this->downloadChunk($user);
            }
            
            $fileIds = $_POST['file_ids'] ?? [];
            
            if (empty($fileIds)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'No files selected'
                ], true);
            }
            
            if (!$this->model->validateUserFiles($user['user_id'], $fileIds)) {
                http_response_code(403);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Access denied'
                ], true);
            }
            
            if (count($fileIds) === 1) {
                return $this->prepareChunkedDownload($user, $fileIds[0]);
            } else {
                return $this->prepareMultipleFileDownload($user, $fileIds);
            }
            
        } catch (Exception $e) {
            error_log("Dashboard download error: " . $e->getMessage());
            
            http_response_code(500);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Download failed'
            ], true);
        }
    }
    
    
    public function createFolder($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $folderName = trim($_POST['folder_name'] ?? '');
            $currentPath = $_POST['path'] ?? '/';
            
            if (empty($folderName)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Folder name is required'
                ], true);
            }
            
            if (!$this->isValidFileName($folderName)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Invalid folder name'
                ], true);
            }
            
            $result = $this->model->createFolder($user['user_id'], $folderName, $currentPath);
            
            if ($result['success']) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Folder created successfully',
                    'folder' => $result['folder']
                ], true);
            } else {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], true);
            }
            
        } catch (Exception $e) {
            error_log("Dashboard create folder error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to create folder'
            ], true);
        }
    }
    
  
    public function rename($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            $itemId = $_POST['item_id'] ?? '';
            $newName = trim($_POST['new_name'] ?? '');
            
            if (empty($itemId) || empty($newName)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Item ID and new name are required'
                ], true);
            }
            
            if (!$this->isValidFileName($newName)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Invalid file name'
                ], true);
            }
            
            $result = $this->model->renameItem($user['user_id'], $itemId, $newName);
            
            if ($result['success']) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Item renamed successfully',
                    'item' => $result['item']
                ], true);
            } else {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], true);
            }
            
        } catch (Exception $e) {
            error_log("Dashboard rename error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to rename item'
            ], true);
        }
    }
    
   
    public function delete($user) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }
        
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                $input = json_decode(file_get_contents('php://input'), true);
                $itemIds = $input['item_ids'] ?? [];
            } else {
                $itemIds = $_POST['item_ids'] ?? [];
            }
            
            if (empty($itemIds)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'No items selected'
                ], true);
            }
            
            if (!$this->model->validateUserItems($user['user_id'], $itemIds)) {
                http_response_code(403);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Access denied'
                ], true);
            }
            
            $result = $this->model->deleteItems($user['user_id'], $itemIds);
            
            if ($result['success']) {
                return $this->view->renderResponse([
                    'success' => true,
                    'message' => 'Items deleted successfully',
                    'deleted_count' => $result['deleted_count']
                ], true);
            } else {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $result['error']
                ], true);
            }
            
        } catch (Exception $e) {
            error_log("Dashboard delete error: " . $e->getMessage());
            
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to delete items'
            ], true);
        }
    }
    
  
    private function prepareChunkedDownload($user, $fileId) {
        try {
            $file = $this->model->getFileById($user['user_id'], $fileId);
            if (!$file) {
                http_response_code(404);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'File not found'
                ], true);
            }
            
            if ($file['uploaded'] != 1) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'File is still uploading to cloud storage. Please wait and try again.',
                    'status' => 'uploading'
                ], true);
            }

            $chunkSize = 20 * 1024 * 1024; 
            $totalChunks = max(1, ceil($file['file_size'] / $chunkSize));
            $downloadId = 'download_' . $fileId . '_' . time() . '_' . uniqid();
            
            return $this->view->renderResponse([
                'success' => true,
                'download_type' => 'single',
                'download_id' => $downloadId,
                'file_id' => $fileId,
                'file_name' => $file['name'],
                'file_size' => $file['file_size'],
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize
            ], true);
            
        } catch (Exception $e) {
            error_log("Prepare chunked download error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to prepare download'
            ], true);
        }
    }

    private function prepareMultipleFileDownload($user, $fileIds) {
        try {
            $files = $this->model->getFilesByIds($user['user_id'], $fileIds);
            
            if (empty($files)) {
                http_response_code(404);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Files not found'
                ], true);
            }
            
            $uploadingFiles = array_filter($files, function($file) {
                return $file['uploaded'] != 1;
            });
            
            if (!empty($uploadingFiles)) {
                $uploadingNames = array_map(function($file) {
                    return $file['name'];
                }, $uploadingFiles);
                
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Some files are still uploading to cloud storage: ' . implode(', ', $uploadingNames),
                    'status' => 'uploading',
                    'uploading_files' => $uploadingNames
                ], true);
            }
            
            $zipInfo = $this->createDownloadZip($user, $files);
            
            if (!$zipInfo['success']) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => $zipInfo['error']
                ], true);
            }
            
            $chunkSize = 20 * 1024 * 1024; 
            $totalChunks = max(1, ceil($zipInfo['file_size'] / $chunkSize));
            $downloadId = 'download_zip_' . time() . '_' . uniqid();
            
            $_SESSION[$downloadId] = [
                'type' => 'zip',
                'file_path' => $zipInfo['file_path'],
                'file_name' => $zipInfo['file_name'],
                'file_size' => $zipInfo['file_size'],
                'created_at' => time()
            ];
            
            return $this->view->renderResponse([
                'success' => true,
                'download_type' => 'zip',
                'download_id' => $downloadId,
                'file_name' => $zipInfo['file_name'],
                'file_size' => $zipInfo['file_size'],
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize
            ], true);
            
        } catch (Exception $e) {
            error_log("Prepare multiple file download error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to prepare download'
            ], true);
        }
    }

    
    private function downloadChunk($user) {
        try {
            $downloadId = $_POST['download_id'] ?? '';
            $chunkNumber = (int)($_POST['chunk_number'] ?? 0);
            $chunkSize = (int)($_POST['chunk_size'] ?? 20971520/*1048576*/);
            
            if (empty($downloadId)) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Invalid download ID'
                ], true);
            }
            
            $cacheKey = 'file_path_' . $downloadId;
            
            if (strpos($downloadId, 'download_zip_') === 0) {
                $zipInfo = $_SESSION[$downloadId] ?? null;
                if (!$zipInfo) {
                    return $this->view->renderResponse([
                        'success' => false,
                        'error' => 'Download session expired'
                    ], true);
                }
                $filePath = $zipInfo['file_path'];
            } else {
                if (isset($_SESSION[$cacheKey])) {
                    $filePath = $_SESSION[$cacheKey];
                } else {
                    preg_match('/download_(\d+)_/', $downloadId, $matches);
                    $fileId = $matches[1] ?? null;
                    
                    if (!$fileId) {
                        return $this->view->renderResponse([
                            'success' => false,
                            'error' => 'Invalid download ID format'
                        ], true);
                    }
                    
                    $file = $this->model->getFileById($user['user_id'], $fileId);
                    if (!$file) {
                        return $this->view->renderResponse([
                            'success' => false,
                            'error' => 'File not found'
                        ], true);
                    }
                    
                    $filePath = $this->model->getFileToTemp($fileId,$user['user_id']);
                    $_SESSION[$cacheKey] = $filePath;
                    
                    $_SESSION[$cacheKey . '_expires'] = time() + 3600;
                }
            }
            
            if (!$filePath || !file_exists($filePath)) {
                unset($_SESSION[$cacheKey]);
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'File not available'
                ], true);
            }
            
            $chunkData = $this->readFileChunk($filePath, $chunkNumber, $chunkSize);
            
            if ($chunkData === false) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Failed to read chunk'
                ], true);
            }
            
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . strlen($chunkData));
            header('Cache-Control: no-cache, must-revalidate');
            
            echo $chunkData;
            exit;
            
        } catch (Exception $e) {
            error_log("Download chunk error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to download chunk'
            ], true);
        }
    }

   
    private function createDownloadZip($user, $files) {
        try {
            if (!class_exists('ZipArchive')) {
                error_log("ZipArchive class not found - PHP ZIP extension not enabled");
                return [
                    'success' => false, 
                    'error' => 'ZIP functionality not available. Please enable PHP ZIP extension in php.ini'
                ];
            }
            
            try {
                $testZip = new ZipArchive();
                unset($testZip);
            } catch (Exception $e) {
                error_log("Cannot instantiate ZipArchive: " . $e->getMessage());
                return [
                    'success' => false, 
                    'error' => 'ZIP functionality error: ' . $e->getMessage()
                ];
            }
            
            $zip = new ZipArchive();
            $zipPath = sys_get_temp_dir() . '/download_' . uniqid() . '.zip';
            
            error_log("Attempting to create ZIP at: $zipPath");
            
            $result = $zip->open($zipPath, ZipArchive::CREATE);
            if ($result !== TRUE) {
                error_log("Cannot create ZIP file. Error code: $result");
                return [
                    'success' => false, 
                    'error' => "Cannot create ZIP file. Error code: $result"
                ];
            }
            
            $tempFiles = [];
            $addedFiles = 0;
            
            foreach ($files as $file) {
                try {
                    error_log("Processing file for ZIP: " . $file['name'] . " (ID: " . $file['file_id'] . ")");
                    
                    $tempPath = $this->model->getFileToTemp($file['file_id'],$user['user_id']);
                    
                    if (!$tempPath) {
                        error_log("Failed to get temp path for file: " . $file['name']);
                        continue;
                    }
                    
                    if (!file_exists($tempPath)) {
                        error_log("Temp file does not exist: " . $tempPath);
                        continue;
                    }
                    
                    $fileSize = filesize($tempPath);
                    if ($fileSize === false || $fileSize === 0) {
                        error_log("File is empty or corrupted: " . $tempPath);
                        continue;
                    }
                    
                    error_log("Adding file to ZIP: {$file['name']} (Size: $fileSize bytes)");
                    
                    if ($zip->addFile($tempPath, $file['name'])) {
                        $tempFiles[] = $tempPath;
                        $addedFiles++;
                        error_log("Successfully added to ZIP: " . $file['name']);
                    } else {
                        error_log("Failed to add file to ZIP: " . $file['name']);
                    }
                    
                } catch (Exception $fileError) {
                    error_log("Error processing file for ZIP: " . $fileError->getMessage());
                }
            }
            
            $closeResult = $zip->close();
            if (!$closeResult) {
                error_log("Failed to close/finalize ZIP file");
                return ['success' => false, 'error' => 'Failed to finalize ZIP file'];
            }
            
            error_log("ZIP closed successfully. Added $addedFiles files.");
            
            if (!file_exists($zipPath)) {
                error_log("ZIP file does not exist after creation");
                return ['success' => false, 'error' => 'ZIP file was not created'];
            }
            
            $zipSize = filesize($zipPath);
            if ($zipSize === false || $zipSize === 0) {
                error_log("ZIP file is empty or corrupted. Size: " . ($zipSize === false ? 'false' : $zipSize));
                if (file_exists($zipPath)) {
                    unlink($zipPath);
                }
                return ['success' => false, 'error' => 'ZIP file is empty - no files could be processed'];
            }
            
            error_log("ZIP created successfully: Size $zipSize bytes, Files: $addedFiles");
            
            $_SESSION['temp_files_' . basename($zipPath)] = $tempFiles;
            
            return [
                'success' => true,
                'file_path' => $zipPath,
                'file_name' => 'selected_files_' . date('Y-m-d_H-i-s') . '.zip',
                'file_size' => $zipSize
            ];
            
        } catch (Exception $e) {
            error_log("ZIP creation exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            if (isset($zipPath) && file_exists($zipPath)) {
                unlink($zipPath);
            }
            
            return ['success' => false, 'error' => 'Failed to create ZIP: ' . $e->getMessage()];
        }
    }

    private function readFileChunk($filePath, $chunkNumber, $chunkSize) {
        try {
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                return false;
            }
            
            $offset = $chunkNumber * $chunkSize;
            if (fseek($handle, $offset) !== 0) {
                fclose($handle);
                return false;
            }
            
            $chunkData = fread($handle, $chunkSize);
            fclose($handle);
            
            return $chunkData;
            
        } catch (Exception $e) {
            error_log("Read file chunk error: " . $e->getMessage());
            return false;
        }
    }
    
  
    private function isValidFileName($name) {
        if (strlen($name) > 255) return false;
        if (preg_match('/[<>:"|*?\\\\\/]/', $name)) return false;
        if (in_array($name, ['.', '..', 'CON', 'PRN', 'AUX', 'NUL'])) return false;
        return true;
    }


    public function fileStatus($user)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], $this->isApiRequest);
        }

        try {
            $fileId = $_POST['file_id'] ?? null;
            if (!$fileId) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'Missing file ID'
                ], true);
            }

            $status = $this->model->getFileUploadStatus($user['user_id'], $fileId);
            if ($status === null) {
                return $this->view->renderResponse([
                    'success' => false,
                    'error' => 'File not found'
                ], true);
            }

            return $this->view->renderResponse([
                'success' => true,
                'uploaded' => $status['uploaded']
            ], true);

        } catch (Exception $e) {
            error_log("Dashboard fileStatus error: " . $e->getMessage());
            return $this->view->renderResponse([
                'success' => false,
                'error' => 'Failed to get file status'
            ], true);
        }
    }
}