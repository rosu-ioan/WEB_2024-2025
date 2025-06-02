CREATE SEQUENCE users_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE cloud_providers_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE user_cloud_accounts_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE directories_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE files_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE file_chunks_seq START WITH 1 INCREMENT BY 1;
CREATE SEQUENCE file_cache_seq START WITH 1 INCREMENT BY 1;

CREATE TABLE users (
    user_id NUMBER PRIMARY KEY,
    username VARCHAR2(50) UNIQUE NOT NULL,
    email VARCHAR2(100) UNIQUE NOT NULL,
    password_hash VARCHAR2(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_storage_used NUMBER DEFAULT 0
);

CREATE OR REPLACE TRIGGER users_id_trigger
    BEFORE INSERT ON users
    FOR EACH ROW
BEGIN
    IF :NEW.user_id IS NULL THEN
        :NEW.user_id := users_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE cloud_providers (
    provider_id NUMBER PRIMARY KEY,
    provider_name VARCHAR2(50) UNIQUE NOT NULL, 
    api_base_url VARCHAR2(255)
);

CREATE OR REPLACE TRIGGER cloud_providers_id_trigger
    BEFORE INSERT ON cloud_providers
    FOR EACH ROW
BEGIN
    IF :NEW.provider_id IS NULL THEN
        :NEW.provider_id := cloud_providers_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE user_cloud_accounts (
    account_id NUMBER PRIMARY KEY,
    user_id NUMBER NOT NULL,
    provider_id NUMBER NOT NULL,
    access_token CLOB,
    refresh_token CLOB,
    token_expires_at TIMESTAMP,
    account_email VARCHAR2(100),
    storage_max NUMBER,
    storage_used NUMBER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_cloud_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_user_cloud_provider FOREIGN KEY (provider_id) REFERENCES cloud_providers(provider_id)
);

CREATE OR REPLACE TRIGGER user_cloud_accounts_id_trigger
    BEFORE INSERT ON user_cloud_accounts
    FOR EACH ROW
BEGIN
    IF :NEW.account_id IS NULL THEN
        :NEW.account_id := user_cloud_accounts_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE directories (
    directory_id NUMBER PRIMARY KEY,
    user_id NUMBER NOT NULL,
    parent_directory_id NUMBER, 
    directory_path VARCHAR2(1000), 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_directories_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_directories_parent FOREIGN KEY (parent_directory_id) REFERENCES directories(directory_id)
);

CREATE OR REPLACE TRIGGER directories_id_trigger
    BEFORE INSERT ON directories
    FOR EACH ROW
BEGIN
    IF :NEW.directory_id IS NULL THEN
        :NEW.directory_id := directories_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE files (
    file_id NUMBER PRIMARY KEY,
    user_id NUMBER NOT NULL,
    directory_id NUMBER, 
    original_filename VARCHAR2(255) NOT NULL,
    file_size NUMBER NOT NULL,
    total_chunks NUMBER DEFAULT 1,
    is_cached NUMBER(1) DEFAULT 0, 
    upload_session_id VARCHAR2(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_files_directory FOREIGN KEY (directory_id) REFERENCES directories(directory_id)
);

CREATE OR REPLACE TRIGGER files_id_trigger
    BEFORE INSERT ON files
    FOR EACH ROW
BEGIN
    IF :NEW.file_id IS NULL THEN
        :NEW.file_id := files_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE file_chunks (
    chunk_id NUMBER PRIMARY KEY,
    file_id NUMBER NOT NULL,
    account_id NUMBER NOT NULL,
    chunk_index NUMBER NOT NULL,
    chunk_size NUMBER NOT NULL,
    cloud_file_id VARCHAR2(255), 
    cloud_file_path VARCHAR2(255),
    chunk_hash VARCHAR2(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chunks_file FOREIGN KEY (file_id) REFERENCES files(file_id),
    CONSTRAINT fk_chunks_account FOREIGN KEY (account_id) REFERENCES user_cloud_accounts(account_id),
    CONSTRAINT uk_file_chunk_index UNIQUE (file_id, chunk_index)
);

CREATE OR REPLACE TRIGGER file_chunks_id_trigger
    BEFORE INSERT ON file_chunks
    FOR EACH ROW
BEGIN
    IF :NEW.chunk_id IS NULL THEN
        :NEW.chunk_id := file_chunks_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE file_cache (
    cache_id NUMBER PRIMARY KEY,
    file_id NUMBER NOT NULL,
    chunk_index NUMBER NOT NULL,
    chunk_data BLOB NOT NULL,
    CONSTRAINT fk_cache_file FOREIGN KEY (file_id) REFERENCES files(file_id),
    CONSTRAINT uk_cache_file_chunk UNIQUE (file_id, chunk_index)
);

CREATE OR REPLACE TRIGGER file_cache_id_trigger
    BEFORE INSERT ON file_cache
    FOR EACH ROW
BEGIN
    IF :NEW.cache_id IS NULL THEN
        :NEW.cache_id := file_cache_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE upload_sessions (
    session_id VARCHAR2(50) PRIMARY KEY,
    user_id NUMBER NOT NULL,
    directory_id NUMBER,
    original_filename VARCHAR2(255) NOT NULL,
    total_size NUMBER NOT NULL,
    chunk_size NUMBER NOT NULL,
    chunks_uploaded NUMBER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + 1),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_sessions_directory FOREIGN KEY (directory_id) REFERENCES directories(directory_id)
);

