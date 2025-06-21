<?php

class AuthModel {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    
    public function authenticateUser($email, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, username, email, password_hash, total_storage_used 
                FROM users 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                error_log("AuthModel: User not found for email: $email");
                return null; 
            }
            
            if (!password_verify($password, $user['password_hash'])) {
                error_log("AuthModel: Invalid password for email: $email");
                return null; 
            }
            
            unset($user['password_hash']);
            
            error_log("AuthModel: Authentication successful for user: " . $user['user_id']);
            return $user;
            
        } catch (PDOException $e) {
            error_log("AuthModel::authenticateUser Error: " . $e->getMessage());
            return null;
        }
    }
   
    public function createUser($username, $email, $password) {
        try {
            $this->db->beginTransaction();
            
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $this->db->prepare("
                INSERT INTO users (username, email, password_hash, created_at, updated_at, total_storage_used) 
                VALUES (?, ?, ?, NOW(), NOW(), 0)
            ");
            
            $stmt->execute([$username, $email, $passwordHash]);
            
            $userId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            error_log("AuthModel: User created successfully with ID: $userId");
            return $userId;
            
        } catch (PDOException $e) {
            $this->db->rollback();
            error_log("AuthModel::createUser Error: " . $e->getMessage());
            return false;
        }
    }
    
    
    public function userExists($email, $username) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM users 
                WHERE email = ? OR username = ?
            ");
            $stmt->execute([$email, $username]);
            $result = $stmt->fetch();
            
            $exists = $result['count'] > 0;
            error_log("AuthModel: User exists check for $email/$username: " . ($exists ? 'true' : 'false'));
            
            return $exists;
            
        } catch (PDOException $e) {
            error_log("AuthModel::userExists Error: " . $e->getMessage());
            return true;
        }
    }
    
    
    public function getUserById($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, username, email, created_at, updated_at, total_storage_used
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            return $user ?: null;
            
        } catch (PDOException $e) {
            error_log("AuthModel::getUserById Error: " . $e->getMessage());
            return null;
        }
    }
    
    
    public function updateLastLogin($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET updated_at = NOW() 
                WHERE user_id = ?
            ");
            $success = $stmt->execute([$userId]);
            
            error_log("AuthModel: Updated last login for user: $userId");
            return $success && $stmt->rowCount() > 0;
            
        } catch (PDOException $e) {
            error_log("AuthModel::updateLastLogin Error: " . $e->getMessage());
            return false;
        }
    }
    
}