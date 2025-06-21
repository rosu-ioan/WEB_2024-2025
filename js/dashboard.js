
document.addEventListener('DOMContentLoaded', function() {
    initializeFileSelection();
    initializeActionButtons();
    initializeNavigation();
    initializeModals();
    initializeUpload();
    
    updateActionButtonStates();
});

function initializeFileSelection() {
    const fileList = document.getElementById('file-list');
    
    if (fileList) {
        fileList.addEventListener('change', function(e) {
            if (e.target.classList.contains('file-checkbox')) {
                handleFileSelection();
            }
        });
        
        fileList.addEventListener('click', function(e) {
            const fileItem = e.target.closest('.file-item');
            if (fileItem && !e.target.classList.contains('file-checkbox') && !e.target.closest('.file-select')) {
                const fileType = fileItem.getAttribute('data-type');
                
                if (fileType === 'folder') {
                    const folderName = fileItem.getAttribute('data-name');
                    const currentPath = window.dashboardData.currentPath;
                    const newPath = currentPath === '/' ? '/' + folderName : currentPath + '/' + folderName;
                    navigateToPath(newPath);
                } else if (fileType === 'file') {
                    const checkbox = fileItem.querySelector('.file-checkbox');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        handleFileSelection();
                    }
                }
            }
        });
    }
}


function initializeActionButtons() {
    const uploadBtn = document.getElementById('upload-btn');
    if (uploadBtn) {
        uploadBtn.addEventListener('click', function() {
            document.getElementById('file-input').click();
        });
    }
    
    const newFolderBtn = document.getElementById('new-folder-btn');
    if (newFolderBtn) {
        newFolderBtn.addEventListener('click', showNewFolderModal);
    }
    
    const downloadBtn = document.getElementById('download-btn');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', handleDownload);
    }
    
    const renameBtn = document.getElementById('rename-btn');
    if (renameBtn) {
        renameBtn.addEventListener('click', showRenameModal);
    }
    
    const deleteBtn = document.getElementById('delete-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', handleDelete);
    }
}


function initializeNavigation() {
    const breadcrumbPath = document.getElementById('breadcrumb-path');
    
    if (breadcrumbPath) {
        breadcrumbPath.addEventListener('click', function(e) {
            if (e.target.classList.contains('clickable')) {
                const path = e.target.getAttribute('data-path');
                navigateToPath(path);
            }
        });
    }
    
    const profileBtn = document.getElementById('profile-btn');
    if (profileBtn) {
        profileBtn.addEventListener('click', function() {
            window.location.href = window.dashboardData.baseUrl + 'profile/index'; 
        });
    }
}


function initializeModals() {
    const renameModal = document.getElementById('rename-modal');
    const renameCancel = document.getElementById('rename-cancel');
    const renameConfirm = document.getElementById('rename-confirm');
    const renameInput = document.getElementById('rename-input');
    
    if (renameCancel) {
        renameCancel.addEventListener('click', hideRenameModal);
    }
    
    if (renameConfirm) {
        renameConfirm.addEventListener('click', handleRename);
    }
    
    if (renameInput) {
        renameInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                handleRename();
            } else if (e.key === 'Escape') {
                hideRenameModal();
            }
        });
    }
    
    const folderModal = document.getElementById('new-folder-modal');
    const folderCancel = document.getElementById('folder-cancel');
    const folderConfirm = document.getElementById('folder-confirm');
    const folderInput = document.getElementById('folder-name-input');
    
    if (folderCancel) {
        folderCancel.addEventListener('click', hideNewFolderModal);
    }
    
    if (folderConfirm) {
        folderConfirm.addEventListener('click', handleCreateFolder);
    }
    
    if (folderInput) {
        folderInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                handleCreateFolder();
            } else if (e.key === 'Escape') {
                hideNewFolderModal();
            }
        });
    }
    
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            if (e.target.id === 'rename-modal') {
                hideRenameModal();
            } else if (e.target.id === 'new-folder-modal') {
                hideNewFolderModal();
            }
        }
    });
}

