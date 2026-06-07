import React, { useEffect, useState } from 'react';
import AppLayout from '../components/AppLayout';
import StatusBadge from '../components/StatusBadge';
import LoadingSpinner from '../components/LoadingSpinner';
import EmptyState from '../components/EmptyState';
import { AlertTriangle, RefreshCw, Trash2 } from 'lucide-react';
import api from '../lib/api';

export default function FailedFiles() {
  const [pdfs, setPdfs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(null);

  const fetchFailed = async () => {
    setLoading(true);
    try {
      const res = await api.get('/pdfs', { params: { status: 'failed', per_page: 100 } });
      setPdfs(res.data.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchFailed(); }, []);

  const handleReprocess = async (id) => {
    setActionLoading(id + '-reprocess');
    try { await api.post(`/pdfs/${id}/reprocess`); fetchFailed(); }
    finally { setActionLoading(null); }
  };

  const handleDelete = async (id, name) => {
    if (!window.confirm(`Delete "${name}"?`)) return;
    setActionLoading(id + '-delete');
    try { await api.delete(`/pdfs/${id}`); fetchFailed(); }
    finally { setActionLoading(null); }
  };

  const reprocessAll = async () => {
    if (!window.confirm('Reprocess all failed PDFs?')) return;
    for (const pdf of pdfs) {
      await api.post(`/pdfs/${pdf.id}/reprocess`).catch(() => {});
    }
    fetchFailed();
  };

  return (
    <AppLayout title="Failed Files">
      <div className="page-banner" style={{ background: 'linear-gradient(135deg,#7f1d1d,#dc2626)', marginBottom: 24 }}>
        <div className="banner-content">
          <div className="banner-title">Failed PDF Processing</div>
          <div className="banner-subtitle">
            These PDFs could not be indexed. Review errors and reprocess them.
          </div>
        </div>
        <AlertTriangle size={80} className="banner-icon" />
      </div>

      {pdfs.length > 0 && (
        <div style={{ marginBottom: 16, display: 'flex', justifyContent: 'flex-end' }}>
          <button className="btn btn-primary" onClick={reprocessAll}>
            <RefreshCw size={16} /> Reprocess All Failed
          </button>
        </div>
      )}

      <div className="card">
        <div className="card-header">
          <span className="card-title">Failed PDFs ({pdfs.length})</span>
        </div>
        {loading ? (
          <LoadingSpinner text="Loading..." />
        ) : pdfs.length === 0 ? (
          <EmptyState
            type="noFailed"
            title="No failed files"
            subtitle="All PDFs have been processed successfully."
          />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>File Name</th>
                  <th>Error</th>
                  <th>Uploaded</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {pdfs.map((pdf) => (
                  <tr key={pdf.id}>
                    <td style={{ fontWeight: 500, maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {pdf.original_name}
                    </td>
                    <td style={{ fontSize: 12, color: 'var(--danger)', maxWidth: 300 }}>
                      {pdf.error_message || 'Unknown error'}
                    </td>
                    <td style={{ fontSize: 12, color: 'var(--muted)' }}>
                      {new Date(pdf.created_at).toLocaleDateString()}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: 4 }}>
                        <button
                          className="btn btn-secondary btn-sm"
                          disabled={actionLoading === pdf.id + '-reprocess'}
                          onClick={() => handleReprocess(pdf.id)}
                          id={`reprocess-failed-${pdf.id}`}
                        >
                          {actionLoading === pdf.id + '-reprocess'
                            ? <div className="spinner" style={{ width: 12, height: 12 }}></div>
                            : <><RefreshCw size={13} /> Retry</>}
                        </button>
                        <button
                          className="btn btn-danger btn-sm"
                          disabled={actionLoading === pdf.id + '-delete'}
                          onClick={() => handleDelete(pdf.id, pdf.original_name)}
                          id={`delete-failed-${pdf.id}`}
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
          </div>
        )}
      </div>
    </AppLayout>
  );
}
