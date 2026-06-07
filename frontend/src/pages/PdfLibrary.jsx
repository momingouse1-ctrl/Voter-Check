import React, { useEffect, useState, useCallback } from 'react';
import AppLayout from '../components/AppLayout';
import StatusBadge from '../components/StatusBadge';
import LoadingSpinner from '../components/LoadingSpinner';
import EmptyState from '../components/EmptyState';
import { BookOpen, Download, Eye, RefreshCw, Trash2, Search } from 'lucide-react';
import api from '../lib/api';
import { useNavigate } from 'react-router-dom';

function formatBytes(bytes) {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return bytes + ' B';
}

export default function PdfLibrary() {
  const [pdfs, setPdfs] = useState([]);
  const [meta, setMeta] = useState({});
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [actionLoading, setActionLoading] = useState(null);
  const navigate = useNavigate();

  const fetchPdfs = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/pdfs', {
        params: { page, search, status: statusFilter, per_page: 25 },
      });
      setPdfs(res.data.data);
      setMeta(res.data);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => { fetchPdfs(); }, [fetchPdfs]);

  const handleDelete = async (id, name) => {
    if (!window.confirm(`Delete "${name}"? This will remove all indexed text.`)) return;
    setActionLoading(id + '-delete');
    try {
      await api.delete(`/pdfs/${id}`);
      fetchPdfs();
    } finally {
      setActionLoading(null);
    }
  };

  const handleReprocess = async (id) => {
    setActionLoading(id + '-reprocess');
    try {
      await api.post(`/pdfs/${id}/reprocess`);
      fetchPdfs();
    } finally {
      setActionLoading(null);
    }
  };

  const pages = meta.last_page || 1;

  return (
    <AppLayout title="PDF Library">
      {/* Banner */}
      <div className="page-banner blue">
        <div className="banner-content">
          <div className="banner-title">PDF Library</div>
          <div className="banner-subtitle">Browse all uploaded PDFs, view their status, and manage your collection.</div>
        </div>
        <BookOpen size={80} className="banner-icon" />
      </div>

      {/* Filters */}
      <div className="card" style={{ padding: '16px 20px', marginBottom: 20 }}>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
            <Search size={16} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)' }} />
            <input
              id="pdf-search-input"
              className="form-input"
              placeholder="Search by filename..."
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              style={{ paddingLeft: 34 }}
            />
          </div>
          <select
            className="form-select"
            style={{ width: 160 }}
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          >
            <option value="">All Statuses</option>
            <option value="indexed">Indexed</option>
            <option value="queued">Queued</option>
            <option value="processing">Processing</option>
            <option value="failed">Failed</option>
            <option value="uploaded">Uploaded</option>
          </select>
          <button className="btn btn-secondary btn-sm" onClick={fetchPdfs}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      {/* Table */}
      <div className="card">
        <div className="card-header">
          <span className="card-title">PDFs ({meta.total || 0})</span>
        </div>
        <div className="table-wrap">
          {loading ? (
            <LoadingSpinner text="Loading PDFs..." />
          ) : pdfs.length === 0 ? (
            <EmptyState
              type="noPdfs"
              title="No PDFs found"
              subtitle="Upload PDF files to start indexing and searching."
              action={<button className="btn btn-primary" onClick={() => navigate('/upload')}>Upload PDFs</button>}
            />
          ) : (
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>File Name</th>
                  <th>Size</th>
                  <th>Pages</th>
                  <th>Status</th>
                  <th>Method</th>
                  <th>Uploaded</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {pdfs.map((pdf, idx) => (
                  <tr key={pdf.id}>
                    <td style={{ color: 'var(--muted)' }}>{(page - 1) * 25 + idx + 1}</td>
                    <td style={{ fontWeight: 500, maxWidth: 280, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {pdf.original_name}
                    </td>
                    <td style={{ color: 'var(--muted)', whiteSpace: 'nowrap' }}>{formatBytes(pdf.file_size)}</td>
                    <td>{pdf.total_pages || '—'}</td>
                    <td><StatusBadge status={pdf.status} /></td>
                    <td style={{ fontSize: 12, color: 'var(--muted)' }}>{pdf.extraction_method || '—'}</td>
                    <td style={{ color: 'var(--muted)', fontSize: 12 }}>
                      {new Date(pdf.created_at).toLocaleDateString()}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: 4 }}>
                        <button
                          className="btn btn-secondary btn-sm"
                          title="View PDF"
                          onClick={() => window.open(`/api/pdfs/${pdf.id}/file`, '_blank')}
                          id={`view-${pdf.id}`}
                        >
                          <Eye size={13} />
                        </button>
                        <button
                          className="btn btn-secondary btn-sm"
                          title="Download"
                          onClick={() => window.open(`/api/pdfs/${pdf.id}/download`, '_blank')}
                          id={`download-${pdf.id}`}
                        >
                          <Download size={13} />
                        </button>
                        <button
                          className="btn btn-secondary btn-sm"
                          title="Reprocess"
                          disabled={actionLoading === pdf.id + '-reprocess'}
                          onClick={() => handleReprocess(pdf.id)}
                          id={`reprocess-${pdf.id}`}
                        >
                          {actionLoading === pdf.id + '-reprocess'
                            ? <div className="spinner" style={{ width: 12, height: 12 }}></div>
                            : <RefreshCw size={13} />}
                        </button>
                        <button
                          className="btn btn-danger btn-sm"
                          title="Delete"
                          disabled={actionLoading === pdf.id + '-delete'}
                          onClick={() => handleDelete(pdf.id, pdf.original_name)}
                          id={`delete-${pdf.id}`}
                        >
                          {actionLoading === pdf.id + '-delete'
                            ? <div className="spinner" style={{ width: 12, height: 12 }}></div>
                            : <Trash2 size={13} />}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* Pagination */}
        {pages > 1 && (
          <div className="pagination">
            <button className="page-btn" disabled={page === 1} onClick={() => setPage(p => p - 1)}>‹</button>
            {Array.from({ length: Math.min(pages, 7) }, (_, i) => {
              const p = i + 1;
              return (
                <button key={p} className={`page-btn ${page === p ? 'active' : ''}`} onClick={() => setPage(p)}>
                  {p}
                </button>
              );
            })}
            <button className="page-btn" disabled={page === pages} onClick={() => setPage(p => p + 1)}>›</button>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