function initializeUpload() {
    const fileInput = document.getElementById('file-input');
    
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleFileUpload(e.target.files);
                e.target.value = '';
            }
        });
    }
    
    const fileList = document.getElementById('file-list');
    if (fileList) {
        fileList.addEventListener('dragover', function(e) {
            e.preventDefault();
            fileList.classList.add('drag-over');
        });
        
        fileList.addEventListener('dragleave', function(e) {
            e.preventDefault();
            fileList.classList.remove('drag-over');
        });
        
        fileList.addEventListener('drop', function(e) {
            e.preventDefault();
            fileList.classList.remove('drag-over');
            
            if (e.dataTransfer.files.length > 0) {
                handleFileUpload(e.dataTransfer.files);
            }
        });
    }
}


function handleFileSelection() {
    updateActionButtonStates();
}


function updateActionButtonStates() {
    const selectedFiles = getSelectedFiles();
    const selectedCount = selectedFiles.length;
    
    const downloadBtn = document.getElementById('download-btn');
    const renameBtn = document.getElementById('rename-btn');
    const deleteBtn = document.getElementById('delete-btn');
    
    if (downloadBtn) {
        downloadBtn.disabled = selectedCount === 0;
    }
    
    if (renameBtn) {
        renameBtn.disabled = selectedCount !== 1;
    }
    
    if (deleteBtn) {
        deleteBtn.disabled = selectedCount === 0;
    }
}

function getSelectedFiles() {
    const checkboxes = document.querySelectorAll('.file-checkbox:checked');
    return Array.from(checkboxes).map(checkbox => ({
        id: checkbox.getAttribute('data-file-id'),
        type: checkbox.getAttribute('data-file-type'),
        name: checkbox.getAttribute('data-file-name')
    }));
}


async function navigateToPath(path) {
    try {
        const response = await fetch(window.dashboardData.baseUrl + 'dashboard/navigate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'path=' + encodeURIComponent(path),
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.dashboardData.currentPath = data.current_path;
            
            const breadcrumbPath = document.getElementById('breadcrumb-path');
            if (breadcrumbPath && data.breadcrumb) {
                breadcrumbPath.innerHTML = generateBreadcrumbHtml(data.breadcrumb);
            }
            
            const fileList = document.getElementById('file-list');
            if (fileList && data.files) {
                fileList.innerHTML = generateFileListHtml(data.files);
            }
            
            clearAllSelections();
            updateActionButtonStates();
            
        } else {
            showToast(data.error || 'Navigation failed', 'error');
        }
        
    } catch (error) {
        console.error('Navigation error:', error);
        showToast('Navigation failed. Please try again.', 'error');
    }
}

