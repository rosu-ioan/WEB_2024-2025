<?php

interface CloudProviderInterface 
{
    public function getAuthorizationUrl(): string;
    public function handleAuthCallback(string $authCode): array;
    public function refreshAccessToken(string $refreshToken): array;
    public function setAccessToken(array $tokenData): void;
    public function isTokenValid(): bool;
    

    public function uploadFile(string $fileName, string $filePath, callable $progressCallback = null): array;
    public function downloadFile(string $fileId): string;
    public function deleteFile(string $fileId): bool;
    public function getFileInfo(string $fileId): array;
    public function getAllFiles(int $limit): array;
    
    public function getRemainingStorage(): array;
    public function getAccountInfo(): array;
}