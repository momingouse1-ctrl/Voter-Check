import React, { useEffect, useState, useCallback } from 'react';
import { Mail, Search, RefreshCw, Copy, Check, Users } from 'lucide-react';
import AppLayout from '../components/AppLayout';
import LoadingSpinner from '../components/LoadingSpinner';
import EmptyState from '../components/EmptyState';
import api from '../lib/api';

export default function EmailsPage() {
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState({});
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [copied, setCopied] = useState(false);

  const fetchEmails = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/users/emails', {
        params: { page, search, per_page: 25 },
      });
      setUsers(res.data.data || []);
      setMeta(res.data || {});
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [page, search]);

  useEffect(() => { fetchEmails(); }, [fetchEmails]);

  const copyEmails = async () => {
    const text = users.map((user) => user.email).join('\n');
    if (!text) return;
    await navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 1400);
  };

  const pages = meta.last_page || 1;

  return (
    <AppLayout title="Collected Emails">
      <div className="page-banner green">
        <div className="banner-content">
          <div className="banner-title">Collected User Emails</div>
          <div className="banner-subtitle">Every email entered on the start screen is saved once. Duplicate entries are not repeated.</div>
        </div>
        <Mail size={80} className="banner-icon" />
      </div>

      <div className="stats-grid" style={{ marginBottom: 20 }}>
        <div className="stat-card">
          <div className="stat-icon blue"><Users size={18} /></div>
          <div className="stat-label">Unique Emails</div>
          <div className="stat-value">{meta.total || 0}</div>
        </div>
      </div>

      <div className="card" style={{ padding: '16px 20px', marginBottom: 20 }}>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
            <Search size={16} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)' }} />
            <input
              className="form-input"
              placeholder="Search email..."
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              style={{ paddingLeft: 34 }}
            />
          </div>
          <button className="btn btn-secondary btn-sm" onClick={fetchEmails}>
            <RefreshCw size={14} /> Refresh
          </button>
          <button className="btn btn-primary btn-sm" onClick={copyEmails} disabled={users.length === 0}>
            {copied ? <Check size={14} /> : <Copy size={14} />}
            {copied ? 'Copied' : 'Copy Page Emails'}
          </button>
        </div>
      </div>

      <div className="card">
        <div className="card-header">
          <span className="card-title">Emails ({meta.total || 0})</span>
        </div>
        <div className="table-wrap">
          {loading ? (
            <LoadingSpinner text="Loading emails..." />
          ) : users.length === 0 ? (
            <EmptyState
              title="No emails found"
              subtitle="When users enter their email to continue, it will appear here once."
            />
          ) : (
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>First Entered</th>
                  <th>Last Updated</th>
                </tr>
              </thead>
              <tbody>
                {users.map((user, idx) => (
                  <tr key={user.id}>
                    <td style={{ color: 'var(--muted)' }}>{(page - 1) * 25 + idx + 1}</td>
                    <td style={{ fontWeight: 600 }}>{user.email}</td>
                    <td>
                      <span className={`badge ${user.is_admin ? 'badge-high' : 'badge-indexed'}`}>
                        {user.is_admin ? 'Admin' : 'User'}
                      </span>
                    </td>
                    <td style={{ color: 'var(--muted)' }}>{new Date(user.created_at).toLocaleString()}</td>
                    <td style={{ color: 'var(--muted)' }}>{new Date(user.updated_at).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {pages > 1 && (
          <div className="pagination">
            <button className="page-btn" disabled={page === 1} onClick={() => setPage((p) => p - 1)}>‹</button>
            {Array.from({ length: Math.min(pages, 7) }, (_, i) => {
              const p = i + 1;
              return (
                <button key={p} className={`page-btn ${page === p ? 'active' : ''}`} onClick={() => setPage(p)}>
                  {p}
                </button>
              );
            })}
            <button className="page-btn" disabled={page === pages} onClick={() => setPage((p) => p + 1)}>›</button>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