async function handleFileUpload(files) {
    if (files.length === 0) return;
    
    const fileArray = Array.from(files);
    
    const uploadToastId = showUploadToast(fileArray);
    
    try {
        let totalFiles = fileArray.length;
        let completedFiles = 0;
        let failedFiles = 0;
        let successfulFiles = 0;
        
        for (let file of fileArray) {
            updateUploadToast(uploadToastId, `Starting upload of ${file.name}...`, completedFiles, totalFiles, file.name);
            
            try {
                await uploadChunkedFile(file, uploadToastId);
                successfulFiles++;
                completedFiles++;
                
                updateFileStatus(uploadToastId, file.name, 'success');
                
                updateUploadToast(uploadToastId, `Uploaded ${successfulFiles}/${totalFiles} files successfully`, completedFiles, totalFiles);
                
            } catch (fileError) {
                console.error(`Error uploading ${file.name}:`, fileError);
                failedFiles++;
                completedFiles++;
                
                updateFileStatus(uploadToastId, file.name, 'failed');
                
                showToast(`Failed to upload ${file.name}: ${fileError.message}`, 'error');
                
                updateUploadToast(uploadToastId, `Processed ${completedFiles}/${totalFiles} files (${failedFiles} failed)`, completedFiles, totalFiles);
            }
        }
        
        if (failedFiles > 0) {
            if (successfulFiles > 0) {
                updateUploadToast(uploadToastId, `Upload completed with ${failedFiles} error${failedFiles !== 1 ? 's' : ''}`, completedFiles, totalFiles);
                showToast(`${successfulFiles} file${successfulFiles !== 1 ? 's' : ''} uploaded successfully, ${failedFiles} failed`, 'warning');
            } else {
                updateUploadToast(uploadToastId, `Upload failed - all ${failedFiles} file${failedFiles !== 1 ? 's' : ''} failed`, completedFiles, totalFiles);
                showToast(`Upload failed - all ${failedFiles} file${failedFiles !== 1 ? 's' : ''} failed`, 'error');
            }
        } else {
            updateUploadToast(uploadToastId, 'Upload complete!', completedFiles, totalFiles);
            showToast(`Successfully uploaded ${successfulFiles} file${successfulFiles !== 1 ? 's' : ''}!`, 'success');
        }
        
        if (successfulFiles > 0) {
            setTimeout(() => {
                navigateToPath(window.dashboardData.currentPath);
            }, 1000);
        }
        
        setTimeout(() => {
            removeUploadToast(uploadToastId);
        }, 5000);
        
    } catch (error) {
        console.error('Upload error:', error);
        showToast(error.message || 'Upload failed. Please try again.', 'error');
        updateUploadToast(uploadToastId, 'Upload failed!', 0, fileArray.length);
        
        setTimeout(() => {
            removeUploadToast(uploadToastId);
        }, 3000);
    }
}

function updateFileStatus(toastId, fileName, status) {
    if (!toastId) return;
    
    const toast = document.getElementById(toastId);
    if (!toast) return;
    
    const fileItems = toast.querySelectorAll('.upload-file-item');
    fileItems.forEach(item => {
        const nameSpan = item.querySelector('.upload-file-name');
        const statusSpan = item.querySelector('.upload-file-status');
        
        if (nameSpan && nameSpan.textContent === fileName && statusSpan) {
            switch (status) {
                case 'success':
                    statusSpan.textContent = '✓';
                    statusSpan.style.color = '#28a745';
                    break;
                case 'failed':
                    statusSpan.textContent = '✗';
                    statusSpan.style.color = '#dc3545';
                    break;
                case 'uploading':
                    statusSpan.textContent = 'Uploading...';
                    statusSpan.style.color = '#4299e1';
                    break;
                default:
                    statusSpan.textContent = 'Pending';
                    statusSpan.style.color = '#6c757d';
            }
        }
    });
}


async function uploadChunkedFile(file, uploadToastId = null) {
    const chunkSize = 20 * 1024 * 1024; 
    const totalChunks = Math.ceil(file.size / chunkSize);
    const uploadId = generateUploadId();
    let fileIdFromServer = null;

    for (let chunkNumber = 0; chunkNumber < totalChunks; chunkNumber++) {
        if (uploadToastId) {
            updateUploadToast(
                uploadToastId, 
                `Uploading ${file.name} - chunk ${chunkNumber + 1}/${totalChunks}...`, 
                chunkNumber, 
                totalChunks,
                file.name
            );
        }
        
        const start = chunkNumber * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);
        
        const formData = new FormData();
        formData.append('chunk', chunk);
        formData.append('chunk_number', chunkNumber);
        formData.append('total_chunks', totalChunks);
        formData.append('file_name', file.name);
        formData.append('file_size', file.size);
        formData.append('upload_id', uploadId);
        formData.append('path', window.dashboardData.currentPath);
        
        const response = await fetch(window.dashboardData.baseUrl + 'api/dashboard/upload', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Chunk upload failed');
        }

        if (data.file_id) {
            fileIdFromServer = data.file_id;
        }
        
        if (data.upload_complete) {
            if (uploadToastId) {
                updateUploadToast(
                    uploadToastId, 
                    `${file.name} uploaded successfully!`, 
                    totalChunks, 
                    totalChunks,
                    file.name
                );
            }
            break;
        }
    }

    if (fileIdFromServer) {
        pollFileUploadStatus(fileIdFromServer, () => {
            navigateToPath(window.dashboardData.currentPath);
        });
    }
}


