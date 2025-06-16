CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    total_storage_used BIGINT DEFAULT 0
);

CREATE TABLE cloud_providers (
    provider_id INT AUTO_INCREMENT PRIMARY KEY,
    provider_name VARCHAR(50) UNIQUE NOT NULL,
    api_base_url VARCHAR(255)
);

CREATE TABLE user_cloud_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider_id INT NOT NULL,
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP NULL,
    account_email VARCHAR(100),
    storage_max BIGINT,
    storage_used BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_cloud_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_user_cloud_provider FOREIGN KEY (provider_id) REFERENCES cloud_providers(provider_id)
);

CREATE TABLE directories (
    directory_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    parent_directory_id INT,
    directory_path VARCHAR(1000),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_directories_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_directories_parent FOREIGN KEY (parent_directory_id) REFERENCES directories(directory_id)
);

CREATE TABLE files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    directory_id INT,
    original_filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    total_chunks INT DEFAULT 1,
    is_cached TINYINT(1) DEFAULT 0,
    upload_session_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_files_directory FOREIGN KEY (directory_id) REFERENCES directories(directory_id)
);

CREATE TABLE file_chunks (
    chunk_id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    account_id INT NOT NULL,
    chunk_index INT NOT NULL,
    chunk_size BIGINT NOT NULL,
    cloud_file_id VARCHAR(255),
    cloud_file_path VARCHAR(255),
    chunk_hash VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_chunks_file FOREIGN KEY (file_id) REFERENCES files(file_id),
    CONSTRAINT fk_chunks_account FOREIGN KEY (account_id) REFERENCES user_cloud_accounts(account_id),
    CONSTRAINT uk_file_chunk_index UNIQUE (file_id, chunk_index)
);

CREATE TABLE file_cache (
    cache_id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    chunk_index INT NOT NULL,
    chunk_data LONGBLOB NOT NULL,
    CONSTRAINT fk_cache_file FOREIGN KEY (file_id) REFERENCES files(file_id),
    CONSTRAINT uk_cache_file_chunk UNIQUE (file_id, chunk_index)
);

CREATE TABLE upload_sessions (
    session_id VARCHAR(50) PRIMARY KEY,
    user_id INT NOT NULL,
    directory_id INT,
    original_filename VARCHAR(255) NOT NULL,
    total_size BIGINT NOT NULL,
    chunk_size INT NOT NULL,
    chunks_uploaded INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + INTERVAL 1 DAY),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_sessions_directory FOREIGN KEY (directory_id) REFERENCES directories(directory_id)
);


