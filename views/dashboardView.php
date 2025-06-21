<?php

require_once __DIR__ . '/../utils/autoload.php';

class DashboardView extends AbstractView {
    
 
    protected function handleHtmlResponse($data) {
        if (isset($data['error'])) {
            return $this->renderError(500, $data['error']);
        }
        
        $fileListHtml = $this->generateFileListHtml($data['files'] ?? []);
        $breadcrumbHtml = $this->generateBreadcrumbHtml($data['breadcrumb'] ?? []);
        
        $storageUsed = $data['user']['total_storage_used'] ?? 0;
        $storageLimit = 50 * 1024 * 1024; 
        $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;
        
        $templateData = [
            'page_title' => 'Dashboard - nor.ust',
            'base_url' => $this->getBaseUrl(),
            'username' => 'Profile: ' . $data['user']['username'] ?? 'User',
            'user_email' => $data['user']['email'] ?? '',
            'file_list' => $fileListHtml,
            'breadcrumb_html' => $breadcrumbHtml,
            'current_path' => $data['current_path'] ?? '/',
            'storage_used' => $this->formatFileSize($storageUsed),
            'storage_limit' => $this->formatFileSize($storageLimit),
            'storage_percent' => $storagePercent,
            'file_count' => count($data['files'] ?? [])
        ];
        
        return $this->renderHtml('dashboard.html', $templateData, false);
    }
    
   
    private function generateFileListHtml($files) {
        if (empty($files)) {
            return '<div class="empty-folder">
                        <div class="empty-icon">📁</div>
                        <p>This folder is empty</p>
                        <p class="empty-hint">Upload files or create folders to get started</p>
                    </div>';
        }

        $html = '';

        foreach ($files as $file) {
            $modifiedDate = date('M j, Y g:i A', strtotime($file['modified']));
            $uploadStatus = '';
            $uploadingLabel = '';
            $uploadedLabel = '';

            if ($file['type'] === 'file' && isset($file['uploaded'])) {
                if (!$file['uploaded']) {
                    $uploadStatus = ' uploading';
                    $uploadingLabel = '<span class="uploading-label">Uploading to cloud…</span>';
                } else {
                    $uploadedLabel = '<span class="uploaded-label">Uploaded</span>';
                }
            }

            $html .= sprintf(
                '<div class="file-item %s%s" data-type="%s" data-name="%s" data-id="%s">
                    <div class="file-select">
                        <input type="checkbox" class="file-checkbox" data-file-id="%s" data-file-type="%s" data-file-name="%s" %s>
                    </div>
                    <div class="file-icon">%s</div>
                    <div class="file-details">
                        <div class="file-name" title="%s">%s %s %s</div>
                        <div class="file-meta">
                            <span class="file-size">%s</span>
                            <span class="file-date">%s</span>
                        </div>
                    </div>
                </div>',
                $file['type'],
                $uploadStatus,
                $file['type'],
                htmlspecialchars($file['name']),
                $file['id'],
                $file['id'],
                $file['type'],
                htmlspecialchars($file['name']),
                ($file['type'] === 'file' && isset($file['uploaded']) && !$file['uploaded']) ? 'disabled' : '',
                $file['icon'],
                htmlspecialchars($file['name']),
                htmlspecialchars($file['name']),
                $uploadingLabel,
                $uploadedLabel,
                $file['size'] ? $file['size'] : '—',
                $modifiedDate
            );
        }

        return $html;
    }
    
   
    private function generateBreadcrumbHtml($breadcrumb) {
        if (empty($breadcrumb)) {
            return '<span class="breadcrumb-item active" data-path="/">Home</span>';
        }
        
        $html = '';
        $totalItems = count($breadcrumb);
        
        foreach ($breadcrumb as $index => $item) {
            $isLast = ($index === $totalItems - 1);
            
            if ($isLast) {
                $html .= sprintf(
                    '<span class="breadcrumb-item active" data-path="%s">%s</span>',
                    htmlspecialchars($item['path']),
                    htmlspecialchars($item['name'])
                );
            } else {
                $html .= sprintf(
                    '<button class="breadcrumb-item clickable" data-path="%s">%s</button>',
                    htmlspecialchars($item['path']),
                    htmlspecialchars($item['name'])
                );
                
                $html .= '<span class="breadcrumb-separator"> › </span>';
            }
        }
        
        return $html;
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
}