function generateUploadId() {
    return Date.now().toString(36) + Math.random().toString(36).substr(2);
}


async function handleDownload() {
    const selectedFiles = getSelectedFiles();
    
    if (selectedFiles.length === 0) {
        showToast('No files selected', 'warning');
        return;
    }
    
    try {
        const formData = new FormData();
        selectedFiles.forEach(file => {
            formData.append('file_ids[]', file.id);
        });
        
        const response = await fetch(window.dashboardData.baseUrl + 'dashboard/download', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            await downloadChunkedFile(data);
        } else {
            showToast(data.error || 'Download failed', 'error');
        }
        
    } catch (error) {
        console.error('Download error:', error);
        showToast('Download failed. Please try again.', 'error');
    }
}

async function downloadChunkedFile(downloadInfo) {
    const { download_id, file_name, file_size, total_chunks, chunk_size } = downloadInfo;
    
    const downloadToastId = showDownloadToast(file_name, file_size);
    
    try {
        const maxConcurrent = Math.min(6, total_chunks); 
        const chunks = new Array(total_chunks);
        let completedChunks = 0;
        
        const downloadPromises = [];
        
        for (let chunkNumber = 0; chunkNumber < total_chunks; chunkNumber++) {
            const promise = downloadChunk(download_id, chunkNumber, chunk_size)
                .then(data => {
                    chunks[chunkNumber] = data;
                    completedChunks++;
                    updateDownloadToast(downloadToastId, 
                        `Downloaded chunk ${completedChunks}/${total_chunks}...`, 
                        completedChunks, total_chunks);
                })
                .catch(error => {
                    console.error(`Failed to download chunk ${chunkNumber}:`, error);
                    throw error;
                });
            
            downloadPromises.push(promise);
            
            if (downloadPromises.length >= maxConcurrent) {
                await Promise.all(downloadPromises.splice(0, maxConcurrent));
            }
        }
        
        if (downloadPromises.length > 0) {
            await Promise.all(downloadPromises);
        }
        
        updateDownloadToast(downloadToastId, 'Assembling file...', total_chunks, total_chunks);
        const completeFile = new Blob(chunks);
        
        const url = window.URL.createObjectURL(completeFile);
        const a = document.createElement('a');
        a.href = url;
        a.download = file_name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        updateDownloadToast(downloadToastId, 'Download complete!', total_chunks, total_chunks);
        showToast('Download completed successfully!', 'success');
        
        setTimeout(() => {
            removeDownloadToast(downloadToastId);
        }, 3000);
        
    } catch (error) {
        console.error('Chunked download error:', error);
        showToast(`Download failed: ${error.message}`, 'error');
        removeDownloadToast(downloadToastId);
    }
}

async function downloadChunk(downloadId, chunkNumber, chunkSize) {
    const formData = new FormData();
    formData.append('chunk_request', '1');
    formData.append('download_id', downloadId);
    formData.append('chunk_number', chunkNumber);
    formData.append('chunk_size', chunkSize);
    
    const response = await fetch(window.dashboardData.baseUrl + 'dashboard/download', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include'
    });
    
    if (!response.ok) {
        throw new Error(`Failed to download chunk ${chunkNumber}: ${response.status}`);
    }
    
    return await response.arrayBuffer();
}


