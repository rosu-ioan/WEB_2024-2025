<?php

require_once __DIR__ . '/AbstractView.php';

class ProfileView extends AbstractView {
    
    
    protected function handleHtmlResponse($data) {
        if (isset($data['error'])) {
            return $this->renderError(500, $data['error']);
        }
        
        $connectedProvidersHtml = $this->generateConnectedProvidersHtml($data['connected_providers'] ?? []);
        $unconnectedProvidersHtml = $this->generateUnconnectedProvidersHtml($data['unconnected_providers'] ?? []);
        
        $storageData = $this->calculateTotalStorage($data['connected_providers'] ?? []);
        
        $alertsHtml = $this->generateAlertsHtml($data);
        
        $templateData = [
            'page_title' => 'Profile - nor.ust',
            'base_url' => $this->getBaseUrl(),
            'username' => $data['user']['username'] ?? 'User',
            'total_storage_used' => $storageData['used_formatted'],
            'total_storage_available' => $storageData['total_formatted'],
            'storage_percentage' => $storageData['percentage'],
            'connected_providers_html' => $connectedProvidersHtml,
            'unconnected_providers_html' => $unconnectedProvidersHtml,
            'alerts_html' => $alertsHtml,
            'connected_count' => count($data['connected_providers'] ?? []),
            'total_providers' => count($data['connected_providers'] ?? []) + count($data['unconnected_providers'] ?? [])
        ];
        
        return $this->renderHtml('profile.html', $templateData, false);
    }
    
  
    private function generateConnectedProvidersHtml($providers) {
        if (empty($providers)) {
            return '<div class="no-providers">
                        <p>No cloud providers connected yet</p>
                    </div>';
        }
        
        $html = '';
        
        foreach ($providers as $provider) {
            $storageUsed = $provider['storage_used'] ?? 0;
            $storageMax = $provider['storage_max'] ?? 0;
            $storagePercentage = $storageMax > 0 ? round(($storageUsed / $storageMax) * 100, 1) : 0;
            
            if (isset($provider['live_storage'])) {
                $storageUsed = $provider['live_storage']['used'];
                $storageMax = $provider['live_storage']['total'];
                $storagePercentage = $provider['live_storage']['percentage_used'];
            }
            
            $html .= sprintf(
                '<div class="provider-card connected" data-account-id="%s">
                    <div class="provider-info">
                        <div class="provider-details">
                            <h3>%s</h3>
                            <p class="provider-email">%s</p>
                            <div class="provider-storage">
                                <div class="storage-text">
                                    <span class="storage-used">%s</span> of <span class="storage-total">%s</span>
                                </div>
                                <div class="storage-bar-small">
                                    <div class="storage-progress-small" style="width: %s%%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="provider-actions">
                        <div class="priority-controls">
                            <button class="priority-btn move-up" data-direction="up">↑</button>
                            <span class="priority-rank">%s</span>
                            <button class="priority-btn move-down" data-direction="down">↓</button>
                        </div>
                        <button class="btn btn-danger disconnect-btn">Disconnect</button>
                    </div>
                </div>',
                htmlspecialchars($provider['account_id']),
                htmlspecialchars($provider['provider_name']),
                htmlspecialchars($provider['account_email'] ?? 'No email available'),
                $this->formatFileSize($storageUsed),
                $this->formatFileSize($storageMax),
                $storagePercentage,
                $provider['priority_rank'] ?? '—'
            );
        }
        
        return $html;
    }
    
  
    private function generateUnconnectedProvidersHtml($providers) {
        if (empty($providers)) {
            return '<div class="no-providers">
                        <p>All available providers are connected</p>
                    </div>';
        }
        
        $html = '';
        
        foreach ($providers as $provider) {
            $html .= sprintf(
                '<div class="provider-card unconnected" data-provider-id="%s">
                    <div class="provider-info">
                        <div class="provider-details">
                            <h3>%s</h3>
                        </div>
                    </div>
                    <div class="provider-actions">
                        <form method="POST" action="%sprofile/connect-provider" class="connect-form">
                            <input type="hidden" name="provider_id" value="%s">
                            <button type="submit" class="btn btn-primary connect-btn">Connect</button>
                        </form>
                    </div>
                </div>',
                htmlspecialchars($provider['provider_id']),
                htmlspecialchars($provider['provider_name']),
                $this->getBaseUrl(),
                htmlspecialchars($provider['provider_id'])
            );
        }
        
        return $html;
    }
    
    
    private function generateAlertsHtml($data) {
        $html = '';
        
        if (isset($data['success_message'])) {
            $message = $data['success_message'] === 'success' ? 
                'Cloud provider connected successfully!' : 
                $data['success_message'];
            
            $html .= sprintf(
                '<div class="alert alert-success">
                    <span>%s</span>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>',
                htmlspecialchars($message)
            );
        }
        
        if (isset($data['error_message'])) {
            $message = $data['error_message'];
            
            $html .= sprintf(
                '<div class="alert alert-error">
                    <span>%s</span>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>',
                htmlspecialchars($message)
            );
        }
        
        return $html;
    }
   
    private function calculateTotalStorage($providers) {
        $totalUsed = 0;
        $totalAvailable = 0;
        
        foreach ($providers as $provider) {
            if (isset($provider['live_storage'])) {
                $totalUsed += $provider['live_storage']['used'];
                $totalAvailable += $provider['live_storage']['total'];
            } else {
                $totalUsed += $provider['storage_used'] ?? 0;
                $totalAvailable += $provider['storage_max'] ?? 0;
            }
        }
        
        $percentage = $totalAvailable > 0 ? round(($totalUsed / $totalAvailable) * 100, 1) : 0;
        
        return [
            'used_formatted' => $this->formatFileSize($totalUsed),
            'total_formatted' => $this->formatFileSize($totalAvailable),
            'percentage' => $percentage
        ];
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