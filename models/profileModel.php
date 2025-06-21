<?php

class ProfileModel {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
   
    public function getAvailableProviders() {
        try {
            $stmt = $this->db->prepare("
                SELECT provider_id, provider_name, provider_class
                FROM cloud_providers 
                ORDER BY provider_name ASC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("ProfileModel::getAvailableProviders Error: " . $e->getMessage());
            return [];
        }
    }
    
    
    public function getConnectedProviders($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    cp.provider_id,
                    cp.provider_name,
                    cp.provider_class,
                    uca.account_id,
                    uca.access_token,
                    uca.refresh_token,
                    uca.token_expires_at,
                    uca.account_email,
                    uca.storage_max,
                    uca.storage_used,
                    uca.priority_rank,
                    uca.created_at
                FROM cloud_providers cp
                LEFT JOIN user_cloud_accounts uca ON cp.provider_id = uca.provider_id AND uca.user_id = ?
                ORDER BY uca.priority_rank ASC, cp.provider_name ASC
            ");
            $stmt->execute([$userId]);
            $providers = $stmt->fetchAll();
            
            foreach ($providers as &$provider) {
                if ($provider['account_id']) {
                    try {
                        $liveStorageData = $this->getLiveStorageData($provider);
                        if ($liveStorageData) {
                            $provider['live_storage'] = $liveStorageData;
                            
                            $this->updateStorageInfo($provider['account_id'], $liveStorageData);
                        }
                    } catch (Exception $e) {
                        error_log("ProfileModel: Failed to get live storage for provider {$provider['provider_id']}: " . $e->getMessage());
                    }
                }
            }
            
            return $providers;
            
        } catch (PDOException $e) {
            error_log("ProfileModel::getConnectedProviders Error: " . $e->getMessage());
            return [];
        }
    }
    
    
    public function storeCloudConnection($userId, $providerId, $tokens) {
        error_log("=== STORE CLOUD CONNECTION START ===");
        error_log("User ID: $userId");
        error_log("Provider ID: $providerId"); 
        error_log("Tokens received: " . print_r($tokens, true));
        
        try {
            $this->db->beginTransaction();
            
            $provider = $this->getProviderById($providerId);
            if (!$provider) {
                error_log("ERROR: Provider not found for ID: $providerId");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Invalid provider'];
            }
            error_log("Provider found: " . print_r($provider, true));
            
            if (!$this->validateProviderClass($provider['provider_class'])) {
                error_log("ERROR: Provider class not found: " . $provider['provider_class']);
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider implementation not found'];
            }
            error_log("Provider class validated: " . $provider['provider_class']);
            
            $stmt = $this->db->prepare("
                SELECT account_id FROM user_cloud_accounts 
                WHERE user_id = ? AND provider_id = ?
            ");
            $stmt->execute([$userId, $providerId]);
            if ($stmt->fetch()) {
                error_log("ERROR: Provider already connected for user $userId, provider $providerId");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider already connected'];
            }
            error_log("Provider not yet connected - proceeding");
            
            error_log("Creating provider instance: " . $provider['provider_class']);
            $providerInstance = $this->createProviderInstance($provider['provider_class']);
            
            error_log("Setting access token on provider instance...");
            $providerInstance->setAccessToken($tokens);
            error_log("Access token set successfully");
            
            error_log("Getting account info from provider...");
            $accountInfo = $providerInstance->getAccountInfo();
            error_log("Account info received: " . print_r($accountInfo, true));
            
            error_log("Getting storage info from provider...");
            $storageInfo = $providerInstance->getRemainingStorage();
            error_log("Storage info received: " . print_r($storageInfo, true));
            
            // AICI
            $tokenExpiry = null;
            if (isset($tokens['expires_in'])) {
                $tokenExpiry = date('Y-m-d H:i:s',time() + $tokens['expires_in']+3601);
                error_log("Token expiry calculated: $tokenExpiry and tokens['expires_in'] is " . ($tokens['expires_in']+3601));
            } elseif (isset($tokens['expires_at'])) {
                $tokenExpiry = date('Y-m-d H:i:s',time() + $tokens['expires_at']+3601);
                error_log("Token expiry set from expires_at: $tokenExpiry");
            }elseif (isset($tokens['expire_at'])) {
                $tokenExpiry = date('Y-m-d H:i:s',time() + $tokens['expire_at']+3601);
                error_log("Token expiry set from expires_at: $tokenExpiry");
            }elseif (isset($tokens['expiry'])) {
                $tokenExpiry = date('Y-m-d H:i:s',time() + $tokens['expiry']+3601);
                error_log("Token expiry set from expiry: $tokenExpiry");
            } else {
                error_log("No expires_in in tokens - no expiry set");
            }
            
            $priority = $this->getNextPriority($userId);
            error_log("Next priority: $priority");
            
            error_log("Inserting into database...");
            $stmt = $this->db->prepare("
                INSERT INTO user_cloud_accounts 
                (user_id, provider_id, access_token, refresh_token, token_expires_at, 
                 account_email, storage_max, storage_used, priority_rank, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $executeResult = $stmt->execute([
                $userId,
                $providerId,
                $tokens['access_token'],
                $tokens['refresh_token'] ?? null,
                $tokenExpiry,
                $accountInfo['email'],
                $storageInfo['total'],
                $storageInfo['used'],
                $priority
            ]);
            
            if (!$executeResult) {
                error_log("ERROR: Database insert failed");
                error_log("Error info: " . print_r($stmt->errorInfo(), true));
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Database insert failed'];
            }
            
            $accountId = $this->db->lastInsertId();
            error_log("Database insert successful - Account ID: $accountId");
            
            $this->db->commit();
            error_log("Transaction committed successfully");
            
            error_log("=== STORE CLOUD CONNECTION SUCCESS ===");
            return [
                'success' => true,
                'account' => [
                    'account_id' => $accountId,
                    'provider_name' => $provider['provider_name'],
                    'account_email' => $accountInfo['email'],
                    'priority' => $priority,
                    'storage' => $storageInfo
                ]
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("=== STORE CLOUD CONNECTION EXCEPTION ===");
            error_log("Exception message: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => 'Failed to store connection: ' . $e->getMessage()];
        }
    }
  
    public function removeCloudConnection($userId, $accountId) {
        try {
            $this->db->beginTransaction();
            
            if (!$this->validateUserCloudAccount($userId, $accountId)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Account not found or access denied'];
            }
            
            $stmt = $this->db->prepare("
                DELETE FROM user_cloud_accounts 
                WHERE user_id = ? AND account_id = ?
            ");
            $stmt->execute([$userId, $accountId]);
            
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Failed to remove connection'];
            }
            
            $this->normalizeProviderPriorities($userId);
            
            $this->db->commit();
            
            error_log("ProfileModel: Cloud connection removed successfully, Account ID: $accountId");
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("ProfileModel::removeCloudConnection Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to remove connection'];
        }
    }
   
    public function moveProviderUp($userId, $accountId) {
        error_log("=== MOVE PROVIDER UP START ===");
        error_log("User ID: $userId");
        error_log("Account ID: $accountId");
        
        try {
            $this->db->beginTransaction();
            error_log("Database transaction started");
            
            error_log("Validating user cloud account...");
            if (!$this->validateUserCloudAccount($userId, $accountId)) {
                error_log("ERROR: Account validation failed");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Account not found or access denied'];
            }
            error_log("Account validation passed");
            
            error_log("Getting current priority...");
            $stmt = $this->db->prepare("
                SELECT priority_rank FROM user_cloud_accounts 
                WHERE user_id = ? AND account_id = ?
            ");
            $stmt->execute([$userId, $accountId]);
            $result = $stmt->fetch();
            
            if (!$result) {
                error_log("ERROR: Could not find account priority");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Account not found'];
            }
            
            $currentPriority = $result['priority_rank'];
            error_log("Current priority: $currentPriority");
            
            if ($currentPriority === 1) {
                error_log("ERROR: Already at top priority");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider is already at highest priority'];
            }
            
            $targetPriority = $currentPriority - 1;
            error_log("Target priority: $targetPriority");
            
            $stmt = $this->db->prepare("
                SELECT account_id FROM user_cloud_accounts 
                WHERE user_id = ? AND priority_rank = ?
            ");
            $stmt->execute([$userId, $targetPriority]);
            $targetAccount = $stmt->fetch();
            
            if (!$targetAccount) {
                error_log("ERROR: No provider found at target priority $targetPriority");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Cannot move up - no provider above'];
            }
            
            error_log("Target account ID: " . $targetAccount['account_id']);
            
            error_log("Swapping priorities...");
            $stmt = $this->db->prepare("
                UPDATE user_cloud_accounts 
                SET priority_rank = ?, updated_at = NOW()
                WHERE user_id = ? AND account_id = ?
            ");
            
            error_log("Moving current provider to priority $targetPriority");
            $result1 = $stmt->execute([$targetPriority, $userId, $accountId]);
            error_log("Update 1 result: " . ($result1 ? 'SUCCESS' : 'FAILED'));
            if (!$result1) {
                error_log("Update 1 error: " . print_r($stmt->errorInfo(), true));
            }
            
            error_log("Moving target provider to priority $currentPriority");
            $result2 = $stmt->execute([$currentPriority, $userId, $targetAccount['account_id']]);
            error_log("Update 2 result: " . ($result2 ? 'SUCCESS' : 'FAILED'));
            if (!$result2) {
                error_log("Update 2 error: " . print_r($stmt->errorInfo(), true));
            }
            
            if (!$result1 || !$result2) {
                error_log("ERROR: One or both updates failed");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Failed to update priorities'];
            }
            
            $this->db->commit();
            error_log("Transaction committed successfully");
            
            error_log("=== MOVE PROVIDER UP SUCCESS ===");
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("=== MOVE PROVIDER UP EXCEPTION ===");
            error_log("PDO Exception: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            return ['success' => false, 'error' => 'Failed to move provider'];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("=== MOVE PROVIDER UP EXCEPTION ===");
            error_log("General Exception: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            return ['success' => false, 'error' => 'Failed to move provider'];
        }
    }
    
    public function moveProviderDown($userId, $accountId) {
        error_log("=== MOVE PROVIDER DOWN START ===");
        error_log("User ID: $userId");
        error_log("Account ID: $accountId");
        
        try {
            $this->db->beginTransaction();
            error_log("Database transaction started");
            
            error_log("Validating user cloud account...");
            if (!$this->validateUserCloudAccount($userId, $accountId)) {
                error_log("ERROR: Account validation failed");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Account not found or access denied'];
            }
            error_log("Account validation passed");
            
            error_log("Getting current priority...");
            $stmt = $this->db->prepare("
                SELECT priority_rank FROM user_cloud_accounts 
                WHERE user_id = ? AND account_id = ?
            ");
            $stmt->execute([$userId, $accountId]);
            $result = $stmt->fetch();
            
            if (!$result) {
                error_log("ERROR: Could not find account priority");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Account not found'];
            }
            
            $currentPriority = $result['priority_rank'];
            error_log("Current priority: $currentPriority");
            
            error_log("Getting maximum priority...");
            $stmt = $this->db->prepare("
                SELECT MAX(priority_rank) as max_priority FROM user_cloud_accounts 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            $maxPriority = $result['max_priority'];
            error_log("Max priority: $maxPriority");
            
            if ($currentPriority === $maxPriority) {
                error_log("ERROR: Already at bottom priority");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider is already at lowest priority'];
            }
            
            $targetPriority = $currentPriority + 1;
            error_log("Target priority: $targetPriority");
            
            $stmt = $this->db->prepare("
                SELECT account_id FROM user_cloud_accounts 
                WHERE user_id = ? AND priority_rank = ?
            ");
            $stmt->execute([$userId, $targetPriority]);
            $targetAccount = $stmt->fetch();
            
            if (!$targetAccount) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Cannot move down - no provider below'];
            }
            
            error_log("Target account ID: " . $targetAccount['account_id']);
            
            error_log("Swapping priorities...");
            $stmt = $this->db->prepare("
                UPDATE user_cloud_accounts 
                SET priority_rank = ?, updated_at = NOW()
                WHERE user_id = ? AND account_id = ?
            ");
            
            error_log("Moving current provider to priority $targetPriority");
            $result1 = $stmt->execute([$targetPriority, $userId, $accountId]);
            error_log("Update 1 result: " . ($result1 ? 'SUCCESS' : 'FAILED'));
            if (!$result1) {
                error_log("Update 1 error: " . print_r($stmt->errorInfo(), true));
            }
            
            error_log("Moving target provider to priority $currentPriority");
            $result2 = $stmt->execute([$currentPriority, $userId, $targetAccount['account_id']]);
            error_log("Update 2 result: " . ($result2 ? 'SUCCESS' : 'FAILED'));
            if (!$result2) {
                error_log("Update 2 error: " . print_r($stmt->errorInfo(), true));
            }
            
            if (!$result1 || !$result2) {
                error_log("ERROR: One or both updates failed");
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Failed to update priorities'];
            }
            
            $this->db->commit();
            error_log("Transaction committed successfully");
            
            error_log("=== MOVE PROVIDER DOWN SUCCESS ===");
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("=== MOVE PROVIDER DOWN EXCEPTION ===");
            error_log("PDO Exception: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            return ['success' => false, 'error' => 'Failed to move provider'];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("=== MOVE PROVIDER DOWN EXCEPTION ===");
            error_log("General Exception: " . $e->getMessage());
            error_log("Exception file: " . $e->getFile() . " line " . $e->getLine());
            return ['success' => false, 'error' => 'Failed to move provider'];
        }
    }
    
    public function normalizeProviderPriorities($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT account_id FROM user_cloud_accounts 
                WHERE user_id = ? 
                ORDER BY priority_rank ASC
            ");
            $stmt->execute([$userId]);
            $accounts = $stmt->fetchAll();
            
            $newPriority = 1;
            $stmt = $this->db->prepare("
                UPDATE user_cloud_accounts 
                SET priority_rank = ?, updated_at = NOW()
                WHERE account_id = ?
            ");
            
            foreach ($accounts as $account) {
                $stmt->execute([$newPriority, $account['account_id']]);
                $newPriority++;
            }
            
            error_log("ProfileModel: Normalized priorities for user: $userId");
            
        } catch (PDOException $e) {
            error_log("ProfileModel::normalizeProviderPriorities Error: " . $e->getMessage());
        }
    }
    
    
    public function getProviderById($providerId) {
        try {
            $stmt = $this->db->prepare("
                SELECT provider_id, provider_name, provider_class
                FROM cloud_providers 
                WHERE provider_id = ?
            ");
            $stmt->execute([$providerId]);
            
            return $stmt->fetch() ?: null;
            
        } catch (PDOException $e) {
            error_log("ProfileModel::getProviderById Error: " . $e->getMessage());
            return null;
        }
    }
    
 
    public function validateUserCloudAccount($userId, $accountId) {
        error_log("=== VALIDATE USER CLOUD ACCOUNT ===");
        error_log("User ID: $userId");
        error_log("Account ID: $accountId");
        
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM user_cloud_accounts 
                WHERE user_id = ? AND account_id = ?
            ");
            $stmt->execute([$userId, $accountId]);
            $result = $stmt->fetch();
            
            $isValid = $result['count'] > 0;
            error_log("Validation result: " . ($isValid ? 'VALID' : 'INVALID'));
            error_log("Count: " . $result['count']);
            
            return $isValid;
            
        } catch (PDOException $e) {
            error_log("Validation error: " . $e->getMessage());
            return false;
        }
    }
    
  
    public function getUserProfile($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, username, email, created_at, updated_at, total_storage_used
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            
            return $stmt->fetch() ?: null;
            
        } catch (PDOException $e) {
            error_log("ProfileModel::getUserProfile Error: " . $e->getMessage());
            return null;
        }
    }
    
   
    public function refreshProviderStorageInfo($userId, $accountId) {
        try {
            $stmt = $this->db->prepare("
                SELECT uca.access_token, uca.refresh_token, cp.provider_class
                FROM user_cloud_accounts uca
                JOIN cloud_providers cp ON uca.provider_id = cp.provider_id
                WHERE uca.user_id = ? AND uca.account_id = ?
            ");
            $stmt->execute([$userId, $accountId]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return null;
            }
            
            $providerInstance = $this->createProviderInstance($connection['provider_class']);
            $tokens = ['access_token' => $connection['access_token']];
            if ($connection['refresh_token']) {
                $tokens['refresh_token'] = $connection['refresh_token'];
            }
            
            $providerInstance->setAccessToken($tokens);
            $storageInfo = $providerInstance->getRemainingStorage();
            
            $this->updateStorageInfo($accountId, $storageInfo);
            
            return $storageInfo;
            
        } catch (Exception $e) {
            error_log("ProfileModel::refreshProviderStorageInfo Error: " . $e->getMessage());
            return null;
        }
    }
   
    private function getNextPriority($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(MAX(priority_rank), 0) + 1 as next_priority
                FROM user_cloud_accounts 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            
            return $result['next_priority'];
            
        } catch (PDOException $e) {
            error_log("ProfileModel::getNextPriority Error: " . $e->getMessage());
            return 1; 
        }
    }
  
    private function validateProviderClass($providerClass) {
        return class_exists($providerClass);
    }
    
 
    private function createProviderInstance($providerClass) {
        if (!class_exists($providerClass)) {
            throw new Exception("Provider class not found: $providerClass");
        }
        
        return new $providerClass();
    }
    
    
    private function getLiveStorageData($provider) {
        if (!$provider['access_token']) {
            return null;
        }
        
        try {
            $providerInstance = $this->createProviderInstance($provider['provider_class']);
            $tokens = ['access_token' => $provider['access_token']];
            if ($provider['refresh_token']) {
                $tokens['refresh_token'] = $provider['refresh_token'];
            }
            
            $providerInstance->setAccessToken($tokens);
            
            if (!$providerInstance->isTokenValid()) {
                error_log("ProfileModel::getLiveStorageData Token is invalid for provider {$provider['provider_name']}");
                return null;
            }
            
            return $providerInstance->getRemainingStorage();
            
        } catch (Exception $e) {
            error_log("ProfileModel::getLiveStorageData Error for provider {$provider['provider_id']}: " . $e->getMessage());
            return null;
        }
    }
    
  
    private function updateStorageInfo($accountId, $storageInfo) {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_cloud_accounts 
                SET storage_max = ?, storage_used = ?, updated_at = NOW()
                WHERE account_id = ?
            ");
            
            return $stmt->execute([
                $storageInfo['total'],
                $storageInfo['used'],
                $accountId
            ]);
            
        } catch (PDOException $e) {
            error_log("ProfileModel::updateStorageInfo Error: " . $e->getMessage());
            return false;
        }
    }
}