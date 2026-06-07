import React, { useState, useCallback } from 'react';
import { useDropzone } from 'react-dropzone';
import AppLayout from '../components/AppLayout';
import StatusBadge from '../components/StatusBadge';
import { Upload, File, X, CheckCircle, AlertCircle, CloudUpload } from 'lucide-react';
import api from '../lib/api';
import { getEmail } from '../lib/auth';

function formatBytes(bytes) {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return bytes + ' B';
}

export default function UploadPage() {
  const [queue, setQueue] = useState([]);
  const [uploading, setUploading] = useState(false);
  const email = getEmail();

  const onDrop = useCallback((acceptedFiles) => {
    const newItems = acceptedFiles.map((file) => ({
      id: Math.random().toString(36).slice(2),
      file,
      name: file.name,
      size: file.size,
      status: 'ready',
      progress: 0,
      error: null,
    }));
    setQueue((prev) => [...prev, ...newItems]);
  }, []);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: { 'application/pdf': ['.pdf'] },
    multiple: true,
  });

  const removeFile = (id) => {
    setQueue((prev) => prev.filter((f) => f.id !== id));
  };

  const uploadAll = async () => {
    const readyFiles = queue.filter((f) => f.status === 'ready');
    if (!readyFiles.length) return;

    setUploading(true);

    // Upload in batches of 20
    const BATCH = 20;
    for (let i = 0; i < readyFiles.length; i += BATCH) {
      const batch = readyFiles.slice(i, i + BATCH);

      // Mark batch as uploading
      setQueue((prev) =>
        prev.map((f) =>
          batch.find((b) => b.id === f.id) ? { ...f, status: 'uploading' } : f
        )
      );

      const formData = new FormData();
      batch.forEach((item) => formData.append('files[]', item.file));
      formData.append('email', email);

      try {
        const res = await api.post('/pdfs/upload', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });

        setQueue((prev) =>
          prev.map((f) =>
            batch.find((b) => b.id === f.id)
              ? { ...f, status: 'uploaded', progress: 100 }
              : f
          )
        );
      } catch (err) {
        const errMsg = err.response?.data?.message || 'Upload failed';
        setQueue((prev) =>
          prev.map((f) =>
            batch.find((b) => b.id === f.id)
              ? { ...f, status: 'failed', error: errMsg }
              : f
          )
        );
      }
    }

    setUploading(false);
  };

  const clearDone = () => {
    setQueue((prev) => prev.filter((f) => f.status !== 'uploaded'));
  };

  const readyCount = queue.filter((f) => f.status === 'ready').length;
  const uploadedCount = queue.filter((f) => f.status === 'uploaded').length;
  const failedCount = queue.filter((f) => f.status === 'failed').length;

  return (
    <AppLayout title="Upload PDFs">
      {/* Banner */}
      <div className="page-banner green">
        <div className="banner-content">
          <div className="banner-title">Upload Bulk PDF Files</div>
          <div className="banner-subtitle">
            Drag and drop hundreds of PDFs. The system will index every page automatically.
          </div>
        </div>
        <CloudUpload size={80} className="banner-icon" />
      </div>

      {/* Dropzone */}
      <div {...getRootProps()} className={`dropzone ${isDragActive ? 'active' : ''}`}>
        <input {...getInputProps()} id="pdf-file-input" />
        <div className="dropzone-icon">
          <Upload size={28} />
        </div>
        <div className="dropzone-title">
          {isDragActive ? 'Drop files here...' : 'Drop PDF files here or click to browse'}
        </div>
        <div className="dropzone-sub">
          Supports multiple files at once • PDF only • Max 100MB per file
        </div>
      </div>

      {/* Controls */}
      {queue.length > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', gap: 12, fontSize: 13, color: 'var(--muted)' }}>
            <span>{queue.length} files total</span>
            {readyCount > 0 && <span style={{ color: 'var(--primary)' }}>{readyCount} ready</span>}
            {uploadedCount > 0 && <span style={{ color: 'var(--accent)' }}>{uploadedCount} uploaded</span>}
            {failedCount > 0 && <span style={{ color: 'var(--danger)' }}>{failedCount} failed</span>}
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            {uploadedCount > 0 && (
              <button className="btn btn-secondary btn-sm" onClick={clearDone}>
                Clear Done
              </button>
            )}
            {readyCount > 0 && (
              <button
                id="upload-all-btn"
                className="btn btn-primary"
                onClick={uploadAll}
                disabled={uploading}
              >
                {uploading ? <><div className="spinner" style={{ width: 14, height: 14 }}></div> Uploading...</> : <><Upload size={16} /> Upload All ({readyCount})</>}
              </button>
            )}
          </div>
        </div>
      )}

      {/* File list */}
      {queue.length > 0 && (
        <div className="file-list">
          {queue.map((item) => (
            <div className="file-row" key={item.id}>
              <div className="file-icon">
                <File size={18} />
              </div>
              <div className="file-info">
                <div className="file-name">{item.name}</div>
                <div className="file-size">{formatBytes(item.size)}</div>
                {item.status === 'uploading' && (
                  <div className="progress-bar" style={{ marginTop: 6 }}>
                    <div className="progress-fill" style={{ width: '60%' }}></div>
                  </div>
                )}
                {item.error && (
                  <div style={{ fontSize: 11, color: 'var(--danger)', marginTop: 4 }}>{item.error}</div>
                )}
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                {item.status === 'ready'     && <span style={{ fontSize: 12, color: 'var(--muted)' }}>Ready</span>}
                {item.status === 'uploading' && <div className="spinner" style={{ width: 16, height: 16 }}></div>}
                {item.status === 'uploaded'  && <CheckCircle size={18} color="var(--accent)" />}
                {item.status === 'failed'    && <AlertCircle size={18} color="var(--danger)" />}
                {item.status !== 'uploading' && (
                  <button
                    className="btn btn-secondary btn-sm"
                    onClick={() => removeFile(item.id)}
                    style={{ padding: '4px 8px' }}
                  >
                    <X size={14} />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {queue.length === 0 && (
        <div className="card" style={{ marginTop: 0 }}>
          <div className="empty-state">
            <div className="empty-state-icon"><File size={28} /></div>
            <div className="empty-state-title">No files selected</div>
            <div className="empty-state-sub">
              Use the dropzone above to add PDF files. You can add hundreds at once.
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