function showNewFolderModal() {
    const modal = document.getElementById('new-folder-modal');
    const input = document.getElementById('folder-name-input');
    
    if (modal) {
        modal.style.display = 'flex';
    }
    if (input) {
        input.value = '';
        input.focus();
    }
}

function hideNewFolderModal() {
    const modal = document.getElementById('new-folder-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

async function handleCreateFolder() {
    const input = document.getElementById('folder-name-input');
    const folderName = input ? input.value.trim() : '';
    
    if (!folderName) {
        showToast('Folder name is required', 'warning');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('folder_name', folderName);
        formData.append('path', window.dashboardData.currentPath);
        
        const response = await fetch(window.dashboardData.baseUrl + 'dashboard/create-folder', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            hideNewFolderModal();
            showToast('Folder created successfully!', 'success');
            
            setTimeout(() => {
                navigateToPath(window.dashboardData.currentPath);
            }, 1000);
            
        } else {
            showToast(data.error || 'Failed to create folder', 'error');
        }
        
    } catch (error) {
        console.error('Create folder error:', error);
        showToast('Failed to create folder. Please try again.', 'error');
    }
}

function showRenameModal() {
    const selectedFiles = getSelectedFiles();
    
    if (selectedFiles.length !== 1) {
        showToast('Please select exactly one item to rename', 'warning');
        return;
    }
    
    const modal = document.getElementById('rename-modal');
    const input = document.getElementById('rename-input');
    
    if (modal) {
        modal.style.display = 'flex';
    }
    if (input) {
        input.value = selectedFiles[0].name;
        input.focus();
        input.select();
    }
}

function hideRenameModal() {
    const modal = document.getElementById('rename-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

async function handleRename() {
    const selectedFiles = getSelectedFiles();
    const input = document.getElementById('rename-input');
    const newName = input ? input.value.trim() : '';
    
    if (selectedFiles.length !== 1) {
        showToast('Please select exactly one item to rename', 'warning');
        return;
    }
    
    if (!newName) {
        showToast('Name is required', 'warning');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('item_id', selectedFiles[0].id);
        formData.append('new_name', newName);
        
        const response = await fetch(window.dashboardData.baseUrl + 'dashboard/rename', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            hideRenameModal();
            showToast('Item renamed successfully!', 'success');
            
            setTimeout(() => {
                location.reload();
            }, 1000);
            
        } else {
            showToast(data.error || 'Failed to rename item', 'error');
        }
        
    } catch (error) {
        console.error('Rename error:', error);
        showToast('Failed to rename item. Please try again.', 'error');
    }
}

async function handleDelete() {
    const selectedFiles = getSelectedFiles();
    
    if (selectedFiles.length === 0) {
        showToast('No items selected', 'warning');
        return;
    }
    
    const itemText = selectedFiles.length === 1 ? 'item' : 'items';
    if (!confirm(`Are you sure you want to delete ${selectedFiles.length} ${itemText}?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        selectedFiles.forEach(file => {
            formData.append('item_ids[]', file.id);
        });
        
        const response = await fetch(window.dashboardData.baseUrl + 'dashboard/delete', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast(`${data.deleted_count} items deleted successfully!`, 'success');
            
            setTimeout(() => {
                location.reload();
            }, 1000);
            
        } else {
            showToast(data.error || 'Failed to delete items', 'error');
        }
        
    } catch (error) {
        console.error('Delete error:', error);
        showToast('Failed to delete items. Please try again.', 'error');
    }
}

function clearAllSelections() {
    const checkboxes = document.querySelectorAll('.file-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

function generateBreadcrumbHtml(breadcrumb) {
    if (!breadcrumb || breadcrumb.length === 0) {
        return '<span class="breadcrumb-item active" data-path="/">Home</span>';
    }
    
    let html = '';
    const totalItems = breadcrumb.length;
    
    breadcrumb.forEach((item, index) => {
        const isLast = (index === totalItems - 1);
        
        if (isLast) {
            html += `<span class="breadcrumb-item active" data-path="${item.path}">${item.name}</span>`;
        } else {
            html += `<button class="breadcrumb-item clickable" data-path="${item.path}">${item.name}</button>`;
            html += '<span class="breadcrumb-separator"> › </span>';
        }
    });
    
    return html;
}

function generateFileListHtml(files) {
    if (!files || files.length === 0) {
        return `<div class="empty-folder">
                    <div class="empty-icon">📁</div>
                    <p>This folder is empty</p>
                    <p class="empty-hint">Upload files or create folders to get started</p>
                </div>`;
    }

    let html = '';

    files.forEach(file => {
        const modifiedDate = new Date(file.modified).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });

        const isUploading = file.type === 'file' && file.uploaded === false;
        const isUploaded = file.type === 'file' && file.uploaded === true;
        const uploadStatus = isUploading ? ' uploading' : '';
        const uploadingLabel = isUploading ? '<span class="uploading-label">Uploading to cloud…</span>' : '';
        const uploadedLabel = isUploaded ? '<span class="uploaded-label">Uploaded</span>' : '';

        html += `<div class="file-item ${file.type}${uploadStatus}" data-type="${file.type}" data-name="${file.name}" data-id="${file.id}">
                    <div class="file-select">
                        <input type="checkbox" class="file-checkbox" data-file-id="${file.id}" data-file-type="${file.type}" data-file-name="${file.name}" ${isUploading ? 'disabled' : ''}>
                    </div>
                    <div class="file-icon">${file.icon}</div>
                    <div class="file-details">
                        <div class="file-name" title="${file.name}">${file.name} ${uploadingLabel} ${uploadedLabel}</div>
                        <div class="file-meta">
                            <span class="file-size">${file.size || '—'}</span>
                            <span class="file-date">${modifiedDate}</span>
                        </div>
                    </div>
                </div>`;
    });

    return html;
}

function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icons = {
        error: '❌',
        success: '✅', 
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    toast.innerHTML = `
        <div class="toast-header">
            <span>${icons[type]} ${type.charAt(0).toUpperCase() + type.slice(1)}</span>
            <button class="toast-close" onclick="closeToast(this)">&times;</button>
        </div>
        <div class="toast-message">${message}</div>
    `;
    
    container.appendChild(toast);
    
    if (duration > 0) {
        setTimeout(() => {
            if (toast.parentNode) {
                closeToast(toast.querySelector('.toast-close'));
            }
        }, duration);
    }
}

function closeToast(closeBtn) {
    const toast = closeBtn.closest('.toast');
    if (toast && toast.parentNode) {
        toast.parentNode.removeChild(toast);
    }
}

function showUploadToast(files) {
    const container = document.getElementById('toast-container');
    if (!container) return null;
    
    const toastId = 'upload-toast-' + Date.now();
    const toast = document.createElement('div');
    toast.className = 'toast upload';
    toast.id = toastId;
    
    const fileArray = Array.from(files);
    
    let fileListHtml = '';
    fileArray.forEach(file => {
        fileListHtml += `
            <div class="upload-file-item">
                <span class="upload-file-name" title="${file.name}">${file.name}</span>
                <span class="upload-file-status">Pending</span>
            </div>
        `;
    });
    
    toast.innerHTML = `
        <div class="toast-header">
            <span>📤 Uploading Files</span>
            <button class="toast-close" onclick="removeUploadToast('${toastId}')">&times;</button>
        </div>
        <div class="toast-message">
            <div class="upload-status">Preparing upload...</div>
            <div class="upload-progress-container">
                <div class="upload-progress-text">0%</div>
                <div class="upload-progress-bar">
                    <div class="upload-progress-fill"></div>
                </div>
            </div>
            <div class="upload-file-list">
                ${fileListHtml}
            </div>
        </div>
    `;
    
    container.appendChild(toast);
    return toastId;
}

function updateUploadToast(toastId, statusText, completedFiles, totalFiles, currentFileName = null) {
    if (!toastId) return;
    
    const toast = document.getElementById(toastId);
    if (!toast) return;
    
    const statusElement = toast.querySelector('.upload-status');
    const progressFill = toast.querySelector('.upload-progress-fill');
    const progressText = toast.querySelector('.upload-progress-text');
    
    if (statusElement) {
        statusElement.textContent = statusText;
    }
    
    const progress = totalFiles > 0 ? Math.round((completedFiles / totalFiles) * 100) : 0;
    
    if (progressFill) {
        progressFill.style.width = progress + '%';
        
        if (statusText.includes('failed') || statusText.includes('error')) {
            progressFill.style.backgroundColor = '#dc3545'; 
        } else if (statusText.includes('Upload complete')) {
            progressFill.style.backgroundColor = '#28a745'; 
        } else {
            progressFill.style.backgroundColor = '#4299e1'; 
        }
    }
    
    if (progressText) {
        progressText.textContent = progress + '%';
    }
    
    if (currentFileName) {
        updateFileStatus(toastId, currentFileName, 'uploading');
    }
}

function removeUploadToast(toastId) {
    if (!toastId) return;
    
    const toast = document.getElementById(toastId);
    if (toast && toast.parentNode) {
        toast.parentNode.removeChild(toast);
    }
}

function showDownloadToast(fileName, fileSize) {
    const container = document.getElementById('toast-container');
    if (!container) return null;
    
    const toastId = 'download-toast-' + Date.now();
    const toast = document.createElement('div');
    toast.className = 'toast download';
    toast.id = toastId;
    
    const fileSizeFormatted = formatFileSize(fileSize);
    
    toast.innerHTML = `
        <div class="toast-header">
            <span>📥 Downloading File</span>
            <button class="toast-close" onclick="removeDownloadToast('${toastId}')">&times;</button>
        </div>
        <div class="toast-message">
            <div class="download-file-name" title="${fileName}">${fileName}</div>
            <div class="download-file-size">${fileSizeFormatted}</div>
            <div class="download-status">Preparing download...</div>
            <div class="download-progress-container">
                <div class="download-progress-text">0%</div>
                <div class="download-progress-bar">
                    <div class="download-progress-fill"></div>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(toast);
    return toastId;
}


function updateDownloadToast(toastId, statusText, completedChunks, totalChunks) {
    if (!toastId) return;
    
    const toast = document.getElementById(toastId);
    if (!toast) return;
    
    const statusElement = toast.querySelector('.download-status');
    const progressFill = toast.querySelector('.download-progress-fill');
    const progressText = toast.querySelector('.download-progress-text');
    
    if (statusElement) {
        statusElement.textContent = statusText;
    }
    
    const progress = totalChunks > 0 ? Math.round((completedChunks / totalChunks) * 100) : 0;
    
    if (progressFill) {
        progressFill.style.width = progress + '%';
    }
    
    if (progressText) {
        progressText.textContent = progress + '%';
    }
}


function removeDownloadToast(toastId) {
    if (!toastId) return;
    
    const toast = document.getElementById(toastId);
    if (toast && toast.parentNode) {
        toast.parentNode.removeChild(toast);
    }
}


function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function pollFileUploadStatus(fileId, onUploaded) {
    const interval = setInterval(() => {
        fetch(window.dashboardData.baseUrl + 'dashboard/file-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `file_id=${encodeURIComponent(fileId)}`,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success && data.uploaded) {
                clearInterval(interval);
                if (typeof onUploaded === 'function') {
                    onUploaded();
                }
            }
        })
        .catch(() => {
        });
    }, 2000); 
}

window.removeUploadToast = removeUploadToast;
window.removeDownloadToast = removeDownloadToast;